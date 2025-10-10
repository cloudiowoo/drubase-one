<?php

namespace Drupal\baas_auth\Service;

/**
 * 权限检查服务接口。
 */
interface PermissionCheckerInterface
{

  /**
   * 检查用户是否有指定权限。
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
  public function hasPermission(int $user_id, string $tenant_id, string $permission): bool;

  /**
   * 检查用户是否有指定资源的操作权限。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $tenant_id
   *   租户ID。
   * @param string $resource
   *   资源名称。
   * @param string $operation
   *   操作类型。
   *
   * @return bool
   *   有权限返回TRUE，无权限返回FALSE。
   */
  public function hasResourcePermission(int $user_id, string $tenant_id, string $resource, string $operation): bool;

  /**
   * 获取用户的所有权限。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return array
   *   权限数组。
   */
  public function getUserPermissions(int $user_id, string $tenant_id): array;

  /**
   * 检查API密钥是否有指定权限。
   *
   * @param string $api_key
   *   API密钥。
   * @param string $permission
   *   权限名称。
   *
   * @return bool
   *   有权限返回TRUE，无权限返回FALSE。
   */
  public function apiKeyHasPermission(string $api_key, string $permission): bool;

  /**
   * 获取用户的所有角色。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return array
   *   角色数组。
   */
  public function getUserRoles(int $user_id, string $tenant_id): array;
}
