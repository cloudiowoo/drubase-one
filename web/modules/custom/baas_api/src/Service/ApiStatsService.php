<?php

declare(strict_types=1);

namespace Drupal\baas_api\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * API统计服务.
 *
 * 负责记录和查询API调用统计信息。
 */
class ApiStatsService
{

  /**
   * Logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * 构造函数.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   日志工厂.
   */
  public function __construct(
    protected Connection $database,
    protected LoggerChannelFactoryInterface $loggerFactory
  ) {
    $this->logger = $loggerFactory->get('baas_api_stats');
  }

  /**
   * 记录API调用.
   *
   * @param string $endpoint
   *   API端点.
   * @param int $status_code
   *   HTTP状态码.
   * @param float $response_time
   *   响应时间（毫秒）.
   * @param string|null $user_id
   *   用户ID.
   * @param string|null $tenant_id
   *   租户ID.
   */
  public function recordCall(
    string $endpoint,
    int $status_code,
    float $response_time,
    ?string $user_id = null,
    ?string $tenant_id = null
  ): void {
    try {
      $this->database->insert('baas_api_stats')
        ->fields([
          'endpoint' => $endpoint,
          'status_code' => $status_code,
          'response_time' => $response_time,
          'user_id' => $user_id,
          'tenant_id' => $tenant_id,
          'timestamp' => time(),
          'date' => date('Y-m-d'),
          'hour' => (int) date('H'),
        ])
        ->execute();
    } catch (\Exception $e) {
      $this->logger->error('Failed to record API stats: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * 获取API统计概览.
   *
   * @param string $period
   *   统计周期（today, week, month）.
   * @param string|null $tenant_id
   *   租户ID筛选.
   *
   * @return array
   *   统计数据.
   */
  public function getOverview(string $period = 'today', ?string $tenant_id = null): array
  {
    $query = $this->database->select('baas_api_stats', 's');
    
    // 根据周期添加条件
    switch ($period) {
      case 'today':
        $query->condition('date', date('Y-m-d'));
        break;
      case 'week':
        $query->condition('timestamp', strtotime('-7 days'), '>=');
        break;
      case 'month':
        $query->condition('timestamp', strtotime('-30 days'), '>=');
        break;
    }

    // 租户筛选
    if ($tenant_id) {
      $query->condition('tenant_id', $tenant_id);
    }

    // 基础统计
    $query->addExpression('COUNT(*)', 'total_calls');
    $query->addExpression('COUNT(DISTINCT endpoint)', 'unique_endpoints');
    $query->addExpression('AVG(response_time)', 'avg_response_time');
    $query->addExpression('MAX(response_time)', 'max_response_time');
    $query->addExpression('COUNT(DISTINCT user_id)', 'unique_users');

    $result = $query->execute()->fetchAssoc();

    // 错误率统计
    $error_query = clone $query;
    $error_query->condition('status_code', 400, '>=');
    $error_count = $error_query->countQuery()->execute()->fetchField();

    $result['error_count'] = (int) $error_count;
    $result['error_rate'] = $result['total_calls'] > 0 ? 
      round(($error_count / $result['total_calls']) * 100, 2) : 0;

    return [
      'total_calls' => (int) $result['total_calls'],
      'unique_endpoints' => (int) $result['unique_endpoints'],
      'unique_users' => (int) $result['unique_users'],
      'avg_response_time' => round((float) $result['avg_response_time'], 2),
      'max_response_time' => round((float) $result['max_response_time'], 2),
      'error_count' => $result['error_count'],
      'error_rate' => $result['error_rate'],
      'period' => $period,
    ];
  }

  /**
   * 获取热门端点统计.
   *
   * @param string $period
   *   统计周期.
   * @param int $limit
   *   返回数量限制.
   * @param string|null $tenant_id
   *   租户ID筛选.
   *
   * @return array
   *   热门端点列表.
   */
  public function getTopEndpoints(string $period = 'today', int $limit = 10, ?string $tenant_id = null): array
  {
    $query = $this->database->select('baas_api_stats', 's')
      ->fields('s', ['endpoint'])
      ->groupBy('endpoint')
      ->orderBy('call_count', 'DESC')
      ->range(0, $limit);

    $query->addExpression('COUNT(*)', 'call_count');
    $query->addExpression('AVG(response_time)', 'avg_response_time');
    $query->addExpression('COUNT(CASE WHEN status_code >= 400 THEN 1 END)', 'error_count');

    // 根据周期添加条件
    switch ($period) {
      case 'today':
        $query->condition('date', date('Y-m-d'));
        break;
      case 'week':
        $query->condition('timestamp', strtotime('-7 days'), '>=');
        break;
      case 'month':
        $query->condition('timestamp', strtotime('-30 days'), '>=');
        break;
    }

    if ($tenant_id) {
      $query->condition('tenant_id', $tenant_id);
    }

    $results = $query->execute()->fetchAll();

    return array_map(function ($row) {
      return [
        'endpoint' => $row->endpoint,
        'call_count' => (int) $row->call_count,
        'avg_response_time' => round((float) $row->avg_response_time, 2),
        'error_count' => (int) $row->error_count,
        'error_rate' => $row->call_count > 0 ? 
          round(($row->error_count / $row->call_count) * 100, 2) : 0,
      ];
    }, $results);
  }

  /**
   * 获取时间序列统计.
   *
   * @param string $period
   *   统计周期.
   * @param string $granularity
   *   时间粒度（hour, day）.
   * @param string|null $tenant_id
   *   租户ID筛选.
   *
   * @return array
   *   时间序列数据.
   */
  public function getTimeSeries(string $period = 'today', string $granularity = 'hour', ?string $tenant_id = null): array
  {
    $query = $this->database->select('baas_api_stats', 's');

    // 根据粒度设置分组字段
    if ($granularity === 'hour') {
      $query->addExpression('CONCAT(date, " ", LPAD(hour, 2, "0"), ":00")', 'time_label');
      $query->addExpression('CONCAT(date, " ", hour)', 'time_key');
      $query->groupBy('date, hour');
    } else {
      $query->addExpression('date', 'time_label');
      $query->addExpression('date', 'time_key');
      $query->groupBy('date');
    }

    $query->addExpression('COUNT(*)', 'call_count');
    $query->addExpression('AVG(response_time)', 'avg_response_time');
    $query->addExpression('COUNT(CASE WHEN status_code >= 400 THEN 1 END)', 'error_count');

    // 根据周期添加条件
    switch ($period) {
      case 'today':
        $query->condition('date', date('Y-m-d'));
        break;
      case 'week':
        $query->condition('timestamp', strtotime('-7 days'), '>=');
        break;
      case 'month':
        $query->condition('timestamp', strtotime('-30 days'), '>=');
        break;
    }

    if ($tenant_id) {
      $query->condition('tenant_id', $tenant_id);
    }

    $query->orderBy('time_key');

    $results = $query->execute()->fetchAll();

    return array_map(function ($row) {
      return [
        'time' => $row->time_label,
        'call_count' => (int) $row->call_count,
        'avg_response_time' => round((float) $row->avg_response_time, 2),
        'error_count' => (int) $row->error_count,
        'error_rate' => $row->call_count > 0 ? 
          round(($row->error_count / $row->call_count) * 100, 2) : 0,
      ];
    }, $results);
  }

  /**
   * 获取租户API使用排行.
   *
   * @param string $period
   *   统计周期.
   * @param int $limit
   *   返回数量限制.
   *
   * @return array
   *   租户使用排行.
   */
  public function getTenantUsage(string $period = 'today', int $limit = 10): array
  {
    $query = $this->database->select('baas_api_stats', 's')
      ->fields('s', ['tenant_id'])
      ->groupBy('tenant_id')
      ->orderBy('call_count', 'DESC')
      ->range(0, $limit)
      ->isNotNull('tenant_id');

    $query->addExpression('COUNT(*)', 'call_count');
    $query->addExpression('AVG(response_time)', 'avg_response_time');
    $query->addExpression('COUNT(DISTINCT endpoint)', 'unique_endpoints');
    $query->addExpression('COUNT(CASE WHEN status_code >= 400 THEN 1 END)', 'error_count');

    // 根据周期添加条件
    switch ($period) {
      case 'today':
        $query->condition('date', date('Y-m-d'));
        break;
      case 'week':
        $query->condition('timestamp', strtotime('-7 days'), '>=');
        break;
      case 'month':
        $query->condition('timestamp', strtotime('-30 days'), '>=');
        break;
    }

    $results = $query->execute()->fetchAll();

    return array_map(function ($row) {
      return [
        'tenant_id' => $row->tenant_id,
        'call_count' => (int) $row->call_count,
        'unique_endpoints' => (int) $row->unique_endpoints,
        'avg_response_time' => round((float) $row->avg_response_time, 2),
        'error_count' => (int) $row->error_count,
        'error_rate' => $row->call_count > 0 ? 
          round(($row->error_count / $row->call_count) * 100, 2) : 0,
      ];
    }, $results);
  }

  /**
   * 清理过期统计数据.
   *
   * @param int $days
   *   保留天数.
   *
   * @return int
   *   删除的记录数.
   */
  public function cleanupOldStats(int $days = 90): int
  {
    try {
      $cutoff_timestamp = strtotime("-{$days} days");
      
      $deleted = $this->database->delete('baas_api_stats')
        ->condition('timestamp', $cutoff_timestamp, '<')
        ->execute();

      $this->logger->info('Cleaned up @count old API stats records older than @days days', [
        '@count' => $deleted,
        '@days' => $days,
      ]);

      return $deleted;
    } catch (\Exception $e) {
      $this->logger->error('Failed to cleanup old stats: @error', ['@error' => $e->getMessage()]);
      return 0;
    }
  }

}