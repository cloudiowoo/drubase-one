<?php
/*
 * @Date: 2025-05-11 16:45:22
 * @LastEditors: cloudio cloudio.woo@gmail.com
 * @LastEditTime: 2025-05-11 16:45:22
 * @FilePath: /drubase/web/modules/custom/baas_api/src/Service/RateLimiter.php
 */

declare(strict_types=1);

namespace Drupal\baas_api\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Psr\Log\LoggerInterface;

/**
 * API速率限制服务.
 *
 * 提供API请求频率限制功能，防止过度使用API。
 */
class RateLimiter {

  /**
   * 数据库连接服务.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * 状态服务.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * 日志通道.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * 缓存服务.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected CacheBackendInterface $cache;

  /**
   * 构造函数.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接服务.
   * @param \Drupal\Core\State\StateInterface $state
   *   状态服务.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂服务.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   缓存服务.
   */
  public function __construct(
    Connection $database,
    StateInterface $state,
    LoggerChannelFactoryInterface $logger_factory,
    CacheBackendInterface $cache
  ) {
    $this->database = $database;
    $this->state = $state;
    $this->logger = $logger_factory->get('baas_api');
    $this->cache = $cache;
  }

  /**
   * 检查API请求是否超过限制.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param string $endpoint
   *   API端点.
   * @param string $method
   *   HTTP方法.
   * @param string $ip
   *   客户端IP.
   *
   * @return array
   *   检查结果，包含以下键：
   *   - allowed: 是否允许请求
   *   - limit: 限制值
   *   - remaining: 剩余请求数
   *   - reset: 重置时间（秒）
   *   - window: 时间窗口（秒）
   */
  public function checkLimit(string $tenant_id, string $endpoint, string $method, string $ip): array {
    // 检查是否启用了速率限制
    if (!$this->state->get('baas_api.rate_limiting_enabled', TRUE)) {
      return [
        'allowed' => TRUE,
        'limit' => 0,
        'remaining' => 0,
        'reset' => 0,
        'window' => 0,
      ];
    }

    // 从数据库获取该租户和端点的限制配置
    $limitConfig = $this->getLimitConfig($tenant_id, $endpoint, $method);
    if (empty($limitConfig)) {
      // 如果没有限制配置，默认允许
      return [
        'allowed' => TRUE,
        'limit' => 0,
        'remaining' => 0,
        'reset' => 0,
        'window' => 0,
      ];
    }

    $limit = (int) $limitConfig['limit'];
    $window = (int) $limitConfig['window'];
    $now = time();
    $windowStart = $now - $window;

    // 生成限制键
    $limitKey = $this->getLimitKey($tenant_id, $endpoint, $method, $ip);

    // 尝试从缓存获取当前使用计数
    $cacheKey = 'baas_api_rate_limit:' . $limitKey;
    $cacheData = $this->cache->get($cacheKey);

    if ($cacheData) {
      $usage = $cacheData->data;
    }
    else {
      // 从数据库计算使用计数
      $usage = $this->getUsageFromDatabase($tenant_id, $endpoint, $method, $ip, $windowStart);

      // 缓存结果，有效期为30秒，避免频繁查询数据库
      $this->cache->set($cacheKey, $usage, time() + 30);
    }

    // 计算剩余请求数和重置时间
    $remaining = max(0, $limit - $usage);
    $reset = $windowStart + $window - $now;

    $allowed = $usage < $limit;

    // 记录限制日志
    if (!$allowed) {
      $this->logger->warning('API请求超过限制: @tenant_id, @endpoint, @method, @ip, @usage/@limit', [
        '@tenant_id' => $tenant_id,
        '@endpoint' => $endpoint,
        '@method' => $method,
        '@ip' => $ip,
        '@usage' => $usage,
        '@limit' => $limit,
      ]);
    }

    return [
      'allowed' => $allowed,
      'limit' => $limit,
      'remaining' => $remaining,
      'reset' => $reset,
      'window' => $window,
    ];
  }

