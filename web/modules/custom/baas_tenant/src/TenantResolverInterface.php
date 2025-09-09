<?php

declare(strict_types=1);

namespace Drupal\baas_tenant;

/**
 * 租户解析服务接口.
 *
 * 定义用于从请求中识别当前租户的方法.
 */
interface TenantResolverInterface {

  /**
   * 从当前请求解析租户.
   *
   * @return array|null
   *   租户数据，如果未找到则返回NULL.
   */
  public function resolveTenant();

  /**
   * 从域名解析租户.
   *
   * @param string $domain
   *   域名.
   *
   * @return array|null
   *   租户数据，如果未找到则返回NULL.
   */
  public function resolveTenantFromDomain(string $domain);

  /**
   * 从API密钥解析租户.
   *
   * @param string $api_key
   *   API密钥.
   *
   * @return array|null
   *   租户数据，如果未找到则返回NULL.
   */
  public function resolveTenantFromApiKey(string $api_key);

  /**
   * 从租户ID解析租户.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array|null
   *   租户数据，如果未找到则返回NULL.
   */
  public function resolveTenantFromId(string $tenant_id);

  /**
   * 获取当前租户ID.
   *
   * @return string|null
   *   当前租户ID，如果未解析到租户则返回NULL.
   */
  public function getCurrentTenantId();

  /**
   * 获取当前租户数据.
   *
   * @return array|null
   *   当前租户数据，如果未解析到租户则返回NULL.
   */
  public function getCurrentTenant();

  /**
   * 清除当前租户上下文.
   */
  public function clearCurrentTenant();

}
