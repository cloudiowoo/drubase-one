<?php

declare(strict_types=1);

namespace Drupal\baas_api\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * API缓存服务。
 *
 * 提供API响应缓存功能，提升API性能。
 */
class ApiCacheService implements ApiCacheServiceInterface
{

  /**
   * 缓存后端。
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected readonly CacheBackendInterface $cache;

  /**
   * 配置工厂。
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected readonly ConfigFactoryInterface $configFactory;

  /**
   * 日志记录器。
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected readonly LoggerChannelInterface $logger;

  /**
   * 默认缓存配置。
   *
   * @var array
   */
  protected array $defaultCacheConfig = [
    'ttl' => 300,                    // 默认5分钟TTL
    'max_age' => 86400,             // 最大24小时
    'vary_headers' => [             // 缓存键变化的头信息
      'Authorization',
      'X-API-Key',
      'Accept',
      'Content-Type',
    ],
    'cache_methods' => [            // 可缓存的HTTP方法
      'GET',
      'HEAD',
    ],
    'cacheable_status_codes' => [   // 可缓存的状态码
      200,
      201,
      204,
      301,
      302,
      304,
      404,
    ],
  ];

  /**
   * 端点特定的缓存配置。
   *
   * @var array
   */
  protected array $endpointCacheConfig = [
    // 健康检查 - 短时间缓存
    '/health' => [
      'ttl' => 30,
      'vary_headers' => [],
    ],
    // API文档 - 长时间缓存
    '/docs' => [
      'ttl' => 3600,
      'vary_headers' => ['Accept'],
    ],
    '/openapi.json' => [
      'ttl' => 3600,
      'vary_headers' => ['Accept'],
    ],
    // 实体模板 - 中等时间缓存
    '/templates' => [
      'ttl' => 600,
      'vary_headers' => ['Authorization', 'X-API-Key'],
    ],
    // 实体列表 - 短时间缓存
    '/entities' => [
      'ttl' => 120,
      'vary_headers' => ['Authorization', 'X-API-Key'],
    ],
    // 项目列表 - 中等时间缓存
    '/projects' => [
      'ttl' => 300,
      'vary_headers' => ['Authorization', 'X-API-Key'],
    ],
  ];

  /**
   * 构造函数。
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   缓存后端。
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   配置工厂。
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂。
   */
  public function __construct(
    CacheBackendInterface $cache,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->cache = $cache;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('baas_api_cache');
  }

  /**
   * {@inheritdoc}
   */
  public function getCachedResponse(Request $request): ?JsonResponse
  {
    if (!$this->isCacheable($request)) {
      return null;
    }

    $cacheKey = $this->buildCacheKey($request);
    $cached = $this->cache->get($cacheKey);

    if ($cached && !empty($cached->data)) {
      $this->logger->debug('Cache hit for request: @method @uri', [
        '@method' => $request->getMethod(),
        '@uri' => $request->getRequestUri(),
      ]);

      $cachedData = $cached->data;
      
      // 重建JsonResponse对象
      $response = new JsonResponse(
        $cachedData['content'],
        $cachedData['status_code'],
        $cachedData['headers']
      );

      // 添加缓存相关头信息
      $response->headers->set('X-Cache', 'HIT');
      $response->headers->set('X-Cache-Key', $cacheKey);
      $response->headers->set('X-Cache-TTL', (string) ($cached->expire - time()));

      return $response;
    }

    $this->logger->debug('Cache miss for request: @method @uri', [
      '@method' => $request->getMethod(),
      '@uri' => $request->getRequestUri(),
    ]);

    return null;
  }

