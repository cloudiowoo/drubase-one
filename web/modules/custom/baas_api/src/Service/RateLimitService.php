<?php

declare(strict_types=1);

namespace Drupal\baas_api\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\baas_project\ProjectUsageTrackerInterface;
use Psr\Log\LoggerInterface;

/**
 * API限流服务.
 *
 * 实现基于令牌桶算法的API限流控制。
 */
class RateLimitService
{

  /**
   * 日志器.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * 默认限流配置.
   *
   * @var array
   */
  protected array $defaultLimits = [
    'user' => [
      'requests' => 1000,  // 每小时1000次请求
      'window' => 3600,    // 时间窗口：1小时
      'burst' => 100,      // 突发请求上限
    ],
    'ip' => [
      'requests' => 100,   // 每小时100次请求
      'window' => 3600,    // 时间窗口：1小时
      'burst' => 20,       // 突发请求上限
    ],
    'tenant' => [
      'requests' => 10000, // 每小时10000次请求
      'window' => 3600,    // 时间窗口：1小时
      'burst' => 1000,     // 突发请求上限
    ],
  ];

  /**
   * 构造函数.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   缓存后端.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   配置工厂.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   日志工厂.
   * @param \Drupal\baas_project\ProjectUsageTrackerInterface|null $projectUsageTracker
   *   项目使用统计跟踪器（可选，用于向后兼容）.
   */
  public function __construct(
    protected CacheBackendInterface $cache,
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected ?ProjectUsageTrackerInterface $projectUsageTracker = null
  ) {
    $this->logger = $loggerFactory->get('baas_api_ratelimit');
  }

  /**
   * 检查是否允许API调用.
   *
   * @param string $identifier
   *   标识符（user:123, ip:192.168.1.1, tenant:abc）.
   * @param string|null $endpoint
   *   API端点（用于特殊限制）.
   *
   * @return bool
   *   是否允许调用.
   */
  public function isAllowed(string $identifier, ?string $endpoint = null): bool
  {
    try {
      $type = $this->getIdentifierType($identifier);
      $limits = $this->getLimitsForType($type, $endpoint);

      if (!$limits) {
        // 如果没有配置限制，默认允许
        return true;
      }

      $bucket = $this->getTokenBucket($identifier, $limits);
      
      // 检查是否有可用令牌
      if ($bucket['tokens'] < 1) {
        return false;
      }
      
      // 消耗一个令牌
      $bucket['tokens']--;
      
      // 保存更新后的令牌桶状态
      $cache_key = $this->getCacheKey($identifier);
      $ttl = $bucket['reset_time'] - time();
      if ($ttl > 0) {
        $this->cache->set($cache_key, $bucket, $bucket['reset_time']);
      }
      
      return true;
    } catch (\Exception $e) {
      $this->logger->error('Rate limit check failed: @error', ['@error' => $e->getMessage()]);
      // 错误时默认允许，避免阻塞正常请求
      return true;
    }
  }

