<?php
/*
 * @Date: 2025-05-12 09:15:34
 * @LastEditors: cloudio cloudio.woo@gmail.com
 * @LastEditTime: 2025-05-12 09:15:34
 * @FilePath: /drubase/web/modules/custom/baas_tenant/src/TenantResolver.php
 */

declare(strict_types=1);

namespace Drupal\baas_tenant;

use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * 租户解析服务.
 *
 * 基于请求信息识别当前租户。
 */
class TenantResolver implements TenantResolverInterface {

  /**
   * 当前租户ID.
   */
  protected ?string $currentTenantId = NULL;

  /**
   * 当前租户数据.
   */
  protected ?array $currentTenant = NULL;

  /**
   * 构造函数.
   */
  public function __construct(
    protected readonly RequestStack $requestStack,
    protected readonly Connection $database,
    protected readonly TenantManagerInterface $tenantManager,
    protected readonly LoggerInterface $logger
  ) {
  }

  /**
   * 从当前请求解析租户.
   *
   * @return array|null
   *   租户数据，如果未找到则返回NULL.
   */
  public function resolveTenant() {
    if ($this->currentTenant !== NULL) {
      return $this->currentTenant;
    }

    $request = $this->requestStack->getCurrentRequest();

    // 1. 尝试从域名解析
    $host = $request->getHost();
    $tenant = $this->resolveTenantFromDomain($host);

    if ($tenant) {
      $this->setCurrentTenant($tenant);
      return $tenant;
    }

    // 2. 尝试从API密钥解析
    $api_key = $request->headers->get('X-API-Key');
    if ($api_key) {
      $tenant = $this->resolveTenantFromApiKey($api_key);
      if ($tenant) {
        $this->setCurrentTenant($tenant);
        return $tenant;
      }
    }

    // 3. 尝试从URL路径解析
    $path = $request->getPathInfo();
    if (preg_match('#^/api/([^/]+)/#', $path, $matches)) {
      $tenant_id = $matches[1];
      $tenant = $this->resolveTenantFromId($tenant_id);
      if ($tenant) {
        $this->setCurrentTenant($tenant);
        return $tenant;
      }
    }

    return NULL;
  }

  /**
   * 从域名解析租户.
   *
   * @param string $domain
   *   域名.
   *
   * @return array|null
   *   租户数据，如果未找到则返回NULL.
   */
  public function resolveTenantFromDomain(string $domain) {
    // 扩展点：域名解析策略配置
    // TODO: 从配置中读取域名解析策略，支持多种解析模式：
    // - 精确匹配：完全匹配域名
    // - 子域名匹配：支持{tenant}.domain.com格式
    // - 路径匹配：支持domain.com/{tenant}格式
    // - 混合匹配：组合上述策略

    // 扩展点：域名缓存
    // TODO: 实现域名解析缓存，提高性能
    // $cacheKey = 'tenant_domain:' . md5($domain);
    // if ($cached = $this->cache->get($cacheKey)) {
    //   return $cached->data;
    // }

    // 尝试直接匹配域名
    $tenant = $this->tenantManager->loadTenantByDomain($domain);
    if ($tenant) {
      // 扩展点：记录域名解析结果
      $this->logger->debug('通过精确域名匹配找到租户: @tenant_id, 域名: @domain', [
        '@tenant_id' => $tenant['tenant_id'],
        '@domain' => $domain,
      ]);
      return $tenant;
    }

    // 尝试匹配子域名
    $parts = explode('.', $domain);
    if (count($parts) > 2) {
      $subdomain = $parts[0];
      $main_domain = implode('.', array_slice($parts, 1));

      // 扩展点：子域名映射表
      // TODO: 实现子域名到租户ID的映射表，支持自定义子域名
      // $tenant_id = $this->getTenantIdFromSubdomain($subdomain);
      // if ($tenant_id) {
      //   return $this->tenantManager->loadTenant($tenant_id);
      // }

      // 查找使用通配符域名的租户
      $tenant = $this->tenantManager->loadTenantByWildcardDomain($main_domain, $subdomain);
      if ($tenant) {
        // 扩展点：记录子域名解析结果
        $this->logger->debug('通过子域名匹配找到租户: @tenant_id, 子域名: @subdomain, 主域名: @main_domain', [
          '@tenant_id' => $tenant['tenant_id'],
          '@subdomain' => $subdomain,
          '@main_domain' => $main_domain,
        ]);
        return $tenant;
      }
    }

    // 扩展点：域名解析失败处理
    // TODO: 实现域名解析失败的处理策略，如默认租户、重定向等
    $this->logger->notice('无法从域名解析租户: @domain', [
      '@domain' => $domain,
    ]);

    return NULL;
  }

  /**
   * 从API密钥解析租户.
   *
   * @param string $api_key
   *   API密钥.
   *
   * @return array|null
   *   租户数据，如果未找到则返回NULL.
   */
  public function resolveTenantFromApiKey(string $api_key) {
    return $this->tenantManager->loadTenantByApiKey($api_key);
  }

  /**
   * 从租户ID解析租户.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array|null
   *   租户数据，如果未找到则返回NULL.
   */
  public function resolveTenantFromId(string $tenant_id) {
    return $this->tenantManager->loadTenant($tenant_id);
  }

  /**
   * 设置当前租户.
   *
   * @param array $tenant
   *   租户数据.
   */
  protected function setCurrentTenant(array $tenant) {
    $this->currentTenantId = $tenant['tenant_id'];
    $this->currentTenant = $tenant;

    $this->logger->info('已识别租户: @tenant_id (@method)', [
      '@tenant_id' => $tenant['tenant_id'],
      '@method' => $tenant['name'],
    ]);
  }

  /**
   * 获取当前租户ID.
   *
   * @return string|null
   *   当前租户ID，如果未解析到租户则返回NULL.
   */
  public function getCurrentTenantId() {
    if ($this->currentTenantId === NULL) {
      $this->resolveTenant();
    }
    return $this->currentTenantId;
  }

  /**
   * 获取当前租户数据.
   *
   * @return array|null
   *   当前租户数据，如果未解析到租户则返回NULL.
   */
  public function getCurrentTenant() {
    if ($this->currentTenant === NULL) {
      $this->resolveTenant();
    }
    return $this->currentTenant;
  }

  /**
   * 清除当前租户上下文.
   */
  public function clearCurrentTenant() {
    $this->currentTenantId = NULL;
    $this->currentTenant = NULL;
  }

}