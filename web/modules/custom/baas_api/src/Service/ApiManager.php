<?php
/*
 * @Date: 2025-05-11 16:30:22
 * @LastEditors: cloudio cloudio.woo@gmail.com
 * @LastEditTime: 2025-05-11 16:30:22
 * @FilePath: /drubase/web/modules/custom/baas_api/src/Service/ApiManager.php
 */

declare(strict_types=1);

namespace Drupal\baas_api\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\baas_tenant\TenantManagerInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\HttpFoundation\Request;
use Psr\Log\LoggerInterface;

/**
 * API管理器服务.
 *
 * 提供API配置、请求记录和统计功能。
 */
class ApiManager {

  /**
   * 数据库连接服务.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * 配置工厂服务.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * 日志通道.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * 租户管理服务.
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected TenantManagerInterface $tenantManager;

  /**
   * 状态服务.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * 构造函数.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接服务.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   配置工厂服务.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂服务.
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理服务.
   * @param \Drupal\Core\State\StateInterface $state
   *   状态服务.
   */
  public function __construct(
    Connection $database,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    TenantManagerInterface $tenant_manager,
    StateInterface $state
  ) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('baas_api');
    $this->tenantManager = $tenant_manager;
    $this->state = $state;
  }

  /**
   * 记录API请求.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param string $endpoint
   *   API端点.
   * @param string $method
   *   HTTP方法.
   * @param int $status_code
   *   HTTP状态码.
   * @param float $request_time
   *   请求处理时间（毫秒）.
   * @param string $ip
   *   客户端IP.
   * @param string|null $user_agent
   *   用户代理.
   *
   * @return bool
   *   是否成功记录.
   */
  public function logApiRequest(string $tenant_id, string $endpoint, string $method, int $status_code, float $request_time, string $ip, ?string $user_agent = NULL): bool {
    try {
      // 记录详细请求日志
      $this->database->insert('baas_api_requests')
        ->fields([
          'tenant_id' => $tenant_id,
          'endpoint' => $endpoint,
          'method' => $method,
          'status_code' => $status_code,
          'execution_time' => $request_time,
          'ip_address' => $ip,
          'user_agent' => $user_agent,
          'request_time' => time(),
        ])
        ->execute();

      // 更新日统计数据
      $date = date('Y-m-d');
      $is_success = $status_code >= 200 && $status_code < 300;
      $is_error = $status_code >= 400;

      // 检查记录是否存在
      $exists = $this->database->select('baas_api_stats')
        ->fields('baas_api_stats', ['id'])
        ->condition('tenant_id', $tenant_id)
        ->condition('date', $date)
        ->condition('endpoint', $endpoint)
        ->condition('method', $method)
        ->execute()
        ->fetchField();

      if ($exists) {
        // 更新现有记录
        $this->database->update('baas_api_stats')
          ->expression('request_count', 'request_count + 1')
          ->expression('success_count', 'success_count + :success', [':success' => $is_success ? 1 : 0])
          ->expression('error_count', 'error_count + :error', [':error' => $is_error ? 1 : 0])
          ->expression('total_time', 'total_time + :time', [':time' => $request_time])
          ->condition('id', $exists)
          ->execute();
      } else {
        // 插入新记录
        $this->database->insert('baas_api_stats')
          ->fields([
          'tenant_id' => $tenant_id,
          'date' => $date,
          'endpoint' => $endpoint,
          'method' => $method,
          'request_count' => 1,
          'success_count' => $is_success ? 1 : 0,
          'error_count' => $is_error ? 1 : 0,
          'total_time' => $request_time,
        ])
        ->execute();
      }

      // 记录租户API调用次数（用于资源限制）
      if (!empty($tenant_id) && $tenant_id !== '*') {
        $this->tenantManager->recordUsage($tenant_id, 'api_call', 1);
      }

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('记录API请求失败: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 从请求中提取API信息.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象.
   *
   * @return array
   *   API信息数组，包含endpoint、method、tenant_id等.
   */
  public function extractApiInfo(Request $request): array {
    $info = [
      'endpoint' => $request->getPathInfo(),
      'method' => $request->getMethod(),
      'tenant_id' => '*', // 默认为全局
      'ip' => $request->getClientIp(),
      'user_agent' => $request->headers->get('User-Agent'),
      'start_time' => microtime(TRUE),
    ];

    // 尝试从请求属性获取租户ID
    $tenant = $request->attributes->get('_tenant');
    if ($tenant) {
      $info['tenant_id'] = is_array($tenant) ? $tenant['tenant_id'] : $tenant->id();
    }
    // 尝试从路径参数获取租户ID
    elseif ($request->attributes->has('tenant_id')) {
      $tenant_id = $request->attributes->get('tenant_id');
      if ($tenant_id && $tenant_id !== '*') {
        $info['tenant_id'] = $tenant_id;
      }
    }

    return $info;
  }

  /**
   * 获取API使用统计数据.
   *
   * @param array $filters
   *   过滤条件.
   *
   * @return array
   *   统计数据.
   */
  public function getApiStats(array $filters = []): array {
    $query = $this->database->select('baas_api_stats', 's')
      ->fields('s');

    // 应用过滤器
    if (!empty($filters['tenant_id'])) {
      $query->condition('tenant_id', $filters['tenant_id']);
    }
    if (!empty($filters['date_from'])) {
      $query->condition('date', $filters['date_from'], '>=');
    }
    if (!empty($filters['date_to'])) {
      $query->condition('date', $filters['date_to'], '<=');
    }
    if (!empty($filters['endpoint'])) {
      $query->condition('endpoint', '%' . $this->database->escapeLike($filters['endpoint']) . '%', 'LIKE');
    }
    if (!empty($filters['method'])) {
      $query->condition('method', $filters['method']);
    }

    // 分页
    $page = $filters['page'] ?? 0;
    $limit = $filters['limit'] ?? 50;
    $query->range($page * $limit, $limit);

    // 排序
    $order_by = $filters['order_by'] ?? 'date';
    $direction = $filters['direction'] ?? 'DESC';
    $query->orderBy($order_by, $direction);

    // 执行查询
    $result = $query->execute()->fetchAll();

    // 获取总记录数
    $count_query = $this->database->select('baas_api_stats', 's');
    $count_query->addExpression('COUNT(*)');
    // 应用相同的过滤器
    if (!empty($filters['tenant_id'])) {
      $count_query->condition('tenant_id', $filters['tenant_id']);
    }
    if (!empty($filters['date_from'])) {
      $count_query->condition('date', $filters['date_from'], '>=');
    }
    if (!empty($filters['date_to'])) {
      $count_query->condition('date', $filters['date_to'], '<=');
    }
    if (!empty($filters['endpoint'])) {
      $count_query->condition('endpoint', '%' . $this->database->escapeLike($filters['endpoint']) . '%', 'LIKE');
    }
    if (!empty($filters['method'])) {
      $count_query->condition('method', $filters['method']);
    }
    $total = $count_query->execute()->fetchField();

    return [
      'stats' => $result,
      'total' => (int) $total,
      'page' => $page,
      'limit' => $limit,
    ];
  }

  /**
   * 获取API限制配置.
   *
   * @param string $tenant_id
   *   租户ID，默认为全局.
   * @param string $endpoint
   *   API端点，默认为全部.
   * @param string $method
   *   HTTP方法，默认为全部.
   *
   * @return array
   *   限制配置.
   */
  public function getRateLimit(string $tenant_id = '*', string $endpoint = '*', string $method = '*'): array {
    // 尝试获取指定的精确匹配限制
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

    // 尝试获取方法通配符限制
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

    // 尝试获取端点通配符限制
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

    // 尝试获取租户全局限制
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

    // 获取全局默认限制
    $limit = $this->database->select('baas_api_rate_limits', 'r')
      ->fields('r')
      ->condition('tenant_id', '*')
      ->condition('endpoint', '*')
      ->condition('method', '*')
      ->condition('status', 1)
      ->execute()
      ->fetchAssoc();

    // 返回默认限制或空配置
    return $limit ?: [
      'tenant_id' => '*',
      'endpoint' => '*',
      'method' => '*',
      'limit' => 1000,
      'window' => 3600,
      'status' => 1,
    ];
  }

  /**
   * 更新API限制配置.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param string $endpoint
   *   API端点.
   * @param string $method
   *   HTTP方法.
   * @param int $limit
   *   请求限制.
   * @param int $window
   *   时间窗口.
   * @param int $status
   *   状态.
   *
   * @return int|bool
   *   更新结果.
   */
  public function updateRateLimit(string $tenant_id, string $endpoint, string $method, int $limit, int $window, int $status = 1) {
    return $this->database->merge('baas_api_rate_limits')
      ->key([
        'tenant_id' => $tenant_id,
        'endpoint' => $endpoint,
        'method' => $method,
      ])
      ->fields([
        'limit' => $limit,
        'window' => $window,
        'status' => $status,
      ])
      ->execute();
  }

  /**
   * 清理过期的API请求记录.
   *
   * @param int $days
   *   保留天数.
   *
   * @return int
   *   清理的记录数.
   */
  public function cleanupRequestLogs(int $days = 30): int {
    $timestamp = time() - ($days * 86400);
    $count = $this->database->delete('baas_api_requests')
      ->condition('timestamp', $timestamp, '<')
      ->execute();

    $this->logger->notice('已清理 @count 条过期API请求记录', [
      '@count' => $count,
    ]);

    return $count;
  }

  /**
   * 清理过期的API统计数据.
   *
   * @param int $days
   *   保留天数.
   *
   * @return int
   *   清理的记录数.
   */
  public function cleanupStats(int $days = 365): int {
    $date = date('Y-m-d', time() - ($days * 86400));
    $count = $this->database->delete('baas_api_stats')
      ->condition('date', $date, '<')
      ->execute();

    $this->logger->notice('已清理 @count 条过期API统计记录', [
      '@count' => $count,
    ]);

    return $count;
  }

}
