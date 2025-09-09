<?php

declare(strict_types=1);

namespace Drupal\baas_project;

/**
 * 项目解析器服务接口。
 *
 * 提供项目上下文解析、切换和访问控制功能。
 */
interface ProjectResolverInterface {

  /**
   * 解析当前项目ID。
   *
   * @return string|null
   *   项目ID或null。
   */
  public function resolveCurrentProject(): string|null;

  /**
   * 设置当前项目上下文。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return bool
   *   设置是否成功。
   */
  public function setCurrentProject(string $project_id): bool;

  /**
   * 清除当前项目上下文。
   *
   * @return void
   */
  public function clearCurrentProject(): void;

  /**
   * 获取用户可访问的项目列表。
   *
   * @param int $user_id
   *   用户ID。
   *
   * @return array
   *   项目列表数组。
   */
  public function getUserProjects(int $user_id): array;

  /**
   * 检查项目访问权限。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $user_id
   *   用户ID。
   * @param string $operation
   *   操作类型。
   *
   * @return bool
   *   是否有访问权限。
   */
  public function checkProjectAccess(string $project_id, int $user_id, string $operation): bool;

  /**
   * 从请求中解析项目ID。
   *
   * @return string|false
   *   项目ID，未找到时返回FALSE。
   */
  public function resolveProjectFromRequest(): string|false;

  /**
   * 从JWT令牌中解析项目ID。
   *
   * @param string $jwt_token
   *   JWT令牌。
   *
   * @return string|false
   *   项目ID，未找到时返回FALSE。
   */
  public function resolveProjectFromJwt(string $jwt_token): string|false;

  /**
   * 从路由参数中解析项目ID。
   *
   * @return string|false
   *   项目ID，未找到时返回FALSE。
   */
  public function resolveProjectFromRoute(): string|false;

  /**
   * 验证项目上下文。
   *
   * @return bool
   *   是否有效。
   */
  public function validateProjectContext(): bool;

  /**
   * 切换项目上下文。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return bool
   *   切换结果。
   */
  public function switchProjectContext(string $project_id): bool;

  /**
   * 获取项目上下文信息。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array|false
   *   项目上下文数据，不存在时返回FALSE。
   */
  public function getProjectContext(string $project_id): array|false;

  /**
   * 获取用户项目权限。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   权限列表。
   */
  public function getUserProjectPermissions(string $project_id): array;

  /**
   * 检查用户是否可以切换到指定项目。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return bool
   *   是否可以切换。
   */
  public function canSwitchToProject(int $user_id, string $project_id): bool;

  /**
   * 获取项目租户ID。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return string|null
   *   租户ID或null。
   */
  public function getProjectTenantId(string $project_id): string|null;

  /**
   * 解析租户的默认项目。
   *
   * @param string $tenant_id
   *   租户ID。
   *
   * @return string|false
   *   默认项目ID，不存在时返回FALSE。
   */
  public function resolveTenantDefaultProject(string $tenant_id): string|false;

  /**
   * 检查项目是否为租户的默认项目。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return bool
   *   是否为默认项目。
   */
  public function isDefaultProject(string $project_id): bool;

  /**
   * 获取项目访问历史。
   *
   * @return array
   *   访问历史。
   */
  public function getProjectAccessHistory(): array;

  /**
   * 记录项目访问历史。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return void
   */
  public function recordProjectAccess(string $project_id): void;

  /**
   * 获取用户最近访问的项目。
   *
   * @param int $user_id
   *   用户ID。
   * @param int $limit
   *   返回数量限制。
   *
   * @return array
   *   最近访问的项目列表。
   */
  public function getRecentProjects(int $user_id, int $limit = 5): array;

  /**
   * 检查项目是否处于活跃状态。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return bool
   *   如果项目活跃返回TRUE，否则返回FALSE。
   */
  public function isProjectActive(string $project_id): bool;

  /**
   * 解析默认项目ID。
   *
   * @param string|null $tenant_id
   *   租户ID，如果为空则使用当前租户。
   *
   * @return string|null
   *   默认项目ID，如果不存在返回NULL。
   */
  public function resolveDefaultProject(?string $tenant_id = null): ?string;

}