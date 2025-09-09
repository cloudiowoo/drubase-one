<?php

declare(strict_types=1);

namespace Drupal\baas_project;

/**
 * 项目管理服务接口。
 *
 * 提供项目的创建、更新、删除和查询功能。
 */
interface ProjectManagerInterface {

  /**
   * 创建新项目。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param array $project_data
   *   项目数据数组，包含name, machine_name, description等。
   *
   * @return string|false
   *   成功时返回项目ID，失败时返回FALSE。
   */
  public function createProject(string $tenant_id, array $project_data): string|false;

  /**
   * 获取项目信息。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array|false
   *   项目数据数组，不存在时返回FALSE。
   */
  public function getProject(string $project_id): array|false;

  /**
   * 更新项目信息。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $data
   *   要更新的数据。
   *
   * @return bool
   *   更新是否成功。
   */
  public function updateProject(string $project_id, array $data): bool;

  /**
   * 删除项目。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return bool
   *   删除是否成功。
   */
  public function deleteProject(string $project_id): bool;

  /**
   * 获取租户的项目列表。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param array $filters
   *   过滤条件。
   *
   * @return array
   *   项目列表数组。
   */
  public function listTenantProjects(string $tenant_id, array $filters = []): array;

  /**
   * 添加项目成员。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $user_id
   *   用户ID。
   * @param string $role
   *   用户角色。
   *
   * @return bool
   *   添加是否成功。
   */
  public function addProjectMember(string $project_id, int $user_id, string $role): bool;

  /**
   * 通过机器名获取项目。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $machine_name
   *   项目机器名。
   *
   * @return array|false
   *   项目数据数组，不存在时返回FALSE。
   */
  public function getProjectByMachineName(string $tenant_id, string $machine_name): array|false;

  /**
   * 移除项目成员。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $user_id
   *   用户ID。
   *
   * @return bool
   *   移除是否成功。
   */
  public function removeProjectMember(string $project_id, int $user_id): bool;

  /**
   * 更新成员角色。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $user_id
   *   用户ID。
   * @param string $role
   *   新角色。
   *
   * @return bool
   *   更新是否成功。
   */
  public function updateMemberRole(string $project_id, int $user_id, string $role): bool;

  /**
   * 获取项目成员列表。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   成员列表数组。
   */
  public function getProjectMembers(string $project_id): array;

  /**
   * 转移项目所有权。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $new_owner_uid
   *   新所有者用户ID。
   *
   * @return bool
   *   转移是否成功。
   */
  public function transferOwnership(string $project_id, int $new_owner_uid): bool;

  /**
   * 检查项目是否存在。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return bool
   *   项目是否存在。
   */
  public function projectExists(string $project_id): bool;

  /**
   * 验证项目机器名是否可用。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $machine_name
   *   机器名。
   * @param string|null $exclude_project_id
   *   排除的项目ID（用于更新时检查）。
   *
   * @return bool
   *   机器名是否可用。
   */
  public function isMachineNameAvailable(string $tenant_id, string $machine_name, ?string $exclude_project_id = null): bool;

  /**
   * 生成项目ID。
   *
   * @param string $tenant_id
   *   租户ID。
   *
   * @return string
   *   生成的项目ID。
   */
  public function generateProjectId(string $tenant_id): string;

  /**
   * 获取项目统计信息。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   统计信息数组。
   */
  public function getProjectStats(string $project_id): array;

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
  public function isProjectMember(string $project_id, int $user_id): bool;

  /**
   * 获取用户在项目中的角色。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $user_id
   *   用户ID。
   *
   * @return string|false
   *   用户角色，不是成员时返回FALSE。
   */
  public function getUserProjectRole(string $project_id, int $user_id): string|false;

}