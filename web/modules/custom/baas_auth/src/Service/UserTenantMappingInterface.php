<?php

namespace Drupal\baas_auth\Service;

/**
 * Interface for user-tenant mapping service.
 */
interface UserTenantMappingInterface
{

  /**
   * 将用户添加到租户.
   *
   * @param int $user_id
   *   Drupal用户ID.
   * @param string $tenant_id
   *   租户ID.
   * @param string $role
   *   用户在租户中的角色.
   * @param bool $is_owner
   *   是否为租户所有者.
   *
   * @return int
   *   映射记录ID.
   *
   * @throws \Drupal\baas_auth\Exception\UserTenantMappingException
   */
  public function addUserToTenant($user_id, $tenant_id, $role = 'tenant_user', $is_owner = FALSE);

  /**
   * 从租户中移除用户.
   *
   * @param int $user_id
   *   Drupal用户ID.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return bool
   *   操作是否成功.
   */
  public function removeUserFromTenant($user_id, $tenant_id);

  /**
   * 更新用户在租户中的角色.
   *
   * @param int $user_id
   *   Drupal用户ID.
   * @param string $tenant_id
   *   租户ID.
   * @param string $role
   *   新角色.
   *
   * @return bool
   *   操作是否成功.
   */
  public function updateUserRole($user_id, $tenant_id, $role);

  /**
   * 获取用户在指定租户中的角色.
   *
   * @param int $user_id
   *   Drupal用户ID.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return string|null
   *   用户角色，如果用户不属于该租户则返回NULL.
   */
  public function getUserRole($user_id, $tenant_id);

  /**
   * 获取用户所属的所有租户.
   *
   * @param int $user_id
   *   Drupal用户ID.
   * @param bool $active_only
   *   是否只返回活跃的映射.
   *
   * @return array
   *   租户列表，每个元素包含租户信息和用户角色.
   */
  public function getUserTenants($user_id, $active_only = TRUE);

  /**
   * 获取租户的所有用户.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param bool $active_only
   *   是否只返回活跃的映射.
   *
   * @return array
   *   用户列表，每个元素包含用户信息和角色.
   */
  public function getTenantUsers($tenant_id, $active_only = TRUE);

  /**
   * 检查用户是否属于指定租户.
   *
   * @param int $user_id
   *   Drupal用户ID.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return bool
   *   用户是否属于该租户.
   */
  public function isUserInTenant($user_id, $tenant_id);

  /**
   * 检查用户是否为租户所有者.
   *
   * @param int $user_id
   *   Drupal用户ID.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return bool
   *   用户是否为租户所有者.
   */
  public function isUserTenantOwner($user_id, $tenant_id);

  /**
   * 获取租户的所有者.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array|null
   *   租户所有者信息，如果没有所有者则返回NULL.
   */
  public function getTenantOwner($tenant_id);

  /**
   * 转移租户所有权.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param int $new_owner_user_id
   *   新所有者的用户ID.
   *
   * @return bool
   *   操作是否成功.
   */
  public function transferTenantOwnership($tenant_id, $new_owner_user_id);

  /**
   * 启用或禁用用户-租户映射.
   *
   * @param int $user_id
   *   Drupal用户ID.
   * @param string $tenant_id
   *   租户ID.
   * @param bool $status
   *   状态：TRUE为启用，FALSE为禁用.
   *
   * @return bool
   *   操作是否成功.
   */
  public function setMappingStatus($user_id, $tenant_id, $status);
}