  /**
   * 记录API调用（消耗令牌）.
   *
   * @param string $identifier
   *   标识符.
   * @param string|null $endpoint
   *   API端点.
   * @param int $tokens
   *   消耗的令牌数量.
   */
  public function recordCall(string $identifier, ?string $endpoint = null, int $tokens = 1): void
  {
    try {
      $type = $this->getIdentifierType($identifier);
      $limits = $this->getLimitsForType($type, $endpoint);

      if (!$limits) {
        return;
      }

      $bucket = $this->getTokenBucket($identifier, $limits);
      
      // 消耗令牌
      $bucket['tokens'] = max(0, $bucket['tokens'] - $tokens);
      $bucket['last_call'] = time();

      // 更新缓存
      $cache_key = $this->getCacheKey($identifier);
      $this->cache->set($cache_key, $bucket, time() + $limits['window']);

      // 记录限流日志
      if ($bucket['tokens'] === 0) {
        $this->logger->warning('Rate limit reached for @identifier', [
          '@identifier' => $identifier,
        ]);
      }
    } catch (\Exception $e) {
      $this->logger->error('Failed to record API call: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * 获取剩余限制信息.
   *
   * @param string $identifier
   *   标识符.
   * @param string|null $endpoint
   *   API端点.
   *
   * @return array
   *   限制信息.
   */
  public function getRemainingLimits(string $identifier, ?string $endpoint = null): array
  {
    try {
      $type = $this->getIdentifierType($identifier);
      $limits = $this->getLimitsForType($type, $endpoint);

      if (!$limits) {
        return [
          'remaining' => -1,  // -1表示无限制
          'limit' => -1,
          'reset_time' => 0,
        ];
      }

      $bucket = $this->getTokenBucket($identifier, $limits);

      return [
        'remaining' => $bucket['tokens'],
        'limit' => $limits['requests'],
        'reset_time' => $bucket['reset_time'],
        'window' => $limits['window'],
      ];
    } catch (\Exception $e) {
      $this->logger->error('Failed to get remaining limits: @error', ['@error' => $e->getMessage()]);
      return [
        'remaining' => -1,
        'limit' => -1,
        'reset_time' => 0,
      ];
    }
  }

  /**
   * 重置指定标识符的限制.
   *
   * @param string $identifier
   *   标识符.
   */
  public function resetLimits(string $identifier): void
  {
    try {
      $cache_key = $this->getCacheKey($identifier);
      $this->cache->delete($cache_key);
      
      $this->logger->info('Rate limits reset for @identifier', [
        '@identifier' => $identifier,
      ]);
    } catch (\Exception $e) {
      $this->logger->error('Failed to reset limits: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * 获取或创建令牌桶.
   *
   * @param string $identifier
   *   标识符.
   * @param array $limits
   *   限制配置.
   *
   * @return array
   *   令牌桶状态.
   */
  protected function getTokenBucket(string $identifier, array $limits): array
  {
    $cache_key = $this->getCacheKey($identifier);
    $bucket = $this->cache->get($cache_key);

    $current_time = time();

    if (!$bucket || !$bucket->data) {
      // 创建新的令牌桶
      return [
        'tokens' => $limits['requests'],
        'last_refill' => $current_time,
        'reset_time' => $current_time + $limits['window'],
      ];
    }

    $bucket_data = $bucket->data;

    // 检查是否需要重置窗口
    if ($current_time >= $bucket_data['reset_time']) {
      return [
        'tokens' => $limits['requests'],
        'last_refill' => $current_time,
        'reset_time' => $current_time + $limits['window'],
      ];
    }

    // 令牌桶算法：按时间补充令牌
    $elapsed = $current_time - $bucket_data['last_refill'];
    if ($elapsed > 0) {
      $refill_rate = $limits['requests'] / $limits['window'];
      $tokens_to_add = floor($elapsed * $refill_rate);
      
      $bucket_data['tokens'] = min(
        $limits['burst'], 
        $bucket_data['tokens'] + $tokens_to_add
      );
      $bucket_data['last_refill'] = $current_time;
    }

    return $bucket_data;
  }

  /**
   * 获取标识符类型.
   *
   * @param string $identifier
   *   标识符.
   *
   * @return string
   *   标识符类型.
   */
  protected function getIdentifierType(string $identifier): string
  {
    $parts = explode(':', $identifier, 2);
    return $parts[0] ?? 'unknown';
  }

  /**
   * 获取指定类型的限制配置.
   *
   * @param string $type
   *   标识符类型.
   * @param string|null $endpoint
   *   API端点.
   *
   * @return array|null
   *   限制配置.
   */
  protected function getLimitsForType(string $type, ?string $endpoint = null): ?array
  {
    // 获取配置的限制
    $config = $this->configFactory->get('baas_api.settings');
    $configured_limits = $config->get('rate_limits') ?? [];

    // 检查端点特殊限制
    if ($endpoint && isset($configured_limits['endpoints'][$endpoint])) {
      return $configured_limits['endpoints'][$endpoint];
    }

    // 检查类型限制
    if (isset($configured_limits[$type])) {
      return $configured_limits[$type];
    }

    // 返回默认限制
    return $this->defaultLimits[$type] ?? null;
  }

  /**
   * 生成缓存键.
   *
   * @param string $identifier
   *   标识符.
   *
   * @return string
   *   缓存键.
   */
  protected function getCacheKey(string $identifier): string
  {
    return 'baas_api_ratelimit:' . md5($identifier);
  }

  /**
   * 获取全局限流统计.
   *
   * @return array
   *   限流统计信息.
   */
  public function getGlobalStats(): array
  {
    try {
      // 这里可以实现更复杂的统计逻辑
      // 例如从数据库或缓存中获取限流事件统计
      
      return [
        'total_requests' => 0,
        'blocked_requests' => 0,
        'active_limits' => 0,
        'top_limited_identifiers' => [],
      ];
    } catch (\Exception $e) {
      $this->logger->error('Failed to get global stats: @error', ['@error' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * 更新限流配置.
   *
   * @param array $new_limits
   *   新的限制配置.
   */
  public function updateLimits(array $new_limits): void
  {
    try {
      $config = $this->configFactory->getEditable('baas_api.settings');
      $config->set('rate_limits', $new_limits);
      $config->save();

      $this->logger->info('Rate limit configuration updated');
    } catch (\Exception $e) {
      $this->logger->error('Failed to update rate limits: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * 检查速率限制.
   *
   * @param string $identifier
   *   标识符.
   * @param string $key
   *   限制键.
   * @param int $limit
   *   限制次数.
   * @param int $window
   *   时间窗口（秒）.
   *
   * @return array
   *   包含allowed, remaining, reset_time等信息的数组.
   */
  public function checkRateLimit(string $identifier, string $key, int $limit, int $window): array
  {
    // 直接使用传入的标识符，不再拼接key
    // 因为调用方已经构建好了正确的标识符（如 user:17）
    
    // 获取令牌桶，使用传入的限制参数
    $limits = [
      'requests' => $limit,
      'window' => $window,
      'burst' => $limit, // 使用相同的值作为burst
    ];
    
    $bucket = $this->getTokenBucket($identifier, $limits);
    
    // 检查是否有可用令牌
    $allowed = $bucket['tokens'] > 0;
    
    if ($allowed) {
      // 消耗一个令牌
      $bucket['tokens']--;
      
      // 保存更新后的令牌桶状态
      $cache_key = $this->getCacheKey($identifier);
      $ttl = $bucket['reset_time'] - time();
      if ($ttl > 0) {
        $this->cache->set($cache_key, $bucket, $bucket['reset_time']);
      }
    }
    
    $result = [
      'allowed' => $allowed,
      'remaining' => max(0, $bucket['tokens']),
      'reset_time' => $bucket['reset_time'],
      'limit' => $limit,
      'window' => $window,
      'retry_after' => $allowed ? 0 : max(1, $bucket['reset_time'] - time()),
    ];
    
    return $result;
  }

  /**
   * 清理过期的限流缓存.
   */
  public function cleanupCache(): void
  {
    try {
      // 删除所有baas_api_ratelimit开头的缓存项
      $this->cache->deleteAll('baas_api_ratelimit:');
      
      $this->logger->info('Rate limit cache cleaned up');
    } catch (\Exception $e) {
      $this->logger->error('Failed to cleanup rate limit cache: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * 检查项目级别的API限流.
   *
   * @param string $project_id
   *   项目ID.
   * @param string $endpoint
   *   API端点.
   * @param int $requested_calls
   *   请求的调用次数.
   *
   * @return bool
   *   是否允许调用.
   */
  public function checkProjectRateLimit(string $project_id, string $endpoint, int $requested_calls = 1): bool
  {
    try {
      // 检查主开关是否启用
      $config = $this->configFactory->get('baas_api.settings');
      if (!$config->get('enable_rate_limiting')) {
        return true; // 限流未启用，允许所有请求
      }

      // 如果没有项目使用统计跟踪器，回退到基础限流
      if (!$this->projectUsageTracker) {
        $this->logger->debug('Project usage tracker not available, falling back to basic rate limiting');
        return $this->isAllowed("project:{$project_id}", $endpoint);
      }

      // 检查项目级别的API调用限制
      $limit_check = $this->projectUsageTracker->checkUsageLimit($project_id, 'api_calls', $requested_calls);
      
      if (!$limit_check['allowed']) {
        // 记录超限情况
        $this->logger->warning('Project API rate limit exceeded', [
          'project_id' => $project_id,
          'endpoint' => $endpoint,
          'current_usage' => $limit_check['current_usage'],
          'limit' => $limit_check['limit'],
          'requested_calls' => $requested_calls,
        ]);

        // 记录拒绝的API调用到使用统计
        try {
          $this->projectUsageTracker->recordUsage(
            $project_id,
            'api_calls_rejected',
            $requested_calls,
            [
              'endpoint' => $endpoint,
              'reason' => 'rate_limit_exceeded',
              'timestamp' => time(),
            ]
          );
        } catch (\Exception $e) {
          // 记录使用统计失败不应该影响限流检查
          $this->logger->error('Failed to record rejected API call: @error', ['@error' => $e->getMessage()]);
        }

        return false;
      }

      // 允许调用，记录使用统计
      try {
        $this->projectUsageTracker->recordUsage(
          $project_id,
          'api_calls',
          $requested_calls,
          [
            'endpoint' => $endpoint,
            'timestamp' => time(),
          ]
        );
      } catch (\Exception $e) {
        // 记录使用统计失败不应该影响API调用
        $this->logger->error('Failed to record API call usage: @error', ['@error' => $e->getMessage()]);
      }

      return true;
    } catch (\Exception $e) {
      $this->logger->error('Project rate limit check failed: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
        'endpoint' => $endpoint,
      ]);
      // 错误时默认允许，避免阻塞正常请求
      return true;
    }
  }

  /**
   * 获取项目的限流统计信息.
   *
   * @param string $project_id
   *   项目ID.
   *
   * @return array
   *   限流统计信息.
   */
  public function getProjectRateLimitStats(string $project_id): array
  {
    try {
      if (!$this->projectUsageTracker) {
        return [
          'available' => false,
          'message' => 'Project usage tracker not available',
        ];
      }

      $current_usage = $this->projectUsageTracker->getCurrentUsage($project_id, 'api_calls');
      $usage_limits = $this->projectUsageTracker->getUsageLimits($project_id);
      $api_calls_usage = $current_usage['api_calls'] ?? ['total_count' => 0];
      $api_calls_limit = $usage_limits['api_calls'] ?? 0;

      return [
        'available' => true,
        'current_usage' => $api_calls_usage['total_count'],
        'limit' => $api_calls_limit,
        'remaining' => max(0, $api_calls_limit - $api_calls_usage['total_count']),
        'usage_percentage' => $api_calls_limit > 0 ? 
          round(($api_calls_usage['total_count'] / $api_calls_limit) * 100, 2) : 0,
        'last_updated' => $api_calls_usage['last_updated'] ?? 0,
      ];
    } catch (\Exception $e) {
      $this->logger->error('Failed to get project rate limit stats: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
      ]);
      return [
        'available' => false,
        'error' => $e->getMessage(),
      ];
    }
  }

}