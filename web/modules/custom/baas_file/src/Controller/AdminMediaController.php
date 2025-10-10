<?php

declare(strict_types=1);

namespace Drupal\baas_file\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\baas_file\Service\FileUsageTracker;
use Drupal\baas_tenant\Service\TenantManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * 系统管理员媒体文件管理控制器。
 *
 * 提供全局媒体文件管理和监控功能。
 */
class AdminMediaController extends ControllerBase
{

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly FileUsageTracker $usageTracker,
    protected readonly TenantManagerInterface $tenantManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_file.usage_tracker'),
      $container->get('baas_tenant.manager'),
    );
  }

  /**
   * 全局媒体文件管理页面。
   *
   * @return array
   *   渲染数组。
   */
  public function mediaManager(): array
  {
    // 获取全局媒体统计
    $global_stats = $this->usageTracker->getGlobalMediaStats('30days');
    
    // 获取租户列表和统计
    $tenants = $this->tenantManager->listTenants();
    $tenant_stats = [];
    
    foreach ($tenants as $tenant_id => $tenant_info) {
      $tenant_stats[$tenant_id] = [
        'info' => $tenant_info,
        'media_stats' => $this->usageTracker->getTenantMediaStats($tenant_id, '30days'),
      ];
    }

    // 获取系统级别的存储统计
    $storage_stats = $this->getSystemStorageStats();

    $build = [
      '#theme' => 'baas_file_admin_media_manager',
      '#global_stats' => $global_stats,
      '#tenant_stats' => $tenant_stats,
      '#storage_stats' => $storage_stats,
      '#attached' => [
        'library' => [
          'baas_file/admin-media-manager',
        ],
        'drupalSettings' => [
          'baasFile' => [
            'admin' => TRUE,
            'apiEndpoints' => [
              'globalStats' => '/api/v1/admin/media/stats',
              'tenantMedia' => '/api/v1/{tenant_id}/media',
            ],
          ],
        ],
      ],
    ];

    return $build;
  }

  /**
   * 获取系统存储统计信息。
   *
   * @return array
   *   存储统计数组。
   */
  protected function getSystemStorageStats(): array
  {
    try {
      // 获取文件系统统计
      $file_storage = \Drupal::entityTypeManager()->getStorage('file');
      
      // 统计文件数量和大小
      $query = $file_storage->getQuery()
        ->accessCheck(FALSE);
      $total_files = $query->count()->execute();

      // 获取总文件大小
      $connection = \Drupal::database();
      $size_query = $connection->select('file_managed', 'f')
        ->addExpression('SUM(filesize)', 'total_size')
        ->addExpression('COUNT(*)', 'file_count')
        ->addExpression('AVG(filesize)', 'avg_size');
      
      $size_result = $size_query->execute()->fetchAssoc();

      // 按文件类型统计
      $type_query = $connection->select('file_managed', 'f')
        ->fields('f', ['filemime'])
        ->addExpression('COUNT(*)', 'count')
        ->addExpression('SUM(filesize)', 'size')
        ->groupBy('f.filemime')
        ->orderBy('count', 'DESC');
      
      $type_stats = $type_query->execute()->fetchAllAssoc('filemime');

      // 按创建时间统计（最近30天）
      $time_query = $connection->select('file_managed', 'f')
        ->addExpression('DATE(FROM_UNIXTIME(created))', 'date')
        ->addExpression('COUNT(*)', 'count')
        ->addExpression('SUM(filesize)', 'size')
        ->condition('created', time() - (30 * 24 * 60 * 60), '>')
        ->groupBy('DATE(FROM_UNIXTIME(created))')
        ->orderBy('date', 'DESC');
      
      $time_stats = $time_query->execute()->fetchAllKeyed();

      return [
        'total_files' => (int) $size_result['file_count'],
        'total_size' => (int) $size_result['total_size'],
        'average_size' => (int) $size_result['avg_size'],
        'total_size_formatted' => format_size($size_result['total_size']),
        'average_size_formatted' => format_size($size_result['avg_size']),
        'by_type' => $type_stats,
        'recent_uploads' => $time_stats,
      ];

    } catch (\Exception $e) {
      \Drupal::logger('baas_file')->error('获取系统存储统计失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      
      return [
        'total_files' => 0,
        'total_size' => 0,
        'average_size' => 0,
        'total_size_formatted' => '0 bytes',
        'average_size_formatted' => '0 bytes',
        'by_type' => [],
        'recent_uploads' => [],
      ];
    }
  }
}