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
use Drupal\baas_project\Service\ProjectEntityCleanupService;
use Drupal\baas_project\Service\ProjectEntityTemplateManager;

/**
 * 项目实体模板删除确认表单。
 */
class ProjectEntityTemplateDeleteForm extends ConfirmFormBase
{
  use StringTranslationTrait;

  /**
   * 要删除的实体模板。
   */
  protected ?array $entityTemplate = NULL;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly Connection $database,
    protected readonly ProjectEntityCleanupService $entityCleanup,
    protected readonly ProjectEntityTemplateManager $entityTemplateManager
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_project.manager'),
      $container->get('database'),
      $container->get('baas_project.entity_cleanup'),
      $container->get('baas_project.entity_template_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'baas_project_entity_template_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $tenant_id = NULL, ?string $project_id = NULL, ?string $template_id = NULL): array
  {
    // 存储参数到表单状态
    $form_state->set('tenant_id', $tenant_id);
    $form_state->set('project_id', $project_id);
    $form_state->set('template_id', $template_id);

    // 加载实体模板
    $this->entityTemplate = $this->loadEntityTemplate($template_id);
    if (!$this->entityTemplate || $this->entityTemplate['project_id'] !== $project_id) {
      $this->messenger()->addError($this->t('找不到指定的实体模板。'));
      return [];
    }

    // 检查是否有依赖数据
    $warning_messages = $this->checkDependencies($template_id);
    if (!empty($warning_messages)) {
      $form['warnings'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . 
          '<h4>' . $this->t('警告：删除此实体将导致以下后果：') . '</h4>' .
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
    if ($this->entityTemplate) {
      return (string) $this->t('确定要删除实体 "@name" 吗？', ['@name' => $this->entityTemplate['name']]);
    }
    return (string) $this->t('确定要删除此实体吗？');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string
  {
    return (string) $this->t('此操作不可撤销。实体的所有字段定义和数据将被永久删除。');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string
  {
    return (string) $this->t('删除');
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
    
    return Url::fromRoute('baas_project.entities', [
      'tenant_id' => $tenant_id,
      'project_id' => $project_id,
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

    if (!$this->entityTemplate) {
      $this->messenger()->addError($this->t('找不到要删除的实体模板。'));
      return;
    }

    try {
      // 使用Entity管理服务进行规范化删除
      $deletion_result = $this->entityTemplateManager->deleteEntityTemplate($template_id);
      
      if ($deletion_result['success']) {
        // 删除成功，显示详细信息
        $this->messenger()->addStatus($this->t('实体 "@name" 已被成功删除。', ['@name' => $this->entityTemplate['name']]));
        
        // 显示清理详情
        foreach ($deletion_result['messages'] as $message) {
          $this->messenger()->addStatus($message);
        }
        
        if (!empty($deletion_result['cleaned_files'])) {
          $this->messenger()->addStatus($this->t('已清理 @count 个动态实体文件', ['@count' => count($deletion_result['cleaned_files'])]));
        }
        
        if (!empty($deletion_result['cleaned_tables'])) {
          $this->messenger()->addStatus($this->t('已删除 @count 个数据表', ['@count' => count($deletion_result['cleaned_tables'])]));
        }
        
        if (!empty($deletion_result['cleaned_records'])) {
          $this->messenger()->addStatus($this->t('已清理 @count 个数据库记录', ['@count' => count($deletion_result['cleaned_records'])]));
        }
        
      } else {
        // 删除失败，显示错误信息
        $this->messenger()->addError($this->t('删除实体时发生错误'));
        
        foreach ($deletion_result['errors'] as $error) {
          $this->messenger()->addError($error);
        }
      }

      // 重定向到实体列表
      $form_state->setRedirect('baas_project.entities', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
      ]);

    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('删除实体时发生异常：@error', ['@error' => $e->getMessage()]));
      \Drupal::logger('baas_project')->error('Error deleting entity template: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * 检查删除依赖。
   */
  protected function checkDependencies(string $template_id): array
  {
    $warnings = [];

    // 使用预览方法检查将要清理的资源
    $preview = $this->entityCleanup->previewCleanupResources($template_id);
    
    // 检查字段数量
    if ($preview['field_count'] > 0) {
      $warnings[] = $this->t('将删除 @count 个字段定义', ['@count' => $preview['field_count']]);
    }
    
    // 检查数据表
    if (!empty($preview['table_names'])) {
      $warnings[] = $this->t('将删除 @count 个数据表', ['@count' => count($preview['table_names'])]);
      foreach ($preview['table_names'] as $table_name) {
        $warnings[] = $this->t('- 数据表: @table', ['@table' => $table_name]);
      }
    }
    
    // 检查动态实体文件
    if (!empty($preview['file_paths'])) {
      $warnings[] = $this->t('将删除 @count 个动态实体文件', ['@count' => count($preview['file_paths'])]);
      foreach ($preview['file_paths'] as $file_path) {
        $warnings[] = $this->t('- 文件: @file', ['@file' => $file_path]);
      }
    }
    
    // 检查数据库记录
    if (!empty($preview['record_counts'])) {
      foreach ($preview['record_counts'] as $record_info) {
        $warnings[] = $this->t('将删除记录: @record', ['@record' => $record_info]);
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
}