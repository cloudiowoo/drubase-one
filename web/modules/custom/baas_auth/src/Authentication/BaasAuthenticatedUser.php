<?php

declare(strict_types=1);

namespace Drupal\baas_auth\Authentication;

use Drupal\Core\Session\AccountInterface;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;

/**
 * BaaS认证用户类。
 */
class BaasAuthenticatedUser implements AccountInterface
{

  /**
   * JWT载荷数据。
   *
   * @var array
   */
  protected array $payload;

  /**
   * 统一权限检查器。
   *
   * @var \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface
   */
  protected readonly UnifiedPermissionCheckerInterface $permissionChecker;

  /**
   * 构造函数。
   *
   * @param array $payload
   *   JWT载荷数据。
   * @param \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface $permission_checker
   *   统一权限检查器。
   */
  public function __construct(array $payload, UnifiedPermissionCheckerInterface $permission_checker)
  {
    $this->payload = $payload;
    $this->permissionChecker = $permission_checker;
  }

  /**
   * {@inheritdoc}
   */
  public function id()
  {
    return $this->payload['sub'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getRoles($exclude_locked_roles = FALSE)
  {
    return $this->payload['roles'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function hasRole($rid)
  {
    $roles = $this->getRoles();
    return in_array($rid, $roles);
  }

  /**
   * {@inheritdoc}
   */
  public function hasPermission($permission)
  {
    $user_id = (int) $this->id();
    $tenant_id = $this->getTenantId();
    
    if (!$user_id || !$tenant_id) {
      return false;
    }

    // 检查是否有项目上下文
    $project_id = $this->payload['project_id'] ?? null;
    
    try {
      if ($project_id) {
        // 检查项目级权限
        return $this->permissionChecker->checkProjectPermission($user_id, $project_id, $permission);
      } else {
        // 检查租户级权限
        return $this->permissionChecker->checkTenantPermission($user_id, $tenant_id, $permission);
      }
    } catch (\Exception $e) {
      // 权限检查失败，记录日志并返回false
      \Drupal::logger('baas_auth')->error('权限检查失败: @error, user_id: @user_id, tenant_id: @tenant_id, project_id: @project_id, permission: @permission', [
        '@error' => $e->getMessage(),
        '@user_id' => $user_id,
        '@tenant_id' => $tenant_id,
        '@project_id' => $project_id ?: 'none',
        '@permission' => $permission,
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isAuthenticated()
  {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function isAnonymous()
  {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getPreferredLangcode($fallback_to_default = TRUE)
  {
    return 'en';
  }

  /**
   * {@inheritdoc}
   */
  public function getPreferredAdminLangcode($fallback_to_default = TRUE)
  {
    return 'en';
  }

  /**
   * {@inheritdoc}
   */
  public function getAccountName()
  {
    return $this->payload['username'] ?? 'baas_user';
  }

  /**
   * {@inheritdoc}
   */
  public function getDisplayName()
  {
    return $this->getAccountName();
  }

  /**
   * {@inheritdoc}
   */
  public function getEmail()
  {
    return $this->payload['email'] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getTimeZone()
  {
    return 'UTC';
  }

  /**
   * {@inheritdoc}
   */
  public function getLastAccessedTime()
  {
    return $this->payload['iat'] ?? time();
  }

  /**
   * 获取租户ID。
   *
   * @return string|null
   *   租户ID。
   */
  public function getTenantId()
  {
    return $this->payload['tenant_id'] ?? NULL;
  }

  /**
   * 获取项目ID。
   *
   * @return string|null
   *   项目ID。
   */
  public function getProjectId(): ?string
  {
    return $this->payload['project_id'] ?? null;
  }

  /**
   * 检查项目级权限。
   *
   * @param string $permission
   *   权限名称。
   * @param string|null $project_id
   *   项目ID，如果不提供则使用当前项目上下文。
   *
   * @return bool
   *   有权限返回true，否则返回false。
   */
  public function hasProjectPermission(string $permission, ?string $project_id = null): bool
  {
    $user_id = (int) $this->id();
    $project_id = $project_id ?? $this->getProjectId();
    
    if (!$user_id || !$project_id) {
      return false;
    }

    try {
      return $this->permissionChecker->checkProjectPermission($user_id, $project_id, $permission);
    } catch (\Exception $e) {
      \Drupal::logger('baas_auth')->error('项目权限检查失败: @error', ['@error' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * 检查租户级权限。
   *
   * @param string $permission
   *   权限名称。
   * @param string|null $tenant_id
   *   租户ID，如果不提供则使用当前租户上下文。
   *
   * @return bool
   *   有权限返回true，否则返回false。
   */
  public function hasTenantPermission(string $permission, ?string $tenant_id = null): bool
  {
    $user_id = (int) $this->id();
    $tenant_id = $tenant_id ?? $this->getTenantId();
    
    if (!$user_id || !$tenant_id) {
      return false;
    }

    try {
      return $this->permissionChecker->checkTenantPermission($user_id, $tenant_id, $permission);
    } catch (\Exception $e) {
      \Drupal::logger('baas_auth')->error('租户权限检查失败: @error', ['@error' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * 检查实体级权限。
   *
   * @param string $entity_name
   *   实体名称。
   * @param string $operation
   *   操作类型（create, read, update, delete）。
   * @param string|null $project_id
   *   项目ID，如果不提供则使用当前项目上下文。
   *
   * @return bool
   *   有权限返回true，否则返回false。
   */
  public function hasEntityPermission(string $entity_name, string $operation, ?string $project_id = null): bool
  {
    $user_id = (int) $this->id();
    $project_id = $project_id ?? $this->getProjectId();
    
    if (!$user_id || !$project_id) {
      return false;
    }

    try {
      return $this->permissionChecker->checkProjectEntityPermission($user_id, $project_id, $entity_name, $operation);
    } catch (\Exception $e) {
      \Drupal::logger('baas_auth')->error('实体权限检查失败: @error', ['@error' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * 获取JWT载荷数据。
   *
   * @return array
   *   载荷数组。
   */
  public function getPayload(): array
  {
    return $this->payload;
  }
}
