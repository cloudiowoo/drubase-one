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
use Drupal\baas_tenant\TenantManagerInterface;

/**
 * 用户项目删除确认表单。
 */
class UserProjectDeleteForm extends ConfirmFormBase
{
  use StringTranslationTrait;

  /**
   * 要删除的项目。
   */
  protected ?array $project = NULL;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly Connection $database,
    protected readonly ProjectEntityCleanupService $entityCleanup,
    protected readonly TenantManagerInterface $tenantManager
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
      $container->get('baas_tenant.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'baas_project_user_project_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $tenant_id = NULL, ?string $project_id = NULL): array
  {
    // 存储参数到表单状态
    $form_state->set('tenant_id', $tenant_id);
    $form_state->set('project_id', $project_id);

    // 加载项目
    $this->project = $this->loadProject($project_id);
    if (!$this->project || $this->project['tenant_id'] !== $tenant_id) {
      $this->messenger()->addError($this->t('找不到指定的项目。'));
      return [];
    }

    // 检查用户权限
    if (!$this->checkDeletePermission($this->project)) {
      $this->messenger()->addError($this->t('您没有权限删除此项目。'));
      return [];
    }

    // 检查是否有依赖数据
    $warning_messages = $this->checkDependencies($project_id);
    if (!empty($warning_messages)) {
      $form['warnings'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . 
          '<h4>' . $this->t('警告：删除此项目将导致以下后果：') . '</h4>' .
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
    if ($this->project) {
      return (string) $this->t('确定要删除项目 "@name" 吗？', ['@name' => $this->project['name']]);
    }
    return (string) $this->t('确定要删除此项目吗？');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string
  {
    return (string) $this->t('此操作不可撤销。项目的所有实体模板、字段定义和数据将被永久删除。');
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
    return Url::fromRoute('baas_project.user_list');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $tenant_id = $form_state->get('tenant_id');
    $project_id = $form_state->get('project_id');

    if (!$this->project) {
      $this->messenger()->addError($this->t('找不到要删除的项目。'));
      return;
    }

    try {
      // 删除项目
      $success = $this->projectManager->deleteProject($project_id);
      
      if ($success) {
        $this->messenger()->addStatus($this->t('项目 "@name" 已被成功删除。', ['@name' => $this->project['name']]));
      } else {
        $this->messenger()->addError($this->t('删除项目时发生错误。'));
      }

      // 重定向到项目列表
      $form_state->setRedirect('baas_project.user_list');

    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('删除项目时发生异常：@error', ['@error' => $e->getMessage()]));
      \Drupal::logger('baas_project')->error('Error deleting project: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * 检查删除依赖。
   */
  protected function checkDependencies(string $project_id): array
  {
    $warnings = [];

    // 检查项目下的实体模板数量
    $entity_count = $this->database->select('baas_entity_template', 'e')
      ->condition('project_id', $project_id)
      ->condition('status', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($entity_count > 0) {
      $warnings[] = $this->t('将删除 @count 个实体模板', ['@count' => $entity_count]);
    }

    // 检查项目成员数量
    if ($this->database->schema()->tableExists('baas_project_members')) {
      $member_count = $this->database->select('baas_project_members', 'm')
        ->condition('project_id', $project_id)
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($member_count > 0) {
        $warnings[] = $this->t('将移除 @count 个项目成员', ['@count' => $member_count]);
      }
    }

    // 检查项目数据表
    $tables = $this->database->query(
      "SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern",
      [':pattern' => '%_project_' . $project_id . '_%']
    )->fetchCol();

    if (!empty($tables)) {
      $warnings[] = $this->t('将删除 @count 个数据表', ['@count' => count($tables)]);
    }

    return $warnings;
  }

  /**
   * 检查用户删除权限。
   */
  protected function checkDeletePermission(array $project): bool
  {
    $current_user = \Drupal::currentUser();
    $user_id = (int) $current_user->id();
    
    // 1. 检查系统级权限
    if ($current_user->hasPermission('delete baas project')) {
      return true;
    }
    
    // 2. 检查项目拥有者权限
    if ($project['owner_uid'] == $user_id) {
      return true;
    }
    
    // 3. 检查租户管理员权限
    if ($current_user->hasPermission('administer baas project')) {
      return true;
    }
    
    return false;
  }

  /**
   * 加载项目。
   */
  protected function loadProject(string $project_id): ?array
  {
    $project = $this->database->select('baas_project_config', 'p')
      ->fields('p')
      ->condition('project_id', $project_id)
      ->execute()
      ->fetch(\PDO::FETCH_ASSOC);

    return $project ?: NULL;
  }
}