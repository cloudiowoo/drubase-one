<?php

declare(strict_types=1);

namespace Drupal\baas_api\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * API缓存服务接口。
 *
 * 定义API缓存服务的标准方法。
 */
interface ApiCacheServiceInterface
{

  /**
   * 获取缓存的响应。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   缓存的响应对象，没有缓存时返回null。
   */
  public function getCachedResponse(Request $request): ?JsonResponse;

  /**
   * 缓存响应。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   * @param \Symfony\Component\HttpFoundation\JsonResponse $response
   *   HTTP响应对象。
   */
  public function cacheResponse(Request $request, JsonResponse $response): void;

  /**
   * 清除缓存。
   *
   * @param array $tags
   *   要清除的缓存标签数组，为空时清除所有API缓存。
   */
  public function invalidateCache(array $tags = []): void;

  /**
   * 清除租户相关缓存。
   *
   * @param string $tenant_id
   *   租户ID。
   */
  public function invalidateTenantCache(string $tenant_id): void;

  /**
   * 清除项目相关缓存。
   *
   * @param string $project_id
   *   项目ID。
   */
  public function invalidateProjectCache(string $project_id): void;

  /**
   * 清除实体相关缓存。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $entity_name
   *   实体名称。
   */
  public function invalidateEntityCache(string $tenant_id, string $entity_name): void;

  /**
   * 缓存预热。
   *
   * @param array $endpoints
   *   要预热的端点列表。
   */
  public function warmupCache(array $endpoints = []): void;

}