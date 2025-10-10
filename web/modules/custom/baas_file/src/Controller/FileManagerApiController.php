<?php

declare(strict_types=1);

namespace Drupal\baas_file\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_project\Service\ResourceLimitManager;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Drupal\baas_file\Service\ProjectFileManagerInterface;
use Drupal\baas_file\Traits\StorageLimitCheckTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * 文件管理器专用API控制器。
 *
 * 为文件管理界面提供简化的API接口，支持session认证。
 */
class FileManagerApiController extends ControllerBase {
  use StorageLimitCheckTrait;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly ResourceLimitManager $resourceLimitManager,
    protected readonly UnifiedPermissionCheckerInterface $permissionChecker,
    protected readonly ProjectFileManagerInterface $fileManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('baas_project.manager'),
      $container->get('baas_project.resource_limit_manager'),
      $container->get('baas_auth.unified_permission_checker'),
      $container->get('baas_file.manager'),
    );
  }

  /**
   * 获取项目文件列表。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function getProjectMediaList(string $tenant_id, string $project_id, Request $request): JsonResponse {
    $user_id = (int) $this->currentUser()->id();
    
    // 如果是匿名用户，要求登录
    if ($user_id === 0) {
      return new JsonResponse([
        'success' => false,
        'error' => 'Authentication required. Please log in to access file manager.',
        'code' => 'AUTHENTICATION_REQUIRED',
      ], 401);
    }
    
    // 简化权限检查：直接查询项目成员表
    $has_access = $this->database->select('baas_project_members', 'm')
      ->fields('m', ['user_id'])
      ->condition('user_id', $user_id)
      ->condition('project_id', $project_id)
      ->condition('status', 1)
      ->execute()
      ->fetchField();
    
    if (!$has_access) {
      return new JsonResponse([
        'success' => false,
        'error' => 'Access denied - you are not a member of this project',
        'code' => 'ACCESS_DENIED',
      ], 403);
    }
    
    // 获取分页参数
    $page = max(1, (int) $request->query->get('page', 1));
    $limit = min(100, max(1, (int) $request->query->get('limit', 20)));

    // 获取过滤参数
    $filters = [];
    if ($search = $request->query->get('search')) {
      $filters['search'] = $search;
    }
    if ($type = $request->query->get('type')) {
      $filters['type'] = $type;
    }

    try {
      // 使用ProjectFileManager服务获取文件列表
      $result = $this->fileManager->getProjectMediaList($project_id, $page, $limit, $filters);

      // 格式化文件数据以匹配前端期望的格式
      $formatted_files = [];
      foreach ($result['files'] as $file) {
        $formatted_files[] = [
          'id' => $file['id'],
          'filename' => $file['filename'],
          'url' => $file['url'],
          'filemime' => $file['filemime'],
          'filesize' => $file['filesize'],
          'size_formatted' => $this->formatFileSize($file['filesize']),
          'is_image' => $file['is_image'],
          'created' => $file['created'],
          'changed' => $file['changed'],
          'project_id' => $project_id,
          'tenant_id' => $tenant_id,
        ];
      }

      return new JsonResponse([
        'success' => true,
        'data' => [
          'files' => $formatted_files,
          'pagination' => [
            'page' => $result['page'],
            'limit' => $result['limit'],
            'total' => $result['total'],
            'pages' => $result['pages'],
          ],
        ],
      ]);
      
    } catch (\Exception $e) {
      \Drupal::logger('baas_file')->error('获取项目文件列表失败: @error', ['@error' => $e->getMessage()]);
      
      return new JsonResponse([
        'success' => false,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], 500);
    }
  }

  /**
   * 检查项目媒体访问权限。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果。
   */
  public function accessProjectMedia(string $tenant_id, string $project_id, AccountInterface $account): AccessResult {
    // 临时允许所有用户访问以进行调试
    \Drupal::logger('baas_file')->info('访问检查 - 用户ID: @uid, 项目ID: @pid', [
      '@uid' => $account->id(),
      '@pid' => $project_id,
    ]);
    
    return AccessResult::allowed()->cachePerUser();
  }

  /**
   * 格式化文件大小。
   *
   * @param int $bytes
   *   字节数。
   *
   * @return string
   *   格式化后的文件大小。
   */
  protected function formatFileSize(int $bytes): string {
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

  /**
   * 获取文件详情。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param int $file_id
   *   文件ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function getFileDetail(string $tenant_id, string $project_id, int $file_id, Request $request): JsonResponse {
    $user_id = (int) $this->currentUser()->id();
    
    // 如果是匿名用户，要求登录
    if ($user_id === 0) {
      return new JsonResponse([
        'success' => false,
        'error' => 'Authentication required. Please log in to access file details.',
        'code' => 'AUTHENTICATION_REQUIRED',
      ], 401);
    }
    
    // 检查项目权限
    $has_access = $this->database->select('baas_project_members', 'm')
      ->fields('m', ['user_id'])
      ->condition('user_id', $user_id)
      ->condition('project_id', $project_id)
      ->condition('status', 1)
      ->execute()
      ->fetchField();
    
    if (!$has_access) {
      return new JsonResponse([
        'success' => false,
        'error' => 'Access denied - you are not a member of this project',
        'code' => 'ACCESS_DENIED',
      ], 403);
    }
    
    try {
      // 查询文件详情 - 使用URI匹配而非baas_project_file_access表（兼容demo数据导入）
      $file = $this->database->select('file_managed', 'f')
        ->fields('f', ['fid', 'uid', 'filename', 'uri', 'filemime', 'filesize', 'created', 'changed'])
        ->condition('f.fid', $file_id)
        ->condition('f.uri', '%' . $this->database->escapeLike($project_id) . '%', 'LIKE')
        ->condition('f.status', 1)
        ->execute()
        ->fetchObject();

      if (!$file) {
        return new JsonResponse([
          'success' => false,
          'error' => 'File not found or access denied',
          'code' => 'FILE_NOT_FOUND',
        ], 404);
      }

      // 获取上传者用户信息
      $uploader = null;
      if ($file->uid) {
        $user_storage = \Drupal::entityTypeManager()->getStorage('user');
        $uploader_user = $user_storage->load($file->uid);
        if ($uploader_user) {
          $uploader = $uploader_user->getAccountName();
        }
      }

      // 尝试从baas_project_file_access表获取上传信息（如果存在）
      $access_info = $this->database->select('baas_project_file_access', 'pfa')
        ->fields('pfa', ['timestamp', 'ip_address', 'user_id'])
        ->condition('file_id', (string) $file_id)
        ->condition('project_id', $project_id)
        ->condition('action', 'upload')
        ->orderBy('timestamp', 'DESC')
        ->execute()
        ->fetchObject();

      // 格式化文件详情
      $file_detail = [
        'id' => (int) $file->fid,
        'filename' => $file->filename,
        'url' => \Drupal::service('file_url_generator')->generateAbsoluteString($file->uri),
        'mime_type' => $file->filemime,
        'filesize' => (int) $file->filesize,
        'filesize_formatted' => $this->formatFileSize((int) $file->filesize),
        'is_image' => strpos($file->filemime, 'image/') === 0,
        'created' => (int) $file->created,
        'changed' => (int) $file->changed,
        'uploaded_timestamp' => $access_info ? (int) $access_info->timestamp : (int) $file->created,
        'uploaded_by' => $uploader ?: 'Unknown',
        'uploaded_by_id' => $access_info ? (int) $access_info->user_id : (int) $file->uid,
        'project_id' => $project_id,
        'tenant_id' => $tenant_id,
        'upload_ip' => $access_info ? $access_info->ip_address : 'Unknown',
        'created_formatted' => date('Y-m-d H:i:s', (int) $file->created),
        'changed_formatted' => date('Y-m-d H:i:s', (int) $file->changed),
        'uploaded_formatted' => date('Y-m-d H:i:s', $access_info ? (int) $access_info->timestamp : (int) $file->created),
      ];
      
      return new JsonResponse([
        'success' => true,
        'data' => $file_detail,
      ]);
      
    } catch (\Exception $e) {
      \Drupal::logger('baas_file')->error('获取文件详情失败: @error', ['@error' => $e->getMessage()]);
      
      return new JsonResponse([
        'success' => false,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], 500);
    }
  }

  /**
   * 上传文件。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function uploadFile(string $tenant_id, string $project_id, Request $request): JsonResponse {
    $user_id = (int) $this->currentUser()->id();
    
    // 如果是匿名用户，要求登录
    if ($user_id === 0) {
      return new JsonResponse([
        'success' => false,
        'error' => 'Authentication required. Please log in to upload files.',
        'code' => 'AUTHENTICATION_REQUIRED',
      ], 401);
    }
    
    // 检查项目上传权限（需要管理员或编辑者权限）
    $member_info = $this->database->select('baas_project_members', 'm')
      ->fields('m', ['user_id', 'role'])
      ->condition('user_id', $user_id)
      ->condition('project_id', $project_id)
      ->condition('status', 1)
      ->execute()
      ->fetchObject();
    
    if (!$member_info) {
      return new JsonResponse([
        'success' => false,
        'error' => 'Access denied - you are not a member of this project',
        'code' => 'ACCESS_DENIED',
      ], 403);
    }
    
    // 检查是否有上传权限
    $can_upload = in_array($member_info->role, ['owner', 'admin', 'editor']);
    if (!$can_upload) {
      return new JsonResponse([
        'success' => false,
        'error' => 'Insufficient permissions - upload operation requires owner, admin, or editor role',
        'code' => 'INSUFFICIENT_PERMISSIONS',
      ], 403);
    }
    
    // 使用Drupal的临时存储机制来防止并发重复上传
    $lock_key = "upload_lock_{$user_id}_{$project_id}";
    $temp_store = \Drupal::service('tempstore.private')->get('baas_file_upload');
    
    try {
      $uploaded_files = $request->files->all();
      
      if (empty($uploaded_files)) {
        return new JsonResponse([
          'success' => false,
          'error' => 'No files provided for upload',
          'code' => 'NO_FILES',
        ], 400);
      }

      // 检查存储限制（特性标志控制）
      if ($this->configFactory()->get('baas_file.settings')->get('enable_storage_limits') ?? false) {
        // 收集要上传的文件进行批量检查
        $files_to_check = [];
        foreach ($uploaded_files as $uploaded_file) {
          if ($uploaded_file && $uploaded_file->isValid()) {
            $files_to_check[] = $uploaded_file;
          }
        }

        if (!empty($files_to_check)) {
          $storage_check = $this->checkMultipleFilesStorage($project_id, $files_to_check);
          if (!$storage_check['allowed']) {
            $error_details = $this->getStorageLimitErrorDetails($storage_check['overall_result']);
            
            // 清除上传锁
            $temp_store->delete($lock_key);
            
            return new JsonResponse([
              'success' => false,
              'error' => $error_details['message'],
              'code' => $error_details['error_type'],
              'details' => $error_details['details'],
              'suggestions' => $error_details['suggestions'],
            ], 413); // Request Entity Too Large
          }
        }
      }
      
      // 检查是否有上传正在进行
      $existing_lock = $temp_store->get($lock_key);
      if ($existing_lock && (time() - $existing_lock) < 10) { // 10秒锁定期
        return new JsonResponse([
          'success' => false,
          'error' => 'Upload already in progress, please wait',
          'code' => 'UPLOAD_IN_PROGRESS',
        ], 429); // Too Many Requests
      }
      
      // 设置上传锁
      $temp_store->set($lock_key, time());
      
      $processed_files = [];
      $errors = [];
      
      // 开启事务
      $transaction = $this->database->startTransaction();
      
      try {
        foreach ($uploaded_files as $field_name => $uploaded_file) {
          if (!$uploaded_file || !$uploaded_file->isValid()) {
            $errors[] = "Invalid file upload for field: {$field_name}";
            continue;
          }
          
          // 验证文件类型和大小
          $max_size = 50 * 1024 * 1024; // 50MB
          if ($uploaded_file->getSize() > $max_size) {
            $errors[] = "File {$uploaded_file->getClientOriginalName()} exceeds maximum size of 50MB";
            continue;
          }
          
          // 防重复上传：检查相同文件名和大小的文件是否在最近5分钟内已上传
          $file_name = $uploaded_file->getClientOriginalName();
          $file_size = $uploaded_file->getSize();
          $recent_upload_check = $this->database->select('file_managed', 'f')
            ->fields('f', ['fid'])
            ->condition('filename', $file_name)
            ->condition('filesize', $file_size)
            ->condition('created', time() - 300, '>') // 5分钟内
            ->range(0, 1)
            ->execute()
            ->fetchField();
          
          if ($recent_upload_check) {
            // 检查是否已在此项目中
            $project_file_check = $this->database->select('baas_project_file_access', 'pfa')
              ->fields('pfa', ['file_id'])
              ->condition('file_id', (string) $recent_upload_check)
              ->condition('project_id', $project_id)
              ->condition('action', 'upload')
              ->condition('timestamp', time() - 300, '>') // 5分钟内
              ->execute()
              ->fetchField();
            
            if ($project_file_check) {
              $errors[] = "File {$file_name} was already uploaded recently to this project";
              continue;
            }
          }
          
          // 准备目录（Drupal 11最佳实践）
          $directory = 'public://baas_uploads';
          $file_system = \Drupal::service('file_system');
          if (!$file_system->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS)) {
            $errors[] = "Failed to prepare directory for file: {$uploaded_file->getClientOriginalName()}";
            continue;
          }
          
          // 创建Drupal文件实体
          $file_entity = \Drupal::service('file.repository')->writeData(
            $uploaded_file->getContent(),
            $directory . '/' . $uploaded_file->getClientOriginalName(),
            \Drupal\Core\File\FileSystemInterface::EXISTS_RENAME
          );
          
          if (!$file_entity) {
            $errors[] = "Failed to save file: {$uploaded_file->getClientOriginalName()}";
            continue;
          }
          
          // 设置文件为永久
          $file_entity->setPermanent();
          $file_entity->save();
          
          // 记录项目文件访问
          $this->database->insert('baas_project_file_access')
            ->fields([
              'project_id' => $project_id,
              'file_id' => (string) $file_entity->id(),
              'user_id' => (string) $user_id,
              'action' => 'upload',
              'ip_address' => $request->getClientIp() ?: 'unknown',
              'user_agent' => substr($request->headers->get('User-Agent', ''), 0, 255),
              'timestamp' => time(),
            ])
            ->execute();
          
          $processed_files[] = [
            'id' => $file_entity->id(),
            'filename' => $file_entity->getFilename(),
            'url' => \Drupal::service('file_url_generator')->generateAbsoluteString($file_entity->getFileUri()),
            'filesize' => $file_entity->getSize(),
            'filemime' => $file_entity->getMimeType(),
          ];
        }
        
        if (!empty($errors)) {
          $transaction->rollBack();
          return new JsonResponse([
            'success' => false,
            'error' => 'Upload failed',
            'errors' => $errors,
            'code' => 'UPLOAD_FAILED',
          ], 400);
        }
        
        // 清理上传锁
        $temp_store->delete($lock_key);
        
        return new JsonResponse([
          'success' => true,
          'message' => 'Files uploaded successfully',
          'data' => [
            'files' => $processed_files,
            'count' => count($processed_files),
          ],
        ]);
        
      } catch (\Exception $e) {
        $transaction->rollBack();
        throw $e;
      }
      
    } catch (\Exception $e) {
      \Drupal::logger('baas_file')->error('文件上传失败: @error', ['@error' => $e->getMessage()]);
      
      // 清理上传锁（即使失败也要清理）
      $temp_store->delete($lock_key);
      
      return new JsonResponse([
        'success' => false,
        'error' => 'Failed to upload files',
        'code' => 'UPLOAD_FAILED',
      ], 500);
    }
  }

  /**
   * 删除文件。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param int $file_id
   *   文件ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function deleteFile(string $tenant_id, string $project_id, int $file_id, Request $request): JsonResponse {
    $user_id = (int) $this->currentUser()->id();
    
    // 如果是匿名用户，要求登录
    if ($user_id === 0) {
      return new JsonResponse([
        'success' => false,
        'error' => 'Authentication required. Please log in to delete files.',
        'code' => 'AUTHENTICATION_REQUIRED',
      ], 401);
    }
    
    // 检查项目删除权限（需要管理员或编辑者权限）
    $member_info = $this->database->select('baas_project_members', 'm')
      ->fields('m', ['user_id', 'role'])
      ->condition('user_id', $user_id)
      ->condition('project_id', $project_id)
      ->condition('status', 1)
      ->execute()
      ->fetchObject();
    
    if (!$member_info) {
      return new JsonResponse([
        'success' => false,
        'error' => 'Access denied - you are not a member of this project',
        'code' => 'ACCESS_DENIED',
      ], 403);
    }
    
    // 检查是否有删除权限
    $can_delete = in_array($member_info->role, ['owner', 'admin', 'editor']);
    if (!$can_delete) {
      return new JsonResponse([
        'success' => false,
        'error' => 'Insufficient permissions - delete operation requires owner, admin, or editor role',
        'code' => 'INSUFFICIENT_PERMISSIONS',
      ], 403);
    }
    
    try {
      // 首先检查文件是否存在于项目中（用于幂等性）
      $access_record = $this->database->select('baas_project_file_access', 'pfa')
        ->fields('pfa', ['file_id', 'action'])
        ->condition('file_id', (string) $file_id)
        ->condition('project_id', $project_id)
        ->orderBy('timestamp', 'DESC')
        ->execute()
        ->fetchObject();
      
      // 如果找到已删除记录，返回成功（幂等性）
      if ($access_record && $access_record->action === 'delete') {
        return new JsonResponse([
          'success' => true,
          'message' => 'File was already deleted',
          'data' => [
            'file_id' => $file_id,
            'filename' => 'deleted_file',
            'already_deleted' => true,
          ],
        ]);
      }
      
      // 获取文件信息 - 使用URI匹配而非baas_project_file_access表（兼容demo数据导入）
      $file = $this->database->select('file_managed', 'f')
        ->fields('f', ['fid', 'filename', 'uri'])
        ->condition('f.fid', $file_id)
        ->condition('f.uri', '%' . $this->database->escapeLike($project_id) . '%', 'LIKE')
        ->condition('f.status', 1)
        ->execute()
        ->fetchObject();
      
      if (!$file) {
        // 如果文件不存在但有访问记录，可能已被删除
        if ($access_record) {
          return new JsonResponse([
            'success' => true,
            'message' => 'File was already deleted',
            'data' => [
              'file_id' => $file_id,
              'filename' => 'deleted_file',
              'already_deleted' => true,
            ],
          ]);
        }
        
        return new JsonResponse([
          'success' => false,
          'error' => 'File not found or access denied',
          'code' => 'FILE_NOT_FOUND',
        ], 404);
      }
      
      // 开启事务
      $transaction = $this->database->startTransaction();
      
      try {
        // 删除文件记录
        $this->database->delete('file_managed')
          ->condition('fid', $file_id)
          ->execute();
        
        // 删除项目文件访问记录
        $this->database->delete('baas_project_file_access')
          ->condition('file_id', (string) $file_id)
          ->condition('project_id', $project_id)
          ->execute();
        
        // 尝试删除物理文件
        $file_uri = $file->uri;
        $file_path = \Drupal::service('file_system')->realpath($file_uri);
        if ($file_path && file_exists($file_path)) {
          unlink($file_path);
        }
        
        // 记录删除操作
        $this->database->insert('baas_project_file_access')
          ->fields([
            'project_id' => $project_id,
            'file_id' => (string) $file_id,
            'user_id' => (string) $user_id,
            'action' => 'delete',
            'ip_address' => $request->getClientIp() ?: 'unknown',
            'user_agent' => substr($request->headers->get('User-Agent', ''), 0, 255),
            'timestamp' => time(),
          ])
          ->execute();
        
        return new JsonResponse([
          'success' => true,
          'message' => 'File deleted successfully',
          'data' => [
            'file_id' => $file_id,
            'filename' => $file->filename,
          ],
        ]);
        
      } catch (\Exception $e) {
        $transaction->rollBack();
        throw $e;
      }
      
    } catch (\Exception $e) {
      \Drupal::logger('baas_file')->error('删除文件失败: @error', ['@error' => $e->getMessage()]);
      
      return new JsonResponse([
        'success' => false,
        'error' => 'Failed to delete file',
        'code' => 'DELETE_FAILED',
      ], 500);
    }
  }
}