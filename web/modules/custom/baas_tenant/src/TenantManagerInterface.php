<?php

declare(strict_types=1);

namespace Drupal\baas_tenant;

/**
 * 定义租户管理服务接口.
 */
interface TenantManagerInterface {

  /**
   * 创建新租户（企业模式）.
   *
   * @param string $name
   *   租户名称.
   * @param int $owner_uid
   *   租户所有者的用户ID.
   * @param array $settings
   *   租户设置.
   *
   * @return string|false
   *   成功返回租户ID，失败返回FALSE.
   */
  public function createTenant(string $name, int $owner_uid, array $settings = []): string|false;

  /**
   * 创建租户（向后兼容的旧接口）.
   *
   * @deprecated 使用 createTenant() 并指定 owner_uid 参数
   * @param string $name
   *   租户名称.
   * @param array $settings
   *   租户设置.
   *
   * @return string|false
   *   成功返回租户ID，失败返回FALSE.
   */
  public function createTenantLegacy(string $name, array $settings = []): string|false;

  /**
   * 获取租户信息.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array|false
   *   租户信息数组，不存在则返回FALSE.
   */
  public function getTenant(string $tenant_id): array|false;

  /**
   * 更新租户信息.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param array $data
   *   要更新的数据.
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE.
   */
  public function updateTenant(string $tenant_id, array $data): bool;

  /**
   * 删除租户.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE.
   */
  public function deleteTenant(string $tenant_id): bool;

  /**
   * 获取所有租户列表.
   *
   * @param array $conditions
   *   筛选条件.
   *
   * @return array
   *   租户列表.
   */
  public function listTenants(array $conditions = []): array;

  /**
   * 为租户创建所需的数据库表结构.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return void
   */
  public function createTenantTables(string $tenant_id): void;

  /**
   * 检查租户资源使用是否超出限制.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param string $resource_type
   *   资源类型 (api_calls, entities, functions, storage).
   * @param int $additionalCount
   *   要添加的资源数量.
   *
   * @return bool
   *   如果资源使用未超限返回TRUE，否则返回FALSE.
   */
  public function checkResourceLimits(string $tenant_id, string $resource_type, int $additionalCount = 1): bool;

  /**
   * 记录租户资源使用情况.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param string $resource_type
   *   资源类型.
   * @param int $count
   *   使用计数.
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE.
   */
  public function recordUsage(string $tenant_id, string $resource_type, int $count = 1): bool;

  /**
   * 获取租户资源使用统计.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param string $resource_type
   *   资源类型.
   * @param int $period
   *   统计周期（天）.
   *
   * @return int
   *   使用计数.
   */
  public function getUsage(string $tenant_id, string $resource_type, int $period = 30): int;

  /**
   * 加载租户信息.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array|null
   *   租户信息数组，不存在则返回NULL.
   */
  public function loadTenant(string $tenant_id): ?array;

  /**
   * 根据域名加载租户.
   *
   * @param string $domain
   *   域名.
   *
   * @return array|null
   *   租户信息数组，不存在则返回NULL.
   */
  public function loadTenantByDomain(string $domain): ?array;

  /**
   * 根据通配符域名加载租户.
   *
   * @param string $main_domain
   *   主域名.
   * @param string $subdomain
   *   子域名.
   *
   * @return array|null
   *   租户信息数组，不存在则返回NULL.
   */
  public function loadTenantByWildcardDomain(string $main_domain, string $subdomain): ?array;

  /**
   * 根据API密钥加载租户.
   *
   * @param string $api_key
   *   API密钥.
   *
   * @return array|null
   *   租户数据，如果未找到则返回NULL.
   */
  public function loadTenantByApiKey(string $api_key): ?array;

  /**
   * 为租户生成新的API密钥.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return string|false
   *   成功时返回新生成的API密钥，失败时返回false.
   */
  public function generateApiKey(string $tenant_id): string|false;

  /**
   * 移除租户的API密钥.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return bool
   *   操作成功返回TRUE，否则返回FALSE.
   */
  public function removeApiKey(string $tenant_id): bool;

  /**
   * 获取租户的API密钥.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return string|null
   *   成功时返回API密钥，未设置或失败时返回NULL.
   */
  public function getApiKey(string $tenant_id): ?string;

  /**
   * 更新租户域名配置.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param array $domain_config
   *   域名配置数组，可包含以下键:
   *   - domain: 主域名
   *   - wildcard_domain: 通配符域名
   *   - allowed_subdomains: 允许的子域名数组
   *   - custom_domains: 自定义域名数组
   *
   * @return bool
   *   更新成功返回TRUE，否则返回FALSE.
   */
  public function updateTenantDomain(string $tenant_id, array $domain_config): bool;

}
