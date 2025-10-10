<?php

declare(strict_types=1);

namespace Drupal\baas_file\Controller;

use Drupal\baas_api\Controller\BaseApiController;
use Drupal\baas_file\Service\ProjectFileManagerInterface;
use Drupal\baas_file\Service\FileAccessChecker;
use Drupal\baas_file\Service\FileUsageTracker;
use Drupal\baas_file\Traits\StorageLimitCheckTrait;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_project\Service\ResourceLimitManager;
use Drupal\baas_tenant\Service\TenantManagerInterface;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * BaaS媒体文件API控制器。
 *
 * 提供媒体文件的REST API接口，包括文件列表、详情、上传、删除等功能。
 */
class MediaApiController extends BaseApiController
{
  use StorageLimitCheckTrait;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly ProjectFileManagerInterface $fileManager,
    protected readonly FileAccessChecker $accessChecker,
    protected readonly FileUsageTracker $usageTracker,
    protected readonly ProjectManagerInterface $projectManager,  
    protected readonly TenantManagerInterface $tenantManager,
    protected UnifiedPermissionCheckerInterface $permissionChecker,
    protected readonly Connection $database,
    protected readonly ResourceLimitManager $resourceLimitManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_file.manager'),
      $container->get('baas_file.access_checker'),
      $container->get('baas_file.usage_tracker'),
      $container->get('baas_project.manager'),
      $container->get('baas_tenant.manager'),
      $container->get('baas_auth.unified_permission_checker'),
      $container->get('database'),
      $container->get('baas_project.resource_limit_manager'),
    );
  }

  /**
   * 获取租户媒体文件列表。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   媒体文件列表响应。
   */
  public function getTenantMediaList(string $tenant_id, Request $request): JsonResponse
  {
    try {
      // 验证租户存在
      if (!$this->tenantManager->tenantExists($tenant_id)) {
        return $this->jsonError('租户不存在', 'TENANT_NOT_FOUND', [], Response::HTTP_NOT_FOUND);
      }

      // 获取查询参数
      $page = max(1, (int) $request->query->get('page', 1));
      $limit = min(100, max(10, (int) $request->query->get('limit', 20)));
      $type = $request->query->get('type', 'all'); // all, image, file
      $project_id = $request->query->get('project_id');

      // 获取媒体文件列表
      $filters = [
        'type' => $type,
        'project_id' => $project_id,
      ];

      $result = $this->fileManager->getTenantMediaList($tenant_id, $page, $limit, $filters);

      return $this->jsonSuccess([
        'files' => $result['files'],
        'pagination' => [
          'page' => $page,
          'limit' => $limit,
          'total' => $result['total'],
          'pages' => ceil($result['total'] / $limit),
        ],
        'filters' => $filters,
      ], '媒体文件列表获取成功');

    } catch (\Exception $e) {
      $this->logger->error('获取租户媒体列表失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return $this->jsonError('获取媒体文件列表失败', 'MEDIA_LIST_ERROR');
    }
  }

  /**
   * 获取项目媒体文件列表。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   项目媒体文件列表响应。
   */
  public function getProjectMediaList(string $tenant_id, string $project_id, Request $request): JsonResponse
  {
    try {
      // 验证项目存在
      if (!$this->projectManager->projectExists($project_id)) {
        return $this->jsonError('项目不存在', 'PROJECT_NOT_FOUND', [], Response::HTTP_NOT_FOUND);
      }

      // 获取查询参数
      $page = max(1, (int) $request->query->get('page', 1));
      $limit = min(100, max(10, (int) $request->query->get('limit', 20)));
      $type = $request->query->get('type', 'all');
      $entity_type = $request->query->get('entity_type');
      $field_name = $request->query->get('field_name');

      // 获取项目媒体文件列表
      $filters = [
        'type' => $type,
        'entity_type' => $entity_type,
        'field_name' => $field_name,
      ];

      $result = $this->fileManager->getProjectMediaList($project_id, $page, $limit, $filters);

      return $this->jsonSuccess([
        'files' => $result['files'],
        'pagination' => [
          'page' => $page,
          'limit' => $limit,
          'total' => $result['total'],
          'pages' => ceil($result['total'] / $limit),
        ],
        'filters' => $filters,
        'project_info' => [
          'project_id' => $project_id,
          'tenant_id' => $tenant_id,
        ],
      ], '项目媒体文件列表获取成功');

    } catch (\Exception $e) {
      $this->logger->error('获取项目媒体列表失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return $this->jsonError('获取项目媒体文件列表失败', 'PROJECT_MEDIA_LIST_ERROR');
    }
  }

  /**
   * 获取媒体文件详情。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param int $file_id
   *   文件ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   媒体文件详情响应。
   */
  public function getMediaDetail(string $tenant_id, int $file_id): JsonResponse
  {
    try {
      // 获取文件实体
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
      if (!$file) {
        return $this->jsonError('文件不存在', 'FILE_NOT_FOUND', [], Response::HTTP_NOT_FOUND);
      }

      // 检查文件访问权限
      if (!$this->accessChecker->checkFileAccess($file, 'view')) {
        return $this->jsonError('无权限访问此文件', 'ACCESS_DENIED', [], Response::HTTP_FORBIDDEN);
      }

      // 获取文件详细信息
      $file_info = $this->fileManager->getFileDetail($file_id);
      if (!$file_info) {
        return $this->jsonError('获取文件详情失败', 'FILE_DETAIL_ERROR');
      }

      // 获取文件使用统计
      $usage_stats = $this->usageTracker->getFileUsage($file_id);

      return $this->jsonSuccess([
        'file' => $file_info,
        'usage' => $usage_stats,
      ], '文件详情获取成功');

    } catch (\Exception $e) {
      $this->logger->error('获取媒体文件详情失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return $this->jsonError('获取文件详情失败', 'MEDIA_DETAIL_ERROR');
    }
  }

  /**
   * 上传媒体文件。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   文件上传响应。
   */
  public function uploadMedia(string $tenant_id, string $project_id, Request $request): JsonResponse
  {
    try {
      // 验证项目存在
      if (!$this->projectManager->projectExists($project_id)) {
        return $this->jsonError('项目不存在', 'PROJECT_NOT_FOUND', [], Response::HTTP_NOT_FOUND);
      }

      // 获取上传的文件
      $uploaded_files = $request->files->all();
      if (empty($uploaded_files)) {
        return $this->jsonError('没有上传文件', 'NO_FILES_UPLOADED', [], Response::HTTP_BAD_REQUEST);
      }

      // 检查存储限制（特性标志控制）
      if ($this->isStorageLimitEnabled()) {
        // 收集所有要上传的文件进行批量检查
        $files_to_check = [];
        foreach ($uploaded_files as $field_key => $file_list) {
          if (!is_array($file_list)) {
            $file_list = [$file_list];
          }
          foreach ($file_list as $uploaded_file) {
            $files_to_check[] = $uploaded_file;
          }
        }

        // 执行批量存储限制检查
        $storage_check = $this->checkMultipleFilesStorage($project_id, $files_to_check);
        if (!$storage_check['allowed']) {
          $error_details = $this->getStorageLimitErrorDetails($storage_check['overall_result']);
          return $this->jsonError(
            $error_details['message'],
            $error_details['error_type'],
            $error_details['details'],
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE
          );
        }
      }

      // 获取上传参数
      $field_name = $request->request->get('field_name');
      $entity_type = $request->request->get('entity_type');
      $description = $request->request->get('description', '');

      $results = [];
      $errors = [];

      foreach ($uploaded_files as $field_key => $file_list) {
        if (!is_array($file_list)) {
          $file_list = [$file_list];
        }

        foreach ($file_list as $uploaded_file) {
          try {
            $upload_result = $this->fileManager->uploadFile(
              $project_id,
              $uploaded_file,
              [
                'field_name' => $field_name,
                'entity_type' => $entity_type,
                'description' => $description,
              ]
            );

            $results[] = $upload_result;
          } catch (\Exception $e) {
            $errors[] = [
              'filename' => $uploaded_file->getClientOriginalName(),
              'error' => $e->getMessage(),
            ];
          }
        }
      }

      if (!empty($errors) && empty($results)) {
        return $this->jsonError('文件上传失败', 'UPLOAD_FAILED', ['errors' => $errors], Response::HTTP_BAD_REQUEST);
      }

      $response_data = [
        'uploaded_files' => $results,
        'errors' => $errors,
        'success_count' => count($results),
        'error_count' => count($errors),
      ];

      // 如果启用了存储限制，添加存储使用信息
      if ($this->isStorageLimitEnabled()) {
        $storage_info = $this->checkStorageBeforeUpload($project_id, 0); // 获取当前状态
        $response_data['storage_info'] = [
          'current_usage' => $storage_info['formatted']['current_usage'],
          'limit' => $storage_info['formatted']['limit'],
          'remaining' => $storage_info['formatted']['remaining'],
          'usage_percentage' => round($storage_info['percentage'], 2) . '%',
        ];
      }

      return $this->jsonSuccess($response_data, '文件上传完成');

    } catch (\Exception $e) {
      $this->logger->error('媒体文件上传失败: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
        'tenant_id' => $tenant_id,
      ]);
      return $this->jsonError('文件上传失败', 'MEDIA_UPLOAD_ERROR');
    }
  }

  /**
   * 删除媒体文件。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param int $file_id
   *   文件ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   文件删除响应。
   */
  public function deleteMedia(string $tenant_id, int $file_id): JsonResponse
  {
    try {
      // 获取文件实体
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
      if (!$file) {
        return $this->jsonError('文件不存在', 'FILE_NOT_FOUND', [], Response::HTTP_NOT_FOUND);
      }

      // 检查删除权限
      if (!$this->accessChecker->checkFileAccess($file, 'delete')) {
        return $this->jsonError('无权限删除此文件', 'ACCESS_DENIED', [], Response::HTTP_FORBIDDEN);
      }

      // 检查文件使用情况
      $usage = $this->usageTracker->getFileUsage($file_id);
      if ($usage['reference_count'] > 0) {
        return $this->jsonError('文件正在被使用，无法删除', 'FILE_IN_USE', [
          'usage' => $usage,
        ], Response::HTTP_CONFLICT);
      }

      // 删除文件
      $delete_result = $this->fileManager->deleteFile($file_id);
      if (!$delete_result) {
        return $this->jsonError('文件删除失败', 'DELETE_FAILED');
      }

      return $this->jsonSuccess([
        'file_id' => $file_id,
        'deleted_at' => date('Y-m-d H:i:s'),
      ], '文件删除成功');

    } catch (\Exception $e) {
      $this->logger->error('删除媒体文件失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return $this->jsonError('文件删除失败', 'MEDIA_DELETE_ERROR');
    }
  }

  /**
   * 获取媒体文件统计。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   媒体统计响应。
   */
  public function getMediaStats(string $tenant_id, Request $request): JsonResponse
  {
    try {
      // 验证租户存在
      if (!$this->tenantManager->tenantExists($tenant_id)) {
        return $this->jsonError('租户不存在', 'TENANT_NOT_FOUND', [], Response::HTTP_NOT_FOUND);
      }

      // 获取统计参数
      $period = $request->query->get('period', '30days'); // 7days, 30days, 90days, all
      $project_id = $request->query->get('project_id');

      // 获取媒体统计数据
      $stats = $this->usageTracker->getTenantMediaStats($tenant_id, $period, $project_id);

      return $this->jsonSuccess([
        'stats' => $stats,
        'tenant_id' => $tenant_id,
        'period' => $period,
        'generated_at' => date('Y-m-d H:i:s'),
      ], '媒体统计获取成功');

    } catch (\Exception $e) {
      $this->logger->error('获取媒体统计失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return $this->jsonError('获取媒体统计失败', 'MEDIA_STATS_ERROR');
    }
  }

  /**
   * 获取全局媒体统计（管理员）。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   全局媒体统计响应。
   */
  public function getGlobalMediaStats(Request $request): JsonResponse
  {
    try {
      // 获取统计参数
      $period = $request->query->get('period', '30days');
      $tenant_id = $request->query->get('tenant_id');

      // 获取全局媒体统计
      $stats = $this->usageTracker->getGlobalMediaStats($period, $tenant_id);

      return $this->jsonSuccess([
        'global_stats' => $stats,
        'period' => $period,
        'filter' => [
          'tenant_id' => $tenant_id,
        ],
        'generated_at' => date('Y-m-d H:i:s'),
      ], '全局媒体统计获取成功');

    } catch (\Exception $e) {
      $this->logger->error('获取全局媒体统计失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return $this->jsonError('获取全局媒体统计失败', 'GLOBAL_MEDIA_STATS_ERROR');
    }
  }

  /**
   * 租户媒体访问权限检查。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   *
   * @return \Drupal\Core\Access\AccessResult
   *   访问结果。
   */
  public function accessTenantMedia(string $tenant_id, AccountInterface $account): AccessResult
  {
    // 检查租户访问权限
    $has_permission = $this->permissionChecker->checkPermission(
      'view_tenant_media',
      'tenant',
      ['tenant_id' => $tenant_id]
    );

    return $has_permission ? AccessResult::allowed() : AccessResult::forbidden();
  }

  /**
   * 项目媒体访问权限检查。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   *
   * @return \Drupal\Core\Access\AccessResult
   *   访问结果。
   */
  public function accessProjectMedia(string $tenant_id, string $project_id, AccountInterface $account): AccessResult
  {
    // 检查项目媒体访问权限
    $has_permission = $this->permissionChecker->checkPermission(
      'view_project_files',
      'project',
      [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
      ]
    );

    return $has_permission ? AccessResult::allowed() : AccessResult::forbidden();
  }

  /**
   * 媒体文件访问权限检查。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param int $file_id
   *   文件ID。
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   *
   * @return \Drupal\Core\Access\AccessResult
   *   访问结果。
   */
  public function accessMediaFile(string $tenant_id, int $file_id, AccountInterface $account): AccessResult
  {
    try {
      // 获取文件实体
      $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
      if (!$file) {
        return AccessResult::forbidden('文件不存在');
      }

      // 使用文件访问检查器
      $has_access = $this->accessChecker->checkFileAccess($file, 'view');
      return $has_access ? AccessResult::allowed() : AccessResult::forbidden();

    } catch (\Exception $e) {
      return AccessResult::forbidden('权限检查失败');
    }
  }
}