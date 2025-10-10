<?php

declare(strict_types=1);

namespace Drupal\baas_file\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * 文件使用统计跟踪服务。
 */
class FileUsageTracker
{

  /**
   * 数据库连接。
   */
  protected Connection $database;

  /**
   * 实体类型管理器。
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * 缓存后端。
   */
  protected CacheBackendInterface $cache;

  /**
   * 事件调度器。
   */
  protected EventDispatcherInterface $eventDispatcher;

  /**
   * 日志记录器。
   */
  protected $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    CacheBackendInterface $cache,
    EventDispatcherInterface $event_dispatcher,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->cache = $cache;
    $this->eventDispatcher = $event_dispatcher;
    $this->logger = $logger_factory->get('baas_file');
  }

  /**
   * 更新所有项目的文件统计。
   */
  public function updateAllProjectStats(): void
  {
    $projects = $this->database->select('baas_project_config', 'p')
      ->fields('p', ['project_id'])
      ->execute()
      ->fetchCol();

    foreach ($projects as $project_id) {
      $this->updateProjectStats($project_id);
    }
  }

  /**
   * 更新特定项目的文件统计。
   */
  public function updateProjectStats(string $project_id): void
  {
    try {
      // 计算项目文件统计
      $stats = $this->calculateProjectStats($project_id);

      // 更新数据库
      $this->database->merge('baas_project_file_usage')
        ->key(['project_id' => $project_id])
        ->fields($stats)
        ->execute();

      // 清除缓存
      $this->cache->delete("baas_file_stats_$project_id");

    } catch (\Exception $e) {
      $this->logger->error('更新项目文件统计失败: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * 计算项目文件统计。
   */
  protected function calculateProjectStats(string $project_id): array
  {
    try {
      // 构建查询以计算项目文件统计
      $query = $this->database->select('file_managed', 'f');
      $query->innerJoin('baas_project_file_access', 'pfa', 'f.fid = pfa.file_id::integer');
      $query->addExpression('COUNT(f.fid)', 'file_count');
      $query->addExpression('SUM(f.filesize)', 'total_size');
      $query->addExpression('SUM(CASE WHEN f.filemime LIKE \'image/%\' THEN 1 ELSE 0 END)', 'image_count');
      $query->addExpression('SUM(CASE WHEN f.filemime NOT LIKE \'image/%\' THEN 1 ELSE 0 END)', 'document_count');
      $query->condition('pfa.project_id', $project_id);
      $query->condition('f.status', 1); // 只统计已发布的文件

      $result = $query->execute()->fetchAssoc();

      return [
        'file_count' => (int) ($result['file_count'] ?? 0),
        'total_size' => (int) ($result['total_size'] ?? 0),
        'image_count' => (int) ($result['image_count'] ?? 0),
        'document_count' => (int) ($result['document_count'] ?? 0),
        'last_updated' => time(),
      ];
    } catch (\Exception $e) {
      $this->logger->error('计算项目文件统计失败: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
      ]);

      // 返回空统计数据
      return [
        'file_count' => 0,
        'total_size' => 0,
        'image_count' => 0,
        'document_count' => 0,
        'last_updated' => time(),
      ];
    }
  }

  /**
   * 获取文件使用统计。
   *
   * @param int $file_id
   *   文件ID。
   *
   * @return array
   *   文件使用统计。
   */
  public function getFileUsage(int $file_id): array
  {
    try {
      // 简化实现：返回基本信息
      $file = $this->entityTypeManager->getStorage('file')->load($file_id);
      if (!$file) {
        return ['reference_count' => 0, 'projects' => [], 'entities' => []];
      }

      return [
        'reference_count' => 1, // 简化为1
        'file_size' => $file->getSize(),
        'mime_type' => $file->getMimeType(),
        'filename' => $file->getFilename(),
      ];

    } catch (\Exception $e) {
      $this->logger->error('获取文件使用统计失败: @error', ['@error' => $e->getMessage()]);
      return ['reference_count' => 0, 'projects' => [], 'entities' => []];
    }
  }

  /**
   * 获取租户媒体统计。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $period
   *   统计周期。
   * @param string|null $project_id
   *   项目ID过滤。
   *
   * @return array
   *   媒体统计数据。
   */
  public function getTenantMediaStats(string $tenant_id, string $period = '30days', ?string $project_id = null): array
  {
    return $this->getEmptyStats();
  }

  /**
   * 获取项目媒体统计。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $period
   *   统计周期。
   *
   * @return array
   *   项目媒体统计。
   */
  public function getProjectMediaStats(string $project_id, string $period = '30days'): array
  {
    return $this->getEmptyStats();
  }

  /**
   * 获取全局媒体统计。
   *
   * @param string $period
   *   统计周期。
   * @param string|null $tenant_id
   *   租户ID过滤。
   *
   * @return array
   *   全局媒体统计。
   */
  public function getGlobalMediaStats(string $period = '30days', ?string $tenant_id = null): array
  {
    return $this->getEmptyStats();
  }

  /**
   * 获取空统计数据。
   *
   * @return array
   *   空统计数组。
   */
  protected function getEmptyStats(): array
  {
    return [
      'total_files' => 0,
      'total_size' => 0,
      'total_size_formatted' => '0 bytes',
      'image_count' => 0,
      'file_count' => 0,
      'project_count' => 0,
      'generated_at' => time(),
    ];
  }
}