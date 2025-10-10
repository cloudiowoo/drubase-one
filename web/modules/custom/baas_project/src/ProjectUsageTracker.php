<?php

declare(strict_types=1);

namespace Drupal\baas_project;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\baas_project\Event\ProjectEvent;
use Drupal\baas_project\Exception\ProjectException;

/**
 * 项目使用统计跟踪器实现。
 *
 * 提供完整的项目资源使用监控功能。
 */
class ProjectUsageTracker implements ProjectUsageTrackerInterface {

  protected readonly LoggerChannelInterface $logger;

  /**
   * 可用资源类型定义。
   */
  protected const RESOURCE_TYPES = [
    'entity_templates' => [
      'label' => 'Entity Templates',
      'description' => 'Number of entity templates created',
      'unit' => 'count',
      'default_limit' => 100,
    ],
    'entity_instances' => [
      'label' => 'Entity Instances',
      'description' => 'Number of entity instances created',
      'unit' => 'count',
      'default_limit' => 10000,
    ],
    'api_calls' => [
      'label' => 'API Calls',
      'description' => 'Number of API calls made',
      'unit' => 'count',
      'default_limit' => 100000,
    ],
    'storage_used' => [
      'label' => 'Storage Used',
      'description' => 'Amount of storage used',
      'unit' => 'bytes',
      'default_limit' => 1073741824, // 1GB
    ],
    'bandwidth_used' => [
      'label' => 'Bandwidth Used',
      'description' => 'Amount of bandwidth used',
      'unit' => 'bytes',
      'default_limit' => 10737418240, // 10GB
    ],
    'database_queries' => [
      'label' => 'Database Queries',
      'description' => 'Number of database queries executed',
      'unit' => 'count',
      'default_limit' => 1000000,
    ],
    'file_uploads' => [
      'label' => 'File Uploads',
      'description' => 'Number of files uploaded',
      'unit' => 'count',
      'default_limit' => 1000,
    ],
    'webhook_calls' => [
      'label' => 'Webhook Calls',
      'description' => 'Number of webhook calls made',
      'unit' => 'count',
      'default_limit' => 10000,
    ],
  ];

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly CacheBackendInterface $cache,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EventDispatcherInterface $eventDispatcher,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('baas_project_usage');
  }

  /**
   * {@inheritdoc}
   */
  public function recordUsage(string $project_id, string $resource_type, int $count = 1, array $metadata = []): bool {
    try {
      // 验证资源类型
      if (!$this->isValidResourceType($resource_type)) {
        throw new ProjectException(
          "Invalid resource type: {$resource_type}",
          ProjectException::INVALID_PROJECT_DATA,
          null,
          ['resource_type' => $resource_type]
        );
      }

      // 检查使用限制
      $limit_check = $this->checkUsageLimit($project_id, $resource_type, $count);
      if (!$limit_check['allowed']) {
        throw new ProjectException(
          $limit_check['message'],
          ProjectException::QUOTA_EXCEEDED,
          null,
          $limit_check
        );
      }

      $current_date = date('Y-m-d');
      $timestamp = time();

      // 开始事务
      $transaction = $this->database->startTransaction();
      
      try {
        // 检查今天是否已有记录
        $existing_record = $this->database->select('baas_project_usage', 'u')
          ->fields('u', ['id', 'count'])
          ->condition('project_id', $project_id)
          ->condition('resource_type', $resource_type)
          ->condition('date', $current_date)
          ->execute()
          ->fetchObject();

        if ($existing_record) {
          // 更新现有记录
          $this->database->update('baas_project_usage')
            ->fields([
              'count' => $existing_record->count + $count,
              'metadata' => json_encode(array_merge(
                json_decode($existing_record->metadata ?? '{}', true),
                $metadata
              )),
              'updated_at' => $timestamp,
            ])
            ->condition('id', $existing_record->id)
            ->execute();
        } else {
          // 创建新记录
          $this->database->insert('baas_project_usage')
            ->fields([
              'project_id' => $project_id,
              'resource_type' => $resource_type,
              'date' => $current_date,
              'count' => $count,
              'metadata' => json_encode($metadata),
              'created_at' => $timestamp,
              'updated_at' => $timestamp,
            ])
            ->execute();
        }

        // 清除相关缓存
        $this->clearUsageCache($project_id, $resource_type);

        // 检查是否需要触发警告
        $alerts = $this->checkUsageAlerts($project_id);
        if (!empty($alerts)) {
          $event = new ProjectEvent($project_id, [
            'resource_type' => $resource_type,
            'count' => $count,
            'alerts' => $alerts,
          ]);
          $this->eventDispatcher->dispatch($event, ProjectEvent::USAGE_ALERT);
        }

        $this->logger->debug('Recorded usage for project @project_id: @resource_type = @count', [
          '@project_id' => $project_id,
          '@resource_type' => $resource_type,
          '@count' => $count,
        ]);

        return true;
      }
      catch (\Exception $e) {
        $transaction->rollBack();
        throw $e;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to record usage for project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUsageStats(string $project_id, array $filters = [], array $options = []): array {
    try {
      $query = $this->database->select('baas_project_usage', 'u')
        ->fields('u')
        ->condition('project_id', $project_id);

      // 应用过滤条件
      if (!empty($filters['resource_type'])) {
        if (is_array($filters['resource_type'])) {
          $query->condition('resource_type', $filters['resource_type'], 'IN');
        } else {
          $query->condition('resource_type', $filters['resource_type']);
        }
      }

      if (!empty($filters['date_from'])) {
        $query->condition('date', $filters['date_from'], '>=');
      }

      if (!empty($filters['date_to'])) {
        $query->condition('date', $filters['date_to'], '<=');
      }

      // 排序
      $sort_field = $options['sort'] ?? 'date';
      $sort_direction = $options['direction'] ?? 'DESC';
      $query->orderBy($sort_field, $sort_direction);

      // 分页
      if (!empty($options['limit'])) {
        $query->range($options['offset'] ?? 0, $options['limit']);
      }

      $results = $query->execute()->fetchAll();

      // 格式化结果
      $stats = [];
      foreach ($results as $row) {
        $stats[] = [
          'id' => (int) $row->id,
          'project_id' => $row->project_id,
          'resource_type' => $row->resource_type,
          'resource_label' => self::RESOURCE_TYPES[$row->resource_type]['label'] ?? $row->resource_type,
          'date' => $row->date,
          'count' => (int) $row->count,
          'metadata' => json_decode($row->metadata ?? '{}', true),
          'created_at' => (int) $row->created_at,
          'updated_at' => (int) $row->updated_at,
        ];
      }

      return $stats;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get usage stats for project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentUsage(string $project_id, ?string $resource_type = null): array {
    try {
      $cache_key = "baas_project:current_usage:{$project_id}" . ($resource_type ? ":{$resource_type}" : '');
      $cached = $this->cache->get($cache_key);
      
      if ($cached && $cached->valid) {
        return $cached->data;
      }

      $query = $this->database->select('baas_project_usage', 'u')
        ->fields('u', ['resource_type'])
        ->condition('project_id', $project_id);
      
      $query->addExpression('SUM(count)', 'total_count');
      $query->addExpression('MAX(updated_at)', 'last_updated');
      $query->groupBy('resource_type');

      if ($resource_type) {
        $query->condition('resource_type', $resource_type);
      }

      $results = $query->execute()->fetchAll();

      $usage = [];
      foreach ($results as $row) {
        $resource_config = self::RESOURCE_TYPES[$row->resource_type] ?? [];
        $usage[$row->resource_type] = [
          'resource_type' => $row->resource_type,
          'resource_label' => $resource_config['label'] ?? $row->resource_type,
          'total_count' => (int) $row->total_count,
          'unit' => $resource_config['unit'] ?? 'count',
          'last_updated' => (int) $row->last_updated,
        ];
      }

      // 如果指定了资源类型但没有使用记录，返回零值
      if ($resource_type && !isset($usage[$resource_type])) {
        $resource_config = self::RESOURCE_TYPES[$resource_type] ?? [];
        $usage[$resource_type] = [
          'resource_type' => $resource_type,
          'resource_label' => $resource_config['label'] ?? $resource_type,
          'total_count' => 0,
          'unit' => $resource_config['unit'] ?? 'count',
          'last_updated' => 0,
        ];
      }

      $this->cache->set($cache_key, $usage, time() + 300); // 缓存5分钟
      return $usage;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get current usage for project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUsageTrend(string $project_id, string $period = 'daily', int $days = 30, array $options = []): array {
    try {
      $end_date = date('Y-m-d');
      $start_date = date('Y-m-d', strtotime("-{$days} days"));

      $query = $this->database->select('baas_project_usage', 'u')
        ->fields('u', ['resource_type', 'date'])
        ->condition('project_id', $project_id)
        ->condition('date', $start_date, '>=')
        ->condition('date', $end_date, '<=');
      
      $query->addExpression('SUM(count)', 'total_count');
      
      // 根据周期进行分组
      switch ($period) {
        case 'weekly':
          $query->addExpression('YEARWEEK(date)', 'period_key');
          $query->groupBy('YEARWEEK(date)');
          break;
        case 'monthly':
          $query->addExpression('DATE_FORMAT(date, "%Y-%m")', 'period_key');
          $query->groupBy('DATE_FORMAT(date, "%Y-%m")');
          break;
        default: // daily
          $query->addExpression('date', 'period_key');
          $query->groupBy('date');
      }
      
      $query->groupBy('resource_type');
      $query->orderBy('date', 'ASC');

      $results = $query->execute()->fetchAll();

      // 组织趋势数据
      $trends = [];
      foreach ($results as $row) {
        $resource_type = $row->resource_type;
        $period_key = $row->period_key;
        
        if (!isset($trends[$resource_type])) {
          $resource_config = self::RESOURCE_TYPES[$resource_type] ?? [];
          $trends[$resource_type] = [
            'resource_type' => $resource_type,
            'resource_label' => $resource_config['label'] ?? $resource_type,
            'unit' => $resource_config['unit'] ?? 'count',
            'data' => [],
          ];
        }
        
        $trends[$resource_type]['data'][] = [
          'period' => $period_key,
          'count' => (int) $row->total_count,
          'date' => $row->date,
        ];
      }

      return $trends;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get usage trend for project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkUsageLimit(string $project_id, string $resource_type, int $requested_count = 1): array {
    try {
      // 获取项目使用限制
      $limits = $this->getUsageLimits($project_id);
      $resource_limit = $limits[$resource_type] ?? null;
      
      if (!$resource_limit) {
        // 如果没有设置限制，使用默认限制
        $resource_limit = self::RESOURCE_TYPES[$resource_type]['default_limit'] ?? PHP_INT_MAX;
      }

      // 获取当前使用量
      $current_usage = $this->getCurrentUsage($project_id, $resource_type);
      $current_count = $current_usage[$resource_type]['total_count'] ?? 0;
      
      $new_total = $current_count + $requested_count;
      $allowed = $new_total <= $resource_limit;
      
      $result = [
        'allowed' => $allowed,
        'current_usage' => $current_count,
        'requested_count' => $requested_count,
        'new_total' => $new_total,
        'limit' => $resource_limit,
        'remaining' => max(0, $resource_limit - $current_count),
        'usage_percentage' => $resource_limit > 0 ? round(($current_count / $resource_limit) * 100, 2) : 0,
      ];
      
      if (!$allowed) {
        $result['message'] = "Usage limit exceeded for {$resource_type}. Current: {$current_count}, Requested: {$requested_count}, Limit: {$resource_limit}";
      }
      
      return $result;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to check usage limit for project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return ['allowed' => false, 'message' => 'Error checking usage limit'];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUsageLimits(string $project_id): array {
    try {
      $cache_key = "baas_project:usage_limits:{$project_id}";
      $cached = $this->cache->get($cache_key);
      
      if ($cached && $cached->valid) {
        return $cached->data;
      }

      // 从项目配置中获取限制
      $project_settings = $this->database->select('baas_project_config', 'p')
        ->fields('p', ['settings'])
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchField();
      
      $settings = json_decode($project_settings ?? '{}', true);
      $limits = $settings['usage_limits'] ?? [];
      
      // 合并默认限制
      foreach (self::RESOURCE_TYPES as $type => $config) {
        if (!isset($limits[$type])) {
          $limits[$type] = $config['default_limit'];
        }
      }
      
      $this->cache->set($cache_key, $limits, time() + 3600); // 缓存1小时
      return $limits;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get usage limits for project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setUsageLimits(string $project_id, array $limits): bool {
    try {
      // 验证限制值
      foreach ($limits as $resource_type => $limit) {
        if (!$this->isValidResourceType($resource_type)) {
          throw new ProjectException(
            "Invalid resource type: {$resource_type}",
            ProjectException::INVALID_PROJECT_DATA
          );
        }
        
        if (!is_numeric($limit) || $limit < 0) {
          throw new ProjectException(
            "Invalid limit value for {$resource_type}: {$limit}",
            ProjectException::INVALID_PROJECT_DATA
          );
        }
      }

      // 获取当前项目设置
      $current_settings = $this->database->select('baas_project_config', 'p')
        ->fields('p', ['settings'])
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchField();
      
      $settings = json_decode($current_settings ?? '{}', true);
      $settings['usage_limits'] = array_merge($settings['usage_limits'] ?? [], $limits);
      
      // 更新项目设置
      $affected_rows = $this->database->update('baas_project_config')
        ->fields([
          'settings' => json_encode($settings),
          'updated' => time(),
        ])
        ->condition('project_id', $project_id)
        ->execute();
      
      if ($affected_rows > 0) {
        // 清除缓存
        $this->cache->delete("baas_project:usage_limits:{$project_id}");
        
        $this->logger->info('Updated usage limits for project @project_id', [
          '@project_id' => $project_id,
        ]);
        
        return true;
      }
      
      return false;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to set usage limits for project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function resetUsage(string $project_id, ?string $resource_type = null, array $options = []): bool {
    try {
      $query = $this->database->delete('baas_project_usage')
        ->condition('project_id', $project_id);
      
      if ($resource_type) {
        $query->condition('resource_type', $resource_type);
      }
      
      // 可选择只重置特定日期范围的数据
      if (!empty($options['date_from'])) {
        $query->condition('date', $options['date_from'], '>=');
      }
      
      if (!empty($options['date_to'])) {
        $query->condition('date', $options['date_to'], '<=');
      }
      
      $affected_rows = $query->execute();
      
      // 清除相关缓存
      $this->clearUsageCache($project_id, $resource_type);
      
      $this->logger->info('Reset usage for project @project_id (@resource_type): @rows rows affected', [
        '@project_id' => $project_id,
        '@resource_type' => $resource_type ?? 'all',
        '@rows' => $affected_rows,
      ]);
      
      return $affected_rows > 0;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to reset usage for project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function recordBatchUsage(array $usage_records): array {
    $results = [
      'success' => [],
      'failed' => [],
      'total' => count($usage_records),
    ];

    foreach ($usage_records as $index => $record) {
      try {
        $project_id = $record['project_id'];
        $resource_type = $record['resource_type'];
        $count = $record['count'] ?? 1;
        $metadata = $record['metadata'] ?? [];
        
        $this->recordUsage($project_id, $resource_type, $count, $metadata);
        $results['success'][] = $index;
      }
      catch (\Exception $e) {
        $results['failed'][] = [
          'index' => $index,
          'record' => $record,
          'error' => $e->getMessage(),
        ];
      }
    }

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function getTenantUsageStats(string $tenant_id, array $filters = [], array $options = []): array {
    try {
      $query = $this->database->select('baas_project_usage', 'u')
        ->fields('u', ['resource_type']);
      $query->addExpression('SUM(count)', 'total_count');
      $query->addExpression('COUNT(DISTINCT project_id)', 'project_count');
      
      // 关联项目表以过滤租户
      $query->leftJoin('baas_project_config', 'p', 'u.project_id = p.project_id');
      $query->condition('p.tenant_id', $tenant_id);
      $query->groupBy('u.resource_type');
      
      // 应用过滤条件
      if (!empty($filters['date_from'])) {
        $query->condition('u.date', $filters['date_from'], '>=');
      }
      
      if (!empty($filters['date_to'])) {
        $query->condition('u.date', $filters['date_to'], '<=');
      }
      
      $results = $query->execute()->fetchAll();
      
      $stats = [];
      foreach ($results as $row) {
        $resource_config = self::RESOURCE_TYPES[$row->resource_type] ?? [];
        $stats[$row->resource_type] = [
          'resource_type' => $row->resource_type,
          'resource_label' => $resource_config['label'] ?? $row->resource_type,
          'total_count' => (int) $row->total_count,
          'project_count' => (int) $row->project_count,
          'unit' => $resource_config['unit'] ?? 'count',
        ];
      }
      
      return $stats;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get tenant usage stats for @tenant_id: @error', [
        '@tenant_id' => $tenant_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectRanking(string $tenant_id, string $resource_type, string $period = 'monthly', int $limit = 10): array {
    try {
      $query = $this->database->select('baas_project_usage', 'u')
        ->fields('u', ['project_id'])
        ->condition('resource_type', $resource_type);
      $query->addExpression('SUM(count)', 'total_count');
      
      // 关联项目表
      $query->leftJoin('baas_project_config', 'p', 'u.project_id = p.project_id');
      $query->addField('p', 'name', 'project_name');
      $query->condition('p.tenant_id', $tenant_id);
      
      // 根据周期过滤日期
      $date_condition = match($period) {
        'daily' => date('Y-m-d'),
        'weekly' => date('Y-m-d', strtotime('-7 days')),
        'monthly' => date('Y-m-d', strtotime('-30 days')),
        default => date('Y-m-d', strtotime('-30 days')),
      };
      
      $query->condition('u.date', $date_condition, '>=');
      $query->groupBy('u.project_id');
      $query->orderBy('total_count', 'DESC');
      $query->range(0, $limit);
      
      $results = $query->execute()->fetchAll();
      
      $ranking = [];
      $rank = 1;
      foreach ($results as $row) {
        $ranking[] = [
          'rank' => $rank++,
          'project_id' => $row->project_id,
          'project_name' => $row->project_name,
          'total_count' => (int) $row->total_count,
          'resource_type' => $resource_type,
          'period' => $period,
        ];
      }
      
      return $ranking;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get project ranking for tenant @tenant_id: @error', [
        '@tenant_id' => $tenant_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function generateUsageReport(string $project_id, array $options = []): array {
    try {
      $report = [
        'project_id' => $project_id,
        'generated_at' => time(),
        'period' => $options['period'] ?? 'monthly',
        'current_usage' => $this->getCurrentUsage($project_id),
        'usage_limits' => $this->getUsageLimits($project_id),
        'trends' => [],
        'alerts' => $this->checkUsageAlerts($project_id),
        'summary' => [],
      ];
      
      // 生成趋势数据
      $days = match($options['period'] ?? 'monthly') {
        'daily' => 7,
        'weekly' => 30,
        'monthly' => 90,
        default => 30,
      };
      
      $report['trends'] = $this->getUsageTrend($project_id, $options['period'] ?? 'daily', $days);
      
      // 生成摘要
      foreach ($report['current_usage'] as $resource_type => $usage) {
        $limit = $report['usage_limits'][$resource_type] ?? 0;
        $percentage = $limit > 0 ? round(($usage['total_count'] / $limit) * 100, 2) : 0;
        
        $report['summary'][$resource_type] = [
          'usage' => $usage['total_count'],
          'limit' => $limit,
          'percentage' => $percentage,
          'status' => $percentage >= 90 ? 'critical' : ($percentage >= 75 ? 'warning' : 'normal'),
        ];
      }
      
      return $report;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to generate usage report for project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function cleanupExpiredRecords(int $days = 365, array $options = []): array {
    try {
      $cutoff_date = date('Y-m-d', strtotime("-{$days} days"));
      
      $affected_rows = $this->database->delete('baas_project_usage')
        ->condition('date', $cutoff_date, '<')
        ->execute();
      
      $this->logger->info('Cleaned up @rows expired usage records older than @days days', [
        '@rows' => $affected_rows,
        '@days' => $days,
      ]);
      
      return [
        'success' => true,
        'records_deleted' => $affected_rows,
        'cutoff_date' => $cutoff_date,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to cleanup expired usage records: @error', [
        '@error' => $e->getMessage(),
      ]);
      return ['success' => false, 'error' => $e->getMessage()];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getAvailableResourceTypes(): array {
    return self::RESOURCE_TYPES;
  }

  /**
   * {@inheritdoc}
   */
  public function isValidResourceType(string $resource_type): bool {
    return array_key_exists($resource_type, self::RESOURCE_TYPES);
  }

  /**
   * {@inheritdoc}
   */
  public function getAggregatedUsage(array $project_ids, string $aggregation = 'sum', array $filters = []): array {
    try {
      if (empty($project_ids)) {
        return [];
      }
      
      $query = $this->database->select('baas_project_usage', 'u')
        ->fields('u', ['resource_type'])
        ->condition('project_id', $project_ids, 'IN');
      
      // 根据聚合方式添加表达式
      switch (strtolower($aggregation)) {
        case 'avg':
          $query->addExpression('AVG(count)', 'aggregated_count');
          break;
        case 'max':
          $query->addExpression('MAX(count)', 'aggregated_count');
          break;
        case 'min':
          $query->addExpression('MIN(count)', 'aggregated_count');
          break;
        default: // sum
          $query->addExpression('SUM(count)', 'aggregated_count');
      }
      
      $query->groupBy('resource_type');
      
      // 应用过滤条件
      if (!empty($filters['date_from'])) {
        $query->condition('date', $filters['date_from'], '>=');
      }
      
      if (!empty($filters['date_to'])) {
        $query->condition('date', $filters['date_to'], '<=');
      }
      
      $results = $query->execute()->fetchAll();
      
      $aggregated = [];
      foreach ($results as $row) {
        $resource_config = self::RESOURCE_TYPES[$row->resource_type] ?? [];
        $aggregated[$row->resource_type] = [
          'resource_type' => $row->resource_type,
          'resource_label' => $resource_config['label'] ?? $row->resource_type,
          'aggregated_count' => (float) $row->aggregated_count,
          'aggregation_method' => $aggregation,
          'project_count' => count($project_ids),
          'unit' => $resource_config['unit'] ?? 'count',
        ];
      }
      
      return $aggregated;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get aggregated usage: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setUsageAlerts(string $project_id, array $thresholds): bool {
    try {
      // 获取当前项目设置
      $current_settings = $this->database->select('baas_project_config', 'p')
        ->fields('p', ['settings'])
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchField();
      
      $settings = json_decode($current_settings ?? '{}', true);
      $settings['usage_alerts'] = array_merge($settings['usage_alerts'] ?? [], $thresholds);
      
      // 更新项目设置
      $affected_rows = $this->database->update('baas_project_config')
        ->fields([
          'settings' => json_encode($settings),
          'updated' => time(),
        ])
        ->condition('project_id', $project_id)
        ->execute();
      
      return $affected_rows > 0;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to set usage alerts for project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkUsageAlerts(string $project_id): array {
    try {
      // 获取警告阈值配置
      $project_settings = $this->database->select('baas_project_config', 'p')
        ->fields('p', ['settings'])
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchField();
      
      $settings = json_decode($project_settings ?? '{}', true);
      $alert_thresholds = $settings['usage_alerts'] ?? [];
      
      if (empty($alert_thresholds)) {
        return [];
      }
      
      $current_usage = $this->getCurrentUsage($project_id);
      $usage_limits = $this->getUsageLimits($project_id);
      
      $alerts = [];
      foreach ($alert_thresholds as $resource_type => $threshold) {
        $usage = $current_usage[$resource_type]['total_count'] ?? 0;
        $limit = $usage_limits[$resource_type] ?? 0;
        
        if ($limit > 0) {
          $percentage = ($usage / $limit) * 100;
          
          if ($percentage >= $threshold) {
            $alerts[] = [
              'resource_type' => $resource_type,
              'current_usage' => $usage,
              'limit' => $limit,
              'percentage' => round($percentage, 2),
              'threshold' => $threshold,
              'severity' => $percentage >= 95 ? 'critical' : ($percentage >= 85 ? 'warning' : 'info'),
              'message' => "Usage for {$resource_type} is at {$percentage}% of limit",
            ];
          }
        }
      }
      
      return $alerts;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to check usage alerts for project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function exportUsageData(string $project_id, string $format = 'csv', array $options = []): array {
    try {
      $usage_stats = $this->getUsageStats($project_id, $options['filters'] ?? [], $options);
      
      $export_data = [
        'format' => $format,
        'project_id' => $project_id,
        'exported_at' => time(),
        'record_count' => count($usage_stats),
        'data' => $usage_stats,
      ];
      
      // 根据格式处理数据
      switch (strtolower($format)) {
        case 'json':
          $export_data['content'] = json_encode($usage_stats, JSON_PRETTY_PRINT);
          $export_data['mime_type'] = 'application/json';
          break;
        case 'xml':
          // XML导出实现
          $export_data['content'] = $this->convertToXml($usage_stats);
          $export_data['mime_type'] = 'application/xml';
          break;
        default: // csv
          $export_data['content'] = $this->convertToCsv($usage_stats);
          $export_data['mime_type'] = 'text/csv';
      }
      
      return $export_data;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to export usage data for project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return ['success' => false, 'error' => $e->getMessage()];
    }
  }

  /**
   * 清除使用统计缓存。
   *
   * @param string $project_id
   *   项目ID。
   * @param string|null $resource_type
   *   资源类型。
   */
  protected function clearUsageCache(string $project_id, ?string $resource_type = null): void {
    $cache_keys = [
      "baas_project:current_usage:{$project_id}",
      "baas_project:usage_limits:{$project_id}",
    ];
    
    if ($resource_type) {
      $cache_keys[] = "baas_project:current_usage:{$project_id}:{$resource_type}";
    }
    
    foreach ($cache_keys as $key) {
      $this->cache->delete($key);
    }
  }

  /**
   * 将数据转换为CSV格式。
   *
   * @param array $data
   *   数据数组。
   *
   * @return string
   *   CSV内容。
   */
  protected function convertToCsv(array $data): string {
    if (empty($data)) {
      return '';
    }
    
    $output = fopen('php://temp', 'r+');
    
    // 写入标题行
    $headers = array_keys($data[0]);
    fputcsv($output, $headers);
    
    // 写入数据行
    foreach ($data as $row) {
      fputcsv($output, $row);
    }
    
    rewind($output);
    $csv_content = stream_get_contents($output);
    fclose($output);
    
    return $csv_content;
  }

  /**
   * 将数据转换为XML格式。
   *
   * @param array $data
   *   数据数组。
   *
   * @return string
   *   XML内容。
   */
  protected function convertToXml(array $data): string {
    $xml = new \SimpleXMLElement('<usage_data></usage_data>');
    
    foreach ($data as $record) {
      $usage_record = $xml->addChild('usage_record');
      foreach ($record as $key => $value) {
        $usage_record->addChild($key, htmlspecialchars((string) $value));
      }
    }
    
    return $xml->asXML();
  }

}