<?php

declare(strict_types=1);

namespace Drupal\baas_project;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
// use Drupal\baas_auth\TenantResolverInterface;
// use Drupal\baas_auth\JwtManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Drupal\baas_project\Exception\ProjectException;

/**
 * 项目解析器服务。
 *
 * 负责解析和管理当前项目上下文。
 */
class ProjectResolver implements ProjectResolverInterface {

  protected readonly LoggerChannelInterface $logger;
  protected ?string $currentProjectId = null;
  protected ?array $currentProjectData = null;
  protected array $accessHistory = [];

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly CacheBackendInterface $cache,
    protected readonly AccountProxyInterface $currentUser,
    // protected readonly TenantResolverInterface $tenantResolver,
    // protected readonly JwtManagerInterface $jwtManager,
    protected readonly RequestStack $requestStack,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('baas_project_resolver');
  }

  /**
   * {@inheritdoc}
   */
  public function resolveCurrentProject(): ?string {
    if ($this->currentProjectId !== null) {
      return $this->currentProjectId;
    }

    $request = $this->requestStack->getCurrentRequest();
    if (!$request) {
      return null;
    }

    // 尝试从多个来源解析项目ID
    $project_id = $this->resolveFromRequest($request)
      ?? $this->resolveFromJwt($request)
      ?? $this->resolveFromRoute($request)
      ?? $this->resolveDefaultProject();

    if ($project_id && $this->validateProjectAccess($project_id)) {
      $this->setCurrentProject($project_id);
      return $project_id;
    }

    return null;
  }

  /**
   * {@inheritdoc}
   */
  public function setCurrentProject(string $project_id): bool {
    try {
      // 验证项目存在性和访问权限
      if (!$this->validateProjectAccess($project_id)) {
        throw new ProjectException(
          'Access denied or project not found',
          ProjectException::PROJECT_ACCESS_DENIED,
          null,
          ['project_id' => $project_id]
        );
      }

      // 加载项目数据
      $project_data = $this->loadProjectData($project_id);
      if (!$project_data) {
        throw new ProjectException(
          'Project data not found',
          ProjectException::PROJECT_NOT_FOUND,
          null,
          ['project_id' => $project_id]
        );
      }

      $this->currentProjectId = $project_id;
      $this->currentProjectData = $project_data;

      // 记录访问历史
      $this->recordProjectAccess($project_id);

      $this->logger->info('Project context set: @project_id', [
        '@project_id' => $project_id,
      ]);

      return true;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to set project context @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function clearCurrentProject(): void {
    $this->currentProjectId = null;
    $this->currentProjectData = null;
    
    $this->logger->debug('Project context cleared');
  }

  /**
   * {@inheritdoc}
   */
  public function hasProjectAccess(string $project_id, string $operation = 'view'): bool {
    try {
      // 检查项目是否存在
      if (!$this->projectExists($project_id)) {
        return false;
      }

      // 获取当前用户ID
      $user_id = (int) $this->currentUser->id();
      if (!$user_id) {
        return false;
      }

      // 检查用户是否为项目成员
      $member_role = $this->getUserProjectRole($project_id, $user_id);
      if (!$member_role) {
        return false;
      }

      // 检查操作权限
      return $this->checkOperationPermission($member_role, $operation);
    }
    catch (\Exception $e) {
      $this->logger->error('Error checking project access @project_id for operation @operation: @error', [
        '@project_id' => $project_id,
        '@operation' => $operation,
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUserProjectPermissions(string $project_id): array {
    try {
      $user_id = (int) $this->currentUser->id();
      if (!$user_id) {
        return [];
      }

      $role = $this->getUserProjectRole($project_id, $user_id);
      if (!$role) {
        return [];
      }

      // 根据角色返回权限列表
      return $this->getRolePermissions($role);
    }
    catch (\Exception $e) {
      $this->logger->error('Error getting user project permissions for @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resolveFromRequest(Request $request): ?string {
    // 从请求头解析
    $project_id = $request->headers->get('X-Project-ID')
      ?? $request->headers->get('X-BaaS-Project')
      ?? $request->query->get('project_id')
      ?? $request->request->get('project_id');

    if ($project_id && is_string($project_id)) {
      return trim($project_id);
    }

    return null;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveFromJwt(Request $request): ?string {
    try {
      // $token = $this->jwtManager->extractTokenFromRequest($request);
      // if (!$token) {
      //   return null;
      // }

      // $payload = $this->jwtManager->decodeToken($token);
      // if (!$payload) {
      //   return null;
      // }

      // // 从JWT payload中提取项目ID
      // return $payload['project_id'] ?? $payload['prj'] ?? null;
      return null;
    }
    catch (\Exception $e) {
      $this->logger->debug('Failed to resolve project from JWT: @error', [
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resolveProjectFromJwt(string $jwt_token): string|false {
    try {
      if (empty($jwt_token)) {
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
          $result = $this->resolveFromJwt($request);
          return $result ?: false;
        }
        return false;
      }

      // $payload = $this->jwtManager->decodeToken($jwt_token);
      // if (!$payload) {
      //   return false;
      // }

      // $project_id = $payload['project_id'] ?? $payload['prj'] ?? null;
      // return $project_id ?: false;
      return false;
    }
    catch (\Exception $e) {
      $this->logger->debug('Failed to resolve project from JWT token: @error', [
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resolveFromRoute(Request $request): ?string {
    try {
      // 从路由参数解析
      $route_params = $request->attributes->all();
      
      return $route_params['project_id']
        ?? $route_params['project']
        ?? $route_params['baas_project_id']
        ?? null;
    }
    catch (\Exception $e) {
      $this->logger->debug('Failed to resolve project from route: @error', [
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resolveProjectFromRoute(): string|false {
    try {
      $request = $this->requestStack->getCurrentRequest();
      if (!$request) {
        return false;
      }

      $result = $this->resolveFromRoute($request);
      return $result ?: false;
    }
    catch (\Exception $e) {
      $this->logger->debug('Failed to resolve project from current route: @error', [
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateProjectContext(): bool {
    if (!$this->currentProjectId) {
      return false;
    }

    // 验证项目仍然存在且可访问
    return $this->validateProjectAccess($this->currentProjectId);
  }

  /**
   * {@inheritdoc}
   */
  public function switchProjectContext(string $project_id): bool {
    // 清除当前上下文
    $this->clearCurrentProject();
    
    // 设置新的项目上下文
    return $this->setCurrentProject($project_id);
  }

  /**
   * 获取当前项目上下文信息。
   *
   * @return array
   *   项目上下文信息数组。
   */
  public function getProjectContextInfo(): array {
    if (!$this->currentProjectId || !$this->currentProjectData) {
      return [];
    }

    return [
      'project_id' => $this->currentProjectId,
      'project_name' => $this->currentProjectData['name'],
      'machine_name' => $this->currentProjectData['machine_name'],
      'tenant_id' => $this->currentProjectData['tenant_id'],
      'user_role' => $this->getUserProjectRole($this->currentProjectId, $this->currentUser->id()),
      'permissions' => $this->getUserProjectPermissions($this->currentProjectId),
      'is_default' => $this->currentProjectData['settings']['is_default'] ?? false,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectTenantId(string $project_id): ?string {
    try {
      $cache_key = "baas_project:tenant_id:{$project_id}";
      $cached = $this->cache->get($cache_key);
      
      if ($cached && $cached->valid) {
        return $cached->data;
      }

      $tenant_id = $this->database->select('baas_project_config', 'p')
        ->fields('p', ['tenant_id'])
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchField();

      if ($tenant_id) {
        $this->cache->set($cache_key, $tenant_id, time() + 3600); // 缓存1小时
        return $tenant_id;
      }

      return null;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get project tenant ID for @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resolveDefaultProject(?string $tenant_id = null): ?string {
    try {
      // $tenant_id = $tenant_id ?? $this->tenantResolver->getCurrentTenantId();
      // if (!$tenant_id) {
      //   return null;
      // }

      // $default_project_id = $tenant_id . '_project_default';
      
      // if ($this->projectExists($default_project_id)) {
      //   return $default_project_id;
      // }

      return null;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to resolve default project for tenant @tenant_id: @error', [
        '@tenant_id' => $tenant_id,
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resolveTenantDefaultProject(string $tenant_id): string|false {
    try {
      // if (empty($tenant_id)) {
      //   $tenant_id = $this->tenantResolver->getCurrentTenantId();
      // }
      
      // if (!$tenant_id) {
      //   return false;
      // }

      $result = $this->resolveDefaultProject($tenant_id);
      return $result ?: false;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to resolve tenant default project for @tenant_id: @error', [
        '@tenant_id' => $tenant_id,
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isDefaultProject(string $project_id): bool {
    try {
      $project_data = $this->loadProjectData($project_id);
      return $project_data && ($project_data['settings']['is_default'] ?? false);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to check if project is default @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function recordProjectAccess(string $project_id): void {
    $this->accessHistory[] = [
      'project_id' => $project_id,
      'user_id' => (int) $this->currentUser->id(),
      'timestamp' => time(),
      'ip_address' => $this->requestStack->getCurrentRequest()?->getClientIp(),
    ];

    // 保持历史记录在合理范围内
    if (count($this->accessHistory) > 100) {
      $this->accessHistory = array_slice($this->accessHistory, -50);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectAccessHistory(): array {
    return $this->accessHistory;
  }

  /**
   * 验证项目访问权限。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return bool
   *   是否有访问权限。
   */
  protected function validateProjectAccess(string $project_id): bool {
    try {
      // 检查项目是否存在
      if (!$this->projectExists($project_id)) {
        return false;
      }

      // 检查用户是否有访问权限
      return $this->hasProjectAccess($project_id, 'view');
    }
    catch (\Exception $e) {
      $this->logger->error('Error validating project access @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return false;
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
      $cache_key = "baas_project:exists:{$project_id}";
      $cached = $this->cache->get($cache_key);
      
      if ($cached && $cached->valid) {
        return $cached->data;
      }

      $exists = (bool) $this->database->select('baas_project_config', 'p')
        ->condition('project_id', $project_id)
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      $this->cache->set($cache_key, $exists, time() + 300); // 缓存5分钟
      return $exists;
    }
    catch (\Exception $e) {
      $this->logger->error('Error checking project existence @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * 加载项目数据。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array|null
   *   项目数据或NULL。
   */
  protected function loadProjectData(string $project_id): ?array {
    try {
      $cache_key = "baas_project:data:{$project_id}";
      $cached = $this->cache->get($cache_key);
      
      if ($cached && $cached->valid) {
        return $cached->data;
      }

      $project = $this->database->select('baas_project_config', 'p')
        ->fields('p')
        ->condition('project_id', $project_id)
        ->condition('status', 1)
        ->execute()
        ->fetchAssoc();

      if ($project) {
        $project['settings'] = json_decode($project['settings'] ?? '{}', true);
        $this->cache->set($cache_key, $project, time() + 3600); // 缓存1小时
        return $project;
      }

      return null;
    }
    catch (\Exception $e) {
      $this->logger->error('Error loading project data @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }

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
  protected function getUserProjectRole(string $project_id, int $user_id): ?string {
    try {
      $cache_key = "baas_project:user_role:{$project_id}:{$user_id}";
      $cached = $this->cache->get($cache_key);
      
      if ($cached && $cached->valid) {
        return $cached->data;
      }

      $role = $this->database->select('baas_project_members', 'm')
        ->fields('m', ['role'])
        ->condition('project_id', $project_id)
        ->condition('user_id', $user_id)
        ->condition('status', 1)
        ->execute()
        ->fetchField();

      if ($role) {
        $this->cache->set($cache_key, $role, time() + 1800); // 缓存30分钟
        return $role;
      }

      return null;
    }
    catch (\Exception $e) {
      $this->logger->error('Error getting user project role @project_id for user @user_id: @error', [
        '@project_id' => $project_id,
        '@user_id' => $user_id,
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }

  /**
   * 检查操作权限。
   *
   * @param string $role
   *   用户角色。
   * @param string $operation
   *   操作类型。
   *
   * @return bool
   *   是否有权限。
   */
  protected function checkOperationPermission(string $role, string $operation): bool {
    $permissions = $this->getRolePermissions($role);
    return in_array($operation, $permissions, true);
  }

  /**
   * 获取角色权限列表。
   *
   * @param string $role
   *   角色名称。
   *
   * @return array
   *   权限列表。
   */
  protected function getRolePermissions(string $role): array {
    $role_permissions = [
      'owner' => ['view', 'edit', 'delete', 'manage_members', 'manage_settings', 'transfer_ownership'],
      'admin' => ['view', 'edit', 'manage_members', 'manage_settings'],
      'editor' => ['view', 'edit'],
      'viewer' => ['view'],
    ];

    return $role_permissions[$role] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getUserProjects(int $user_id): array {
    try {
      $cache_key = "baas_project:user_projects:{$user_id}";
      $cached = $this->cache->get($cache_key);
      
      if ($cached && $cached->valid) {
        return $cached->data;
      }

      $projects = $this->database->select('baas_project_members', 'm')
        ->fields('m', ['project_id', 'role'])
        ->condition('user_id', $user_id)
        ->condition('status', 1)
        ->execute()
        ->fetchAllKeyed();

      $this->cache->set($cache_key, $projects, time() + 1800); // 缓存30分钟
      return $projects;
    }
    catch (\Exception $e) {
      $this->logger->error('Error getting user projects for user @user_id: @error', [
        '@user_id' => $user_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkProjectAccess(string $project_id, int $user_id, string $operation): bool {
    try {
      // 检查项目是否存在
      if (!$this->projectExists($project_id)) {
        return false;
      }

      // 检查用户是否为项目成员
      $member_role = $this->getUserProjectRole($project_id, $user_id);
      if (!$member_role) {
        return false;
      }

      // 检查操作权限
      return $this->checkOperationPermission($member_role, $operation);
    }
    catch (\Exception $e) {
      $this->logger->error('Error checking project access @project_id for user @user_id operation @operation: @error', [
        '@project_id' => $project_id,
        '@user_id' => $user_id,
        '@operation' => $operation,
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resolveProjectFromRequest(): string|false {
    try {
      $request = $this->requestStack->getCurrentRequest();
      if (!$request) {
        return false;
      }

      $result = $this->resolveFromRequest($request);
      return $result ?: false;
    }
    catch (\Exception $e) {
      $this->logger->debug('Failed to resolve project from current request: @error', [
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectContext(string $project_id): array|false {
    try {
      $project_data = $this->loadProjectData($project_id);
      if (!$project_data) {
        return false;
      }

      $user_id = (int) $this->currentUser->id();
      return [
        'project_id' => $project_id,
        'project_name' => $project_data['name'],
        'machine_name' => $project_data['machine_name'],
        'tenant_id' => $project_data['tenant_id'],
        'user_role' => $this->getUserProjectRole($project_id, $user_id),
        'permissions' => $this->getUserProjectPermissions($project_id),
        'is_default' => $project_data['settings']['is_default'] ?? false,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Error getting project context for @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function canSwitchToProject(int $user_id, string $project_id): bool {
    return $this->checkProjectAccess($project_id, $user_id, 'view');
  }

  /**
   * {@inheritdoc}
   */
  public function getRecentProjects(int $user_id, int $limit = 5): array {
    try {
      // 从访问历史中获取最近访问的项目
      $recent_access = array_slice(array_reverse($this->accessHistory), 0, $limit);
      $recent_projects = [];
      
      foreach ($recent_access as $access) {
        if ($access['user_id'] == $user_id) {
          $project_data = $this->loadProjectData($access['project_id']);
          if ($project_data) {
            $recent_projects[] = [
              'project_id' => $access['project_id'],
              'name' => $project_data['name'],
              'last_access' => $access['timestamp'],
            ];
          }
        }
      }

      return $recent_projects;
    }
    catch (\Exception $e) {
      $this->logger->error('Error getting recent projects for user @user_id: @error', [
        '@user_id' => $user_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isProjectActive(string $project_id): bool {
    try {
      $project_data = $this->loadProjectData($project_id);
      return $project_data && ($project_data['status'] == 1);
    }
    catch (\Exception $e) {
      $this->logger->error('Error checking if project is active @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

}