  /**
   * {@inheritdoc}
   */
  public function cacheResponse(Request $request, JsonResponse $response): void
  {
    if (!$this->isCacheable($request) || !$this->isResponseCacheable($response)) {
      return;
    }

    $cacheKey = $this->buildCacheKey($request);
    $config = $this->getCacheConfig($request);
    $ttl = $config['ttl'];

    // 准备缓存数据
    $cacheData = [
      'content' => json_decode($response->getContent(), true),
      'status_code' => $response->getStatusCode(),
      'headers' => $this->filterHeaders($response->headers->all()),
      'created' => time(),
      'method' => $request->getMethod(),
      'uri' => $request->getRequestUri(),
    ];

    // 设置缓存
    $this->cache->set($cacheKey, $cacheData, time() + $ttl, $this->getCacheTags($request));

    $this->logger->debug('Cached response for request: @method @uri (TTL: @ttl seconds)', [
      '@method' => $request->getMethod(),
      '@uri' => $request->getRequestUri(),
      '@ttl' => $ttl,
    ]);

    // 为响应添加缓存头信息
    $response->headers->set('X-Cache', 'MISS');
    $response->headers->set('X-Cache-Key', $cacheKey);
    $response->headers->set('X-Cache-TTL', (string) $ttl);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateCache(array $tags = []): void
  {
    if (empty($tags)) {
      // 清除所有API缓存
      $this->cache->deleteMultiple($this->cache->getMultiple(['baas_api']));
      $this->logger->info('Cleared all API cache');
    } else {
      // 按标签清除缓存
      $this->cache->invalidateTags($tags);
      $this->logger->info('Invalidated cache tags: @tags', [
        '@tags' => implode(', ', $tags),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateTenantCache(string $tenant_id): void
  {
    $tags = ["baas_api:tenant:{$tenant_id}"];
    $this->invalidateCache($tags);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateProjectCache(string $project_id): void
  {
    $tags = ["baas_api:project:{$project_id}"];
    $this->invalidateCache($tags);
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateEntityCache(string $tenant_id, string $entity_name): void
  {
    $tags = [
      "baas_api:tenant:{$tenant_id}",
      "baas_api:entity:{$entity_name}",
    ];
    $this->invalidateCache($tags);
  }

  /**
   * {@inheritdoc}
   */
  public function warmupCache(array $endpoints = []): void
  {
    $this->logger->info('Starting cache warmup for @count endpoints', [
      '@count' => count($endpoints),
    ]);

    foreach ($endpoints as $endpoint) {
      try {
        // 这里可以实现缓存预热逻辑
        // 比如模拟请求来填充缓存
        $this->logger->debug('Warming up cache for endpoint: @endpoint', [
          '@endpoint' => $endpoint,
        ]);
      } catch (\Exception $e) {
        $this->logger->error('Failed to warm up cache for endpoint @endpoint: @error', [
          '@endpoint' => $endpoint,
          '@error' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * 检查请求是否可缓存。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return bool
   *   可缓存返回true。
   */
  protected function isCacheable(Request $request): bool
  {
    $method = $request->getMethod();
    $config = $this->getCacheConfig($request);

    // 检查HTTP方法
    if (!in_array($method, $config['cache_methods'])) {
      return false;
    }

    // 检查是否包含no-cache头
    if ($request->headers->get('Cache-Control') === 'no-cache') {
      return false;
    }

    // 检查是否为调试请求
    if ($request->query->has('no_cache') || $request->query->has('debug')) {
      return false;
    }

    return true;
  }

  /**
   * 检查响应是否可缓存。
   *
   * @param \Symfony\Component\HttpFoundation\JsonResponse $response
   *   HTTP响应对象。
   *
   * @return bool
   *   可缓存返回true。
   */
  protected function isResponseCacheable(JsonResponse $response): bool
  {
    $statusCode = $response->getStatusCode();
    $config = $this->defaultCacheConfig;

    // 检查状态码
    if (!in_array($statusCode, $config['cacheable_status_codes'])) {
      return false;
    }

    // 检查Cache-Control头
    $cacheControl = $response->headers->get('Cache-Control');
    if ($cacheControl && (
      strpos($cacheControl, 'no-cache') !== false ||
      strpos($cacheControl, 'no-store') !== false ||
      strpos($cacheControl, 'private') !== false
    )) {
      return false;
    }

    return true;
  }

  /**
   * 构建缓存键。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return string
   *   缓存键。
   */
  protected function buildCacheKey(Request $request): string
  {
    $config = $this->getCacheConfig($request);
    
    // 基础键值
    $keyParts = [
      'baas_api',
      $request->getMethod(),
      $request->getPathInfo(),
    ];

    // 添加查询参数
    $queryParams = $request->query->all();
    if (!empty($queryParams)) {
      ksort($queryParams); // 确保键值排序一致
      $keyParts[] = http_build_query($queryParams);
    }

    // 添加vary headers
    foreach ($config['vary_headers'] as $header) {
      $headerValue = $request->headers->get($header);
      if ($headerValue) {
        // 对于Authorization头，只使用类型而不是完整值
        if ($header === 'Authorization') {
          $headerValue = explode(' ', $headerValue)[0] ?? $headerValue;
        }
        $keyParts[] = $header . ':' . substr(md5($headerValue), 0, 8);
      }
    }

    return implode(':', $keyParts);
  }

  /**
   * 获取缓存配置。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return array
   *   缓存配置数组。
   */
  protected function getCacheConfig(Request $request): array
  {
    $path = $request->getPathInfo();
    $config = $this->defaultCacheConfig;

    // 检查端点特定配置
    foreach ($this->endpointCacheConfig as $pattern => $endpointConfig) {
      if (strpos($path, $pattern) !== false) {
        $config = array_merge($config, $endpointConfig);
        break;
      }
    }

    // 合并系统配置
    $systemConfig = $this->configFactory->get('baas_api.cache')->get() ?: [];
    if (!empty($systemConfig)) {
      $config = array_merge($config, $systemConfig);
    }

    return $config;
  }

  /**
   * 获取缓存标签。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return array
   *   缓存标签数组。
   */
  protected function getCacheTags(Request $request): array
  {
    $tags = ['baas_api'];
    $path = $request->getPathInfo();

    // 从路径中提取租户ID
    if (preg_match('#/api/v1/([^/]+)#', $path, $matches)) {
      $tenantId = $matches[1];
      $tags[] = "baas_api:tenant:{$tenantId}";
    }

    // 从路径中提取项目ID
    if (preg_match('#/projects/([^/]+)#', $path, $matches)) {
      $projectId = $matches[1];
      $tags[] = "baas_api:project:{$projectId}";
    }

    // 从路径中提取实体名
    if (preg_match('#/entities/([^/]+)#', $path, $matches)) {
      $entityName = $matches[1];
      $tags[] = "baas_api:entity:{$entityName}";
    }

    return $tags;
  }

  /**
   * 过滤响应头信息。
   *
   * @param array $headers
   *   原始头信息数组。
   *
   * @return array
   *   过滤后的头信息数组。
   */
  protected function filterHeaders(array $headers): array
  {
    // 移除不应该缓存的头信息
    $excludeHeaders = [
      'set-cookie',
      'x-drupal-cache',
      'x-drupal-dynamic-cache',
      'date',
      'expires',
      'last-modified',
    ];

    foreach ($excludeHeaders as $excludeHeader) {
      unset($headers[strtolower($excludeHeader)]);
    }

    return $headers;
  }

}