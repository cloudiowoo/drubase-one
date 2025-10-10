<?php

declare(strict_types=1);

namespace Drupal\baas_project\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;
use Drupal\baas_project\ProjectResolverInterface;
use Drupal\baas_project\ProjectMemberManagerInterface;
// use Drupal\baas_auth\TenantResolverInterface;

/**
 * 项目访问检查器。
 *
 * 验证用户对项目的访问权限。
 */
class ProjectAccessChecker implements AccessInterface {

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly ProjectResolverInterface $projectResolver,
    protected readonly ProjectMemberManagerInterface $memberManager,
    // protected readonly TenantResolverInterface $tenantResolver,
  ) {}

  /**
   * 检查项目访问权限。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   * @param string|null $project_id
   *   项目ID。
   * @param string $operation
   *   操作类型。
   * @param \Symfony\Component\Routing\Route|null $route
   *   路由对象。
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果。
   */
  public function access(AccountInterface $account, ?string $project_id = null, string $operation = 'view', ?Route $route = null): AccessResultInterface {
    // 调试日志：记录访问检查开始
    \Drupal::logger('baas_project')->info('ProjectAccessChecker::access 开始 - user_id: @user_id, project_id: @project_id, operation: @operation', [
      '@user_id' => $account->id(),
      '@project_id' => $project_id ?: 'NULL',
      '@operation' => $operation,
    ]);

    // 如果没有提供项目ID，尝试从当前上下文解析
    if (!$project_id) {
      $project_id = $this->projectResolver->resolveCurrentProject();
      \Drupal::logger('baas_project')->info('从上下文解析项目ID: @project_id', ['@project_id' => $project_id ?: 'NULL']);
    }

    // 如果仍然没有项目ID，检查是否必需
    if (!$project_id) {
      $project_required = $route?->getOption('_project_required') ?? true;
      if ($project_required) {
        \Drupal::logger('baas_project')->warning('项目上下文是必需的但不可用');
        return AccessResult::forbidden('Project context is required but not available')
          ->addCacheContexts(['user', 'route']);
      }
      // 如果项目不是必需的，允许访问
      \Drupal::logger('baas_project')->info('项目上下文不是必需的，允许访问');
      return AccessResult::allowed()
        ->addCacheContexts(['user', 'route']);
    }

    // 检查用户是否已登录
    if (!$account->isAuthenticated()) {
      \Drupal::logger('baas_project')->warning('用户未认证');
      return AccessResult::forbidden('User must be authenticated')
        ->addCacheContexts(['user']);
    }

    try {
      // 检查项目是否存在
      if (!$this->projectExists($project_id)) {
        \Drupal::logger('baas_project')->warning('项目不存在: @project_id', ['@project_id' => $project_id]);
        return AccessResult::forbidden('Project not found')
          ->addCacheContexts(['user', 'route'])
          ->addCacheTags(["baas_project:{$project_id}"]);
      }

      // 检查用户是否为项目成员
      $is_member = $this->memberManager->isMember($project_id, $account->id());
      \Drupal::logger('baas_project')->info('成员检查结果: @is_member', ['@is_member' => $is_member ? 'TRUE' : 'FALSE']);
      
      if (!$is_member) {
        // 检查是否为租户管理员（可以访问租户下的所有项目）
        $is_tenant_admin = $this->isTenantAdmin($account, $project_id);
        \Drupal::logger('baas_project')->info('租户管理员检查结果: @is_tenant_admin', ['@is_tenant_admin' => $is_tenant_admin ? 'TRUE' : 'FALSE']);
        
        if (!$is_tenant_admin) {
          \Drupal::logger('baas_project')->warning('用户不是项目成员也不是租户管理员');
          return AccessResult::forbidden('User is not a project member')
            ->addCacheContexts(['user', 'route'])
            ->addCacheTags(["baas_project:{$project_id}", "baas_project_member:{$project_id}:{$account->id()}"]);
        }
      }

      // 检查操作权限
      $has_permission = $this->hasOperationPermission($account, $project_id, $operation);
      \Drupal::logger('baas_project')->info('操作权限检查结果: @has_permission, operation: @operation', [
        '@has_permission' => $has_permission ? 'TRUE' : 'FALSE',
        '@operation' => $operation,
      ]);
      
      if (!$has_permission) {
        \Drupal::logger('baas_project')->warning('用户没有操作权限: @operation', ['@operation' => $operation]);
        return AccessResult::forbidden("User does not have permission to {$operation} this project")
          ->addCacheContexts(['user', 'route'])
          ->addCacheTags(["baas_project:{$project_id}", "baas_project_member:{$project_id}:{$account->id()}"]);
      }

      // 访问允许
      \Drupal::logger('baas_project')->info('访问检查通过，允许访问');
      return AccessResult::allowed()
        ->addCacheContexts(['user', 'route'])
        ->addCacheTags(["baas_project:{$project_id}", "baas_project_member:{$project_id}:{$account->id()}"]);
    }
    catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('Error checking project access: @error', [
        '@error' => $e->getMessage(),
      ]);
      
      return AccessResult::forbidden('Error checking project access')
        ->addCacheContexts(['user', 'route']);
    }
  }

  /**
   * 检查项目查看权限。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   * @param string|null $project_id
   *   项目ID。
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果。
   */
  public static function accessView(AccountInterface $account, ?string $project_id = null): AccessResultInterface {
    $checker = \Drupal::service('baas_project.access_checker');
    return $checker->access($account, $project_id, 'view');
  }

  /**
   * 检查项目编辑权限。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   * @param string|null $project_id
   *   项目ID。
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果。
   */
  public static function accessEdit(AccountInterface $account, ?string $project_id = null): AccessResultInterface {
    \Drupal::logger('baas_project')->info('ProjectAccessChecker::accessEdit 被调用 - user_id: @user_id, project_id: @project_id', [
      '@user_id' => $account->id(),
      '@project_id' => $project_id ?: 'NULL',
    ]);
    
    $checker = \Drupal::service('baas_project.access_checker');
    return $checker->access($account, $project_id, 'edit');
  }

  /**
   * 检查项目删除权限。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   * @param string|null $project_id
   *   项目ID。
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果。
   */
  public static function accessDelete(AccountInterface $account, ?string $project_id = null): AccessResultInterface {
    $checker = \Drupal::service('baas_project.access_checker');
    return $checker->access($account, $project_id, 'delete');
  }

  /**
   * 检查项目成员管理权限。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   * @param string|null $project_id
   *   项目ID。
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果。
   */
  public static function accessManageMembers(AccountInterface $account, ?string $project_id = null): AccessResultInterface {
    $checker = \Drupal::service('baas_project.access_checker');
    return $checker->access($account, $project_id, 'manage_members');
  }

  /**
   * 检查项目设置管理权限。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   * @param string|null $project_id
   *   项目ID。
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果。
   */
  public function accessManageSettings(AccountInterface $account, ?string $project_id = null): AccessResultInterface {
    return $this->access($account, $project_id, 'manage_settings');
  }

  /**
   * 检查项目所有权转移权限。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   * @param string|null $project_id
   *   项目ID。
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果。
   */
  public static function accessTransferOwnership(AccountInterface $account, ?string $project_id = null): AccessResultInterface {
    $checker = \Drupal::service('baas_project.access_checker');
    return $checker->access($account, $project_id, 'transfer_ownership');
  }

  /**
   * 检查项目创建权限。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   * @param string|null $tenant_id
   *   租户ID。
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果。
   */
  public static function accessCreateProject(AccountInterface $account, ?string $tenant_id = null): AccessResultInterface {
    $checker = \Drupal::service('baas_project.access_checker');
    return $checker->doAccessCreateProject($account, $tenant_id);
  }

  public function doAccessCreateProject(AccountInterface $account, ?string $tenant_id = null): AccessResultInterface {
    // 检查用户是否已登录
    if (!$account->isAuthenticated()) {
      return AccessResult::forbidden('User must be authenticated')
        ->addCacheContexts(['user']);
    }

    try {
      // 如果没有提供租户ID，尝试从当前上下文解析
      if (!$tenant_id) {
        // $tenant_id = $this->tenantResolver->getCurrentTenantId();
        $tenant_id = null; // TODO: Implement tenant resolution
      }

      if (!$tenant_id) {
        return AccessResult::forbidden('Tenant context is required to create projects')
          ->addCacheContexts(['user', 'route']);
      }

      // 检查用户是否为租户成员
      if (!$this->isTenantMember($account, $tenant_id)) {
        return AccessResult::forbidden('User is not a member of this tenant')
          ->addCacheContexts(['user', 'route'])
          ->addCacheTags(["baas_tenant:{$tenant_id}"]);
      }

      // 检查租户级别的项目创建权限
      if (!$this->hasTenantPermission($account, $tenant_id, 'create_projects')) {
        return AccessResult::forbidden('User does not have permission to create projects in this tenant')
          ->addCacheContexts(['user', 'route'])
          ->addCacheTags(["baas_tenant:{$tenant_id}", "baas_tenant_member:{$tenant_id}:{$account->id()}"]);
      }

      return AccessResult::allowed()
        ->addCacheContexts(['user', 'route'])
        ->addCacheTags(["baas_tenant:{$tenant_id}", "baas_tenant_member:{$tenant_id}:{$account->id()}"]);
    }
    catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('Error checking project creation access: @error', [
        '@error' => $e->getMessage(),
      ]);
      
      return AccessResult::forbidden('Error checking project creation access')
        ->addCacheContexts(['user', 'route']);
    }
  }

  /**
   * 检查项目是否存在。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return bool
   *   项目是否存在。
   */
  protected function projectExists(string $project_id): bool {
    try {
      $database = \Drupal::database();
      return (bool) $database->select('baas_project_config', 'p')
        ->condition('project_id', $project_id)
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    catch (\Exception $e) {
      return false;
    }
  }

  /**
   * 检查用户是否有特定操作权限。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   * @param string $project_id
   *   项目ID。
   * @param string $operation
   *   操作类型。
   *
   * @return bool
   *   是否有权限。
   */
  protected function hasOperationPermission(AccountInterface $account, string $project_id, string $operation): bool {
    try {
      // 获取用户在项目中的角色
      $role = $this->memberManager->getMemberRole($project_id, $account->id());
      \Drupal::logger('baas_project')->info('hasOperationPermission: 用户角色 - user_id: @user_id, project_id: @project_id, role: @role', [
        '@user_id' => $account->id(),
        '@project_id' => $project_id,
        '@role' => $role ?: 'NULL',
      ]);
      
      if (!$role) {
        // 检查是否为租户管理员
        $is_tenant_admin = $this->isTenantAdmin($account, $project_id);
        \Drupal::logger('baas_project')->info('hasOperationPermission: 无角色但检查租户管理员 - is_tenant_admin: @is_tenant_admin', [
          '@is_tenant_admin' => $is_tenant_admin ? 'TRUE' : 'FALSE',
        ]);
        
        if ($is_tenant_admin) {
          // 租户管理员拥有所有权限
          return true;
        }
        return false;
      }

      // 检查角色是否有该操作权限
      $has_role_permission = $this->memberManager->hasRolePermission($role, $operation);
      \Drupal::logger('baas_project')->info('hasOperationPermission: 角色权限检查 - role: @role, operation: @operation, has_permission: @has_permission', [
        '@role' => $role,
        '@operation' => $operation,
        '@has_permission' => $has_role_permission ? 'TRUE' : 'FALSE',
      ]);
      
      return $has_role_permission;
    }
    catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('hasOperationPermission 异常: @error', [
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * 检查用户是否为租户管理员。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   * @param string $project_id
   *   项目ID。
   *
   * @return bool
   *   是否为租户管理员。
   */
  protected function isTenantAdmin(AccountInterface $account, string $project_id): bool {
    try {
      // 获取项目所属的租户ID
      $tenant_id = $this->projectResolver->getProjectTenantId($project_id);
      if (!$tenant_id) {
        return false;
      }

      return $this->hasTenantPermission($account, $tenant_id, 'admin');
    }
    catch (\Exception $e) {
      return false;
    }
  }

  /**
   * 检查用户是否为租户成员。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return bool
   *   是否为租户成员。
   */
  protected function isTenantMember(AccountInterface $account, string $tenant_id): bool {
    try {
      $database = \Drupal::database();
      return (bool) $database->select('baas_user_tenant_mapping', 'm')
        ->condition('user_id', (int) $account->id())
        ->condition('tenant_id', $tenant_id)
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    catch (\Exception $e) {
      return false;
    }
  }

  /**
   * 检查用户是否有租户级别的权限。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   * @param string $tenant_id
   *   租户ID。
   * @param string $permission
   *   权限名称。
   *
   * @return bool
   *   是否有权限。
   */
  protected function hasTenantPermission(AccountInterface $account, string $tenant_id, string $permission): bool {
    try {
      $database = \Drupal::database();
      
      // 获取用户在租户中的角色
      $user_roles = $database->select('baas_user_tenant_mapping', 'm')
        ->fields('m', ['role'])
        ->condition('user_id', (int) $account->id())
        ->condition('tenant_id', $tenant_id)
        ->condition('status', 1)
        ->execute()
        ->fetchCol();

      if (empty($user_roles)) {
        return false;
      }

      // 检查角色权限
      foreach ($user_roles as $role) {
        $has_permission = $database->select('baas_auth_permissions', 'p')
          ->condition('role_name', $role)
          ->condition('resource', 'project')
          ->condition('operation', $permission)
          ->condition('tenant_id', $tenant_id)
          ->countQuery()
          ->execute()
          ->fetchField();

        if ($has_permission) {
          return true;
        }

        // 检查通用权限（不限租户）
        $has_general_permission = $database->select('baas_auth_permissions', 'p')
          ->condition('role_name', $role)
          ->condition('resource', 'project')
          ->condition('operation', $permission)
          ->isNull('tenant_id')
          ->countQuery()
          ->execute()
          ->fetchField();

        if ($has_general_permission) {
          return true;
        }
      }

      return false;
    }
    catch (\Exception $e) {
      return false;
    }
  }

}