<?php

declare(strict_types=1);

namespace Drupal\baas_project\Controller;

use Drupal\baas_api\Controller\BaseApiController;
use Drupal\baas_api\Service\ApiResponseService;
use Drupal\baas_api\Service\ApiValidationService;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\baas_entity\Service\TemplateManagerInterface;
use Drupal\baas_entity\Controller\EntityDataApiController;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\baas_project\Service\ProjectTableNameGenerator;
use Drupal\baas_file\Service\ProjectFileManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\HttpFoundation\Response;
use Drupal\baas_entity\Service\FieldTypeManager;
use Drupal\baas_project\Service\FileFieldExpander;

/**
 * 项目实体API控制器。
 *
 * 处理项目级实体模板和数据的API请求。
 */
class ProjectEntityApiController extends BaseApiController {

  /**
   * 模板管理服务。
   */
  protected TemplateManagerInterface $templateManager;

  /**
   * 实体数据API控制器。
   */
  protected EntityDataApiController $entityDataController;

  /**
   * 项目管理服务。
   */
  protected ProjectManagerInterface $projectManager;

  /**
   * 数据库连接。
   */
  protected Connection $database;

  /**
   * 项目表名生成器。
   */
  protected ProjectTableNameGenerator $tableNameGenerator;

  /**
   * 项目文件管理器。
   */
  protected ProjectFileManagerInterface $fileManager;

  /**
   * 文件系统服务。
   */
  protected FileSystemInterface $fileSystem;

  /**
   * 字段类型管理器。
   */
  protected FieldTypeManager $fieldTypeManager;

  /**
   * 文件字段扩展器。
   */
  protected FileFieldExpander $fileFieldExpander;

