<?php

namespace Drupal\baas_tenant\Service;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\baas_auth\Service\UserTenantMappingInterface;

/**
 * 租户权限检查服务.
 *
 * 提供细粒度的租户权限检查功能。
 */
class TenantPermissionChecker
{

  /**
   * 数据库连接.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * 日志服务.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * 用户-租户映射服务.
   *
   * @var \Drupal\baas_auth\Service\UserTenantMappingInterface
   */
  protected $userTenantMapping;

  /**
   * 构造函数.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂.
   * @param \Drupal\baas_auth\Service\UserTenantMappingInterface $user_tenant_mapping
   *   用户-租户映射服务.
   */
  public function __construct(
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
    UserTenantMappingInterface $user_tenant_mapping
  ) {
    $this->database = $database;
    $this->logger = $logger_factory->get('baas_tenant');
    $this->userTenantMapping = $user_tenant_mapping;
  }

  /**
   * 检查用户是否可以创建租户.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户.
   *
   * @return bool
   *   是否允许创建租户.
   */
  public function canCreateTenant(AccountInterface $account): bool
  {
    // 系统管理员总是可以创建
    if ($account->hasPermission('administer baas tenants')) {
      return TRUE;
    }

    // 注册用户可以创建自己的租户
    if ($account->hasPermission('create own tenant') && $account->isAuthenticated()) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * 检查用户是否可以查看指定租户.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return bool
   *   是否允许查看租户.
   */
  public function canViewTenant(AccountInterface $account, string $tenant_id): bool
  {
    // 系统管理员总是可以查看
    if ($account->hasPermission('administer baas tenants') || $account->hasPermission('view any tenant')) {
      return TRUE;
    }

    // 检查是否有查看自己租户的权限且确实属于该租户
    if ($account->hasPermission('view own tenant')) {
      return $this->userTenantMapping->isUserInTenant((int) $account->id(), $tenant_id);
    }

    // 检查遗留权限
    if ($account->hasPermission('view baas tenants') || $account->hasPermission('access any tenant')) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * 检查用户是否可以编辑指定租户.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return bool
   *   是否允许编辑租户.
   */
  public function canEditTenant(AccountInterface $account, string $tenant_id): bool
  {
    // 系统管理员总是可以编辑
    if ($account->hasPermission('administer baas tenants') || $account->hasPermission('administer tenant')) {
      return TRUE;
    }

    // 检查是否有编辑自己租户的权限且确实是租户所有者
    if ($account->hasPermission('edit own tenant')) {
      return $this->userTenantMapping->isUserTenantOwner((int) $account->id(), $tenant_id);
    }

    return FALSE;
  }

  /**
   * 检查用户是否可以删除指定租户.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return bool
   *   是否允许删除租户.
   */
  public function canDeleteTenant(AccountInterface $account, string $tenant_id): bool
  {
    // 系统管理员总是可以删除
    if ($account->hasPermission('administer baas tenants') || $account->hasPermission('administer tenant')) {
      return TRUE;
    }

    // 检查是否有删除自己租户的权限且确实是租户所有者
    if ($account->hasPermission('delete own tenant')) {
      return $this->userTenantMapping->isUserTenantOwner((int) $account->id(), $tenant_id);
    }

    return FALSE;
  }

  /**
   * 检查用户是否可以管理租户成员.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return bool
   *   是否允许管理租户成员.
   */
  public function canManageTenantMembers(AccountInterface $account, string $tenant_id): bool
  {
    // 系统管理员总是可以管理
    if ($account->hasPermission('administer baas tenants')) {
      return TRUE;
    }

    // 检查是否有管理租户成员的权限且确实是租户所有者或管理员
    if ($account->hasPermission('manage tenant members')) {
      $user_role = $this->userTenantMapping->getUserRole((int) $account->id(), $tenant_id);
      return $this->userTenantMapping->isUserTenantOwner((int) $account->id(), $tenant_id) ||
        in_array($user_role, ['tenant_admin', 'tenant_manager']);
    }

    return FALSE;
  }

  /**
   * 检查用户是否可以查看租户成员.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return bool
   *   是否允许查看租户成员.
   */
  public function canViewTenantMembers(AccountInterface $account, string $tenant_id): bool
  {
    // 系统管理员总是可以查看
    if ($account->hasPermission('administer baas tenants')) {
      return TRUE;
    }

    // 检查是否有查看租户成员的权限且属于该租户
    if ($account->hasPermission('view tenant members')) {
      return $this->userTenantMapping->isUserInTenant((int) $account->id(), $tenant_id);
    }

    return FALSE;
  }

  /**
   * 检查用户是否可以管理租户API密钥.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return bool
   *   是否允许管理租户API密钥.
   */
  public function canManageTenantApiKeys(AccountInterface $account, string $tenant_id): bool
  {
    // 系统管理员总是可以管理
    if ($account->hasPermission('administer baas tenants')) {
      return TRUE;
    }

    // 检查是否有管理API密钥的权限且确实是租户所有者或管理员
    if ($account->hasPermission('manage tenant api keys')) {
      $user_role = $this->userTenantMapping->getUserRole((int) $account->id(), $tenant_id);
      return $this->userTenantMapping->isUserTenantOwner((int) $account->id(), $tenant_id) ||
        in_array($user_role, ['tenant_admin', 'tenant_manager']);
    }

    return FALSE;
  }

  /**
   * 检查用户是否可以访问租户资源.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return bool
   *   是否允许访问租户资源.
   */
  public function canAccessTenantResources(AccountInterface $account, string $tenant_id): bool
  {
    // 系统管理员总是可以访问
    if ($account->hasPermission('administer baas tenants') || $account->hasPermission('access any tenant')) {
      return TRUE;
    }

    // 检查是否有访问租户资源的权限且属于该租户
    if ($account->hasPermission('access tenant resources') || $account->hasPermission('access own tenant')) {
      return $this->userTenantMapping->isUserInTenant((int) $account->id(), $tenant_id);
    }

    return FALSE;
  }

  /**
   * 检查用户是否可以转移租户所有权.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return bool
   *   是否允许转移租户所有权.
   */
  public function canTransferTenantOwnership(AccountInterface $account, string $tenant_id): bool
  {
    // 系统管理员总是可以转移
    if ($account->hasPermission('administer baas tenants')) {
      return TRUE;
    }

    // 检查是否有转移所有权的权限且确实是租户所有者
    if ($account->hasPermission('transfer tenant ownership')) {
      return $this->userTenantMapping->isUserTenantOwner((int) $account->id(), $tenant_id);
    }

    return FALSE;
  }

  /**
   * 获取用户在租户中的角色.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return string|null
   *   用户角色，如果用户不属于该租户则返回NULL.
   */
  public function getUserTenantRole(AccountInterface $account, string $tenant_id): ?string
  {
    return $this->userTenantMapping->getUserRole((int) $account->id(), $tenant_id);
  }

  /**
   * 检查用户是否拥有指定租户.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return bool
   *   是否拥有租户.
   */
  public function isUserTenantOwner(AccountInterface $account, string $tenant_id): bool
  {
    return $this->userTenantMapping->isUserTenantOwner((int) $account->id(), $tenant_id);
  }

  /**
   * 获取用户可以查看的所有租户列表.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户.
   *
   * @return array
   *   租户列表.
   */
  public function getUserAccessibleTenants(AccountInterface $account): array
  {
    // 系统管理员可以看到所有租户
    if ($account->hasPermission('administer baas tenants') || $account->hasPermission('view any tenant')) {
      return $this->getAllTenants();
    }

    // 普通用户只能看到自己的租户
    if ($account->hasPermission('view own tenant')) {
      return $this->getUserTenantsWithDetails((int) $account->id());
    }

    return [];
  }

  /**
   * 获取用户的租户列表（包含租户详细信息）.
   *
   * @param int $user_id
   *   用户ID.
   *
   * @return array
   *   包含完整租户信息的数组.
   */
  protected function getUserTenantsWithDetails(int $user_id): array
  {
    try {
      $query = $this->database->select('baas_user_tenant_mapping', 'm');
      $query->fields('m', ['tenant_id', 'role', 'is_owner', 'created']);

      // 联合租户配置表获取租户详细信息
      $query->leftJoin('baas_tenant_config', 't', 'm.tenant_id = t.tenant_id');
      $query->addField('t', 'name');
      $query->addField('t', 'status');

      $query->condition('m.user_id', $user_id)
        ->condition('m.status', 1);

      $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

      // 确保每个租户都有必需的字段
      foreach ($results as &$tenant) {
        $tenant['name'] = $tenant['name'] ?? $tenant['tenant_id'];
        $tenant['status'] = $tenant['status'] ?? 1; // 默认为启用状态
      }

      return $results;
    } catch (\Exception $e) {
      $this->logger->error('获取用户租户详细信息失败: @error', ['@error' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * 获取所有租户（仅供管理员使用）.
   *
   * @return array
   *   所有租户列表.
   */
  protected function getAllTenants(): array
  {
    try {
      return $this->database->select('baas_tenant_config', 't')
        ->fields('t', ['tenant_id', 'name', 'status', 'created'])
        ->condition('status', 1)
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
      $this->logger->error('获取所有租户失败: @error', ['@error' => $e->getMessage()]);
      return [];
    }
  }
}
