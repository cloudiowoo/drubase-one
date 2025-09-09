<?php

declare(strict_types=1);

namespace Drupal\baas_project;

/**
 * 项目使用统计跟踪器接口。
 *
 * 定义项目资源使用统计和监控的核心方法。
 */
interface ProjectUsageTrackerInterface {

  /**
   * 记录项目使用情况。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $resource_type
   *   资源类型（entity_templates, entity_instances, api_calls, storage等）。
   * @param int $count
   *   使用数量。
   * @param array $metadata
   *   额外的元数据。
   *
   * @return bool
   *   是否记录成功。
   */
  public function recordUsage(string $project_id, string $resource_type, int $count = 1, array $metadata = []): bool;

  /**
   * 获取项目使用统计。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $filters
   *   过滤条件。
   * @param array $options
   *   查询选项。
   *
   * @return array
   *   使用统计数据。
   */
  public function getUsageStats(string $project_id, array $filters = [], array $options = []): array;

  /**
   * 获取项目当前使用情况。
   *
   * @param string $project_id
   *   项目ID。
   * @param string|null $resource_type
   *   资源类型，为NULL时返回所有类型。
   *
   * @return array
   *   当前使用情况。
   */
  public function getCurrentUsage(string $project_id, ?string $resource_type = null): array;

  /**
   * 获取项目历史使用趋势。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $period
   *   时间周期（daily, weekly, monthly）。
   * @param int $days
   *   天数。
   * @param array $options
   *   查询选项。
   *
   * @return array
   *   使用趋势数据。
   */
  public function getUsageTrend(string $project_id, string $period = 'daily', int $days = 30, array $options = []): array;

  /**
   * 检查项目是否超出使用限制。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $resource_type
   *   资源类型。
   * @param int $requested_count
   *   请求的使用数量。
   *
   * @return array
   *   检查结果，包含是否允许和相关信息。
   */
  public function checkUsageLimit(string $project_id, string $resource_type, int $requested_count = 1): array;

  /**
   * 获取项目使用限制配置。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   使用限制配置。
   */
  public function getUsageLimits(string $project_id): array;

  /**
   * 设置项目使用限制。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $limits
   *   限制配置。
   *
   * @return bool
   *   是否设置成功。
   */
  public function setUsageLimits(string $project_id, array $limits): bool;

  /**
   * 重置项目使用统计。
   *
   * @param string $project_id
   *   项目ID。
   * @param string|null $resource_type
   *   资源类型，为NULL时重置所有类型。
   * @param array $options
   *   重置选项。
   *
   * @return bool
   *   是否重置成功。
   */
  public function resetUsage(string $project_id, ?string $resource_type = null, array $options = []): bool;

  /**
   * 批量记录使用情况。
   *
   * @param array $usage_records
   *   使用记录数组。
   *
   * @return array
   *   批量操作结果。
   */
  public function recordBatchUsage(array $usage_records): array;

  /**
   * 获取租户级别的使用统计。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param array $filters
   *   过滤条件。
   * @param array $options
   *   查询选项。
   *
   * @return array
   *   租户使用统计。
   */
  public function getTenantUsageStats(string $tenant_id, array $filters = [], array $options = []): array;

  /**
   * 获取项目排行榜。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $resource_type
   *   资源类型。
   * @param string $period
   *   时间周期。
   * @param int $limit
   *   返回数量限制。
   *
   * @return array
   *   项目排行榜。
   */
  public function getProjectRanking(string $tenant_id, string $resource_type, string $period = 'monthly', int $limit = 10): array;

  /**
   * 生成使用报告。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $options
   *   报告选项。
   *
   * @return array
   *   使用报告数据。
   */
  public function generateUsageReport(string $project_id, array $options = []): array;

  /**
   * 清理过期的使用记录。
   *
   * @param int $days
   *   保留天数。
   * @param array $options
   *   清理选项。
   *
   * @return array
   *   清理结果。
   */
  public function cleanupExpiredRecords(int $days = 365, array $options = []): array;

  /**
   * 获取可用的资源类型。
   *
   * @return array
   *   资源类型列表。
   */
  public function getAvailableResourceTypes(): array;

  /**
   * 验证资源类型是否有效。
   *
   * @param string $resource_type
   *   资源类型。
   *
   * @return bool
   *   是否有效。
   */
  public function isValidResourceType(string $resource_type): bool;

  /**
   * 获取使用统计的聚合数据。
   *
   * @param array $project_ids
   *   项目ID数组。
   * @param string $aggregation
   *   聚合方式（sum, avg, max, min）。
   * @param array $filters
   *   过滤条件。
   *
   * @return array
   *   聚合统计数据。
   */
  public function getAggregatedUsage(array $project_ids, string $aggregation = 'sum', array $filters = []): array;

  /**
   * 设置使用警告阈值。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $thresholds
   *   警告阈值配置。
   *
   * @return bool
   *   是否设置成功。
   */
  public function setUsageAlerts(string $project_id, array $thresholds): bool;

  /**
   * 检查使用警告。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   警告信息。
   */
  public function checkUsageAlerts(string $project_id): array;

  /**
   * 导出使用数据。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $format
   *   导出格式（csv, json, xml）。
   * @param array $options
   *   导出选项。
   *
   * @return array
   *   导出结果。
   */
  public function exportUsageData(string $project_id, string $format = 'csv', array $options = []): array;

}