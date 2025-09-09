<?php

declare(strict_types=1);

namespace Drupal\baas_auth\Service;

/**
 * 统一权限检查服务接口。
 *
 * 提供租户级和项目级的统一权限检查功能，解决权限检查逻辑分散的问题。
 */
interface UnifiedPermissionCheckerInterface
{

  /**
   * 检查租户级权限。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $tenant_id
   *   租户ID。
   * @param string $permission
   *   权限名称。
   *
   * @return bool
   *   有权限返回TRUE，无权限返回FALSE。
   */
  public function checkTenantPermission(int $user_id, string $tenant_id, string $permission): bool;

  /**
   * 检查项目级权限。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $permission
   *   权限名称。
   *
   * @return bool
   *   有权限返回TRUE，无权限返回FALSE。
   */
  public function checkProjectPermission(int $user_id, string $project_id, string $permission): bool;

  /**
   * 获取用户在租户中的角色。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return string|false
   *   角色名称，如果没有角色返回FALSE。
   */
  public function getUserTenantRole(int $user_id, string $tenant_id): string|false;

  /**
   * 获取用户在项目中的角色。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return string|false
   *   角色名称，如果没有角色返回FALSE。
   */
  public function getUserProjectRole(int $user_id, string $project_id): string|false;

  /**
   * 检查用户是否可以访问租户。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return bool
   *   可以访问返回TRUE，否则返回FALSE。
   */
  public function canAccessTenant(int $user_id, string $tenant_id): bool;

  /**
   * 检查用户是否可以访问项目。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return bool
   *   可以访问返回TRUE，否则返回FALSE。
   */
  public function canAccessProject(int $user_id, string $project_id): bool;

  /**
   * 检查项目级实体权限。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   * @param string $operation
   *   操作类型（create, read, update, delete）。
   *
   * @return bool
   *   有权限返回TRUE，无权限返回FALSE。
   */
  public function checkProjectEntityPermission(int $user_id, string $project_id, string $entity_name, string $operation): bool;

  /**
   * 获取用户在项目中的所有权限。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   权限数组。
   */
  public function getProjectPermissions(int $user_id, string $project_id): array;

  /**
   * 验证JWT中的项目上下文。
   *
   * @param string $jwt_token
   *   JWT令牌。
   * @param string $required_project_id
   *   要求的项目ID。
   *
   * @return bool
   *   验证通过返回TRUE，否则返回FALSE。
   */
  public function validateProjectContext(string $jwt_token, string $required_project_id): bool;

  /**
   * 切换项目上下文。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return array|false
   *   新的JWT payload数组，失败返回FALSE。
   */
  public function switchProjectContext(int $user_id, string $project_id): array|false;

  /**
   * 获取有效权限（租户+项目权限合并）。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $tenant_id
   *   租户ID。
   * @param string|null $project_id
   *   项目ID（可选）。
   *
   * @return array
   *   有效权限数组。
   */
  public function getEffectivePermissions(int $user_id, string $tenant_id, string $project_id = null): array;

  /**
   * 解析权限层级（租户权限与项目权限的继承和覆盖）。
   *
   * @param array $tenant_permissions
   *   租户权限数组。
   * @param array $project_permissions
   *   项目权限数组。
   *
   * @return array
   *   解析后的权限数组。
   */
  public function resolvePermissionHierarchy(array $tenant_permissions, array $project_permissions): array;

  /**
   * 检查级联权限（从租户到项目的权限检查）。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $permission
   *   权限名称。
   *
   * @return bool
   *   有权限返回TRUE，无权限返回FALSE。
   */
  public function checkCascadingPermission(int $user_id, string $tenant_id, string $project_id, string $permission): bool;

  /**
   * 清除用户权限缓存。
   *
   * @param int $user_id
   *   用户ID。
   * @param string|null $tenant_id
   *   租户ID（可选）。
   * @param string|null $project_id
   *   项目ID（可选）。
   */
  public function clearUserPermissionCache(int $user_id, string $tenant_id = null, string $project_id = null): void;

}