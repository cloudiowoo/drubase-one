<?php

declare(strict_types=1);

namespace Drupal\baas_file\Controller;

use Drupal\baas_api\Controller\BaseApiController;
use Drupal\baas_api\Service\ApiResponseService;
use Drupal\baas_api\Service\ApiValidationService;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\baas_project\ProjectManagerInterface;

/**
 * 文件API控制器。
 *
 * 处理项目文件的API请求。
 */
class FileApiController extends BaseApiController {

  /**
   * 数据库连接。
   */
  protected Connection $database;

  /**
   * 项目管理器。
   */
  protected ProjectManagerInterface $projectManager;

  /**
   * 构造函数。
   */
  public function __construct(
    ApiResponseService $response_service,
    ApiValidationService $validation_service,
    UnifiedPermissionCheckerInterface $permission_checker,
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    ProjectManagerInterface $project_manager
  ) {
    parent::__construct($response_service, $validation_service, $permission_checker);
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->projectManager = $project_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_api.response'),
      $container->get('baas_api.validation'),
      $container->get('baas_auth.unified_permission_checker'),
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('baas_project.manager')
    );
  }

  /**
   * 获取文件信息。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param int $file_id
   *   文件ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   包含文件信息的JSON响应。
   */
  public function getFileInfo(string $tenant_id, string $project_id, int $file_id): JsonResponse {
    try {
      // 验证项目访问权限
      $project = $this->projectManager->getProject($project_id);
      if (!$project) {
        return $this->createErrorResponse('项目不存在', 'PROJECT_NOT_FOUND', 404);
      }

      if ($project['tenant_id'] !== $tenant_id) {
        return $this->createErrorResponse('项目不属于指定租户', 'PROJECT_TENANT_MISMATCH', 400);
      }

      // 加载文件实体
      $file_storage = $this->entityTypeManager->getStorage('file');
      $file = $file_storage->load($file_id);

      if (!$file) {
        return $this->createErrorResponse('文件不存在', 'FILE_NOT_FOUND', 404);
      }

      // 检查文件是否属于该项目
      $file_access_record = $this->database->select('baas_project_file_access', 'a')
        ->fields('a')
        ->condition('project_id', $project_id)
        ->condition('file_id', $file_id)
        ->condition('action', 'upload')
        ->orderBy('timestamp', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();

      if (!$file_access_record) {
        return $this->createErrorResponse('文件不属于该项目', 'FILE_NOT_IN_PROJECT', 404);
      }

      // 记录文件访问日志
      $this->database->insert('baas_project_file_access')
        ->fields([
          'project_id' => $project_id,
          'file_id' => $file_id,
          'user_id' => $this->getCurrentUserId() ?: 0,
          'action' => 'view',
          'ip_address' => \Drupal::request()->getClientIp(),
          'user_agent' => \Drupal::request()->headers->get('User-Agent'),
          'timestamp' => time(),
        ])
        ->execute();

      // 格式化文件信息
      $file_info = [
        'id' => $file->id(),
        'filename' => $file->getFilename(),
        'original_filename' => $file->getFilename(),
        'uri' => $file->getFileUri(),
        'url' => \Drupal::service('file_url_generator')->generateAbsoluteString($file->getFileUri()),
        'mime_type' => $file->getMimeType(),
        'filesize' => $file->getSize(),
        'filesize_formatted' => $this->formatFileSize($file->getSize()),
        'created' => $file->getCreatedTime(),
        'is_image' => strpos($file->getMimeType(), 'image/') === 0,
        'project_id' => $project_id,
        'tenant_id' => $tenant_id,
        'uploaded_by' => $file_access_record['user_id'],
        'uploaded_at' => $file_access_record['timestamp'],
      ];

      return $this->createSuccessResponse($file_info, '获取文件信息成功');

    } catch (\Exception $e) {
      return $this->createErrorResponse('获取文件信息失败: ' . $e->getMessage(), 'FILE_INFO_ERROR', 500);
    }
  }

  /**
   * 获取项目文件统计。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   包含文件统计的JSON响应。
   */
  public function getProjectFileUsage(string $tenant_id, string $project_id): JsonResponse {
    try {
      // 验证项目访问权限
      $project = $this->projectManager->getProject($project_id);
      if (!$project) {
        return $this->createErrorResponse('项目不存在', 'PROJECT_NOT_FOUND', 404);
      }

      if ($project['tenant_id'] !== $tenant_id) {
        return $this->createErrorResponse('项目不属于指定租户', 'PROJECT_TENANT_MISMATCH', 400);
      }

      // 获取文件使用统计
      $usage_stats = $this->database->select('baas_project_file_usage', 'u')
        ->fields('u')
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchAssoc();

      if (!$usage_stats) {
        $usage_stats = [
          'project_id' => $project_id,
          'file_count' => 0,
          'total_size' => 0,
          'image_count' => 0,
          'document_count' => 0,
          'last_updated' => null,
        ];
      }

      // 格式化统计信息
      $formatted_stats = [
        'project_id' => $project_id,
        'tenant_id' => $tenant_id,
        'file_count' => (int) $usage_stats['file_count'],
        'total_size' => (int) $usage_stats['total_size'],
        'total_size_formatted' => $this->formatFileSize((int) $usage_stats['total_size']),
        'image_count' => (int) $usage_stats['image_count'],
        'document_count' => (int) $usage_stats['document_count'],
        'other_count' => (int) $usage_stats['file_count'] - (int) $usage_stats['image_count'] - (int) $usage_stats['document_count'],
        'last_updated' => $usage_stats['last_updated'] ? (int) $usage_stats['last_updated'] : null,
      ];

      return $this->createSuccessResponse($formatted_stats, '获取项目文件统计成功');

    } catch (\Exception $e) {
      return $this->createErrorResponse('获取文件统计失败: ' . $e->getMessage(), 'FILE_USAGE_ERROR', 500);
    }
  }

  /**
   * 格式化文件大小。
   *
   * @param mixed $bytes
   *   字节数。
   *
   * @return string
   *   格式化后的文件大小。
   */
  protected function formatFileSize($bytes): string {
    $bytes = (int) $bytes;
    
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