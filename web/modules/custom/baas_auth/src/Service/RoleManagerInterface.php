<?php

namespace Drupal\baas_auth\Service;

/**
 * 角色管理服务接口。
 */
interface RoleManagerInterface
{

  /**
   * 获取默认角色配置。
   *
   * @return array
   *   默认角色数组。
   */
  public function getDefaultRoles(): array;

  /**
   * 分配角色给用户。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $tenant_id
   *   租户ID。
   * @param string $role_name
   *   角色名称。
   * @param int|null $assigned_by
   *   分配者用户ID。
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE。
   */
  public function assignRole(int $user_id, string $tenant_id, string $role_name, int $assigned_by = NULL): bool;

  /**
   * 撤销用户角色。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $tenant_id
   *   租户ID。
   * @param string $role_name
   *   角色名称。
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE。
   */
  public function revokeRole(int $user_id, string $tenant_id, string $role_name): bool;

  /**
   * 获取用户的所有角色。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return array
   *   角色名称数组。
   */
  public function getUserRoles(int $user_id, string $tenant_id): array;

  /**
   * 获取拥有指定角色的用户。
   *
   * @param string $role_name
   *   角色名称。
   * @param string|null $tenant_id
   *   可选的租户ID。
   *
   * @return array
   *   用户数组。
   */
  public function getUsersWithRole(string $role_name, string $tenant_id = NULL): array;

  /**
   * 检查角色是否存在。
   *
   * @param string $role_name
   *   角色名称。
   *
   * @return bool
   *   存在返回TRUE，不存在返回FALSE。
   */
  public function roleExists(string $role_name): bool;

  /**
   * 获取角色的权限。
   *
   * @param string $role_name
   *   角色名称。
   *
   * @return array
   *   权限数组。
   */
  public function getRolePermissions(string $role_name): array;

  /**
   * 获取所有角色。
   *
   * @return array
   *   角色名称数组。
   */
  public function getAllRoles(): array;
}
