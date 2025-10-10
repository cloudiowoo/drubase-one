<?php

declare(strict_types=1);

namespace Drupal\baas_auth\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * 统一权限检查服务实现。
 *
 * 提供租户级和项目级的统一权限检查功能，解决权限检查逻辑分散的问题。
 */
class UnifiedPermissionChecker implements UnifiedPermissionCheckerInterface
{

  /**
   * 数据库连接。
   */
  protected readonly Connection $database;

  /**
   * 缓存后端。
   */
  protected readonly CacheBackendInterface $cache;

  /**
   * 日志记录器。
   */
  protected readonly LoggerChannelInterface $logger;

  /**
   * 原有权限检查器（向后兼容）。
   */
  protected readonly PermissionCheckerInterface $legacyChecker;

  /**
   * JWT令牌管理器。
   */
  protected readonly JwtTokenManagerInterface $jwtManager;

  /**
   * 构造函数。
   *
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接。
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   缓存后端。
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂。
   * @param \Drupal\baas_auth\Service\PermissionCheckerInterface $legacy_checker
   *   原有权限检查器。
   * @param \Drupal\baas_auth\Service\JwtTokenManagerInterface $jwt_manager
   *   JWT令牌管理器。
   */
  public function __construct(
    Connection $database,
    CacheBackendInterface $cache,
    LoggerChannelFactoryInterface $logger_factory,
    PermissionCheckerInterface $legacy_checker,
    JwtTokenManagerInterface $jwt_manager
  ) {
    $this->database = $database;
    $this->cache = $cache;
    $this->logger = $logger_factory->get('baas_auth_unified');
    $this->legacyChecker = $legacy_checker;
    $this->jwtManager = $jwt_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function checkTenantPermission(int $user_id, string $tenant_id, string $permission): bool
  {
    try {
      // 使用现有的权限检查逻辑（向后兼容）
      return $this->legacyChecker->hasPermission($user_id, $tenant_id, $permission);
    } catch (\Exception $e) {
      $this->logger->error('租户权限检查失败: @message', ['@message' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkProjectPermission(int $user_id, string $project_id, string $permission): bool
  {
    try {
      $cache_key = "baas_auth:project_permissions:{$user_id}:{$project_id}";
      $cached = $this->cache->get($cache_key);

      if ($cached) {
        $permissions = $cached->data;
      } else {
        $permissions = $this->getProjectPermissions($user_id, $project_id);
        $this->cache->set($cache_key, $permissions, time() + 1800); // 缓存30分钟
      }

      return in_array($permission, $permissions);
    } catch (\Exception $e) {
      $this->logger->error('项目权限检查失败: @message', ['@message' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUserTenantRole(int $user_id, string $tenant_id): string|false
  {
    try {
      $roles = $this->legacyChecker->getUserRoles($user_id, $tenant_id);
      return !empty($roles) ? $roles[0] : false;
    } catch (\Exception $e) {
      $this->logger->error('获取租户角色失败: @message', ['@message' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUserProjectRole(int $user_id, string $project_id): string|false
  {
    try {
      // 查询项目成员表获取用户在项目中的角色
      $query = $this->database->select('baas_project_members', 'pm')
        ->fields('pm', ['role'])
        ->condition('user_id', $user_id)
        ->condition('project_id', $project_id)
        ->condition('status', 1);

      $result = $query->execute()->fetchField();
      return $result ?: false;
    } catch (\Exception $e) {
      $this->logger->error('获取项目角色失败: @message', ['@message' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function canAccessTenant(int $user_id, string $tenant_id): bool
  {
    try {
      // 检查用户是否在租户中
      $query = $this->database->select('baas_user_tenant_mapping', 'utm')
        ->fields('utm', ['id'])
        ->condition('user_id', $user_id)
        ->condition('tenant_id', $tenant_id)
        ->condition('status', 1);

      return (bool) $query->execute()->fetchField();
    } catch (\Exception $e) {
      $this->logger->error('租户访问检查失败: @message', ['@message' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function canAccessProject(int $user_id, string $project_id): bool
  {
    try {
      // 首先检查项目是否存在
      $project_query = $this->database->select('baas_project_config', 'pc')
        ->fields('pc', ['tenant_id'])
        ->condition('project_id', $project_id)
        ->condition('status', 1);

      $tenant_id = $project_query->execute()->fetchField();
      if (!$tenant_id) {
        return false;
      }

      // 检查用户是否可以访问该租户
      if (!$this->canAccessTenant($user_id, $tenant_id)) {
        return false;
      }

      // 检查用户是否是项目成员
      $member_query = $this->database->select('baas_project_members', 'pm')
        ->fields('pm', ['id'])
        ->condition('user_id', $user_id)
        ->condition('project_id', $project_id)
        ->condition('status', 1);

      return (bool) $member_query->execute()->fetchField();
    } catch (\Exception $e) {
      $this->logger->error('项目访问检查失败: @message', ['@message' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkProjectEntityPermission(int $user_id, string $project_id, string $entity_name, string $operation): bool
  {
    try {
      // 首先检查项目访问权限
      if (!$this->canAccessProject($user_id, $project_id)) {
        return false;
      }

      // 获取用户在项目中的角色
      $role = $this->getUserProjectRole($user_id, $project_id);
      if (!$role) {
        return false;
      }

      // 根据角色和操作类型检查权限
      $permission_map = [
        'owner' => ['create', 'read', 'update', 'delete'],
        'admin' => ['create', 'read', 'update', 'delete'],
        'editor' => ['create', 'read', 'update'],
        'viewer' => ['read'],
        'member' => ['read'],
      ];

      return isset($permission_map[$role]) && in_array($operation, $permission_map[$role]);
    } catch (\Exception $e) {
      $this->logger->error('项目实体权限检查失败: @message', ['@message' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectPermissions(int $user_id, string $project_id): array
  {
    try {
      $permissions = [];

      // 获取用户在项目中的角色
      $role = $this->getUserProjectRole($user_id, $project_id);
      if (!$role) {
        return [];
      }

      // 根据角色分配权限
      switch ($role) {
        case 'owner':
          $permissions = [
            'manage_project',
            'manage_project_members',
            'create_project_entities',
            'edit_project_entities',
            'delete_project_entities',
            'access_project_entities',
            // 项目级实体模板权限
            'view project entity templates',
            'create project entity templates',
            'edit project entity templates',
            'delete project entity templates',
            // 项目级实体数据权限
            'view project entity data',
            'create project entity data',
            'edit project entity data',
            'delete project entity data',
            // 项目函数和环境变量权限
            'create project functions',
            'edit project functions',
            'delete project functions',
            'execute project functions',
            'view function logs',
            'view project env vars',
            'manage project env vars',
            // 项目级实时功能权限
            'manage realtime',
            'view realtime',
            'access realtime',
            'view realtime data',
            'broadcast realtime messages',
            'manage realtime presence',
          ];
          break;

        case 'admin':
          $permissions = [
            'manage_project_members',
            'create_project_entities',
            'edit_project_entities',
            'delete_project_entities',
            'access_project_entities',
            // 项目级实体模板权限（管理员级别）
            'view project entity templates',
            'create project entity templates',
            'edit project entity templates',
            'delete project entity templates',
            // 项目级实体数据权限（管理员级别）
            'view project entity data',
            'create project entity data',
            'edit project entity data',
            'delete project entity data',
            // 项目函数和环境变量权限（管理员级别）
            'create project functions',
            'edit project functions',
            'delete project functions',
            'execute project functions',
            'view function logs',
            'view project env vars',
            'manage project env vars',
            // 项目级实时功能权限（管理员级别）
            'manage realtime',
            'view realtime',
            'access realtime',
            'view realtime data',
            'broadcast realtime messages',
            'manage realtime presence',
          ];
          break;

        case 'editor':
          $permissions = [
            'create_project_entities',
            'edit_project_entities',
            'access_project_entities',
            // 项目级实体模板权限（编辑者级别）
            'view project entity templates',
            'edit project entity templates',
            // 项目级实体数据权限（编辑者级别）
            'view project entity data',
            'create project entity data',
            'edit project entity data',
            // 项目函数和环境变量权限（编辑者级别）
            'create project functions',
            'edit project functions',
            'execute project functions',
            'view function logs',
            'view project env vars',
            'manage project env vars',
          ];
          break;

        case 'viewer':
        case 'member':
        default:
          $permissions = [
            'access_project_entities',
            // 项目级实体模板权限（查看者级别）
            'view project entity templates',
            // 项目级实体数据权限（查看者级别）
            'view project entity data',
          ];
          break;
      }

      return $permissions;
    } catch (\Exception $e) {
      $this->logger->error('获取项目权限失败: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateProjectContext(string $jwt_token, string $required_project_id): bool
  {
    try {
      $payload = $this->jwtManager->validateToken($jwt_token);
      if (!$payload) {
        return false;
      }

      return isset($payload['project_id']) && $payload['project_id'] === $required_project_id;
    } catch (\Exception $e) {
      $this->logger->error('项目上下文验证失败: @message', ['@message' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function switchProjectContext(int $user_id, string $project_id): array|false
  {
    try {
      // 检查用户是否可以访问该项目
      if (!$this->canAccessProject($user_id, $project_id)) {
        return false;
      }

      // 获取项目信息
      $project_query = $this->database->select('baas_project_config', 'pc')
        ->fields('pc', ['tenant_id', 'project_name'])
        ->condition('project_id', $project_id);

      $project_info = $project_query->execute()->fetchAssoc();
      if (!$project_info) {
        return false;
      }

      // 构建新的JWT payload
      $payload = [
        'sub' => (string) $user_id,
        'tenant_id' => $project_info['tenant_id'],
        'project_id' => $project_id,
        'permissions' => [
          'tenant' => $this->legacyChecker->getUserPermissions($user_id, $project_info['tenant_id']),
          'project' => $this->getProjectPermissions($user_id, $project_id),
          'project_role' => $this->getUserProjectRole($user_id, $project_id),
        ],
        'context' => [
          'tenant_role' => $this->getUserTenantRole($user_id, $project_info['tenant_id']),
          'current_project_permissions' => $this->getProjectPermissions($user_id, $project_id),
        ],
        'iat' => time(),
        'exp' => time() + 86400, // 24小时过期
      ];

      return $payload;
    } catch (\Exception $e) {
      $this->logger->error('项目上下文切换失败: @message', ['@message' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getEffectivePermissions(int $user_id, string $tenant_id, string $project_id = null): array
  {
    try {
      $tenant_permissions = $this->legacyChecker->getUserPermissions($user_id, $tenant_id);
      
      if ($project_id) {
        $project_permissions = $this->getProjectPermissions($user_id, $project_id);
        return $this->resolvePermissionHierarchy($tenant_permissions, $project_permissions);
      }

      return $tenant_permissions;
    } catch (\Exception $e) {
      $this->logger->error('获取有效权限失败: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resolvePermissionHierarchy(array $tenant_permissions, array $project_permissions): array
  {
    // 项目权限优先，租户权限作为基础
    $effective_permissions = array_merge($tenant_permissions, $project_permissions);
    
    // 去重并排序
    return array_unique($effective_permissions);
  }

  /**
   * {@inheritdoc}
   */
  public function checkCascadingPermission(int $user_id, string $tenant_id, string $project_id, string $permission): bool
  {
    try {
      // 首先检查租户级权限
      if ($this->checkTenantPermission($user_id, $tenant_id, $permission)) {
        return true;
      }

      // 然后检查项目级权限
      return $this->checkProjectPermission($user_id, $project_id, $permission);
    } catch (\Exception $e) {
      $this->logger->error('级联权限检查失败: @message', ['@message' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function clearUserPermissionCache(int $user_id, string $tenant_id = null, string $project_id = null): void
  {
    try {
      if ($tenant_id) {
        $this->legacyChecker->clearUserPermissionCache($user_id, $tenant_id);
      }

      if ($project_id) {
        $cache_key = "baas_auth:project_permissions:{$user_id}:{$project_id}";
        $this->cache->delete($cache_key);
      }

      // 清除用户相关的所有权限缓存
      if (!$tenant_id && !$project_id) {
        $this->cache->deleteMultiple([
          "baas_auth:permissions:{$user_id}:*",
          "baas_auth:project_permissions:{$user_id}:*",
        ]);
      }
    } catch (\Exception $e) {
      $this->logger->error('清除权限缓存失败: @message', ['@message' => $e->getMessage()]);
    }
  }

}