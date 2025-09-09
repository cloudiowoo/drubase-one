<?php

declare(strict_types=1);

namespace Drupal\baas_entity\Controller;

use Drupal\baas_entity\Controller\EntityDataApiController;
use Drupal\baas_entity\Service\FieldTypeManager;
use Drupal\baas_file\Service\ProjectFileManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_api\Service\ApiResponseService;
use Drupal\baas_api\Service\ApiValidationService;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Drupal\baas_entity\Service\TemplateManager;
use Drupal\baas_entity\Service\EntityRegistry;
use Drupal\baas_entity\Service\EntityReferenceResolver;
use Drupal\baas_tenant\TenantManagerInterface;
use Drupal\baas_tenant\TenantResolver;
use Drupal\Core\Database\Connection;

/**
 * 支持文件上传的实体数据API控制器。
 *
 * 扩展基础API控制器，添加文件上传支持。
 */
class EntityDataWithFilesApiController extends EntityDataApiController
{
  
  /**
   * 字段类型管理器。
   *
   * @var \Drupal\baas_entity\Service\FieldTypeManager
   */
  protected FieldTypeManager $fieldTypeManager;

  /**
   * 构造函数。
   */
  public function __construct(
    ApiResponseService $response_service,
    ApiValidationService $validation_service,
    UnifiedPermissionCheckerInterface $permission_checker,
    TemplateManager $template_manager,
    EntityRegistry $entity_registry,
    EntityReferenceResolver $entity_reference_resolver,
    EntityTypeManagerInterface $entity_type_manager,
    TenantManagerInterface $tenant_manager,
    TenantResolver $tenant_resolver,
    Connection $database,
    FieldTypeManager $fieldTypeManager,
    protected readonly ProjectFileManagerInterface $fileManager,
    protected readonly FileSystemInterface $fileSystem,
  ) {
    parent::__construct(
      $response_service,
      $validation_service,
      $permission_checker,
      $template_manager,
      $entity_registry,
      $entity_reference_resolver,
      $entity_type_manager,
      $tenant_manager,
      $tenant_resolver,
      $database
    );
    $this->fieldTypeManager = $fieldTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_api.response'),
      $container->get('baas_api.validation'),
      $container->get('baas_auth.unified_permission_checker'),
      $container->get('baas_entity.template_manager'),
      $container->get('baas_entity.entity_registry'),
      $container->get('baas_entity.entity_reference_resolver'),
      $container->get('entity_type.manager'),
      $container->get('baas_tenant.manager'),
      $container->get('baas_tenant.resolver'),
      $container->get('database'),
      $container->get('baas_entity.field_type_manager'),
      $container->get('baas_file.manager'),
      $container->get('file_system'),
    );
  }

  /**
   * 创建实体（支持文件上传）。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function createEntityWithFiles(Request $request, string $tenant_id, string $project_id, string $entity_name): JsonResponse
  {
    try {
      // 路由级别已经通过ProjectAccessChecker::accessEdit检查了权限
      // 这里只需要做基本的存在性验证
      
      $tenant_manager = \Drupal::service('baas_tenant.manager');
      $tenant = $tenant_manager->getTenant($tenant_id);
      if (!$tenant) {
        return $this->createErrorResponse('Tenant not found', 'TENANT_NOT_FOUND', 404);
      }

      $project_manager = \Drupal::service('baas_project.manager');
      $project = $project_manager->getProject($project_id);
      if (!$project) {
        return $this->createErrorResponse('Project not found', 'PROJECT_NOT_FOUND', 404);
      }

      if ($project['tenant_id'] !== $tenant_id) {
        return $this->createErrorResponse('Project does not belong to tenant', 'PROJECT_TENANT_MISMATCH', 400);
      }

      // 实体名称验证
      $entity_validation = $this->validateEntityName($entity_name);
      if (!$entity_validation['valid']) {
        return $this->createErrorResponse(
          'Invalid entity name format',
          'INVALID_ENTITY_NAME',
          400
        );
      }

      // 获取项目表名生成器服务
      $table_name_generator = \Drupal::service('baas_project.table_name_generator');

      // 检查请求类型并处理数据
      $content_type = $request->headers->get('content-type', '');
      
      if (strpos($content_type, 'multipart/form-data') !== false) {
        // 处理包含文件的表单数据
        $result = $this->processMultipartRequest($request, $project_id, $entity_name);
      } else {
        // 处理纯JSON数据
        $validation_result = $this->validateJsonRequest($request->getContent() ?: '');
        if (!$validation_result['success']) {
          return $this->createErrorResponse($validation_result['message'], 'INVALID_JSON_DATA', Response::HTTP_BAD_REQUEST);
        }
        $result = ['success' => true, 'data' => $validation_result['data'], 'files' => []];
      }

      if (!$result['success']) {
        return $this->createErrorResponse($result['message'], 'REQUEST_PROCESSING_ERROR', Response::HTTP_BAD_REQUEST);
      }

      // 准备实体数据
      $content = $result['data'];
      $content['tenant_id'] = $tenant_id;
      $content['project_id'] = $project_id;

      // 处理文件字段
      $content = $this->processFileFields($content, $result['files'], $project_id);

      // 使用直接数据库插入方法（与ProjectEntityApiController相同）
      $table_name = $table_name_generator->generateTableName($tenant_id, $project_id, $entity_name);
      
      // 检查表是否存在
      if (!$this->database->schema()->tableExists($table_name)) {
        return $this->createErrorResponse('实体表不存在', 'TABLE_NOT_FOUND', 404);
      }
      
      // 获取实体模板ID用于密码字段处理和唯一性验证
      $template = $this->database->select('baas_entity_template', 'et')
        ->fields('et', ['id'])
        ->condition('name', $entity_name)
        ->condition('tenant_id', $tenant_id)
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchAssoc();

      // 处理密码字段
      $template_id = $template ? $template['id'] : null;
      $content = $this->processPasswordFields($content, $template_id);
      
      // 添加系统字段
      $content['uuid'] = \Drupal::service('uuid')->generate();
      $content['tenant_id'] = $tenant_id;
      $content['project_id'] = $project_id;
      $content['created'] = time();
      $content['updated'] = time();

      if ($template) {
        // 验证字段唯一性约束
        $validation_error = $this->validateUniqueFields($template['id'], $table_name, $content);
        if ($validation_error) {
          return $this->createErrorResponse(
            $validation_error['error'],
            $validation_error['code'],
            400
          );
        }
      }

      // 直接插入数据库
      $id = $this->database->insert($table_name)
        ->fields($content)
        ->execute();

      // 格式化响应数据
      $entity_data = array_merge(['id' => $id], $content);
      
      // 过滤密码字段
      if ($template) {
        $entity_data = $this->filterPasswordFields($entity_data, $template['id']);
      }

      return $this->createCreatedResponse([
        'id' => $id,
        'entity' => $entity_data,
        'project_id' => $project_id,
        'entity_name' => $entity_name,
      ], '成功创建项目实体数据');

    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('创建实体失败: @error', [
        '@error' => $e->getMessage(),
      ]);

      // 检查是否为NOT NULL约束错误
      if (strpos($e->getMessage(), 'not-null constraint') !== false || strpos($e->getMessage(), 'NOT NULL') !== false) {
        // 解析字段名
        if (preg_match('/column "([^"]+)"/', $e->getMessage(), $matches)) {
          $field_name = $matches[1];
          return $this->createErrorResponse(
            "必填字段 '{$field_name}' 不能为空", 
            'REQUIRED_FIELD_MISSING', 
            Response::HTTP_BAD_REQUEST
          );
        }
        return $this->createErrorResponse('缺少必填字段', 'REQUIRED_FIELD_MISSING', Response::HTTP_BAD_REQUEST);
      }

      // 检查是否为唯一约束错误
      if (strpos($e->getMessage(), 'duplicate key value') !== false || strpos($e->getMessage(), 'UNIQUE constraint') !== false) {
        return $this->createErrorResponse('数据重复，请检查唯一字段值', 'DUPLICATE_VALUE', Response::HTTP_CONFLICT);
      }

      return $this->createErrorResponse('创建实体时发生内部错误', 'INTERNAL_ERROR', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 更新实体（支持文件上传）。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   * @param int $id
   *   实体ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function updateEntityWithFiles(Request $request, string $tenant_id, string $project_id, string $entity_name, int $id): JsonResponse
  {
    try {
      // 路由级别已经检查了权限，这里只需要做基本验证
      $tenant_manager = \Drupal::service('baas_tenant.manager');
      $tenant = $tenant_manager->getTenant($tenant_id);
      if (!$tenant) {
        return $this->createErrorResponse('Tenant not found', 'TENANT_NOT_FOUND', 404);
      }

      $project_manager = \Drupal::service('baas_project.manager');
      $project = $project_manager->getProject($project_id);
      if (!$project) {
        return $this->createErrorResponse('Project not found', 'PROJECT_NOT_FOUND', 404);
      }

      if ($project['tenant_id'] !== $tenant_id) {
        return $this->createErrorResponse('Project does not belong to tenant', 'PROJECT_TENANT_MISMATCH', 400);
      }

      // 实体名称验证
      $entity_validation = $this->validateEntityName($entity_name);
      if (!$entity_validation['valid']) {
        return $this->createErrorResponse(
          'Invalid entity name format',
          'INVALID_ENTITY_NAME',
          400
        );
      }

      // 对于项目级实体，使用ProjectTableNameGenerator生成正确的entity_type_id
      $table_name_generator = \Drupal::service('baas_project.table_name_generator');
      $entity_type_id = $table_name_generator->generateEntityTypeId($tenant_id, $project_id, $entity_name);

      // 加载现有实体
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);
      if (!$entity) {
        return $this->createErrorResponse('找不到指定的实体', 'ENTITY_NOT_FOUND', Response::HTTP_NOT_FOUND);
      }

      // 检查实体归属
      if ($entity->get('tenant_id')->value !== $tenant_id || $entity->get('project_id')->value !== $project_id) {
        return $this->createErrorResponse('无权访问此实体', 'ENTITY_ACCESS_DENIED', Response::HTTP_FORBIDDEN);
      }

      // 处理请求数据
      $content_type = $request->headers->get('content-type', '');
      
      if (strpos($content_type, 'multipart/form-data') !== false) {
        // 处理包含文件的表单数据
        $result = $this->processMultipartRequest($request, $project_id, $entity_name);
      } else {
        // 处理纯JSON数据
        $validation_result = $this->validateJsonRequest($request->getContent() ?: '');
        if (!$validation_result['success']) {
          return $this->createErrorResponse($validation_result['message'], 'INVALID_JSON_DATA', Response::HTTP_BAD_REQUEST);
        }
        $result = ['data' => $validation_result['data'], 'files' => []];
      }

      if (!$result['success']) {
        return $this->createErrorResponse($result['message'], 'REQUEST_PROCESSING_ERROR', Response::HTTP_BAD_REQUEST);
      }

      // 处理文件字段
      $content = $this->processFileFields($result['data'], $result['files'], $project_id);

      // 获取实体的字段名称
      $field_names = array_keys($entity->getFields());

      // 更新实体字段
      foreach ($content as $field => $value) {
        if (in_array($field, $field_names) && !in_array($field, ['id', 'uuid', 'tenant_id', 'project_id'])) {
          $entity->set($field, $value);
        }
      }

      $entity->save();

      // 格式化实体数据以供响应
      $entity_data = $this->formatEntityData($entity);

      return $this->createSuccessResponse($entity_data, '实体更新成功');

    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('更新实体失败: @error', [
        '@error' => $e->getMessage(),
      ]);

      return $this->createErrorResponse('更新实体时发生内部错误', 'INTERNAL_ERROR', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 处理multipart/form-data请求。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   *
   * @return array
   *   包含数据和文件的数组。
   */
  protected function processMultipartRequest(Request $request, string $project_id, string $entity_name): array
  {
    try {
      // 获取表单数据
      $data = $request->request->all();
      
      // 获取上传的文件
      $files = [];
      $uploaded_files = $request->files->all();
      
      foreach ($uploaded_files as $field_name => $uploaded_file) {
        if ($uploaded_file && $uploaded_file->isValid()) {
          try {
            // 上传文件到项目
            $upload_result = $this->fileManager->uploadFile($project_id, $uploaded_file, [
              'field_name' => $field_name,
              'entity_type' => $entity_name,
            ]);
            
            if ($upload_result['success']) {
              $files[$field_name] = $upload_result['file_id'];
            } else {
              \Drupal::logger('baas_entity')->warning('文件上传失败: @field - @error', [
                '@field' => $field_name,
                '@error' => $upload_result['error'] ?? 'Unknown error',
              ]);
            }
          } catch (\Exception $e) {
            \Drupal::logger('baas_entity')->error('文件上传异常: @field - @error', [
              '@field' => $field_name,
              '@error' => $e->getMessage(),
            ]);
          }
        }
      }

      return [
        'success' => true,
        'data' => $data,
        'files' => $files,
      ];

    } catch (\Exception $e) {
      return [
        'success' => false,
        'message' => '处理multipart请求失败: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * 处理文件字段。
   *
   * @param array $data
   *   实体数据。
   * @param array $files
   *   上传的文件ID映射。
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   处理后的数据。
   */
  protected function processFileFields(array $data, array $files, string $project_id): array
  {
    // 将上传的文件ID合并到数据中
    foreach ($files as $field_name => $file_id) {
      $data[$field_name] = $file_id;
    }

    return $data;
  }

  /**
   * 格式化实体数据（扩展支持文件字段）。
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   实体对象。
   *
   * @return array
   *   格式化后的实体数据。
   */
  protected function formatEntityData($entity): array
  {
    // 基础数据格式化
    $data = [];
    foreach ($entity->getFields() as $field_name => $field) {
      $field_value = $field->getValue();
      
      // 简化单值字段格式
      if (is_array($field_value) && count($field_value) === 1 && isset($field_value[0]['value'])) {
        $data[$field_name] = $field_value[0]['value'];
      } else {
        $data[$field_name] = $field_value;
      }
    }

    // 扩展文件字段的格式化
    foreach ($entity->getFields() as $field_name => $field) {
      $field_value = $field->getValue();
      
      // 检查是否是文件字段
      if ($this->isFileField($field)) {
        $data[$field_name] = $this->formatFileField($field_value);
      }
    }

    return $data;
  }

  /**
   * 检查是否是文件字段。
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $field
   *   字段对象。
   *
   * @return bool
   *   是否是文件字段。
   */
  protected function isFileField($field): bool
  {
    $field_type = $field->getFieldDefinition()->getType();
    return in_array($field_type, ['file', 'image']);
  }

  /**
   * 格式化文件字段值。
   *
   * @param array $field_value
   *   字段值。
   *
   * @return array
   *   格式化后的文件信息。
   */
  protected function formatFileField(array $field_value): array
  {
    $formatted = [];

    foreach ($field_value as $item) {
      if (isset($item['target_id'])) {
        $file = $this->entityTypeManager->getStorage('file')->load($item['target_id']);
        if ($file) {
          $formatted[] = [
            'id' => $file->id(),
            'filename' => $file->getFilename(),
            'url' => $file->createFileUrl(),
            'filesize' => $file->getSize(),
            'size_formatted' => format_size($file->getSize()),
            'mime_type' => $file->getMimeType(),
            'is_image' => strpos($file->getMimeType(), 'image/') === 0,
          ];
        }
      }
    }

    return $formatted;
  }

  /**
   * 创建成功响应。
   */
  protected function createSuccessResponse($data, string $message = '', array $meta = [], array $pagination = [], int $status = 200): JsonResponse
  {
    return new JsonResponse([
      'success' => true,
      'message' => $message ?: '操作成功',
      'data' => $data,
      'meta' => $meta,
      'pagination' => $pagination,
    ], $status);
  }

  /**
   * 创建创建成功响应。
   */
  protected function createCreatedResponse($data, string $message = 'Resource created successfully', array $meta = []): JsonResponse
  {
    $response_data = [
      'success' => true,
      'message' => $message,
      'data' => $data,
    ];
    
    if (!empty($meta)) {
      $response_data['meta'] = $meta;
    }
    
    return new JsonResponse($response_data, Response::HTTP_CREATED);
  }

  /**
   * 创建错误响应。
   */
  protected function createErrorResponse(string $error, string $code, int $status = 400, array $context = [], array $meta = []): JsonResponse
  {
    $response_data = [
      'success' => false,
      'error' => $error,
      'code' => $code,
    ];

    if (!empty($context)) {
      $response_data['context'] = $context;
    }
    
    if (!empty($meta)) {
      $response_data['meta'] = $meta;
    }

    return new JsonResponse($response_data, $status);
  }

  /**
   * 验证JSON请求。
   */
  protected function validateJsonRequest(string $content, array $required_fields = [], array $allowed_fields = []): array
  {
    if (empty($content)) {
      return [
        'success' => false,
        'message' => '请求内容为空',
      ];
    }

    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return [
        'success' => false,
        'message' => 'JSON格式错误: ' . json_last_error_msg(),
      ];
    }

    return [
      'success' => true,
      'data' => $data,
    ];
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

      $fieldTypeManager = $this->fieldTypeManager;
      $passwordPlugin = $fieldTypeManager->getPlugin('password');

      foreach ($password_fields as $field) {
        $field_name = $field['name'];
        
        // 如果数据中有这个密码字段
        if (isset($data[$field_name]) && !empty($data[$field_name])) {
          $settings = is_string($field['settings']) ? 
            json_decode($field['settings'], TRUE) : 
            ($field['settings'] ?? []);
          
          // 使用密码字段插件处理密码
          $data[$field_name] = $passwordPlugin->processValue($data[$field_name], $settings);
          
          \Drupal::logger('baas_entity')->info('密码字段 @field 已处理', [
            '@field' => $field_name,
          ]);
        }
      }

      return $data;
    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('处理密码字段失败: @error', [
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
      \Drupal::logger('baas_entity')->error('过滤密码字段失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return $data; // 出错时返回原始数据
    }
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
      \Drupal::logger('baas_entity')->error('唯一性验证失败: @error', ['@error' => $e->getMessage()]);
      return [
        'error' => '唯一性验证失败: ' . $e->getMessage(),
        'code' => 'UNIQUE_VALIDATION_ERROR',
      ];
    }
  }
}