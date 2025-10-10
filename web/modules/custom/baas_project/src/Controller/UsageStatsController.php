<?php

declare(strict_types=1);

namespace Drupal\baas_project\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\baas_project\ProjectUsageTrackerInterface;
use Drupal\baas_project\Service\ResourceLimitManager;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_tenant\TenantManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * 使用统计API控制器。
 *
 * 提供项目和租户级别的使用统计、监控和报表功能。
 */
class UsageStatsController extends ControllerBase {

  protected readonly LoggerChannelInterface $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly CacheBackendInterface $cache,
    protected readonly ProjectUsageTrackerInterface $usageTracker,
    protected readonly ResourceLimitManager $resourceLimitManager,
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly TenantManagerInterface $tenantManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('baas_project_stats');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('cache.default'),
      $container->get('baas_project.usage_tracker'),
      $container->get('baas_project.resource_limit_manager'),
      $container->get('baas_project.manager'),
      $container->get('baas_tenant.manager'),
      $container->get('logger.factory'),
    );
  }

  /**
   * 获取项目使用统计。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function getProjectUsageStats(Request $request, string $tenant_id, string $project_id): JsonResponse {
    try {
      // 验证项目存在性和权限
      $project = $this->projectManager->getProject($project_id);
      if (!$project) {
        return new JsonResponse([
          'success' => false,
          'error' => 'Project not found',
          'code' => 404,
        ], 404);
      }

      if ($project['tenant_id'] !== $tenant_id) {
        return new JsonResponse([
          'success' => false,
          'error' => 'Project does not belong to this tenant',
          'code' => 403,
        ], 403);
      }

      // 检查缓存
      $cache_key = "usage_stats:project:{$project_id}";
      $cached = $this->cache->get($cache_key);
      if ($cached && !empty($cached->data)) {
        return new JsonResponse([
          'success' => true,
          'data' => $cached->data,
          'cached' => true,
        ]);
      }

      // 获取查询参数
      $period = $request->query->get('period', 'monthly');
      $days = (int) $request->query->get('days', 30);
      $include_trends = $request->query->getBoolean('include_trends', true);
      $include_warnings = $request->query->getBoolean('include_warnings', true);

      // 构建统计数据
      $stats = [
        'project_id' => $project_id,
        'tenant_id' => $tenant_id,
        'project_name' => $project['name'],
        'generated_at' => time(),
        'period' => $period,
        'storage' => $this->getStorageStats($project_id),
        'api_calls' => $this->getApiCallStats($project_id),
        'entities' => $this->getEntityStats($project_id),
        'files' => $this->getFileStats($project_id),
        'limits' => $this->resourceLimitManager->getEffectiveLimits($project_id),
        'current_usage' => $this->usageTracker->getCurrentUsage($project_id),
      ];

      // 添加趋势数据
      if ($include_trends) {
        $stats['trends'] = $this->usageTracker->getUsageTrend($project_id, $period, $days);
      }

      // 添加警告信息
      if ($include_warnings) {
        $stats['warnings'] = $this->checkUsageWarnings($project_id);
      }

      // 添加使用百分比和状态
      $stats['usage_summary'] = $this->calculateUsageSummary($stats);

      // 缓存结果（5分钟）
      $this->cache->set($cache_key, $stats, time() + 300);

      return new JsonResponse([
        'success' => true,
        'data' => $stats,
        'message' => 'Project usage statistics retrieved successfully',
      ]);

    } catch (\Exception $e) {
      $this->logger->error('Failed to get project usage stats: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
        'tenant_id' => $tenant_id,
      ]);

      return new JsonResponse([
        'success' => false,
        'error' => 'Failed to retrieve usage statistics',
        'code' => 500,
      ], 500);
    }
  }

  /**
   * 获取租户使用汇总。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function getTenantUsageStats(Request $request, string $tenant_id): JsonResponse {
    try {
      // 验证租户存在性
      $tenant = $this->tenantManager->getTenant($tenant_id);
      if (!$tenant) {
        return new JsonResponse([
          'success' => false,
          'error' => 'Tenant not found',
          'code' => 404,
        ], 404);
      }

      // 检查缓存
      $cache_key = "usage_stats:tenant:{$tenant_id}";
      $cached = $this->cache->get($cache_key);
      if ($cached && !empty($cached->data)) {
        return new JsonResponse([
          'success' => true,
          'data' => $cached->data,
          'cached' => true,
        ]);
      }

      // 获取查询参数
      $include_projects = $request->query->getBoolean('include_projects', false);
      $period = $request->query->get('period', 'monthly');

      // 获取租户下所有项目
      $projects = $this->getTenantProjects($tenant_id);
      $project_ids = array_column($projects, 'project_id');

      // 构建租户级统计数据
      $stats = [
        'tenant_id' => $tenant_id,
        'tenant_name' => $tenant['name'] ?? 'Unknown',
        'generated_at' => time(),
        'period' => $period,
        'project_count' => count($projects),
        'summary' => [
          'total_storage' => 0,
          'total_api_calls' => 0,
          'total_entities' => 0,
          'total_files' => 0,
        ],
        'resource_usage' => $this->usageTracker->getTenantUsageStats($tenant_id),
        'aggregated_usage' => [],
        'top_projects' => [],
      ];

      // 计算聚合使用量
      if (!empty($project_ids)) {
        $stats['aggregated_usage'] = $this->usageTracker->getAggregatedUsage($project_ids, 'sum');
        
        // 获取各资源类型使用量排名
        foreach (['storage', 'api_calls', 'entities'] as $resource_type) {
          $stats['top_projects'][$resource_type] = $this->usageTracker->getProjectRanking(
            $tenant_id, 
            $resource_type, 
            $period, 
            10
          );
        }
      }

      // 包含项目详情
      if ($include_projects) {
        $stats['projects'] = [];
        foreach ($projects as $project) {
          $project_stats = $this->getProjectSummaryStats($project['project_id']);
          $stats['projects'][] = array_merge($project, $project_stats);
        }
      }

      // 计算汇总数据
      $stats['summary'] = $this->calculateTenantSummary($stats);

      // 缓存结果（10分钟）
      $this->cache->set($cache_key, $stats, time() + 600);

      return new JsonResponse([
        'success' => true,
        'data' => $stats,
        'message' => 'Tenant usage statistics retrieved successfully',
      ]);

    } catch (\Exception $e) {
      $this->logger->error('Failed to get tenant usage stats: @error', [
        '@error' => $e->getMessage(),
        'tenant_id' => $tenant_id,
      ]);

      return new JsonResponse([
        'success' => false,
        'error' => 'Failed to retrieve tenant usage statistics',
        'code' => 500,
      ], 500);
    }
  }

  /**
   * 获取使用警告。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function getUsageWarnings(Request $request, string $tenant_id, string $project_id): JsonResponse {
    try {
      // 验证项目存在性和权限
      $project = $this->projectManager->getProject($project_id);
      if (!$project) {
        return new JsonResponse([
          'success' => false,
          'error' => 'Project not found',
          'code' => 404,
        ], 404);
      }

      if ($project['tenant_id'] !== $tenant_id) {
        return new JsonResponse([
          'success' => false,
          'error' => 'Project does not belong to this tenant',
          'code' => 403,
        ], 403);
      }

      // 检查缓存
      $cache_key = "usage_warnings:project:{$project_id}";
      $cached = $this->cache->get($cache_key);
      if ($cached && !empty($cached->data)) {
        return new JsonResponse([
          'success' => true,
          'data' => $cached->data,
          'cached' => true,
        ]);
      }

      // 获取警告信息
      $warnings = $this->checkUsageWarnings($project_id);
      $alerts = $this->usageTracker->checkUsageAlerts($project_id);

      // 构建响应数据
      $data = [
        'project_id' => $project_id,
        'tenant_id' => $tenant_id,
        'checked_at' => time(),
        'warning_count' => count($warnings),
        'alert_count' => count($alerts),
        'warnings' => $warnings,
        'alerts' => $alerts,
        'recommendations' => $this->generateRecommendations($project_id, $warnings, $alerts),
      ];

      // 缓存结果（2分钟）
      $this->cache->set($cache_key, $data, time() + 120);

      return new JsonResponse([
        'success' => true,
        'data' => $data,
        'message' => 'Usage warnings retrieved successfully',
      ]);

    } catch (\Exception $e) {
      $this->logger->error('Failed to get usage warnings: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
        'tenant_id' => $tenant_id,
      ]);

      return new JsonResponse([
        'success' => false,
        'error' => 'Failed to retrieve usage warnings',
        'code' => 500,
      ], 500);
    }
  }

  /**
   * 获取存储统计数据。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   存储统计数据。
   */
  protected function getStorageStats(string $project_id): array {
    try {
      $query = $this->database->select('baas_project_file_usage', 'u')
        ->fields('u', ['total_size', 'file_count', 'image_count'])
        ->condition('project_id', $project_id);

      $result = $query->execute()->fetchAssoc();

      return [
        'total_size' => (int) ($result['total_size'] ?? 0),
        'total_size_formatted' => $this->resourceLimitManager->formatBytes((int) ($result['total_size'] ?? 0)),
        'file_count' => (int) ($result['file_count'] ?? 0),
        'image_count' => (int) ($result['image_count'] ?? 0),
        'last_updated' => time(),
      ];
    } catch (\Exception $e) {
      $this->logger->error('Failed to get storage stats: @error', ['@error' => $e->getMessage()]);
      return [
        'total_size' => 0,
        'total_size_formatted' => '0 B',
        'file_count' => 0,
        'image_count' => 0,
        'last_updated' => time(),
      ];
    }
  }

  /**
   * 获取API调用统计数据。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   API调用统计数据。
   */
  protected function getApiCallStats(string $project_id): array {
    try {
      $today = date('Y-m-d');
      $hour_start = strtotime(date('Y-m-d H:00:00'));

      // 今日API调用总数
      $daily_calls = (int) $this->database->select('baas_project_usage', 'u')
        ->fields('u', ['count'])
        ->condition('project_id', $project_id)
        ->condition('resource_type', 'api_calls')
        ->condition('date', $today)
        ->execute()
        ->fetchField() ?: 0;

      // 当前小时API调用数
      $hourly_calls = (int) $this->database->select('baas_project_usage', 'u')
        ->fields('u', ['count'])
        ->condition('project_id', $project_id)
        ->condition('resource_type', 'api_calls')
        ->condition('created_at', $hour_start, '>=')
        ->execute()
        ->fetchField() ?: 0;

      return [
        'daily_calls' => $daily_calls,
        'hourly_calls' => $hourly_calls,
        'last_updated' => time(),
      ];
    } catch (\Exception $e) {
      $this->logger->error('Failed to get API call stats: @error', ['@error' => $e->getMessage()]);
      return [
        'daily_calls' => 0,
        'hourly_calls' => 0,
        'last_updated' => time(),
      ];
    }
  }

  /**
   * 获取实体统计数据。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   实体统计数据。
   */
  protected function getEntityStats(string $project_id): array {
    try {
      // 实体模板数量
      $template_count = (int) $this->database->select('baas_entity_template', 't')
        ->condition('project_id', $project_id)
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField() ?: 0;

      // 实体实例总数（所有模板的数据记录总和）
      $instance_count = 0;
      $templates = $this->database->select('baas_entity_template', 't')
        ->fields('t', ['name'])
        ->condition('project_id', $project_id)
        ->condition('status', 1)
        ->execute()
        ->fetchAll();

      foreach ($templates as $template) {
        $table_name = "tenant_{$project_id}_{$template->name}";
        if ($this->database->schema()->tableExists($table_name)) {
          $count = (int) $this->database->select($table_name, 't')
            ->countQuery()
            ->execute()
            ->fetchField() ?: 0;
          $instance_count += $count;
        }
      }

      return [
        'template_count' => $template_count,
        'instance_count' => $instance_count,
        'templates' => array_map(function($t) { return $t->name; }, $templates),
        'last_updated' => time(),
      ];
    } catch (\Exception $e) {
      $this->logger->error('Failed to get entity stats: @error', ['@error' => $e->getMessage()]);
      return [
        'template_count' => 0,
        'instance_count' => 0,
        'templates' => [],
        'last_updated' => time(),
      ];
    }
  }

  /**
   * 获取文件统计数据。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   文件统计数据。
   */
  protected function getFileStats(string $project_id): array {
    try {
      $query = $this->database->select('baas_project_file_access', 'fa')
        ->condition('project_id', $project_id);
      $query->leftJoin('file_managed', 'f', 'fa.file_id::integer = f.fid');
      $query->addExpression('COUNT(DISTINCT fa.file_id)', 'unique_files');
      $query->addExpression('COUNT(fa.id)', 'total_accesses');
      $query->addExpression('SUM(f.filesize)', 'total_size');

      $result = $query->execute()->fetchAssoc();

      return [
        'unique_files' => (int) ($result['unique_files'] ?? 0),
        'total_accesses' => (int) ($result['total_accesses'] ?? 0),
        'total_size' => (int) ($result['total_size'] ?? 0),
        'total_size_formatted' => $this->resourceLimitManager->formatBytes((int) ($result['total_size'] ?? 0)),
        'last_updated' => time(),
      ];
    } catch (\Exception $e) {
      $this->logger->error('Failed to get file stats: @error', ['@error' => $e->getMessage()]);
      return [
        'unique_files' => 0,
        'total_accesses' => 0,
        'total_size' => 0,
        'total_size_formatted' => '0 B',
        'last_updated' => time(),
      ];
    }
  }

  /**
   * 检查使用警告。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   警告数组。
   */
  protected function checkUsageWarnings(string $project_id): array {
    $warnings = [];
    $limits = $this->resourceLimitManager->getEffectiveLimits($project_id);

    // 检查存储使用率
    $storage_stats = $this->getStorageStats($project_id);
    $storage_percent = $limits['max_storage'] > 0 ? ($storage_stats['total_size'] / $limits['max_storage']) * 100 : 0;

    if ($storage_percent >= 95) {
      $warnings[] = [
        'type' => 'storage',
        'level' => 'critical',
        'percentage' => round($storage_percent, 2),
        'message' => 'Storage usage is critically high (≥95%)',
        'recommendation' => 'Consider upgrading storage limits or cleaning up unused files',
      ];
    } elseif ($storage_percent >= 85) {
      $warnings[] = [
        'type' => 'storage',
        'level' => 'warning',
        'percentage' => round($storage_percent, 2),
        'message' => 'Storage usage is high (≥85%)',
        'recommendation' => 'Monitor storage usage and consider cleanup',
      ];
    } elseif ($storage_percent >= 75) {
      $warnings[] = [
        'type' => 'storage',
        'level' => 'info',
        'percentage' => round($storage_percent, 2),
        'message' => 'Storage usage is moderate (≥75%)',
        'recommendation' => 'Keep monitoring storage usage',
      ];
    }

    // 检查API调用使用率
    $api_stats = $this->getApiCallStats($project_id);
    $api_percent = $limits['max_api_calls'] > 0 ? ($api_stats['hourly_calls'] / $limits['max_api_calls']) * 100 : 0;

    if ($api_percent >= 90) {
      $warnings[] = [
        'type' => 'api_calls',
        'level' => 'critical',
        'percentage' => round($api_percent, 2),
        'message' => 'API call rate is critically high (≥90%)',
        'recommendation' => 'Consider upgrading API limits or optimizing API usage',
      ];
    } elseif ($api_percent >= 75) {
      $warnings[] = [
        'type' => 'api_calls',
        'level' => 'warning',
        'percentage' => round($api_percent, 2),
        'message' => 'API call rate is high (≥75%)',
        'recommendation' => 'Monitor API usage patterns',
      ];
    }

    // 检查实体数量
    $entity_stats = $this->getEntityStats($project_id);
    $entity_percent = $limits['max_entities'] > 0 ? ($entity_stats['template_count'] / $limits['max_entities']) * 100 : 0;

    if ($entity_percent >= 90) {
      $warnings[] = [
        'type' => 'entities',
        'level' => 'critical',
        'percentage' => round($entity_percent, 2),
        'message' => 'Entity count is critically high (≥90%)',
        'recommendation' => 'Consider upgrading entity limits or optimizing entity structure',
      ];
    } elseif ($entity_percent >= 80) {
      $warnings[] = [
        'type' => 'entities',
        'level' => 'warning',
        'percentage' => round($entity_percent, 2),
        'message' => 'Entity count is high (≥80%)',
        'recommendation' => 'Monitor entity creation and consider optimization',
      ];
    }

    return $warnings;
  }

  /**
   * 计算使用汇总。
   *
   * @param array $stats
   *   统计数据。
   *
   * @return array
   *   使用汇总。
   */
  protected function calculateUsageSummary(array $stats): array {
    $summary = [];
    $limits = $stats['limits'];

    // 存储使用率
    $storage_used = $stats['storage']['total_size'];
    $storage_limit = $limits['max_storage'];
    $storage_percent = $storage_limit > 0 ? ($storage_used / $storage_limit) * 100 : 0;
    
    $summary['storage'] = [
      'used' => $storage_used,
      'used_formatted' => $this->resourceLimitManager->formatBytes($storage_used),
      'limit' => $storage_limit,
      'limit_formatted' => $this->resourceLimitManager->formatBytes($storage_limit),
      'percentage' => round($storage_percent, 2),
      'remaining' => max(0, $storage_limit - $storage_used),
      'remaining_formatted' => $this->resourceLimitManager->formatBytes(max(0, $storage_limit - $storage_used)),
      'status' => $this->getUsageStatus($storage_percent),
    ];

    // API调用使用率
    $api_used = $stats['api_calls']['hourly_calls'];
    $api_limit = $limits['max_api_calls'];
    $api_percent = $api_limit > 0 ? ($api_used / $api_limit) * 100 : 0;
    
    $summary['api_calls'] = [
      'used' => $api_used,
      'limit' => $api_limit,
      'percentage' => round($api_percent, 2),
      'remaining' => max(0, $api_limit - $api_used),
      'status' => $this->getUsageStatus($api_percent),
    ];

    // 实体使用率
    $entity_used = $stats['entities']['template_count'];
    $entity_limit = $limits['max_entities'];
    $entity_percent = $entity_limit > 0 ? ($entity_used / $entity_limit) * 100 : 0;
    
    $summary['entities'] = [
      'used' => $entity_used,
      'limit' => $entity_limit,
      'percentage' => round($entity_percent, 2),
      'remaining' => max(0, $entity_limit - $entity_used),
      'status' => $this->getUsageStatus($entity_percent),
    ];

    return $summary;
  }

  /**
   * 获取使用状态。
   *
   * @param float $percentage
   *   使用百分比。
   *
   * @return string
   *   状态字符串。
   */
  protected function getUsageStatus(float $percentage): string {
    if ($percentage >= 95) {
      return 'critical';
    } elseif ($percentage >= 85) {
      return 'warning';
    } elseif ($percentage >= 75) {
      return 'caution';
    } else {
      return 'normal';
    }
  }

  /**
   * 获取租户项目列表。
   *
   * @param string $tenant_id
   *   租户ID。
   *
   * @return array
   *   项目列表。
   */
  protected function getTenantProjects(string $tenant_id): array {
    try {
      return $this->database->select('baas_project_config', 'p')
        ->fields('p', ['project_id', 'name', 'created', 'status'])
        ->condition('tenant_id', $tenant_id)
        ->condition('status', 1)
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
      $this->logger->error('Failed to get tenant projects: @error', ['@error' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * 获取项目汇总统计。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   项目汇总统计。
   */
  protected function getProjectSummaryStats(string $project_id): array {
    $storage = $this->getStorageStats($project_id);
    $entities = $this->getEntityStats($project_id);
    $limits = $this->resourceLimitManager->getEffectiveLimits($project_id);

    return [
      'storage_used' => $storage['total_size'],
      'storage_formatted' => $storage['total_size_formatted'],
      'file_count' => $storage['file_count'],
      'entity_count' => $entities['template_count'],
      'instance_count' => $entities['instance_count'],
      'storage_percentage' => $limits['max_storage'] > 0 ? round(($storage['total_size'] / $limits['max_storage']) * 100, 2) : 0,
      'entity_percentage' => $limits['max_entities'] > 0 ? round(($entities['template_count'] / $limits['max_entities']) * 100, 2) : 0,
    ];
  }

  /**
   * 计算租户汇总数据。
   *
   * @param array $stats
   *   统计数据。
   *
   * @return array
   *   租户汇总数据。
   */
  protected function calculateTenantSummary(array $stats): array {
    $summary = [
      'total_storage' => 0,
      'total_api_calls' => 0,
      'total_entities' => 0,
      'total_files' => 0,
    ];

    if (!empty($stats['aggregated_usage'])) {
      foreach ($stats['aggregated_usage'] as $resource_type => $usage) {
        switch ($resource_type) {
          case 'storage_used':
            $summary['total_storage'] = (int) $usage['aggregated_count'];
            break;
          case 'api_calls':
            $summary['total_api_calls'] = (int) $usage['aggregated_count'];
            break;
          case 'entity_templates':
            $summary['total_entities'] = (int) $usage['aggregated_count'];
            break;
          case 'file_uploads':
            $summary['total_files'] = (int) $usage['aggregated_count'];
            break;
        }
      }
    }

    $summary['total_storage_formatted'] = $this->resourceLimitManager->formatBytes($summary['total_storage']);

    return $summary;
  }

  /**
   * 测试统计API功能（无需认证）。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function testStats(): JsonResponse {
    try {
      // 获取系统基本信息
      $stats = [
        'api_version' => '1.0',
        'server_time' => time(),
        'controller_loaded' => true,
        'services_available' => [
          'database' => !empty($this->database),
          'cache' => !empty($this->cache),
          'usage_tracker' => !empty($this->usageTracker),
          'resource_limit_manager' => !empty($this->resourceLimitManager),
          'project_manager' => !empty($this->projectManager),
          'tenant_manager' => !empty($this->tenantManager),
        ],
        'database_connection' => false,
        'tenant_count' => 0,
        'project_count' => 0,
      ];

      // 测试数据库连接
      try {
        $tenant_count = $this->database->select('baas_tenant_config', 't')
          ->condition('status', 1)
          ->countQuery()
          ->execute()
          ->fetchField();
        $stats['tenant_count'] = (int) $tenant_count;
        $stats['database_connection'] = true;

        $project_count = $this->database->select('baas_project_config', 'p')
          ->condition('status', 1)
          ->countQuery()
          ->execute()
          ->fetchField();
        $stats['project_count'] = (int) $project_count;
      } catch (\Exception $e) {
        $stats['database_error'] = $e->getMessage();
      }

      return new JsonResponse([
        'success' => true,
        'data' => $stats,
        'message' => 'Usage statistics controller is working properly',
      ]);

    } catch (\Exception $e) {
      $this->logger->error('Test stats failed: @error', ['@error' => $e->getMessage()]);

      return new JsonResponse([
        'success' => false,
        'error' => 'Test failed: ' . $e->getMessage(),
        'code' => 500,
      ], 500);
    }
  }

  /**
   * 生成建议。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $warnings
   *   警告数组。
   * @param array $alerts
   *   告警数组。
   *
   * @return array
   *   建议数组。
   */
  protected function generateRecommendations(string $project_id, array $warnings, array $alerts): array {
    $recommendations = [];

    // 基于警告生成建议
    foreach ($warnings as $warning) {
      switch ($warning['type']) {
        case 'storage':
          if ($warning['level'] === 'critical') {
            $recommendations[] = [
              'type' => 'urgent',
              'title' => 'Upgrade Storage Limits',
              'description' => 'Your storage usage is critically high. Consider upgrading your plan or cleaning up unused files.',
              'action' => 'upgrade_storage',
            ];
          }
          break;
        case 'api_calls':
          if ($warning['level'] === 'critical') {
            $recommendations[] = [
              'type' => 'urgent',
              'title' => 'Optimize API Usage',
              'description' => 'Your API usage is critically high. Consider implementing caching or upgrading your API limits.',
              'action' => 'optimize_api',
            ];
          }
          break;
        case 'entities':
          if ($warning['level'] === 'critical') {
            $recommendations[] = [
              'type' => 'urgent',
              'title' => 'Review Entity Structure',
              'description' => 'You are approaching the entity limit. Consider consolidating entities or upgrading your plan.',
              'action' => 'review_entities',
            ];
          }
          break;
      }
    }

    // 基于使用模式的一般建议
    if (empty($warnings)) {
      $recommendations[] = [
        'type' => 'info',
        'title' => 'Usage is Normal',
        'description' => 'Your current usage is within normal limits. Continue monitoring for optimal performance.',
        'action' => 'continue_monitoring',
      ];
    }

    return $recommendations;
  }
}