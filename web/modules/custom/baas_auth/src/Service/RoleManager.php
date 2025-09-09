<?php

namespace Drupal\baas_auth\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Psr\Log\LoggerInterface;

/**
 * 角色管理服务。
 */
class RoleManager implements RoleManagerInterface
{

  /**
   * 数据库连接。
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * 权限检查服务。
   *
   * @var \Drupal\baas_auth\Service\PermissionCheckerInterface
   */
  protected $permissionChecker;

  /**
   * 缓存后端。
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * 配置工厂。
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * 日志记录器。
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    Connection $database,
    PermissionCheckerInterface $permission_checker,
    CacheBackendInterface $cache,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger
  ) {
    $this->database = $database;
    $this->permissionChecker = $permission_checker;
    $this->cache = $cache;
    $this->configFactory = $config_factory;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultRoles(): array
  {
    $cid = 'baas_auth:default_roles';
    $cache = $this->cache->get($cid);

    if ($cache) {
      return $cache->data;
    }

    $config = $this->configFactory->get('baas_auth.default_roles');
    $default_roles = $config->get('default_roles') ?: [];

    $this->cache->set($cid, $default_roles, time() + 3600);
    return $default_roles;
  }

  /**
   * {@inheritdoc}
   */
  public function assignRole(int $user_id, string $tenant_id, string $role_name, int $assigned_by = NULL): bool
  {
    try {
      // 检查角色是否存在
      $default_roles = $this->getDefaultRoles();
      if (!isset($default_roles[$role_name])) {
        throw new \InvalidArgumentException("Role '{$role_name}' does not exist.");
      }

      // 检查用户是否已有此角色
      $existing = $this->database->select('baas_auth_user_roles', 'r')
        ->fields('r', ['id'])
        ->condition('user_id', $user_id)
        ->condition('tenant_id', $tenant_id)
        ->condition('role_name', $role_name)
        ->execute()
        ->fetchField();

      if ($existing) {
        return TRUE; // 角色已存在，返回成功
      }

      // 分配角色
      $this->database->insert('baas_auth_user_roles')
        ->fields([
          'user_id' => $user_id,
          'tenant_id' => $tenant_id,
          'role_name' => $role_name,
          'assigned_by' => $assigned_by,
          'created' => time(),
        ])
        ->execute();

      // 清除用户权限缓存
      $this->clearUserPermissionsCache($user_id, $tenant_id);

      $this->logger->info('Role @role assigned to user @user in tenant @tenant', [
        '@role' => $role_name,
        '@user' => $user_id,
        '@tenant' => $tenant_id,
      ]);

      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Failed to assign role: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function revokeRole(int $user_id, string $tenant_id, string $role_name): bool
  {
    try {
      $deleted = $this->database->delete('baas_auth_user_roles')
        ->condition('user_id', $user_id)
        ->condition('tenant_id', $tenant_id)
        ->condition('role_name', $role_name)
        ->execute();

      if ($deleted > 0) {
        // 清除用户权限缓存
        $this->clearUserPermissionsCache($user_id, $tenant_id);

        $this->logger->info('Role @role revoked from user @user in tenant @tenant', [
          '@role' => $role_name,
          '@user' => $user_id,
          '@tenant' => $tenant_id,
        ]);

        return TRUE;
      }

      return FALSE;
    } catch (\Exception $e) {
      $this->logger->error('Failed to revoke role: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUserRoles(int $user_id, string $tenant_id): array
  {
    return $this->database->select('baas_auth_user_roles', 'r')
      ->fields('r', ['role_name'])
      ->condition('user_id', $user_id)
      ->condition('tenant_id', $tenant_id)
      ->execute()
      ->fetchCol();
  }

  /**
   * {@inheritdoc}
   */
  public function getUsersWithRole(string $role_name, string $tenant_id = NULL): array
  {
    $query = $this->database->select('baas_auth_user_roles', 'r')
      ->fields('r', ['user_id', 'tenant_id'])
      ->condition('role_name', $role_name);

    if ($tenant_id) {
      $query->condition('tenant_id', $tenant_id);
    }

    return $query->execute()->fetchAll();
  }

  /**
   * {@inheritdoc}
   */
  public function roleExists(string $role_name): bool
  {
    $default_roles = $this->getDefaultRoles();
    return isset($default_roles[$role_name]);
  }

  /**
   * {@inheritdoc}
   */
  public function getRolePermissions(string $role_name): array
  {
    $default_roles = $this->getDefaultRoles();

    if (!isset($default_roles[$role_name])) {
      return [];
    }

    return $default_roles[$role_name]['permissions'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getAllRoles(): array
  {
    return array_keys($this->getDefaultRoles());
  }

  /**
   * 清除用户权限缓存。
   */
  protected function clearUserPermissionsCache(int $user_id, string $tenant_id): void
  {
    $cid = "baas_auth:user_permissions:{$user_id}:{$tenant_id}";
    $this->cache->delete($cid);
  }
}
