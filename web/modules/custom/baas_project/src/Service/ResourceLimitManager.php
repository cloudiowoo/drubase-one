<?php

declare(strict_types=1);

namespace Drupal\baas_project\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_tenant\TenantManagerInterface;

/**
 * 资源限制管理服务。
 *
 * 提供租户和项目级别的资源限制管理功能，包括存储、API调用、实体数量等限制的获取和检查。
 */
class ResourceLimitManager
{

  protected readonly LoggerChannelInterface $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly CacheBackendInterface $cache,
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly TenantManagerInterface $tenantManager,
  ) {
    $this->logger = $loggerFactory->get('baas_project');
  }

  /**
   * 获取项目的有效资源限制。
   *
   * 按优先级合并配置：项目 > 租户 > 默认值
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   包含有效限制的数组。
   */
  public function getEffectiveLimits(string $project_id): array
  {
    try {
      // 尝试从缓存获取
      $cache_key = "resource_limits:project:$project_id";
      $cached = $this->cache->get($cache_key);
      if ($cached && !empty($cached->data)) {
        return $cached->data;
      }

      // 获取项目配置
      $project = $this->projectManager->getProject($project_id);
      if (!$project) {
        throw new \InvalidArgumentException("Project not found: $project_id");
      }

      // 处理项目设置（可能是字符串或数组）
      $project_settings_raw = $project['settings'] ?? '{}';
      if (is_string($project_settings_raw)) {
        $project_settings = json_decode($project_settings_raw, true) ?: [];
      } else {
        $project_settings = is_array($project_settings_raw) ? $project_settings_raw : [];
      }

      // 获取租户配置
      $tenant = $this->tenantManager->getTenant($project['tenant_id']);
      $tenant_settings_raw = $tenant['settings'] ?? '{}';
      if (is_string($tenant_settings_raw)) {
        $tenant_settings = json_decode($tenant_settings_raw, true) ?: [];
      } else {
        $tenant_settings = is_array($tenant_settings_raw) ? $tenant_settings_raw : [];
      }

      // 获取系统默认配置
      $default_limits = $this->getDefaultLimits();

      // 合并限制（项目 > 租户 > 默认）
      $limits = [
        'max_storage' => $this->convertToBytes(
          $project_settings['max_storage'] 
          ?? $tenant_settings['max_storage'] 
          ?? $default_limits['max_storage']
        ),
        'max_api_calls' => $project_settings['max_api_calls'] 
          ?? $tenant_settings['max_requests'] 
          ?? $default_limits['max_api_calls'],
        'max_entities' => $project_settings['max_entities'] 
          ?? $tenant_settings['max_entities'] 
          ?? $default_limits['max_entities'],
        'max_file_size' => $this->convertToBytes(
          $project_settings['max_file_size']
          ?? $tenant_settings['max_file_size']
          ?? $default_limits['max_file_size']
        ),
        'rate_limits' => $project_settings['rate_limits'] ?? [],
        'source' => $this->determineSource($project_settings, $tenant_settings),
        'updated_at' => time(),
      ];

      // 缓存结果（缓存5分钟）
      $this->cache->set($cache_key, $limits, time() + 300);

      return $limits;

    } catch (\Exception $e) {
      $this->logger->error('获取资源限制失败: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
      ]);

      // 返回默认限制
      return $this->getDefaultLimits();
    }
  }

  /**
   * 检查资源使用是否超过限制。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $resource_type
   *   资源类型（storage, api_calls, entities）。
   * @param int $amount
   *   要检查的使用量。
   *
   * @return array
   *   检查结果，包含是否允许和相关信息。
   */
  public function checkResourceLimit(string $project_id, string $resource_type, int $amount = 1): array
  {
    try {
      $limits = $this->getEffectiveLimits($project_id);
      $current_usage = $this->getCurrentUsage($project_id, $resource_type);

      $limit_key = "max_$resource_type";
      if (!isset($limits[$limit_key])) {
        // 未知资源类型，默认允许
        return [
          'allowed' => true,
          'current_usage' => $current_usage,
          'limit' => null,
          'remaining' => null,
          'resource_type' => $resource_type,
        ];
      }

      $limit = $limits[$limit_key];
      $new_usage = $current_usage + $amount;
      $allowed = $new_usage <= $limit;

      return [
        'allowed' => $allowed,
        'current_usage' => $current_usage,
        'new_usage' => $new_usage,
        'limit' => $limit,
        'remaining' => max(0, $limit - $current_usage),
        'resource_type' => $resource_type,
        'percentage' => $limit > 0 ? ($current_usage / $limit) * 100 : 0,
      ];

    } catch (\Exception $e) {
      $this->logger->error('检查资源限制失败: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
        'resource_type' => $resource_type,
      ]);

      // 出错时默认允许
      return [
        'allowed' => true,
        'current_usage' => 0,
        'limit' => null,
        'remaining' => null,
        'resource_type' => $resource_type,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * 获取当前资源使用量。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $resource_type
   *   资源类型。
   *
   * @return int
   *   当前使用量。
   */
  protected function getCurrentUsage(string $project_id, string $resource_type): int
  {
    switch ($resource_type) {
      case 'storage':
        // 从文件使用统计表获取存储使用量
        return (int) $this->database->select('baas_project_file_usage', 'u')
          ->fields('u', ['total_size'])
          ->condition('project_id', $project_id)
          ->execute()
          ->fetchField() ?: 0;

      case 'api_calls':
        // 从项目使用统计表获取API调用次数（当前小时）
        $hour_start = strtotime(date('Y-m-d H:00:00'));
        return (int) $this->database->select('baas_project_usage', 'u')
          ->fields('u', ['usage_amount'])
          ->condition('project_id', $project_id)
          ->condition('usage_type', 'api_calls')
          ->condition('usage_date', $hour_start, '>=')
          ->execute()
          ->fetchField() ?: 0;

      case 'entities':
        // 计算项目中的实体数量
        return (int) $this->database->select('baas_entity_template', 't')
          ->condition('project_id', $project_id)
          ->condition('status', 1)
          ->countQuery()
          ->execute()
          ->fetchField() ?: 0;

      default:
        return 0;
    }
  }

  /**
   * 将MB转换为字节。
   *
   * @param mixed $mb
   *   MB值。
   *
   * @return int
   *   字节数。
   */
  protected function convertToBytes($mb): int
  {
    if (is_numeric($mb)) {
      return (int) ($mb * 1024 * 1024);
    }
    return 0;
  }

  /**
   * 确定限制来源。
   *
   * @param array $project_settings
   *   项目设置。
   * @param array $tenant_settings
   *   租户设置。
   *
   * @return array
   *   限制来源信息。
   */
  protected function determineSource(array $project_settings, array $tenant_settings): array
  {
    $sources = [];

    // 检查每个限制的来源
    $limits = ['max_storage', 'max_api_calls', 'max_entities', 'max_file_size'];
    
    foreach ($limits as $limit) {
      if (isset($project_settings[$limit])) {
        $sources[$limit] = 'project';
      } elseif (isset($tenant_settings[$limit]) || isset($tenant_settings[str_replace('max_', 'max_', $limit)])) {
        $sources[$limit] = 'tenant';
      } else {
        $sources[$limit] = 'default';
      }
    }

    return $sources;
  }

  /**
   * 获取系统默认限制。
   *
   * @return array
   *   默认限制配置。
   */
  protected function getDefaultLimits(): array
  {
    $config = $this->configFactory->get('baas_project.settings');
    
    return [
      'max_storage' => 1024 * 1024 * 1024, // 1GB in bytes
      'max_api_calls' => 10000, // 每小时10000次
      'max_entities' => 100, // 100个实体
      'max_file_size' => 100 * 1024 * 1024, // 100MB in bytes
      'source' => ['max_storage' => 'default', 'max_api_calls' => 'default', 'max_entities' => 'default', 'max_file_size' => 'default'],
    ];
  }

  /**
   * 清除项目资源限制缓存。
   *
   * @param string $project_id
   *   项目ID。
   */
  public function clearCache(string $project_id): void
  {
    $cache_key = "resource_limits:project:$project_id";
    $this->cache->delete($cache_key);
  }

  /**
   * 格式化字节数为人类可读格式。
   *
   * @param int $bytes
   *   字节数。
   *
   * @return string
   *   格式化后的字符串。
   */
  public function formatBytes(int $bytes): string
  {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $factor = floor((strlen((string) $bytes) - 1) / 3);
    
    return sprintf("%.2f %s", $bytes / pow(1024, $factor), $units[$factor]);
  }
}