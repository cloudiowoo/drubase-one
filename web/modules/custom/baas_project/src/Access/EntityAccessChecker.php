<?php

declare(strict_types=1);

namespace Drupal\baas_project\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Database\Connection;
use Drupal\baas_project\ProjectManagerInterface;

/**
 * 实体访问权限检查器。
 * 
 * 根据用户的项目角色控制对实体的访问权限。
 */
class EntityAccessChecker
{

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly ProjectManagerInterface $projectManager
  ) {}

  /**
   * 检查用户对实体的访问权限。
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   要访问的实体。
   * @param string $operation
   *   操作类型 (view, create, update, delete)。
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果。
   */
  public function checkEntityAccess(EntityInterface $entity, string $operation, AccountInterface $account): AccessResultInterface
  {
    // 管理员有全部权限
    if ($account->hasPermission('administer baas project')) {
      return AccessResult::allowed()->cachePerUser();
    }

    // 获取实体的项目ID
    $project_id = $this->getEntityProjectId($entity);
    if (!$project_id) {
      return AccessResult::forbidden('实体没有关联的项目')->cachePerUser();
    }

    // 获取用户在项目中的角色
    $user_role = $this->projectManager->getUserProjectRole($project_id, (int) $account->id());
    if (!$user_role) {
      return AccessResult::forbidden('用户不是项目成员')->cachePerUser();
    }

    // 检查角色是否允许该操作
    if ($this->isOperationAllowed($user_role, $operation)) {
      return AccessResult::allowed()->cachePerUser()->addCacheContexts(['user']);
    }

    return AccessResult::forbidden('用户角色不允许该操作')->cachePerUser();
  }

  /**
   * 检查用户是否可以在项目中创建实体。
   *
   * @param string $entity_type_id
   *   实体类型ID。
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果。
   */
  public function checkEntityCreateAccess(string $entity_type_id, AccountInterface $account): AccessResultInterface
  {
    // 管理员有全部权限
    if ($account->hasPermission('administer baas project')) {
      return AccessResult::allowed()->cachePerUser();
    }

    // 从实体类型ID解析项目ID
    $project_id = $this->getProjectIdFromEntityTypeId($entity_type_id);
    if (!$project_id) {
      return AccessResult::forbidden('无法确定实体类型所属项目')->cachePerUser();
    }

    // 获取用户在项目中的角色
    $user_role = $this->projectManager->getUserProjectRole($project_id, (int) $account->id());
    if (!$user_role) {
      return AccessResult::forbidden('用户不是项目成员')->cachePerUser();
    }

    // 检查角色是否允许创建操作
    if ($this->isOperationAllowed($user_role, 'create')) {
      return AccessResult::allowed()->cachePerUser()->addCacheContexts(['user']);
    }

    return AccessResult::forbidden('用户角色不允许创建实体')->cachePerUser();
  }

  /**
   * 获取实体的项目ID。
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   实体对象。
   *
   * @return string|null
   *   项目ID，如果无法确定则返回null。
   */
  protected function getEntityProjectId(EntityInterface $entity): ?string
  {
    // 方法1：从实体类型ID获取项目ID
    $entity_type_id = $entity->getEntityTypeId();
    $project_id = $this->getProjectIdFromEntityTypeId($entity_type_id);
    if ($project_id) {
      return $project_id;
    }

    // 方法2：如果实体有project_id字段
    if ($entity->hasField('project_id')) {
      return $entity->get('project_id')->value;
    }

    // 方法3：通过查询数据库获取
    return $this->getProjectIdFromDatabase($entity);
  }

  /**
   * 从实体类型ID解析项目ID。
   *
   * @param string $entity_type_id
   *   实体类型ID。
   *
   * @return string|null
   *   项目ID。
   */
  protected function getProjectIdFromEntityTypeId(string $entity_type_id): ?string
  {
    // 动态实体类型ID格式: {tenant_id}_{entity_name}
    // 例如: user_14_0ebf86fe_posts
    $parts = explode('_', $entity_type_id);
    
    if (count($parts) < 3) {
      return null;
    }

    // 解析租户ID (前两部分) 和实体名称 (最后一部分)
    $tenant_id = $parts[0] . '_' . $parts[1];
    $entity_name = $parts[count($parts) - 1];

    try {
      // 查询实体模板获取项目ID
      $project_id = $this->database->select('baas_entity_template', 'e')
        ->fields('e', ['project_id'])
        ->condition('tenant_id', $tenant_id)
        ->condition('name', $entity_name)
        ->condition('status', 1)
        ->execute()
        ->fetchField();

      return $project_id ?: null;
    } catch (\Exception $e) {
      return null;
    }
  }

  /**
   * 从数据库查询实体的项目ID。
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   实体对象。
   *
   * @return string|null
   *   项目ID。
   */
  protected function getProjectIdFromDatabase(EntityInterface $entity): ?string
  {
    // 使用实体类型ID获取项目ID
    $entity_type_id = $entity->getEntityTypeId();
    return $this->getProjectIdFromEntityTypeId($entity_type_id);
  }

  /**
   * 检查用户角色是否允许执行指定操作。
   *
   * @param string $role
   *   用户在项目中的角色。
   * @param string $operation
   *   操作类型。
   *
   * @return bool
   *   是否允许操作。
   */
  protected function isOperationAllowed(string $role, string $operation): bool
  {
    // 定义角色权限映射
    $role_permissions = [
      'owner' => ['view', 'create', 'update', 'delete'],
      'admin' => ['view', 'create', 'update', 'delete'],
      'editor' => ['view', 'create', 'update'],
      'member' => ['view'],
      'viewer' => ['view'],
    ];

    $allowed_operations = $role_permissions[$role] ?? [];
    return in_array($operation, $allowed_operations);
  }

  /**
   * 获取实体操作的权限要求。
   *
   * @param string $operation
   *   操作类型。
   *
   * @return array
   *   所需的最低角色列表。
   */
  public function getOperationRequiredRoles(string $operation): array
  {
    $operation_roles = [
      'view' => ['owner', 'admin', 'editor', 'member', 'viewer'],
      'create' => ['owner', 'admin', 'editor'],
      'update' => ['owner', 'admin', 'editor'],
      'delete' => ['owner', 'admin'],
    ];

    return $operation_roles[$operation] ?? [];
  }
}