  /**
   * 获取限制配置.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param string $endpoint
   *   API端点.
   * @param string $method
   *   HTTP方法.
   *
   * @return array|null
   *   限制配置.
   */
  protected function getLimitConfig(string $tenant_id, string $endpoint, string $method): ?array {
    // 先查询精确匹配
    $limit = $this->database->select('baas_api_rate_limits', 'r')
      ->fields('r')
      ->condition('tenant_id', $tenant_id)
      ->condition('endpoint', $endpoint)
      ->condition('method', $method)
      ->condition('status', 1)
      ->execute()
      ->fetchAssoc();

    if ($limit) {
      return $limit;
    }

    // 查询方法通配符
    $limit = $this->database->select('baas_api_rate_limits', 'r')
      ->fields('r')
      ->condition('tenant_id', $tenant_id)
      ->condition('endpoint', $endpoint)
      ->condition('method', '*')
      ->condition('status', 1)
      ->execute()
      ->fetchAssoc();

    if ($limit) {
      return $limit;
    }

    // 查询端点通配符
    $limit = $this->database->select('baas_api_rate_limits', 'r')
      ->fields('r')
      ->condition('tenant_id', $tenant_id)
      ->condition('endpoint', '*')
      ->condition('method', $method)
      ->condition('status', 1)
      ->execute()
      ->fetchAssoc();

    if ($limit) {
      return $limit;
    }

    // 查询租户全局限制
    $limit = $this->database->select('baas_api_rate_limits', 'r')
      ->fields('r')
      ->condition('tenant_id', $tenant_id)
      ->condition('endpoint', '*')
      ->condition('method', '*')
      ->condition('status', 1)
      ->execute()
      ->fetchAssoc();

    if ($limit) {
      return $limit;
    }

    // 查询全局默认限制
    return $this->database->select('baas_api_rate_limits', 'r')
      ->fields('r')
      ->condition('tenant_id', '*')
      ->condition('endpoint', '*')
      ->condition('method', '*')
      ->condition('status', 1)
      ->execute()
      ->fetchAssoc();
  }

  /**
   * 从数据库获取使用计数.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param string $endpoint
   *   API端点.
   * @param string $method
   *   HTTP方法.
   * @param string $ip
   *   客户端IP.
   * @param int $windowStart
   *   时间窗口开始时间.
   *
   * @return int
   *   使用计数.
   */
  protected function getUsageFromDatabase(string $tenant_id, string $endpoint, string $method, string $ip, int $windowStart): int {
    // 检查跟踪表中是否有记录
    $query = $this->database->select('baas_api_rate_tracking', 'r');
    $query->fields('r', ['counter']);
    $query->condition('tenant_id', $tenant_id)
      ->condition('endpoint', $endpoint)
      ->condition('ip_address', $ip)
      ->condition('window_start', $windowStart, '>=');

    $result = $query->execute()->fetchAssoc();

    if ($result) {
      return (int) $result['counter'];
    }

    // 如果没有记录，从请求表中统计
    $query = $this->database->select('baas_api_requests', 'r');
    $query->addExpression('COUNT(*)', 'count');
    $query->condition('tenant_id', $tenant_id)
      ->condition('endpoint', $endpoint)
      ->condition('method', $method)
      ->condition('ip_address', $ip)
      ->condition('request_time', $windowStart, '>=');

    $count = $query->execute()->fetchField();

    // 修复：确保使用关联数组而不是索引数组作为key
    $this->database->merge('baas_api_rate_tracking')
      ->keys([
        'tenant_id' => $tenant_id,
        'endpoint' => $endpoint,
        'ip_address' => $ip,
        'method' => $method,
      ])
      ->fields([
        'counter' => $count,
        'window_start' => $windowStart,
      ])
      ->execute();

    return (int) $count;
  }

  /**
   * 生成限制键.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param string $endpoint
   *   API端点.
   * @param string $method
   *   HTTP方法.
   * @param string $ip
   *   客户端IP.
   *
   * @return string
   *   限制键.
   */
  protected function getLimitKey(string $tenant_id, string $endpoint, string $method, string $ip): string {
    return md5($tenant_id . '|' . $endpoint . '|' . $method . '|' . $ip);
  }

  /**
   * 清理过期的限制缓存.
   */
  public function cleanupCache(): void {
    $this->cache->deleteAll();
    $this->logger->notice('已清理API速率限制缓存');
  }

}
