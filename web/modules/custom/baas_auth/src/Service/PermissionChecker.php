<?php

namespace Drupal\baas_auth\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * 权限检查器服务实现。
 */
class PermissionChecker implements PermissionCheckerInterface
{

  /**
   * 数据库连接。
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * 缓存后端。
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * 日志记录器。
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * 构造函数。
   *
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接。
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   缓存后端。
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂。
   */
  public function __construct(
    Connection $database,
    CacheBackendInterface $cache,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->database = $database;
    $this->cache = $cache;
    $this->logger = $logger_factory->get('baas_auth');
  }

  /**
   * {@inheritdoc}
   */
  public function hasPermission(int $user_id, string $tenant_id, string $permission): bool
  {
    try {
      $cache_key = "baas_auth:permissions:{$user_id}:{$tenant_id}";
      $cached = $this->cache->get($cache_key);

      if ($cached) {
        $permissions = $cached->data;
      } else {
        $permissions = $this->getUserPermissions($user_id, $tenant_id);
        $this->cache->set($cache_key, $permissions, time() + 3600); // 缓存1小时
      }

      return in_array($permission, $permissions);
    } catch (\Exception $e) {
      $this->logger->error('权限检查失败: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function hasResourcePermission(int $user_id, string $tenant_id, string $resource, string $operation): bool
  {
    try {
      // 构建资源权限名称，例如：user.create, post.edit, etc.
      $permission = $resource . '.' . $operation;
      return $this->hasPermission($user_id, $tenant_id, $permission);
    } catch (\Exception $e) {
      $this->logger->error('资源权限检查失败: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUserPermissions(int $user_id, string $tenant_id): array
  {
    try {
      $permissions = [];

      // 获取用户在指定租户下的角色
      $roles = $this->getUserRoles($user_id, $tenant_id);

      // 如果用户没有特定角色，给予基础权限
      if (empty($roles) || $roles === ['authenticated']) {
        $permissions = ['read', 'write'];
      } else {
        // 根据角色获取权限
        foreach ($roles as $role) {
          // 查询角色的权限
          $query = $this->database->select('baas_auth_permissions', 'p')
            ->fields('p', ['resource', 'operation'])
            ->condition('role_name', $role);

          $or_group = $query->orConditionGroup()
            ->condition('tenant_id', $tenant_id)
            ->isNull('tenant_id');

          $query->condition($or_group);
          $role_permissions = $query->execute();

          while ($row = $role_permissions->fetchAssoc()) {
            $permission = $row['resource'] . '.' . $row['operation'];
            if (!in_array($permission, $permissions)) {
              $permissions[] = $permission;
            }
          }
        }

        // 确保至少有基础权限
        if (!in_array('read', $permissions)) {
          $permissions[] = 'read';
        }
        if (!in_array('write', $permissions)) {
          $permissions[] = 'write';
        }
      }

      // 如果是用户ID 1，给予管理员权限
      if ($user_id === 1) {
        $admin_permissions = ['admin', 'delete', 'user.manage', 'api_key.manage', 'permission.assign'];
        foreach ($admin_permissions as $admin_perm) {
          if (!in_array($admin_perm, $permissions)) {
            $permissions[] = $admin_perm;
          }
        }
      }

      return $permissions;
    } catch (\Exception $e) {
      $this->logger->error('获取用户权限失败: @message', ['@message' => $e->getMessage()]);
      return ['read']; // 默认只给读权限
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUserRoles(int $user_id, string $tenant_id): array
  {
    try {
      $cid = "baas_auth:user_roles:{$user_id}:{$tenant_id}";

      if ($cache = \Drupal::cache()->get($cid)) {
        return $cache->data;
      }

      $roles = [];

      // 查询用户在指定租户下的角色
      $query = $this->database->select('baas_auth_user_roles', 'ur')
        ->fields('ur', ['role_name'])
        ->condition('user_id', $user_id)
        ->condition('tenant_id', $tenant_id);

      $result = $query->execute();

      while ($row = $result->fetchAssoc()) {
        $roles[] = $row['role_name'];
      }

      // 如果没有特定角色，至少有authenticated角色
      if (empty($roles)) {
        $roles = ['authenticated'];
      }

      // 缓存1小时
      \Drupal::cache()->set($cid, $roles, time() + 3600);

      return $roles;
    } catch (\Exception $e) {
      $this->logger->error('获取用户角色失败: @message', ['@message' => $e->getMessage()]);
      return ['authenticated']; // 默认返回authenticated角色
    }
  }

  /**
   * {@inheritdoc}
   */
  public function hasRole(int $user_id, string $tenant_id, string $role): bool
  {
    try {
      $roles = $this->getUserRoles($user_id, $tenant_id);
      return in_array($role, $roles);
    } catch (\Exception $e) {
      $this->logger->error('角色检查失败: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function clearUserPermissionCache(int $user_id, string $tenant_id): void
  {
    $cache_key = "baas_auth:permissions:{$user_id}:{$tenant_id}";
    $this->cache->delete($cache_key);
  }

  /**
   * {@inheritdoc}
   */
  public function apiKeyHasPermission(string $api_key, string $permission): bool
  {
    try {
      // 这里应该查询API密钥的权限
      // 简化实现：假设所有API密钥都有读取权限
      $allowed_permissions = ['read'];

      return in_array($permission, $allowed_permissions);
    } catch (\Exception $e) {
      $this->logger->error('API密钥权限检查失败: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * 检查用户权限（向后兼容的方法）。
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
  public function checkPermission(int $user_id, string $tenant_id, string $permission): bool
  {
    return $this->hasPermission($user_id, $tenant_id, $permission);
  }
}
