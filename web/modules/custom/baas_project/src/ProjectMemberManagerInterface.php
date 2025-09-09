<?php

declare(strict_types=1);

namespace Drupal\baas_project;

/**
 * 项目成员管理器接口。
 *
 * 定义项目成员管理的核心方法。
 */
interface ProjectMemberManagerInterface {

  /**
   * 添加项目成员。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $user_id
   *   用户ID。
   * @param string $role
   *   成员角色（owner, admin, editor, viewer）。
   * @param array $options
   *   可选参数。
   *
   * @return bool
   *   是否添加成功。
   *
   * @throws \Drupal\baas_project\Exception\ProjectException
   *   当添加失败时抛出异常。
   */
  public function addMember(string $project_id, int $user_id, string $role, array $options = []): bool;

  /**
   * 移除项目成员。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $user_id
   *   用户ID。
   * @param array $options
   *   可选参数。
   *
   * @return bool
   *   是否移除成功。
   *
   * @throws \Drupal\baas_project\Exception\ProjectException
   *   当移除失败时抛出异常。
   */
  public function removeMember(string $project_id, int $user_id, array $options = []): bool;

  /**
   * 更新成员角色。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $user_id
   *   用户ID。
   * @param string $new_role
   *   新角色。
   * @param array $options
   *   可选参数。
   *
   * @return bool
   *   是否更新成功。
   *
   * @throws \Drupal\baas_project\Exception\ProjectException
   *   当更新失败时抛出异常。
   */
  public function updateMemberRole(string $project_id, int $user_id, string $new_role, array $options = []): bool;

  /**
   * 获取项目成员列表。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $filters
   *   过滤条件。
   * @param array $options
   *   查询选项（排序、分页等）。
   *
   * @return array
   *   成员列表。
   */
  public function getMembers(string $project_id, array $filters = [], array $options = []): array;

  /**
   * 获取成员详细信息。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $user_id
   *   用户ID。
   *
   * @return array|null
   *   成员信息或NULL。
   */
  public function getMember(string $project_id, int $user_id): ?array;

  /**
   * 检查用户是否为项目成员。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $user_id
   *   用户ID。
   *
   * @return bool
   *   是否为项目成员。
   */
  public function isMember(string $project_id, string|int $user_id): bool;

  /**
   * 获取用户在项目中的角色。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $user_id
   *   用户ID。
   *
   * @return string|null
   *   用户角色或NULL。
   */
  public function getMemberRole(string $project_id, string|int $user_id): ?string;

  /**
   * 转移项目所有权。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $current_owner_id
   *   当前所有者ID。
   * @param int $new_owner_id
   *   新所有者ID。
   * @param array $options
   *   可选参数。
   *
   * @return bool
   *   是否转移成功。
   *
   * @throws \Drupal\baas_project\Exception\ProjectException
   *   当转移失败时抛出异常。
   */
  public function transferOwnership(string $project_id, int $current_owner_id, int $new_owner_id, array $options = []): bool;

  /**
   * 批量添加成员。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $members
   *   成员数据数组，每个元素包含user_id和role。
   * @param array $options
   *   可选参数。
   *
   * @return array
   *   批量操作结果。
   */
  public function addMembers(string $project_id, array $members, array $options = []): array;

  /**
   * 批量移除成员。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $user_ids
   *   用户ID数组。
   * @param array $options
   *   可选参数。
   *
   * @return array
   *   批量操作结果。
   */
  public function removeMembers(string $project_id, array $user_ids, array $options = []): array;

  /**
   * 获取用户参与的项目列表。
   *
   * @param int $user_id
   *   用户ID。
   * @param array $filters
   *   过滤条件。
   * @param array $options
   *   查询选项。
   *
   * @return array
   *   项目列表。
   */
  public function getUserProjects(int $user_id, array $filters = [], array $options = []): array;

  /**
   * 获取项目成员统计信息。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   统计信息。
   */
  public function getMemberStats(string $project_id): array;

  /**
   * 验证角色是否有效。
   *
   * @param string $role
   *   角色名称。
   *
   * @return bool
   *   角色是否有效。
   */
  public function isValidRole(string $role): bool;

  /**
   * 获取所有可用角色。
   *
   * @return array
   *   角色列表。
   */
  public function getAvailableRoles(): array;

  /**
   * 检查角色权限。
   *
   * @param string $role
   *   角色名称。
   * @param string $permission
   *   权限名称。
   *
   * @return bool
   *   是否有权限。
   */
  public function hasRolePermission(string $role, string $permission): bool;

  /**
   * 获取角色权限列表。
   *
   * @param string $role
   *   角色名称。
   *
   * @return array
   *   权限列表。
   */
  public function getRolePermissions(string $role): array;

  /**
   * 邀请用户加入项目。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $email
   *   邀请邮箱。
   * @param string $role
   *   邀请角色。
   * @param int $inviter_id
   *   邀请者ID。
   * @param array $options
   *   可选参数。
   *
   * @return array
   *   邀请结果。
   *
   * @throws \Drupal\baas_project\Exception\ProjectException
   *   当邀请失败时抛出异常。
   */
  public function inviteUser(string $project_id, string $email, string $role, int $inviter_id, array $options = []): array;

  /**
   * 接受项目邀请。
   *
   * @param string $invitation_token
   *   邀请令牌。
   * @param int $user_id
   *   用户ID。
   * @param array $options
   *   可选参数。
   *
   * @return bool
   *   是否接受成功。
   *
   * @throws \Drupal\baas_project\Exception\ProjectException
   *   当接受失败时抛出异常。
   */
  public function acceptInvitation(string $invitation_token, int $user_id, array $options = []): bool;

  /**
   * 拒绝项目邀请。
   *
   * @param string $invitation_token
   *   邀请令牌。
   * @param array $options
   *   可选参数。
   *
   * @return bool
   *   是否拒绝成功。
   */
  public function rejectInvitation(string $invitation_token, array $options = []): bool;

  /**
   * 获取项目邀请列表。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $filters
   *   过滤条件。
   * @param array $options
   *   查询选项。
   *
   * @return array
   *   邀请列表。
   */
  public function getInvitations(string $project_id, array $filters = [], array $options = []): array;

  /**
   * 撤销项目邀请。
   *
   * @param string $invitation_token
   *   邀请令牌。
   * @param array $options
   *   可选参数。
   *
   * @return bool
   *   是否撤销成功。
   */
  public function revokeInvitation(string $invitation_token, array $options = []): bool;

}