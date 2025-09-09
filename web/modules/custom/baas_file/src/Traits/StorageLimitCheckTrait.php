<?php

declare(strict_types=1);

namespace Drupal\baas_file\Traits;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\baas_project\Service\ResourceLimitManager;

/**
 * 文件存储限制检查特性。
 * 
 * 提供文件上传前的存储空间限制检查功能。
 * 
 * 使用此Trait的类必须提供以下属性或方法：
 * - $database (Connection) 或 database()
 * - $resourceLimitManager (ResourceLimitManager) 或 resourceLimitManager()
 */
trait StorageLimitCheckTrait
{

  /**
   * 检查存储空间是否足够。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $file_size
   *   要上传的文件大小（字节）。
   *
   * @return array
   *   检查结果数组，包含：
   *   - allowed: 是否允许上传
   *   - current_usage: 当前使用量（字节）
   *   - new_total: 上传后的总使用量（字节）
   *   - limit: 存储限制（字节）
   *   - remaining: 剩余空间（字节）
   *   - formatted: 格式化的大小信息
   */
  protected function checkStorageBeforeUpload(string $project_id, int $file_size): array
  {
    try {
      // 获取数据库连接
      $database = property_exists($this, 'database') ? $this->database : $this->database();
      // 获取资源限制管理器
      $resourceLimitManager = property_exists($this, 'resourceLimitManager') ? $this->resourceLimitManager : $this->resourceLimitManager();

      // 获取当前存储使用量
      $current = (int) $database->select('baas_project_file_usage', 'u')
        ->fields('u', ['total_size'])
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchField() ?: 0;

      // 获取项目的存储限制
      $limits = $resourceLimitManager->getEffectiveLimits($project_id);
      $storage_limit = $limits['max_storage'] ?? 0;

      // 计算上传后的总大小
      $new_total = $current + $file_size;
      $allowed = $new_total <= $storage_limit;
      $remaining = max(0, $storage_limit - $current);

      return [
        'allowed' => $allowed,
        'current_usage' => $current,
        'new_total' => $new_total,
        'limit' => $storage_limit,
        'remaining' => $remaining,
        'file_size' => $file_size,
        'formatted' => [
          'current_usage' => $this->formatBytes($current),
          'new_total' => $this->formatBytes($new_total),
          'limit' => $this->formatBytes($storage_limit),
          'remaining' => $this->formatBytes($remaining),
          'file_size' => $this->formatBytes($file_size),
        ],
        'percentage' => $storage_limit > 0 ? ($current / $storage_limit) * 100 : 0,
        'new_percentage' => $storage_limit > 0 ? ($new_total / $storage_limit) * 100 : 0,
      ];

    } catch (\Exception $e) {
      // 出错时记录日志并允许上传（向后兼容）
      \Drupal::logger('baas_file')->error('存储限制检查失败: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
        'file_size' => $file_size,
      ]);

      return [
        'allowed' => true, // 默认允许，确保向后兼容
        'current_usage' => 0,
        'new_total' => $file_size,
        'limit' => 0,
        'remaining' => 0,
        'file_size' => $file_size,
        'formatted' => [
          'current_usage' => '0 B',
          'new_total' => $this->formatBytes($file_size),
          'limit' => '0 B',
          'remaining' => '0 B',
          'file_size' => $this->formatBytes($file_size),
        ],
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * 检查是否启用了存储限制功能。
   *
   * @return bool
   *   如果启用了存储限制功能则返回true。
   */
  protected function isStorageLimitEnabled(): bool
  {
    return $this->configFactory()
      ->get('baas_file.settings')
      ->get('enable_storage_limits') ?? false;
  }

  /**
   * 获取详细的存储限制错误信息。
   *
   * @param array $check_result
   *   存储检查结果。
   *
   * @return array
   *   格式化的错误信息。
   */
  protected function getStorageLimitErrorDetails(array $check_result): array
  {
    return [
      'error_type' => 'STORAGE_LIMIT_EXCEEDED',
      'message' => '存储空间不足，无法上传文件',
      'details' => [
        'current_usage' => $check_result['formatted']['current_usage'],
        'file_size' => $check_result['formatted']['file_size'],
        'would_use' => $check_result['formatted']['new_total'],
        'limit' => $check_result['formatted']['limit'],
        'remaining' => $check_result['formatted']['remaining'],
        'current_percentage' => round($check_result['percentage'], 2) . '%',
        'would_percentage' => round($check_result['new_percentage'], 2) . '%',
      ],
      'suggestions' => [
        '请删除一些不需要的文件以释放空间',
        '或联系管理员增加存储配额',
        '当前文件大小: ' . $check_result['formatted']['file_size'],
        '可用空间: ' . $check_result['formatted']['remaining'],
      ],
    ];
  }

  /**
   * 批量检查多个文件的存储限制。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $files
   *   文件数组，每个元素包含文件信息。
   *
   * @return array
   *   批量检查结果。
   */
  protected function checkMultipleFilesStorage(string $project_id, array $files): array
  {
    $total_size = 0;
    $file_details = [];

    // 计算总大小并收集文件信息
    foreach ($files as $file) {
      $file_size = is_object($file) ? $file->getSize() : ($file['size'] ?? 0);
      $filename = is_object($file) ? $file->getClientOriginalName() : ($file['name'] ?? 'unknown');
      
      $total_size += $file_size;
      $file_details[] = [
        'name' => $filename,
        'size' => $file_size,
        'formatted_size' => $this->formatBytes($file_size),
      ];
    }

    // 检查总存储限制
    $check_result = $this->checkStorageBeforeUpload($project_id, $total_size);

    return [
      'overall_result' => $check_result,
      'total_files' => count($files),
      'total_size' => $total_size,
      'formatted_total_size' => $this->formatBytes($total_size),
      'file_details' => $file_details,
      'allowed' => $check_result['allowed'],
    ];
  }

  /**
   * 格式化字节数为人类可读格式。
   *
   * @param int $bytes
   *   字节数。
   *
   * @return string
   *   格式化后的大小字符串。
   */
  protected function formatBytes(int $bytes): string
  {
    if ($bytes === 0) {
      return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = floor(log($bytes, 1024));
    $power = min($power, count($units) - 1);

    $size = $bytes / pow(1024, $power);
    $formatted = number_format($size, $power > 0 ? 2 : 0);

    return $formatted . ' ' . $units[$power];
  }
}