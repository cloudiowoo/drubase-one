<?php

declare(strict_types=1);

namespace Drupal\baas_project\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\baas_project\Service\ProjectEntityTemplateManager;
use Drupal\baas_project\Service\ProjectTableNameGenerator;

/**
 * 项目实体字段删除确认表单。
 */
class ProjectEntityFieldDeleteForm extends ConfirmFormBase
{
  use StringTranslationTrait;

  /**
   * 要删除的字段。
   */
  protected ?array $entityField = NULL;

  /**
   * 实体模板信息。
   */
  protected ?array $entityTemplate = NULL;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly Connection $database,
    protected readonly ProjectEntityTemplateManager $entityTemplateManager,
    protected readonly ProjectTableNameGenerator $tableNameGenerator
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_project.manager'),
      $container->get('database'),
      $container->get('baas_project.entity_template_manager'),
      $container->get('baas_project.table_name_generator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'baas_project_entity_field_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $tenant_id = NULL, ?string $project_id = NULL, ?string $template_id = NULL, ?string $field_id = NULL): array
  {
    // 存储参数到表单状态
    $form_state->set('tenant_id', $tenant_id);
    $form_state->set('project_id', $project_id);
    $form_state->set('template_id', $template_id);
    $form_state->set('field_id', $field_id);

    // 加载实体模板
    $this->entityTemplate = $this->loadEntityTemplate($template_id);
    if (!$this->entityTemplate || $this->entityTemplate['project_id'] !== $project_id) {
      $this->messenger()->addError($this->t('找不到指定的实体模板。'));
      return [];
    }

    // 加载字段
    $this->entityField = $this->loadEntityField($field_id);
    if (!$this->entityField || $this->entityField['template_id'] !== $template_id) {
      $this->messenger()->addError($this->t('找不到指定的字段。'));
      return [];
    }

    // 检查字段依赖
    $warning_messages = $this->checkFieldDependencies($tenant_id, $project_id, $this->entityTemplate['name'], $this->entityField['name']);
    if (!empty($warning_messages)) {
      $form['warnings'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . 
          '<h4>' . $this->t('警告：删除此字段将导致以下后果：') . '</h4>' .
          '<ul><li>' . implode('</li><li>', $warning_messages) . '</li></ul>' .
          '</div>',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string
  {
    if ($this->entityField) {
      return (string) $this->t('确定要删除字段 "@name" 吗？', ['@name' => $this->entityField['name']]);
    }
    return (string) $this->t('确定要删除此字段吗？');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string
  {
    return (string) $this->t('此操作不可撤销。字段中的所有数据将被永久删除。');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string
  {
    return (string) $this->t('删除字段');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText(): string
  {
    return (string) $this->t('取消');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url
  {
    $tenant_id = $this->getRouteMatch()->getParameter('tenant_id');
    $project_id = $this->getRouteMatch()->getParameter('project_id');
    $template_id = $this->getRouteMatch()->getParameter('template_id');
    
    return Url::fromRoute('baas_project.entity_template_edit', [
      'tenant_id' => $tenant_id,
      'project_id' => $project_id,
      'template_id' => $template_id,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $tenant_id = $form_state->get('tenant_id');
    $project_id = $form_state->get('project_id');
    $template_id = $form_state->get('template_id');
    $field_id = $form_state->get('field_id');

    if (!$this->entityField || !$this->entityTemplate) {
      $this->messenger()->addError($this->t('找不到要删除的字段。'));
      return;
    }

    try {
      // 添加调试日志
      \Drupal::logger('baas_project')->info('🔥 ProjectEntityFieldDeleteForm::submitForm - 开始删除字段: field_id=@field_id, field_name=@field_name', [
        '@field_id' => $field_id,
        '@field_name' => $this->entityField ? $this->entityField['name'] : 'unknown',
      ]);

      // 使用Entity管理服务进行规范化删除
      $deletion_result = $this->entityTemplateManager->deleteEntityField($field_id);
      
      \Drupal::logger('baas_project')->info('🔥 deleteEntityField 返回结果: @result', [
        '@result' => json_encode($deletion_result),
      ]);
      
      if ($deletion_result['success']) {
        // 删除成功，显示消息
        $this->messenger()->addStatus($this->t('字段 "@name" 已被成功删除。', ['@name' => $this->entityField['name']]));
        
        // 显示其他消息
        foreach ($deletion_result['messages'] as $message) {
          $this->messenger()->addStatus($message);
        }
        
      } else {
        // 删除失败，显示错误信息
        $this->messenger()->addError($this->t('删除字段时发生错误'));
        
        foreach ($deletion_result['errors'] as $error) {
          $this->messenger()->addError($error);
        }
      }

      // 重定向到实体模板编辑页面
      $form_state->setRedirect('baas_project.entity_template_edit', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
        'template_id' => $template_id,
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('🔥 删除字段时发生异常：@error', ['@error' => $e->getMessage()]);
      $this->messenger()->addError($this->t('删除字段时发生异常：@error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * 检查字段删除依赖。
   */
  protected function checkFieldDependencies(string $tenant_id, string $project_id, string $entity_name, string $field_name): array
  {
    $warnings = [];

    // 检查数据表是否存在以及是否有数据
    $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);
    
    if ($this->database->schema()->tableExists($table_name) && 
        $this->database->schema()->fieldExists($table_name, $field_name)) {
      
      try {
        // 检查是否有非空数据
        $data_count = $this->database->select($table_name, 't')
          ->condition($field_name, '', '<>')
          ->condition($field_name, NULL, 'IS NOT NULL')
          ->countQuery()
          ->execute()
          ->fetchField();

        if ($data_count > 0) {
          $warnings[] = $this->t('字段包含 @count 条数据，删除后数据将丢失', ['@count' => $data_count]);
        }

        $warnings[] = $this->t('将从数据表 "@table" 中删除列 "@column"', [
          '@table' => $table_name,
          '@column' => $field_name,
        ]);
      } catch (\Exception $e) {
        // 忽略查询错误
        $warnings[] = $this->t('无法检查字段数据，删除可能影响现有数据');
      }
    }

    return $warnings;
  }

  /**
   * 加载实体模板。
   */
  protected function loadEntityTemplate(string $template_id): ?array
  {
    $template = $this->database->select('baas_entity_template', 'e')
      ->fields('e')
      ->condition('id', $template_id)
      ->execute()
      ->fetch(\PDO::FETCH_ASSOC);

    return $template ?: NULL;
  }

  /**
   * 加载实体字段。
   */
  protected function loadEntityField(string $field_id): ?array
  {
    if (!$this->database->schema()->tableExists('baas_entity_field')) {
      return NULL;
    }

    $field = $this->database->select('baas_entity_field', 'f')
      ->fields('f')
      ->condition('id', $field_id)
      ->execute()
      ->fetch(\PDO::FETCH_ASSOC);

    return $field ?: NULL;
  }
}