  /**
   * 构造函数。
   */
  public function __construct(
    ApiResponseService $apiResponseService,
    ApiValidationService $apiValidationService,
    UnifiedPermissionCheckerInterface $permissionChecker,
    TemplateManagerInterface $templateManager,
    EntityDataApiController $entityDataController,
    ProjectManagerInterface $projectManager,
    Connection $database,
    ProjectTableNameGenerator $tableNameGenerator,
    ProjectFileManagerInterface $fileManager,
    FileSystemInterface $fileSystem,
    FieldTypeManager $fieldTypeManager,
    FileFieldExpander $fileFieldExpander
  ) {
    parent::__construct($apiResponseService, $apiValidationService, $permissionChecker);
    $this->templateManager = $templateManager;
    $this->entityDataController = $entityDataController;
    $this->projectManager = $projectManager;
    $this->database = $database;
    $this->tableNameGenerator = $tableNameGenerator;
    $this->fileManager = $fileManager;
    $this->fileSystem = $fileSystem;
    $this->fieldTypeManager = $fieldTypeManager;
    $this->fileFieldExpander = $fileFieldExpander;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('baas_api.response'),
      $container->get('baas_api.validation'),
      $container->get('baas_auth.unified_permission_checker'),
      $container->get('baas_entity.template_manager'),
      $container->get('class_resolver')->getInstanceFromDefinition(EntityDataApiController::class),
      $container->get('baas_project.manager'),
      $container->get('database'),
      $container->get('baas_project.table_name_generator'),
      $container->get('baas_file.manager'),
      $container->get('file_system'),
      $container->get('baas_entity.field_type_manager'),
      $container->get('baas_project.file_field_expander')
    );
  }

  /**
   * 测试文件上传功能。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function testFileUpload(Request $request): JsonResponse {
    \Drupal::logger('baas_project')->info('文件上传测试请求开始');
    
    try {
      $content_type = $request->headers->get('content-type', '');
      $method = $request->getMethod();
      
      $debug_info = [
        'method' => $method,
        'content_type' => $content_type,
        'content_length' => $request->headers->get('content-length', '0'),
        'headers' => $request->headers->all(),
      ];
      
      if (strpos($content_type, 'multipart/form-data') !== false) {
        $form_data = $request->request->all();
        $files = $request->files->all();
        
        $debug_info['form_data'] = $form_data;
        $debug_info['files_count'] = count($files);
        $debug_info['files_info'] = [];
        
        foreach ($files as $field_name => $file) {
          if ($file) {
            $debug_info['files_info'][$field_name] = [
              'original_name' => $file->getClientOriginalName(),
              'size' => $file->getSize(),
              'mime_type' => $file->getMimeType(),
              'error' => $file->getError(),
              'is_valid' => $file->isValid(),
            ];
          }
        }
        
        \Drupal::logger('baas_project')->info('文件上传测试成功: @info', [
          '@info' => json_encode($debug_info),
        ]);
        
        return new JsonResponse([
          'success' => true,
          'message' => '文件上传测试成功',
          'debug' => $debug_info,
        ]);
      } else {
        return new JsonResponse([
          'success' => false,
          'message' => '不是multipart请求',
          'debug' => $debug_info,
        ]);
      }
      
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('文件上传测试失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      
      return new JsonResponse([
        'success' => false,
        'message' => '文件上传测试失败: ' . $e->getMessage(),
        'error' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * 最简单的multipart测试（无需认证）。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function simpleMultipartTest(Request $request): JsonResponse {
    try {
      $content_type = $request->headers->get('content-type', '');
      
      return new JsonResponse([
        'success' => true,
        'message' => '最简单的multipart测试成功',
        'debug' => [
          'method' => $request->getMethod(),
          'content_type' => $content_type,
          'is_multipart' => strpos($content_type, 'multipart/form-data') !== false,
          'form_data' => $request->request->all(),
          'files_count' => count($request->files->all()),
          'auth_header' => $request->headers->get('Authorization', 'none'),
          'api_key_header' => $request->headers->get('X-API-Key', 'none'),
          // 添加全局变量调试
          'global_post' => $_POST,
          'global_files_keys' => array_keys($_FILES),
          'raw_content_preview' => substr($request->getContent(), 0, 200),
        ],
      ]);
    } catch (\Exception $e) {
      return new JsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * 简单的配置检查（无需认证）。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function simpleConfigCheck(Request $request): JsonResponse {
    $config = [
      'php_version' => PHP_VERSION,
      'upload_max_filesize' => ini_get('upload_max_filesize'),
      'post_max_size' => ini_get('post_max_size'),
      'max_file_uploads' => ini_get('max_file_uploads'),
      'file_uploads' => ini_get('file_uploads') ? 'enabled' : 'disabled',
      'memory_limit' => ini_get('memory_limit'),
      'max_execution_time' => ini_get('max_execution_time'),
      'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
      'tmp_dir_writable' => is_writable(ini_get('upload_tmp_dir') ?: sys_get_temp_dir()),
    ];
    
    return new JsonResponse([
      'success' => true,
      'message' => '配置检查完成',
      'config' => $config,
      'server_info' => [
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
        'request_method' => $request->getMethod(),
        'content_type' => $request->headers->get('content-type', 'none'),
      ],
    ]);
  }

  /**
   * 检查PHP配置。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function testPhpConfig(Request $request): JsonResponse {
    $config = [
      'upload_max_filesize' => ini_get('upload_max_filesize'),
      'post_max_size' => ini_get('post_max_size'),
      'max_file_uploads' => ini_get('max_file_uploads'),
      'memory_limit' => ini_get('memory_limit'),
      'max_execution_time' => ini_get('max_execution_time'),
      'file_uploads' => ini_get('file_uploads') ? 'On' : 'Off',
      'upload_tmp_dir' => ini_get('upload_tmp_dir') ?: '/tmp',
    ];
    
    return new JsonResponse([
      'success' => true,
      'message' => 'PHP配置信息',
      'config' => $config,
    ]);
  }

  /**
   * 获取项目的所有实体模板。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   包含模板列表的JSON响应。
   */
  public function listTemplates(string $tenant_id, string $project_id): JsonResponse {
    try {
      // 验证项目访问权限
      $project = $this->projectManager->getProject($project_id);
      if (!$project) {
        return $this->responseService->createErrorResponse('项目不存在', 'PROJECT_NOT_FOUND', 404);
      }

      // 获取项目的租户ID
      $tenant_id = $project['tenant_id'];

      // 获取项目下的实体模板
      $templates = $this->database->select('baas_entity_template', 'bet')
        ->fields('bet')
        ->condition('project_id', $project_id)
        ->condition('status', 1)
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      // 格式化模板数据
      $formatted_templates = [];
      foreach ($templates as $template) {
        $formatted_templates[] = [
          'id' => $template['id'],
          'name' => $template['name'],
          'label' => $template['label'],
          'description' => $template['description'],
          'project_id' => $template['project_id'],
          'tenant_id' => $template['tenant_id'],
          'status' => (int) $template['status'],
          'created' => (int) $template['created'],
          'updated' => (int) $template['updated'],
          'settings' => $template['settings'] ? json_decode($template['settings'], true) : [],
        ];
      }

      return $this->responseService->createSuccessResponse([
        'templates' => $formatted_templates,
        'project_id' => $project_id,
        'tenant_id' => $tenant_id,
        'count' => count($formatted_templates),
      ], '成功获取项目实体模板');

    } catch (\Exception $e) {
      return $this->responseService->createErrorResponse('获取模板失败: ' . $e->getMessage(), 'TEMPLATE_FETCH_ERROR', 500);
    }
  }

  /**
   * 获取项目的特定实体模板。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $template_name
   *   模板名称。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   包含模板详情的JSON响应。
   */
  public function getTemplate(string $tenant_id, string $project_id, string $template_name): JsonResponse {
    try {
      // 验证项目访问权限
      $project = $this->projectManager->getProject($project_id);
      if (!$project) {
        return $this->responseService->createErrorResponse('项目不存在', 'PROJECT_NOT_FOUND', 404);
      }

      // 获取模板详情
      $template = $this->database->select('baas_entity_template', 'bet')
        ->fields('bet')
        ->condition('project_id', $project_id)
        ->condition('name', $template_name)
        ->condition('status', 1)
        ->execute()
        ->fetchAssoc();

      if (!$template) {
        return $this->responseService->createErrorResponse('模板不存在', 'TEMPLATE_NOT_FOUND', 404);
      }

      // 获取模板字段
      $fields = $this->database->select('baas_entity_field', 'bef')
        ->fields('bef')
        ->condition('template_id', $template['id'])
        ->orderBy('weight', 'ASC')
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      // 格式化字段数据
      $formatted_fields = [];
      foreach ($fields as $field) {
        $formatted_fields[] = [
          'id' => $field['id'],
          'name' => $field['name'],
          'label' => $field['label'],
          'type' => $field['type'],
          'required' => (bool) $field['required'],
          'settings' => $field['settings'] ? json_decode($field['settings'], true) : [],
          'weight' => (int) $field['weight'],
        ];
      }

      return $this->responseService->createSuccessResponse([
        'template' => [
          'id' => $template['id'],
          'name' => $template['name'],
          'label' => $template['label'],
          'description' => $template['description'],
          'project_id' => $template['project_id'],
          'tenant_id' => $template['tenant_id'],
          'status' => (int) $template['status'],
          'created' => (int) $template['created'],
          'updated' => (int) $template['updated'],
          'settings' => $template['settings'] ? json_decode($template['settings'], true) : [],
          'fields' => $formatted_fields,
        ],
      ], '成功获取项目实体模板');

    } catch (\Exception $e) {
      return $this->responseService->createErrorResponse('获取模板失败: ' . $e->getMessage(), 'TEMPLATE_FETCH_ERROR', 500);
    }
  }

  /**
   * 获取项目实体数据列表。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   包含实体数据列表的JSON响应。
   */
  public function listEntities(string $project_id, string $entity_name, Request $request): JsonResponse {
    try {
      // 验证项目访问权限
      $project = $this->projectManager->getProject($project_id);
      if (!$project) {
        return $this->responseService->createErrorResponse('项目不存在', 'PROJECT_NOT_FOUND', 404);
      }

      $tenant_id = $project['tenant_id'];
      
      // 检查API限流
      $endpoint = $request->getPathInfo();
      $limit_check = $this->requireResourceLimits($project_id, 'api_calls', 1, $endpoint);
      if ($limit_check !== null) {
        return $limit_check;
      }

      // 验证实体模板存在
      $template = $this->database->select('baas_entity_template', 'bet')
        ->fields('bet', ['id', 'name'])
        ->condition('project_id', $project_id)
        ->condition('name', $entity_name)
        ->condition('status', 1)
        ->execute()
        ->fetchAssoc();

      if (!$template) {
        return $this->responseService->createErrorResponse('实体模板不存在', 'TEMPLATE_NOT_FOUND', 404);
      }

      // 构建动态表名
      $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);

      // 检查表是否存在
      if (!$this->database->schema()->tableExists($table_name)) {
        return $this->responseService->createSuccessResponse([
          'entities' => [],
          'project_id' => $project_id,
          'entity_name' => $entity_name,
          'count' => 0,
          'table_name' => $table_name,
        ], '实体表不存在，返回空列表');
      }

      // 分页参数
      $page = max(1, (int) $request->query->get('page', 1));
      $limit = min(100, max(1, (int) $request->query->get('limit', 20)));
      $offset = ($page - 1) * $limit;

      // 查询实体数据
      $query = $this->database->select($table_name, 'e')
        ->fields('e')
        ->range($offset, $limit)
        ->orderBy('id', 'DESC');

      // 处理过滤条件
      $filters = $request->query->all('filter') ?? [];
      if (!empty($filters)) {
        foreach ($filters as $field => $value) {
          if ($value !== '' && $value !== null) {
            // 支持基本的等值匹配
            $query->condition($field, $value);
          }
        }
      }

      // 调试日志：记录查询条件
      if (!empty($filters)) {
        \Drupal::logger('baas_project')->info('ProjectEntityApiController: 应用过滤条件 - table: @table, filters: @filters', [
          '@table' => $table_name,
          '@filters' => json_encode($filters),
        ]);
      }

      $entities = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

      // 获取总数（也要应用相同的过滤条件）
      $total_query = $this->database->select($table_name, 'e');
      $total_query->addExpression('COUNT(*)', 'count');
      
      // 为总数查询也应用过滤条件
      if (!empty($filters)) {
        foreach ($filters as $field => $value) {
          if ($value !== '' && $value !== null) {
            $total_query->condition($field, $value);
          }
        }
      }
      
      $total = $total_query->execute()->fetchField();

      return $this->responseService->createSuccessResponse([
        'entities' => $entities,
        'project_id' => $project_id,
        'entity_name' => $entity_name,
        'pagination' => [
          'page' => $page,
          'limit' => $limit,
          'total' => (int) $total,
          'pages' => ceil($total / $limit),
        ],
      ], '成功获取项目实体数据');

    } catch (\Exception $e) {
      return $this->responseService->createErrorResponse('获取实体数据失败: ' . $e->getMessage(), 'ENTITY_FETCH_ERROR', 500);
    }
  }

  /**
   * 获取项目特定实体数据。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   * @param string $id
   *   实体ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   包含实体数据的JSON响应。
   */
  public function getEntity(string $tenant_id, string $project_id, string $entity_name, string $id): JsonResponse {
    try {
      // 验证项目访问权限
      $project = $this->projectManager->getProject($project_id);
      if (!$project) {
        return $this->responseService->createErrorResponse('项目不存在', 'PROJECT_NOT_FOUND', 404);
      }

      $tenant_id = $project['tenant_id'];
      
      // 检查API限流
      $request = \Drupal::request();
      $endpoint = $request->getPathInfo();
      $limit_check = $this->requireResourceLimits($project_id, 'api_calls', 1, $endpoint);
      if ($limit_check !== null) {
        return $limit_check;
      }

      // 构建动态表名
      $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);

      // 检查表是否存在
      if (!$this->database->schema()->tableExists($table_name)) {
        return $this->responseService->createErrorResponse('实体表不存在', 'TABLE_NOT_FOUND', 404);
      }

      // 查询实体数据
      $entity = $this->database->select($table_name, 'e')
        ->fields('e')
        ->condition('id', $id)
        ->execute()
        ->fetchAssoc();

      if (!$entity) {
        return $this->responseService->createErrorResponse('实体不存在', 'ENTITY_NOT_FOUND', 404);
      }

      // 扩展文件字段
      \Drupal::logger('baas_api')->info('ProjectEntityApiController - 调用FileFieldExpander前的实体数据: @data', [
        '@data' => json_encode($entity)
      ]);
      
      $expanded_entity = $this->fileFieldExpander->expandFileFields($entity);
      
      \Drupal::logger('baas_api')->info('ProjectEntityApiController - FileFieldExpander处理后的实体数据: @data', [
        '@data' => json_encode($expanded_entity)
      ]);

      return $this->responseService->createSuccessResponse([
        'entity' => $expanded_entity,
        'project_id' => $project_id,
        'entity_name' => $entity_name,
      ], '成功获取项目实体数据');

    } catch (\Exception $e) {
      return $this->responseService->createErrorResponse('获取实体数据失败: ' . $e->getMessage(), 'ENTITY_FETCH_ERROR', 500);
    }
  }

  /**
   * 创建项目实体数据。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   包含创建结果的JSON响应。
   */
  public function createEntity(string $tenant_id, string $project_id, string $entity_name, Request $request): JsonResponse {
    try {
      // 验证项目访问权限
      $project = $this->projectManager->getProject($project_id);
      if (!$project) {
        return $this->responseService->createErrorResponse('项目不存在', 'PROJECT_NOT_FOUND', 404);
      }

      $tenant_id = $project['tenant_id'];
      
      // 检查API限流
      $endpoint = $request->getPathInfo();
      $limit_check = $this->requireResourceLimits($project_id, 'api_calls', 1, $endpoint);
      if ($limit_check !== null) {
        return $limit_check;
      }

      // 获取实体模板及其字段定义，用于验证字段类型
      $template = $this->database->select('baas_entity_template', 'et')
        ->fields('et', ['id'])
        ->condition('name', $entity_name)
        ->condition('tenant_id', $tenant_id)
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchAssoc();

      if (!$template) {
        return $this->responseService->createErrorResponse('实体模板不存在', 'TEMPLATE_NOT_FOUND', 404);
      }

      // 获取字段类型定义
      $field_types = $this->database->select('baas_entity_field', 'ef')
        ->fields('ef', ['name', 'type'])
        ->condition('template_id', $template['id'])
        ->execute()
        ->fetchAllKeyed(0, 1); // 键值对形式：field_name => field_type

      // 构建动态表名
      $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);

      // 检查表是否存在
      if (!$this->database->schema()->tableExists($table_name)) {
        return $this->responseService->createErrorResponse('实体表不存在', 'TABLE_NOT_FOUND', 404);
      }

      // 解析请求数据，支持JSON和multipart/form-data
      $content_type = $request->headers->get('content-type', '');
      $files = [];
      
      if (strpos($content_type, 'multipart/form-data') !== false) {
        // 处理包含文件的表单数据
        $data = $request->request->all();
        
        // 首先验证所有上传的文件，避免在验证失败后文件已被保存
        $uploaded_files = $request->files->all();
        foreach ($uploaded_files as $field_name => $uploaded_file) {
          if ($uploaded_file && $uploaded_file->isValid()) {
            // 验证字段类型：检查该字段是否支持文件上传
            if (!isset($field_types[$field_name])) {
              return $this->responseService->createErrorResponse(
                "字段 '{$field_name}' 在实体模板中不存在",
                'FIELD_NOT_EXISTS',
                400
              );
            }

            $field_type = $field_types[$field_name];
            if (!in_array($field_type, ['file', 'image'])) {
              return $this->responseService->createErrorResponse(
                "字段 '{$field_name}' 的类型为 '{$field_type}'，不支持文件上传。只有 'file' 和 'image' 类型的字段才能接受文件上传。",
                'INVALID_FIELD_TYPE_FOR_FILE',
                400
              );
            }

            // 如果是image类型，验证文件是否为图片
            if ($field_type === 'image') {
              $mime_type = $uploaded_file->getMimeType();
              $client_mime_type = $uploaded_file->getClientMimeType();
              $original_name = $uploaded_file->getClientOriginalName();
              
              // 详细调试信息
              \Drupal::logger('baas_project')->info('图片类型验证调试(CREATE): field=@field, detected_mime=@mime, client_mime=@client_mime, filename=@filename, size=@size', [
                '@field' => $field_name,
                '@mime' => $mime_type,
                '@client_mime' => $client_mime_type,
                '@filename' => $original_name,
                '@size' => $uploaded_file->getSize(),
              ]);
              
              // 检查检测到的MIME类型和客户端声明的MIME类型
              $is_image_by_detection = str_starts_with($mime_type, 'image/');
              $is_image_by_client = str_starts_with($client_mime_type, 'image/');
              $is_image_by_extension = preg_match('/\.(jpg|jpeg|png|gif|bmp|webp)$/i', $original_name);
              
              if (!$is_image_by_detection && !$is_image_by_client && !$is_image_by_extension) {
                return $this->responseService->createErrorResponse(
                  "字段 '{$field_name}' 要求图片类型文件，但上传的文件类型为 '{$mime_type}'（客户端：'{$client_mime_type}'）。请上传有效的图片文件。",
                  'INVALID_IMAGE_FILE_TYPE',
                  400
                );
              }
              
              // 如果检测失败但客户端类型或扩展名表明是图片，记录警告但允许继续
              if (!$is_image_by_detection && ($is_image_by_client || $is_image_by_extension)) {
                \Drupal::logger('baas_project')->warning('图片类型检测不一致，但基于客户端信息或扩展名允许上传: detected=@detected, client=@client, filename=@filename', [
                  '@detected' => $mime_type,
                  '@client' => $client_mime_type,
                  '@filename' => $original_name,
                ]);
              }
            }
          }
        }
        
        // 验证通过后，开始处理文件上传
        foreach ($uploaded_files as $field_name => $uploaded_file) {
          if ($uploaded_file && $uploaded_file->isValid()) {
            try {
              $upload_result = $this->fileManager->uploadFileFromRequest($project_id, $uploaded_file, [
                'field_name' => $field_name,
                'entity_type' => $entity_name,
              ]);
              
              if ($upload_result['success']) {
                $files[$field_name] = $upload_result['file_id'];
              } else {
                return $this->responseService->createErrorResponse(
                  "文件上传失败: " . ($upload_result['error'] ?? '未知错误'),
                  'FILE_UPLOAD_FAILED',
                  500
                );
              }
            } catch (\Exception $e) {
              \Drupal::logger('baas_project')->error('文件上传异常: @field - @error', [
                '@field' => $field_name,
                '@error' => $e->getMessage(),
              ]);
              return $this->responseService->createErrorResponse(
                "文件上传异常: " . $e->getMessage(),
                'FILE_UPLOAD_EXCEPTION',
                500
              );
            }
          }
        }
        
        // 合并文件字段到数据中
        foreach ($files as $field_name => $file_id) {
          $data[$field_name] = $file_id;
        }
      } else {
        // 处理JSON数据
        $data = json_decode($request->getContent(), true);
        if (!$data) {
          return $this->responseService->createErrorResponse('无效的JSON数据', 'INVALID_JSON', 400);
        }
      }

      // 添加系统字段
      $data['uuid'] = \Drupal::service('uuid')->generate();
      $data['tenant_id'] = $tenant_id;
      $data['project_id'] = $project_id;
      $data['created'] = time();
      $data['updated'] = time();

      // 验证字段唯一性约束
      $validation_error = $this->validateUniqueFields($template['id'], $table_name, $data);
      if ($validation_error) {
        return $this->responseService->createErrorResponse(
          $validation_error['error'],
          $validation_error['code'],
          400,
          $validation_error
        );
      }

      // 插入数据
      $id = $this->database->insert($table_name)
        ->fields($data)
        ->execute();

      return $this->responseService->createSuccessResponse([
        'id' => $id,
        'entity' => array_merge(['id' => $id], $data),
        'project_id' => $project_id,
        'entity_name' => $entity_name,
      ], '成功创建项目实体数据', [], [], 201);

    } catch (\Exception $e) {
      return $this->responseService->createErrorResponse('创建实体数据失败: ' . $e->getMessage(), 'ENTITY_CREATE_ERROR', 500);
    }
  }

  /**
   * 更新项目实体数据。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   * @param string $id
   *   实体ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   包含更新结果的JSON响应。
   */
  public function updateEntity(string $tenant_id, string $project_id, string $entity_name, string $id, Request $request): JsonResponse {
    try {
      // 记录请求详情用于调试
      \Drupal::logger('baas_project')->info('更新实体请求: project_id=@project_id, entity_name=@entity_name, id=@id, content_type=@content_type, method=@method', [
        '@project_id' => $project_id,
        '@entity_name' => $entity_name,
        '@id' => $id,
        '@content_type' => $request->headers->get('content-type', 'unknown'),
        '@method' => $request->getMethod(),
      ]);
      
      // 调试：检查原始请求数据
      \Drupal::logger('baas_project')->info('原始请求数据调试: content_length=@length, all_headers=@headers', [
        '@length' => $request->headers->get('content-length', '0'),
        '@headers' => json_encode($request->headers->all()),
      ]);
      
      // 验证项目访问权限
      $project = $this->projectManager->getProject($project_id);
      if (!$project) {
        return $this->responseService->createErrorResponse('项目不存在', 'PROJECT_NOT_FOUND', 404);
      }

      $tenant_id = $project['tenant_id'];
      
      // 检查API限流
      $endpoint = $request->getPathInfo();
      $limit_check = $this->requireResourceLimits($project_id, 'api_calls', 1, $endpoint);
      if ($limit_check !== null) {
        return $limit_check;
      }

      // 构建动态表名
      $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);

      // 检查表是否存在
      if (!$this->database->schema()->tableExists($table_name)) {
        return $this->responseService->createErrorResponse('实体表不存在', 'TABLE_NOT_FOUND', 404);
      }

      // 检查实体是否存在
      $existing = $this->database->select($table_name, 'e')
        ->fields('e')
        ->condition('id', $id)
        ->execute()
        ->fetchAssoc();

      if (!$existing) {
        return $this->responseService->createErrorResponse('实体不存在', 'ENTITY_NOT_FOUND', 404);
      }

      // 获取实体模板及其字段定义，用于字段类型验证和文件更新
      $template = $this->database->select('baas_entity_template', 'et')
        ->fields('et', ['id'])
        ->condition('name', $entity_name)
        ->condition('tenant_id', $tenant_id)
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchAssoc();

      if (!$template) {
        return $this->responseService->createErrorResponse('实体模板不存在', 'TEMPLATE_NOT_FOUND', 404);
      }

      // 获取所有字段类型定义
      $field_types = $this->database->select('baas_entity_field', 'ef')
        ->fields('ef', ['name', 'type'])
        ->condition('template_id', $template['id'])
        ->execute()
        ->fetchAllKeyed(0, 1); // 键值对形式：field_name => field_type

      // 获取文件类型字段，用于旧文件清理
      $file_fields = array_filter($field_types, function($type) {
        return in_array($type, ['file', 'image']);
      });

      // 解析请求数据，支持JSON和multipart/form-data
      $content_type = $request->headers->get('content-type', '');
      $files = [];
      
      // 记录请求详情
      \Drupal::logger('baas_project')->info('updateEntity请求Content-Type: @content_type, Method: @method', [
        '@content_type' => $content_type,
        '@method' => $request->getMethod(),
      ]);
      
      if (strpos($content_type, 'multipart/form-data') !== false) {
        // 处理包含文件的表单数据
        $data = $request->request->all();
        
        // 调试日志：记录multipart数据
        \Drupal::logger('baas_project')->info('Multipart表单数据: @data', [
          '@data' => json_encode($data),
        ]);
        
        // 调试：检查Request对象的详细状态
        \Drupal::logger('baas_project')->info('Request对象调试: request_bag=@request, query_bag=@query, content=@content', [
          '@request' => json_encode($request->request->all()),
          '@query' => json_encode($request->query->all()),
          '@content' => substr($request->getContent(), 0, 200), // 只取前200字符
        ]);
        
        // 处理上传的文件
        $uploaded_files = $request->files->all();
        \Drupal::logger('baas_project')->info('上传文件数量: @count, 文件详情: @files', [
          '@count' => count($uploaded_files),
          '@files' => json_encode(array_keys($uploaded_files)),
        ]);
        
        // 调试：检查$_POST和$_FILES全局变量
        \Drupal::logger('baas_project')->info('全局变量调试: POST=@post, FILES=@files_global', [
          '@post' => json_encode($_POST),
          '@files_global' => json_encode(array_keys($_FILES)),
        ]);
        // 首先验证所有上传的文件，避免在验证失败后文件已被保存
        foreach ($uploaded_files as $field_name => $uploaded_file) {
          if ($uploaded_file && $uploaded_file->isValid()) {
            // 验证字段类型：检查该字段是否支持文件上传
            if (!isset($field_types[$field_name])) {
              return $this->responseService->createErrorResponse(
                "字段 '{$field_name}' 在实体模板中不存在",
                'FIELD_NOT_EXISTS',
                400
              );
            }

            $field_type = $field_types[$field_name];
            if (!in_array($field_type, ['file', 'image'])) {
              return $this->responseService->createErrorResponse(
                "字段 '{$field_name}' 的类型为 '{$field_type}'，不支持文件上传。只有 'file' 和 'image' 类型的字段才能接受文件上传。",
                'INVALID_FIELD_TYPE_FOR_FILE',
                400
              );
            }

            // 如果是image类型，验证文件是否为图片
            if ($field_type === 'image') {
              $mime_type = $uploaded_file->getMimeType();
              $client_mime_type = $uploaded_file->getClientMimeType();
              $original_name = $uploaded_file->getClientOriginalName();
              
              // 详细调试信息
              \Drupal::logger('baas_project')->info('图片类型验证调试(UPDATE): field=@field, detected_mime=@mime, client_mime=@client_mime, filename=@filename, size=@size', [
                '@field' => $field_name,
                '@mime' => $mime_type,
                '@client_mime' => $client_mime_type,
                '@filename' => $original_name,
                '@size' => $uploaded_file->getSize(),
              ]);
              
              // 检查检测到的MIME类型和客户端声明的MIME类型
              $is_image_by_detection = str_starts_with($mime_type, 'image/');
              $is_image_by_client = str_starts_with($client_mime_type, 'image/');
              $is_image_by_extension = preg_match('/\.(jpg|jpeg|png|gif|bmp|webp)$/i', $original_name);
              
              if (!$is_image_by_detection && !$is_image_by_client && !$is_image_by_extension) {
                return $this->responseService->createErrorResponse(
                  "字段 '{$field_name}' 要求图片类型文件，但上传的文件类型为 '{$mime_type}'（客户端：'{$client_mime_type}'）。请上传有效的图片文件。",
                  'INVALID_IMAGE_FILE_TYPE',
                  400
                );
              }
              
              // 如果检测失败但客户端类型或扩展名表明是图片，记录警告但允许继续
              if (!$is_image_by_detection && ($is_image_by_client || $is_image_by_extension)) {
                \Drupal::logger('baas_project')->warning('图片类型检测不一致，但基于客户端信息或扩展名允许上传: detected=@detected, client=@client, filename=@filename', [
                  '@detected' => $mime_type,
                  '@client' => $client_mime_type,
                  '@filename' => $original_name,
                ]);
              }
            }
          }
        }
        
        // 验证通过后，开始处理文件上传
        foreach ($uploaded_files as $field_name => $uploaded_file) {
          if ($uploaded_file && $uploaded_file->isValid()) {
            try {
              $upload_result = $this->fileManager->uploadFileFromRequest($project_id, $uploaded_file, [
                'field_name' => $field_name,
                'entity_type' => $entity_name,
              ]);
              
              if ($upload_result['success']) {
                $files[$field_name] = $upload_result['file_id'];
              } else {
                return $this->responseService->createErrorResponse(
                  "文件上传失败: " . ($upload_result['error'] ?? '未知错误'),
                  'FILE_UPLOAD_FAILED',
                  500
                );
              }
            } catch (\Exception $e) {
              \Drupal::logger('baas_project')->error('文件上传异常: @field - @error', [
                '@field' => $field_name,
                '@error' => $e->getMessage(),
              ]);
              return $this->responseService->createErrorResponse(
                "文件上传异常: " . $e->getMessage(),
                'FILE_UPLOAD_EXCEPTION',
                500
              );
            }
          }
        }
        
        // 合并文件字段到数据中
        foreach ($files as $field_name => $file_id) {
          $data[$field_name] = $file_id;
        }
      } else {
        // 处理JSON数据
        $data = json_decode($request->getContent(), true);
        if (!$data) {
          return $this->responseService->createErrorResponse('无效的JSON数据', 'INVALID_JSON', 400);
        }
      }

      // 处理文件字段更新：删除旧文件
      $deleted_files = [];
      foreach ($file_fields as $field_name => $field_type) {
        // 检查此字段是否有新值（新上传的文件或通过JSON更新的文件ID）
        if (isset($data[$field_name]) && !empty($data[$field_name])) {
          // 获取当前字段的旧文件ID
          $old_file_ids = $existing[$field_name] ?? null;
          
          if (!empty($old_file_ids)) {
            // 处理多个文件ID（逗号分隔）
            if (is_string($old_file_ids) && strpos($old_file_ids, ',') !== false) {
              $old_file_ids = explode(',', $old_file_ids);
            } elseif (!is_array($old_file_ids)) {
              $old_file_ids = [$old_file_ids];
            }

            // 删除旧文件
            foreach ($old_file_ids as $old_file_id) {
              $old_file_id = trim($old_file_id);
              if (!empty($old_file_id) && is_numeric($old_file_id)) {
                $deleted_file = $this->deleteAssociatedFile((int) $old_file_id, $project_id);
                if ($deleted_file) {
                  $deleted_files[] = $deleted_file;
                  \Drupal::logger('baas_project')->info('更新实体时删除旧文件: @filename (ID: @file_id) from field @field', [
                    '@filename' => $deleted_file['filename'],
                    '@file_id' => $old_file_id,
                    '@field' => $field_name,
                  ]);
                }
              }
            }
          }
        }
      }

      // 更新时间戳
      $data['updated'] = time();

      // 处理密码字段
      if ($template['id']) {
        $data = $this->processPasswordFields($data, $template['id']);
      }

      // 调试日志：记录即将更新的数据
      \Drupal::logger('baas_project')->info('准备更新数据到表 @table: @data', [
        '@table' => $table_name,
        '@data' => json_encode($data),
      ]);

      // 验证字段唯一性约束（排除当前记录）
      $validation_error = $this->validateUniqueFields($template['id'], $table_name, $data, $id);
      if ($validation_error) {
        return $this->responseService->createErrorResponse(
          $validation_error['error'],
          $validation_error['code'],
          400,
          $validation_error
        );
      }

      // 更新数据
      $update_query = $this->database->update($table_name)
        ->fields($data)
        ->condition('id', $id);
      
      $affected_rows = $update_query->execute();
      
      \Drupal::logger('baas_project')->info('数据库更新结果: @rows 行受影响', [
        '@rows' => $affected_rows,
      ]);

      // 获取更新后的数据
      $updated_entity = $this->database->select($table_name, 'e')
        ->fields('e')
        ->condition('id', $id)
        ->execute()
        ->fetchAssoc();

      // 过滤密码字段
      if ($template['id']) {
        $updated_entity = $this->filterPasswordFields($updated_entity, $template['id']);
      }

      return $this->responseService->createSuccessResponse([
        'entity' => $updated_entity,
        'project_id' => $project_id,
        'entity_name' => $entity_name,
        'deleted_files' => $deleted_files,
        'deleted_files_count' => count($deleted_files),
      ], '成功更新项目实体数据' . (count($deleted_files) > 0 ? '，已删除 ' . count($deleted_files) . ' 个旧文件' : ''));

    } catch (\Exception $e) {
      return $this->responseService->createErrorResponse('更新实体数据失败: ' . $e->getMessage(), 'ENTITY_UPDATE_ERROR', 500);
    }
  }

  /**
   * 删除项目实体数据。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   * @param string $id
   *   实体ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   包含删除结果的JSON响应。
   */
  public function deleteEntity(string $tenant_id, string $project_id, string $entity_name, string $id): JsonResponse {
    try {
      // 验证项目访问权限
      $project = $this->projectManager->getProject($project_id);
      if (!$project) {
        return $this->responseService->createErrorResponse('项目不存在', 'PROJECT_NOT_FOUND', 404);
      }

      $tenant_id = $project['tenant_id'];
      
      // 检查API限流
      $request = \Drupal::request();
      $endpoint = $request->getPathInfo();
      $limit_check = $this->requireResourceLimits($project_id, 'api_calls', 1, $endpoint);
      if ($limit_check !== null) {
        return $limit_check;
      }

      // 构建动态表名
      $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);

      // 检查表是否存在
      if (!$this->database->schema()->tableExists($table_name)) {
        return $this->responseService->createErrorResponse('实体表不存在', 'TABLE_NOT_FOUND', 404);
      }

      // 检查实体是否存在
      $existing = $this->database->select($table_name, 'e')
        ->fields('e')
        ->condition('id', $id)
        ->execute()
        ->fetchAssoc();

      if (!$existing) {
        return $this->responseService->createErrorResponse('实体不存在', 'ENTITY_NOT_FOUND', 404);
      }

      // 获取实体模板ID
      $template = $this->database->select('baas_entity_template', 'et')
        ->fields('et', ['id'])
        ->condition('name', $entity_name)
        ->condition('tenant_id', $tenant_id)
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchAssoc();

      $deleted_files = [];
      if ($template) {
        // 获取所有文件类型字段
        $file_fields = $this->database->select('baas_entity_field', 'ef')
          ->fields('ef', ['name', 'type'])
          ->condition('template_id', $template['id'])
          ->condition('type', ['file', 'image'], 'IN')
          ->execute()
          ->fetchAll();

        // 删除关联的文件
        foreach ($file_fields as $field) {
          $field_name = $field->name;
          if (!empty($existing[$field_name])) {
            $file_ids = $existing[$field_name];
            // 处理多个文件ID（逗号分隔）
            if (is_string($file_ids) && strpos($file_ids, ',') !== false) {
              $file_ids = explode(',', $file_ids);
            } elseif (!is_array($file_ids)) {
              $file_ids = [$file_ids];
            }

            foreach ($file_ids as $file_id) {
              $file_id = trim($file_id);
              if (!empty($file_id) && is_numeric($file_id)) {
                $deleted_file = $this->deleteAssociatedFile((int) $file_id, $project_id);
                if ($deleted_file) {
                  $deleted_files[] = $deleted_file;
                }
              }
            }
          }
        }
      }

      // 删除实体数据
      $this->database->delete($table_name)
        ->condition('id', $id)
        ->execute();

      return $this->responseService->createSuccessResponse([
        'deleted_id' => $id,
        'project_id' => $project_id,
        'entity_name' => $entity_name,
        'deleted_files' => $deleted_files,
        'deleted_files_count' => count($deleted_files),
      ], '成功删除项目实体数据及关联文件');

    } catch (\Exception $e) {
      return $this->responseService->createErrorResponse('删除实体数据失败: ' . $e->getMessage(), 'ENTITY_DELETE_ERROR', 500);
    }
  }

  /**
   * 删除关联的文件。
   *
   * @param int $file_id
   *   文件ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return array|null
   *   删除的文件信息，失败返回null。
   */
  protected function deleteAssociatedFile(int $file_id, string $project_id): ?array {
    try {
      // 获取文件信息
      $file_info = $this->database->select('file_managed', 'f')
        ->fields('f', ['fid', 'filename', 'uri', 'filesize'])
        ->condition('fid', $file_id)
        ->condition('status', 1)
        ->execute()
        ->fetchAssoc();

      if (!$file_info) {
        \Drupal::logger('baas_project')->warning('文件不存在或已删除: @file_id', ['@file_id' => $file_id]);
        return null;
      }

      // 检查文件是否属于此项目
      $file_access = $this->database->select('baas_project_file_access', 'pfa')
        ->fields('pfa', ['file_id'])
        ->condition('file_id', (string) $file_id)
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchField();

      if (!$file_access) {
        \Drupal::logger('baas_project')->warning('文件不属于项目: file_id=@file_id, project_id=@project_id', [
          '@file_id' => $file_id,
          '@project_id' => $project_id,
        ]);
        return null;
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

        // 删除物理文件
        $file_uri = $file_info['uri'];
        $file_path = \Drupal::service('file_system')->realpath($file_uri);
        if ($file_path && file_exists($file_path)) {
          unlink($file_path);
          \Drupal::logger('baas_project')->info('已删除物理文件: @path', ['@path' => $file_path]);
        }

        \Drupal::logger('baas_project')->info('成功删除关联文件: @filename (ID: @file_id)', [
          '@filename' => $file_info['filename'],
          '@file_id' => $file_id,
        ]);

        return [
          'file_id' => $file_id,
          'filename' => $file_info['filename'],
          'filesize' => (int) $file_info['filesize'],
          'uri' => $file_info['uri'],
        ];

      } catch (\Exception $e) {
        $transaction->rollBack();
        throw $e;
      }

    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('删除关联文件失败: @error', ['@error' => $e->getMessage()]);
      return null;
    }
  }

  /**
   * 处理CORS预检请求
   */
  public function handleOptions(): Response {
    return $this->createCorsResponse();
  }

  /**
   * 创建CORS响应
   */
  protected function createCorsResponse(): Response {
    $response = new Response('', 204);
    
    // 强制设置CORS头部
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS, PATCH');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-Key, X-Requested-With, X-BaaS-Project-ID, X-BaaS-Tenant-ID, x-baas-project-id, x-baas-tenant-id');
    $response->headers->set('Access-Control-Allow-Credentials', 'true');
    $response->headers->set('Access-Control-Max-Age', '3600');
    
    return $response;
  }

  /**
   * 验证字段唯一性约束。
   *
   * @param string $template_id
   *   模板ID。
   * @param string $table_name
   *   表名。
   * @param array $data
   *   要验证的数据。
   * @param string|null $exclude_id
   *   排除的记录ID（用于更新时）。
   *
   * @return array|null
   *   如果验证失败返回错误信息数组，成功返回null。
   */
  protected function validateUniqueFields(string $template_id, string $table_name, array $data, ?string $exclude_id = null): ?array {
    try {
      // 获取有唯一性约束的字段
      $unique_fields = $this->database->select('baas_entity_field', 'ef')
        ->fields('ef', ['name', 'label', 'settings'])
        ->condition('template_id', $template_id)
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      $unique_field_names = [];
      foreach ($unique_fields as $field) {
        $settings = is_string($field['settings']) ? json_decode($field['settings'], TRUE) : ($field['settings'] ?? []);
        if (!empty($settings['unique']) && $settings['unique'] == 1) {
          $unique_field_names[$field['name']] = $field['label'] ?: $field['name'];
        }
      }

      if (empty($unique_field_names)) {
        return null; // 没有唯一性约束字段
      }

      // 检查每个唯一性字段
      foreach ($unique_field_names as $field_name => $field_label) {
        if (!isset($data[$field_name]) || $data[$field_name] === '' || $data[$field_name] === null) {
          continue; // 跳过空值
        }

        $query = $this->database->select($table_name, 'e')
          ->fields('e', ['id'])
          ->condition($field_name, $data[$field_name])
          ->range(0, 1);

        // 如果是更新操作，排除当前记录
        if ($exclude_id !== null) {
          $query->condition('id', $exclude_id, '!=');
        }

        $existing = $query->execute()->fetchAssoc();

        if ($existing) {
          return [
            'error' => "字段 '{$field_label}' 的值 '{$data[$field_name]}' 已存在，请使用不同的值",
            'code' => 'UNIQUE_CONSTRAINT_VIOLATION',
            'field' => $field_name,
            'value' => $data[$field_name],
          ];
        }
      }

      return null; // 验证通过
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('唯一性验证失败: @error', ['@error' => $e->getMessage()]);
      return [
        'error' => '唯一性验证失败: ' . $e->getMessage(),
        'code' => 'UNIQUE_VALIDATION_ERROR',
      ];
    }
  }

  /**
   * 处理密码字段。
   *
   * @param array $data
   *   实体数据。
   * @param string $template_id
   *   模板ID。
   *
   * @return array
   *   处理后的数据。
   */
  protected function processPasswordFields(array $data, string $template_id): array {
    try {
      // 获取密码字段
      $password_fields = $this->database->select('baas_entity_field', 'ef')
        ->fields('ef', ['name', 'settings'])
        ->condition('template_id', $template_id)
        ->condition('type', 'password')
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      $passwordPlugin = $this->fieldTypeManager->getPlugin('password');

      foreach ($password_fields as $field) {
        $field_name = $field['name'];
        
        // 如果数据中有这个密码字段
        if (isset($data[$field_name]) && !empty($data[$field_name])) {
          $settings = is_string($field['settings']) ? 
            json_decode($field['settings'], TRUE) : 
            ($field['settings'] ?? []);
          
          // 使用密码字段插件处理密码
          $data[$field_name] = $passwordPlugin->processValue($data[$field_name], $settings);
          
          \Drupal::logger('baas_project')->info('密码字段 @field 已处理', [
            '@field' => $field_name,
          ]);
        }
      }

      return $data;
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('处理密码字段失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return $data; // 出错时返回原始数据
    }
  }

  /**
   * 从API响应中过滤密码字段。
   *
   * @param array $data
   *   实体数据。
   * @param string $template_id
   *   模板ID。
   *
   * @return array
   *   过滤后的数据。
   */
  protected function filterPasswordFields(array $data, string $template_id): array {
    try {
      // 获取需要隐藏的密码字段
      $password_fields = $this->database->select('baas_entity_field', 'ef')
        ->fields('ef', ['name', 'settings'])
        ->condition('template_id', $template_id)
        ->condition('type', 'password')
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      foreach ($password_fields as $field) {
        $field_name = $field['name'];
        $settings = is_string($field['settings']) ? 
          json_decode($field['settings'], TRUE) : 
          ($field['settings'] ?? []);
        
        // 检查是否应该在API中隐藏
        $hide_in_api = $settings['hide_in_api'] ?? true;
        
        if ($hide_in_api && isset($data[$field_name])) {
          // 从响应中移除密码字段
          unset($data[$field_name]);
        }
      }

      return $data;
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('过滤密码字段失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return $data; // 出错时返回原始数据
    }
  }

}