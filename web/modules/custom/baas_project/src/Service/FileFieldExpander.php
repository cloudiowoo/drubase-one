<?php

declare(strict_types=1);

namespace Drupal\baas_project\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Psr\Log\LoggerInterface;

/**
 * 文件字段扩展服务。
 * 
 * 自动检测和扩展实体数据中的文件引用字段，添加完整的文件元数据。
 */
class FileFieldExpander {

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly FileUrlGeneratorInterface $fileUrlGenerator,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * 扩展实体数据中的文件字段。
   * 
   * @param array $entity_data
   *   原始实体数据。
   * 
   * @return array
   *   扩展后的实体数据，包含文件元数据。
   */
  public function expandFileFields(array $entity_data): array {
    $this->logger->info('FileFieldExpander.expandFileFields - 开始处理实体数据: @data', [
      '@data' => json_encode($entity_data)
    ]);
    
    $expanded_data = $entity_data;

    foreach ($entity_data as $field_name => $field_value) {
      // 跳过系统字段和非文件字段
      if ($this->isSystemField($field_name)) {
        continue;
      }

      // 检查是否应该扩展此字段
      $this->logger->info('FileFieldExpander - 检查字段: @field_name = @field_value', [
        '@field_name' => $field_name,
        '@field_value' => $field_value,
      ]);
      
      if ($this->shouldExpandFileField($field_name, $field_value)) {
        $this->logger->info('FileFieldExpander - 字段符合扩展条件，获取文件元数据: @field_name -> @file_id', [
          '@field_name' => $field_name,
          '@file_id' => $field_value,
        ]);
        
        $file_metadata = $this->getFileMetadata((int) $field_value);
        if ($file_metadata) {
          // 添加文件元数据字段
          $expanded_data[$field_name . '_file'] = $file_metadata;
          
          $this->logger->info('文件字段已扩展: @field_name -> @metadata', [
            '@field_name' => $field_name,
            '@metadata' => json_encode($file_metadata),
          ]);
        } else {
          $this->logger->warning('FileFieldExpander - 无法获取文件元数据: @field_name -> @file_id', [
            '@field_name' => $field_name,
            '@file_id' => $field_value,
          ]);
        }
      } else {
        $this->logger->info('FileFieldExpander - 字段不符合扩展条件: @field_name', [
          '@field_name' => $field_name,
        ]);
      }
    }

    $this->logger->info('FileFieldExpander.expandFileFields - 处理完成，返回结果: @result', [
      '@result' => json_encode($expanded_data)
    ]);

    return $expanded_data;
  }

  /**
   * 判断字段是否应该进行文件扩展。
   * 
   * @param string $field_name
   *   字段名称。
   * @param mixed $field_value
   *   字段值。
   * 
   * @return bool
   *   是否应该扩展。
   */
  protected function shouldExpandFileField(string $field_name, $field_value): bool {
    // 1. 检查值是否为数字（文件ID）
    if (!is_numeric($field_value)) {
      return false;
    }
    
    $file_id = (int) $field_value;
    if ($file_id <= 0) {
      return false;
    }
    
    // 2. 检查文件是否存在
    $file = File::load($file_id);
    if (!$file) {
      return false;
    }
    
    // 3. 检查字段名是否像文件字段 OR 文件是媒体类型
    return $this->looksLikeFileField($field_name) || $this->isMediaFile($file);
  }

  /**
   * 检查字段名是否像文件字段。
   * 
   * @param string $field_name
   *   字段名称。
   * 
   * @return bool
   *   是否像文件字段。
   */
  protected function looksLikeFileField(string $field_name): bool {
    // 将字段名转为小写进行匹配
    $field_name_lower = strtolower($field_name);
    
    $file_patterns = [
      // 头像相关
      'avatar', 'avat', 'profile', 'photo', 'pic', 'picture',
      // 图片相关
      'image', 'img', 'logo', 'icon', 'banner', 'cover', 'thumb', 'thumbnail',
      // 文件相关
      'file', 'upload', 'attachment', 'document', 'doc',
      // 证书相关
      'certificate', 'cert', 'license', 'credential',
      // 媒体相关
      'media', 'asset', 'resource',
    ];
    
    foreach ($file_patterns as $pattern) {
      if (strpos($field_name_lower, $pattern) !== false) {
        return true;
      }
    }
    
    return false;
  }

  /**
   * 检查文件是否为媒体文件。
   * 
   * @param \Drupal\file\Entity\File $file
   *   文件实体。
   * 
   * @return bool
   *   是否为媒体文件。
   */
  protected function isMediaFile(File $file): bool {
    $mime_type = $file->getMimeType();
    
    $media_types = [
      'image/', 'video/', 'audio/', 
      'application/pdf', 'application/msword', 'application/vnd.ms-excel',
      'application/vnd.openxmlformats-officedocument',
    ];
    
    foreach ($media_types as $media_type) {
      if (strpos($mime_type, $media_type) === 0) {
        return true;
      }
    }
    
    return false;
  }

  /**
   * 获取文件元数据。
   * 
   * @param int $file_id
   *   文件ID。
   * 
   * @return array|null
   *   文件元数据或null。
   */
  protected function getFileMetadata(int $file_id): ?array {
    try {
      $file = File::load($file_id);
      if (!$file) {
        return null;
      }

      // 生成文件URL
      $file_uri = $file->getFileUri();
      $file_url = $this->fileUrlGenerator->generateAbsoluteString($file_uri);

      // 获取文件大小的友好格式
      $file_size = $file->getSize();
      $size_formatted = $this->formatFileSize($file_size);

      // 判断是否为图片
      $mime_type = $file->getMimeType();
      $is_image = strpos($mime_type, 'image/') === 0;

      return [
        'id' => $file->id(),
        'filename' => $file->getFilename(),
        'url' => $file_url,
        'filesize' => $file_size,
        'size_formatted' => $size_formatted,
        'mime_type' => $mime_type,
        'is_image' => $is_image,
        'created' => $file->getCreatedTime(),
        'uri' => $file_uri,
      ];

    } catch (\Exception $e) {
      $this->logger->error('获取文件元数据失败: @file_id - @error', [
        '@file_id' => $file_id,
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }

  /**
   * 格式化文件大小。
   * 
   * @param int $bytes
   *   字节数。
   * 
   * @return string
   *   格式化后的大小。
   */
  protected function formatFileSize(int $bytes): string {
    if ($bytes >= 1073741824) {
      return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
      return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
      return number_format($bytes / 1024, 2) . ' KB';
    } else {
      return $bytes . ' bytes';
    }
  }

  /**
   * 检查是否为系统字段。
   * 
   * @param string $field_name
   *   字段名称。
   * 
   * @return bool
   *   是否为系统字段。
   */
  protected function isSystemField(string $field_name): bool {
    $system_fields = [
      'id', 'uuid', 'created', 'updated', 'tenant_id', 'project_id',
    ];
    
    return in_array($field_name, $system_fields);
  }
}