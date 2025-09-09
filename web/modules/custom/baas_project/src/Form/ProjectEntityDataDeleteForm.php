<?php

declare(strict_types=1);

namespace Drupal\baas_project\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\baas_project\Service\ProjectTableNameGenerator;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * 项目实体数据删除确认表单。
 */
class ProjectEntityDataDeleteForm extends ConfirmFormBase
{

  /**
   * 租户ID。
   *
   * @var string
   */
  protected string $tenantId;

  /**
   * 项目ID。
   *
   * @var string
   */
  protected string $projectId;

  /**
   * 实体名称。
   *
   * @var string
   */
  protected string $entityName;

  /**
   * 数据ID。
   *
   * @var string
   */
  protected string $dataId;

  /**
   * 实体数据。
   *
   * @var array|null
   */
  protected ?array $entityData = null;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly Connection $database,
    protected readonly ProjectTableNameGenerator $tableNameGenerator,
    protected readonly AccountInterface $currentUser
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_project.manager'),
      $container->get('database'),
      $container->get('baas_project.table_name_generator'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'baas_project_entity_data_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $tenant_id = NULL, ?string $project_id = NULL, ?string $entity_name = NULL, ?string $data_id = NULL): array
  {
    // 存储参数到类属性
    $this->tenantId = $tenant_id;
    $this->projectId = $project_id;
    $this->entityName = $entity_name;
    $this->dataId = $data_id;

    // 验证权限
    if (!$this->validateAccess()) {
      throw new AccessDeniedHttpException('您没有权限访问此实体。');
    }

    // 加载数据记录
    $this->entityData = $this->loadEntityData();
    if (!$this->entityData) {
      throw new NotFoundHttpException('找不到指定的数据记录。');
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion()
  {
    return $this->t('确定要删除数据记录 "@title" 吗？', [
      '@title' => $this->entityData['title'] ?? $this->entityData['id'] ?? '未知',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription()
  {
    return $this->t('此操作不可撤销，删除后数据将无法恢复。');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText()
  {
    return $this->t('删除');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText()
  {
    return $this->t('取消');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl()
  {
    return new Url('baas_project.entity_data', [
      'tenant_id' => $this->tenantId,
      'project_id' => $this->projectId,
      'entity_name' => $this->entityName,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    try {
      // 删除数据记录
      $table_name = $this->tableNameGenerator->generateTableName(
        $this->tenantId,
        $this->projectId,
        $this->entityName
      );

      $deleted = $this->database->delete($table_name)
        ->condition('id', $this->dataId)
        ->execute();

      if ($deleted) {
        $this->messenger()->addStatus($this->t('数据记录 "@title" 已成功删除。', [
          '@title' => $this->entityData['title'] ?? $this->entityData['id'] ?? '未知',
        ]));

        \Drupal::logger('baas_project')->info('删除实体数据记录: 租户=@tenant_id, 项目=@project_id, 实体=@entity_name, 记录ID=@data_id', [
          '@tenant_id' => $this->tenantId,
          '@project_id' => $this->projectId,
          '@entity_name' => $this->entityName,
          '@data_id' => $this->dataId,
        ]);
      } else {
        $this->messenger()->addWarning($this->t('没有找到要删除的数据记录。'));
      }

      // 重定向到数据管理页面
      $form_state->setRedirect('baas_project.entity_data', [
        'tenant_id' => $this->tenantId,
        'project_id' => $this->projectId,
        'entity_name' => $this->entityName,
      ]);
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('删除数据时发生错误：@error', ['@error' => $e->getMessage()]));
      \Drupal::logger('baas_project')->error('删除实体数据失败: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * 验证访问权限。
   */
  protected function validateAccess(): bool
  {
    $current_user = $this->currentUser;
    $user_id = (int) $current_user->id();

    // 验证项目访问权限
    $project = $this->projectManager->getProject($this->projectId);
    if (!$project || $project['tenant_id'] !== $this->tenantId) {
      return FALSE;
    }

    // 检查用户是否是项目成员或有管理权限
    $user_role = $this->projectManager->getUserProjectRole($this->projectId, $user_id);
    $has_admin_permission = $current_user->hasPermission('administer baas project') ||
      $current_user->hasPermission('delete baas project content');

    // 对于删除操作，需要管理员或拥有者权限
    return ($user_role && in_array($user_role, ['owner', 'admin'])) || $has_admin_permission;
  }

  /**
   * 加载实体数据。
   */
  protected function loadEntityData(): ?array
  {
    $table_name = $this->tableNameGenerator->generateTableName(
      $this->tenantId,
      $this->projectId,
      $this->entityName
    );

    if (!$this->database->schema()->tableExists($table_name)) {
      return NULL;
    }

    $data = $this->database->select($table_name, 'd')
      ->fields('d')
      ->condition('id', $this->dataId)
      ->execute()
      ->fetch(\PDO::FETCH_ASSOC);

    return $data ?: NULL;
  }
}
