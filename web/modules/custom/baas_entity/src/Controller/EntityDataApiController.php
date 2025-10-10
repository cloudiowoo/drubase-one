<?php

declare(strict_types=1);

namespace Drupal\baas_entity\Controller;

use Drupal\baas_api\Controller\BaseApiController;
use Drupal\baas_api\Service\ApiResponseService;
use Drupal\baas_api\Service\ApiValidationService;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\baas_entity\Service\TemplateManager;
use Drupal\baas_entity\Service\EntityRegistry;
use Drupal\baas_entity\Service\EntityReferenceResolver;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\baas_tenant\TenantManagerInterface;
use Drupal\baas_tenant\TenantResolver;
use Drupal\Core\Database\Connection;

/**
 * 实体数据API控制器。
 *
 * 处理租户实体数据的API请求。
 */
class EntityDataApiController extends BaseApiController {

  /**
   * 模板管理服务。
   *
   * @var \Drupal\baas_entity\Service\TemplateManager
   */
  protected TemplateManager $templateManager;

  /**
   * 实体注册服务。
   *
   * @var \Drupal\baas_entity\Service\EntityRegistry
   */
  protected EntityRegistry $entityRegistry;

  /**
   * 实体引用解析服务。
   *
   * @var \Drupal\baas_entity\Service\EntityReferenceResolver
   */
  protected EntityReferenceResolver $entityReferenceResolver;

  /**
   * 租户管理服务。
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected TenantManagerInterface $tenantManager;

  /**
   * 租户解析服务。
   *
   * @var \Drupal\baas_tenant\TenantResolver
   */
  protected TenantResolver $tenantResolver;

  /**
   * 数据库连接。
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_api\Service\ApiResponseService $response_service
   *   API响应服务。
   * @param \Drupal\baas_api\Service\ApiValidationService $validation_service
   *   API验证服务。
   * @param \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface $permission_checker
   *   统一权限检查器。
   * @param \Drupal\baas_entity\Service\TemplateManager $template_manager
   *   模板管理服务。
   * @param \Drupal\baas_entity\Service\EntityRegistry $entity_registry
   *   实体注册服务。
   * @param \Drupal\baas_entity\Service\EntityReferenceResolver $entity_reference_resolver
   *   实体引用解析服务。
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   实体类型管理器。
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理服务。
   * @param \Drupal\baas_tenant\TenantResolver $tenant_resolver
   *   租户解析服务。
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接。
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
    Connection $database
  ) {
    parent::__construct($response_service, $validation_service, $permission_checker);
    $this->templateManager = $template_manager;
    $this->entityRegistry = $entity_registry;
    $this->entityReferenceResolver = $entity_reference_resolver;
    $this->entityTypeManager = $entity_type_manager;
    $this->tenantManager = $tenant_manager;
    $this->tenantResolver = $tenant_resolver;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
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
      $container->get('database')
    );
  }

  /**
   * 获取租户实体数据列表。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $tenant_id
   *   租户ID。
   * @param string $entity_name
   *   实体名称。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function listEntities(Request $request, string $tenant_id, string $entity_name): JsonResponse {
    try {
      // 验证租户权限
      $tenant_validation = $this->validateTenantAccess($tenant_id, 'view');
      if (!$tenant_validation['valid']) {
        return $this->createErrorResponse(
          $tenant_validation['error'],
          $tenant_validation['code'],
          $tenant_validation['code'] === 'TENANT_NOT_FOUND' ? 404 : 403
        );
      }

      // 验证实体名称格式
      $entity_validation = $this->validateEntityName($entity_name);
      if (!$entity_validation['valid']) {
        return $this->createErrorResponse(
          'Invalid entity name format',
          'INVALID_ENTITY_NAME',
          400
        );
      }

      // 解析分页参数
      $pagination = $this->parsePaginationParams($request);
      $sort = $this->parseSortParams($request, ['id', 'created', 'updated']);
      $filters = $this->parseFilterParams($request, ['status', 'created_by']);

      // 获取实体类型ID
      $entity_type_id = $tenant_id . '_' . $entity_name;

      // 检查实体类型是否存在
      if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
        return $this->createErrorResponse(
          '实体类型不存在: ' . $entity_name,
          'ENTITY_TYPE_NOT_FOUND',
          404
        );
      }

      // 检查实体类型是否注册
      if (!$this->entityRegistry->isEntityTypeRegistered($entity_type_id)) {
        // 尝试重新注册实体类型
        $this->rebuildEntityTypeDefinitions($entity_type_id);
      }

      // 获取实体存储
      $entity_storage = $this->entityTypeManager->getStorage($entity_type_id);
      
      // 构建查询
      $query = $entity_storage->getQuery()->accessCheck(TRUE);
      
      // 应用筛选条件
      foreach ($filters as $field => $value) {
        $query->condition($field, $value);
      }
      
      // 应用排序
      $query->sort($sort['field'], $sort['direction']);
      
      // 获取总数（用于分页）
      $total_query = clone $query;
      $total = $total_query->count()->execute();
      
      // 应用分页
      $query->range($pagination['offset'], $pagination['limit']);
      
      // 执行查询
      $entity_ids = $query->execute();
      
      // 加载实体数据
      $entities = $entity_storage->loadMultiple($entity_ids);
      
      // 格式化实体数据
      $data = [];
      foreach ($entities as $entity) {
        $data[] = $this->formatEntityData($entity);
      }
      
      return $this->createPaginatedResponse(
        $data,
        (int) $total,
        $pagination['page'],
        $pagination['limit'],
        '获取实体列表成功'
      );
      
    } catch (\Exception $e) {
      $this->getLogger('baas_entity')->error('获取实体列表失败: @error', [
        '@error' => $e->getMessage(),
        '@tenant' => $tenant_id,
        '@entity' => $entity_name,
      ]);
      return $this->createErrorResponse('获取实体列表失败', 'ENTITY_LIST_ERROR', 500);
    }
  }

  /**
   * 格式化实体数据。
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   实体对象。
   *
   * @return array
   *   格式化的实体数据。
   */
  protected function formatEntityData($entity): array {
    $data = [];
    
    // 获取实体字段
    foreach ($entity->getFields() as $field_name => $field) {
      $field_value = $field->getValue();
      
      // 简化字段值格式
      if (is_array($field_value) && count($field_value) === 1 && isset($field_value[0]['value'])) {
        $data[$field_name] = $field_value[0]['value'];
      } else {
        $data[$field_name] = $field_value;
      }
    }
    
    return $data;
  }

  /**
   * 重新构建实体类型定义。
   *
   * @param string $entity_type_id
   *   实体类型ID。
   */
  protected function rebuildEntityTypeDefinitions(string $entity_type_id): void {
    try {
      // 清除缓存
      $this->entityTypeManager->clearCachedDefinitions();
      
      // 重新注册实体类型
      $this->entityRegistry->registerEntityType($entity_type_id);
      
      // 再次清除缓存
      $this->entityTypeManager->clearCachedDefinitions();
      
    } catch (\Exception $e) {
      $this->getLogger('baas_entity')->error('重新构建实体类型定义失败: @error', [
        '@error' => $e->getMessage(),
        '@entity_type' => $entity_type_id,
      ]);
    }
  }



  /**
   * 获取当前租户的实体数据列表。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $entity_name
   *   实体名称。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function listCurrentTenantEntities(Request $request, string $entity_name): JsonResponse {
    // 获取当前租户
    $tenant = $this->tenantResolver->getCurrentTenant();

    if (!$tenant) {
      return $this->createErrorResponse('无法识别当前租户', 'TENANT_NOT_RESOLVED', 404);
    }

    // 调用现有方法获取实体数据列表
    return $this->listEntities($request, $tenant['tenant_id'], $entity_name);
  }

  /**
   * 获取租户的单个实体数据。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $tenant_id
   *   租户ID。
   * @param string $entity_name
   *   实体名称。
   * @param int $id
   *   实体ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function getEntity(Request $request, string $tenant_id, string $entity_name, int $id): JsonResponse {
    try {
      // 验证租户权限
      $tenant_validation = $this->validateTenantAccess($tenant_id, 'view');
      if (!$tenant_validation['valid']) {
        return $this->createErrorResponse(
          $tenant_validation['error'],
          $tenant_validation['code'],
          $tenant_validation['code'] === 'TENANT_NOT_FOUND' ? 404 : 403
        );
      }

      // 验证实体名称格式
      $entity_validation = $this->validateEntityName($entity_name);
      if (!$entity_validation['valid']) {
        return $this->createErrorResponse(
          'Invalid entity name format',
          'INVALID_ENTITY_NAME',
          400
        );
      }

      // 获取实体类型ID
      $entity_type_id = $tenant_id . '_' . $entity_name;

      // 检查实体类型是否存在
      if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
        return $this->createErrorResponse(
          '实体类型不存在: ' . $entity_name,
          'ENTITY_TYPE_NOT_FOUND',
          404
        );
      }

      // 加载实体
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);

      if (!$entity) {
        return $this->createErrorResponse(
          '未找到实体: ' . $id,
          'ENTITY_NOT_FOUND',
          404
        );
      }

      // 格式化实体数据
      $entity_data = $this->formatEntityData($entity);
      
      // 解析实体引用字段
      $reference_fields = $this->entityReferenceResolver->getEntityReferenceFields($tenant_id, $entity_name);
      if (!empty($reference_fields)) {
        $entity_data = $this->entityReferenceResolver->resolveEntityReferences($tenant_id, $entity_name, $entity_data, $reference_fields);
      }
      
      return $this->createSuccessResponse($entity_data, '获取实体数据成功');
      
    } catch (\Exception $e) {
      $this->getLogger('baas_entity')->error('获取实体数据失败: @error', [
        '@error' => $e->getMessage(),
        '@tenant' => $tenant_id,
        '@entity' => $entity_name,
        '@id' => $id,
      ]);
      return $this->createErrorResponse('获取实体数据失败', 'ENTITY_GET_ERROR', 500);
    }
  }

  /**
   * 获取当前租户的单个实体数据。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $entity_name
   *   实体名称。
   * @param int $id
   *   实体ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function getCurrentTenantEntity(Request $request, string $entity_name, int $id): JsonResponse {
    // 获取当前租户
    $tenant = $this->tenantResolver->getCurrentTenant();

    if (!$tenant) {
      return $this->createErrorResponse(
        '无法识别当前租户',
        'TENANT_NOT_RESOLVED',
        404
      );
    }

    // 调用现有方法获取实体数据
    return $this->getEntity($request, $tenant['tenant_id'], $entity_name, $id);
  }

  /**
   * 创建租户实体。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $tenant_id
   *   租户ID。
   * @param string $entity_name
   *   实体名称。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function createEntity(Request $request, string $tenant_id, string $entity_name): JsonResponse {
    try {
      // 验证租户权限
      $tenant_validation = $this->validateTenantAccess($tenant_id, 'create');
      if (!$tenant_validation['valid']) {
        return $this->createErrorResponse(
          $tenant_validation['error'],
          $tenant_validation['code'],
          $tenant_validation['code'] === 'TENANT_NOT_FOUND' ? 404 : 403
        );
      }

      // 验证实体名称格式
      $entity_validation = $this->validateEntityName($entity_name);
      if (!$entity_validation['valid']) {
        return $this->createErrorResponse(
          'Invalid entity name format',
          'INVALID_ENTITY_NAME',
          400
        );
      }

      // 获取实体类型ID
      $entity_type_id = $tenant_id . '_' . $entity_name;

      // 检查实体类型是否存在
      if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
        return $this->createErrorResponse(
          '实体类型不存在: ' . $entity_name,
          'ENTITY_TYPE_NOT_FOUND',
          404
        );
      }

      // 验证JSON请求体
      $validation_result = $this->validateJsonRequest($request->getContent());
      if (!$validation_result['valid']) {
        return $this->createErrorResponse(
          $validation_result['error'],
          $validation_result['code'],
          400
        );
      }

      $content = $validation_result['data'];
      
      // 添加租户ID
      $content['tenant_id'] = $tenant_id;

      // 创建实体
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->create($content);
      $entity->save();

      // 格式化实体数据
      $entity_data = $this->formatEntityData($entity);
      
      // 解析实体引用字段
      $reference_fields = $this->entityReferenceResolver->getEntityReferenceFields($tenant_id, $entity_name);
      if (!empty($reference_fields)) {
        $entity_data = $this->entityReferenceResolver->resolveEntityReferences($tenant_id, $entity_name, $entity_data, $reference_fields);
      }
      
      return $this->createCreatedResponse($entity_data, '实体创建成功');
      
    } catch (\Exception $e) {
      $this->getLogger('baas_entity')->error('创建实体数据失败: @error', [
        '@error' => $e->getMessage(),
        '@tenant' => $tenant_id,
        '@entity' => $entity_name,
      ]);
      return $this->createErrorResponse('创建实体数据失败', 'ENTITY_CREATE_ERROR', 500);
    }
  }

  /**
   * 创建当前租户实体数据。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $entity_name
   *   实体名称。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function createCurrentTenantEntity(Request $request, string $entity_name): JsonResponse {
    // 获取当前租户
    $tenant = $this->tenantResolver->getCurrentTenant();

    if (!$tenant) {
      return $this->createErrorResponse(
        '无法识别当前租户',
        'TENANT_NOT_RESOLVED',
        404
      );
    }

    // 调用现有方法创建实体数据
    return $this->createEntity($request, $tenant['tenant_id'], $entity_name);
  }

  /**
   * 更新租户实体。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $tenant_id
   *   租户ID。
   * @param string $entity_name
   *   实体名称。
   * @param int $id
   *   实体ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function updateEntity(Request $request, string $tenant_id, string $entity_name, int $id): JsonResponse {
    try {
      // 验证租户权限
      $tenant_validation = $this->validateTenantAccess($tenant_id, 'update');
      if (!$tenant_validation['valid']) {
        return $this->createErrorResponse(
          $tenant_validation['error'],
          $tenant_validation['code'],
          $tenant_validation['code'] === 'TENANT_NOT_FOUND' ? 404 : 403
        );
      }

      // 验证实体名称格式
      $entity_validation = $this->validateEntityName($entity_name);
      if (!$entity_validation['valid']) {
        return $this->createErrorResponse(
          'Invalid entity name format',
          'INVALID_ENTITY_NAME',
          400
        );
      }

      // 获取实体类型ID
      $entity_type_id = $tenant_id . '_' . $entity_name;

      // 检查实体类型是否存在
      if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
        return $this->createErrorResponse(
          '实体类型不存在: ' . $entity_name,
          'ENTITY_TYPE_NOT_FOUND',
          404
        );
      }

      // 加载实体
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);

      if (!$entity) {
        return $this->createErrorResponse(
          '未找到实体: ' . $id,
          'ENTITY_NOT_FOUND',
          404
        );
      }

      // 验证JSON请求体
      $validation_result = $this->validateJsonRequest($request->getContent());
      if (!$validation_result['valid']) {
        return $this->createErrorResponse(
          $validation_result['error'],
          $validation_result['code'],
          400
        );
      }

      $content = $validation_result['data'];
      
      // 不允许修改租户ID
      if (isset($content['tenant_id'])) {
        unset($content['tenant_id']);
      }

      // 获取实体数据作为数组
      $entity_array = $entity->toArray();
      $field_names = array_keys($entity_array);

      // 更新实体
      foreach ($content as $field => $value) {
        if (in_array($field, $field_names)) {
          $entity->$field = $value;
        }
      }

      $entity->save();

      // 格式化实体数据
      $entity_data = $this->formatEntityData($entity);
      
      // 解析实体引用字段
      $reference_fields = $this->entityReferenceResolver->getEntityReferenceFields($tenant_id, $entity_name);
      if (!empty($reference_fields)) {
        $entity_data = $this->entityReferenceResolver->resolveEntityReferences($tenant_id, $entity_name, $entity_data, $reference_fields);
      }
      
      return $this->createSuccessResponse($entity_data, '实体更新成功');
      
    } catch (\Exception $e) {
      $this->getLogger('baas_entity')->error('更新实体数据失败: @error', [
        '@error' => $e->getMessage(),
        '@tenant' => $tenant_id,
        '@entity' => $entity_name,
        '@id' => $id,
      ]);
      return $this->createErrorResponse('更新实体数据失败', 'ENTITY_UPDATE_ERROR', 500);
    }
  }

  /**
   * 更新当前租户实体数据。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $entity_name
   *   实体名称。
   * @param int $id
   *   实体ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function updateCurrentTenantEntity(Request $request, string $entity_name, int $id): JsonResponse {
    // 获取当前租户
    $tenant = $this->tenantResolver->getCurrentTenant();

    if (!$tenant) {
      return $this->createErrorResponse(
        '无法识别当前租户',
        'TENANT_NOT_RESOLVED',
        404
      );
    }

    // 调用现有方法更新实体数据
    return $this->updateEntity($request, $tenant['tenant_id'], $entity_name, $id);
  }

  /**
   * 删除租户实体。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $tenant_id
   *   租户ID。
   * @param string $entity_name
   *   实体名称。
   * @param int $id
   *   实体ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function deleteEntity(Request $request, string $tenant_id, string $entity_name, int $id): JsonResponse {
    try {
      // 验证租户权限
      $tenant_validation = $this->validateTenantAccess($tenant_id, 'delete');
      if (!$tenant_validation['valid']) {
        return $this->createErrorResponse(
          $tenant_validation['error'],
          $tenant_validation['code'],
          $tenant_validation['code'] === 'TENANT_NOT_FOUND' ? 404 : 403
        );
      }

      // 验证实体名称格式
      $entity_validation = $this->validateEntityName($entity_name);
      if (!$entity_validation['valid']) {
        return $this->createErrorResponse(
          'Invalid entity name format',
          'INVALID_ENTITY_NAME',
          400
        );
      }

      // 获取实体类型ID
      $entity_type_id = $tenant_id . '_' . $entity_name;

      // 检查实体类型是否存在
      if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
        return $this->createErrorResponse(
          '实体类型不存在: ' . $entity_name,
          'ENTITY_TYPE_NOT_FOUND',
          404
        );
      }

      // 加载实体
      $entity = $this->entityTypeManager->getStorage($entity_type_id)->load($id);

      if (!$entity) {
        return $this->createErrorResponse(
          '未找到实体: ' . $id,
          'ENTITY_NOT_FOUND',
          404
        );
      }

      // 删除实体
      $entity->delete();

      return $this->createNoContentResponse('实体已删除');
      
    } catch (\Exception $e) {
      $this->getLogger('baas_entity')->error('删除实体数据失败: @error', [
        '@error' => $e->getMessage(),
        '@tenant' => $tenant_id,
        '@entity' => $entity_name,
        '@id' => $id,
      ]);
      return $this->createErrorResponse('删除实体数据失败', 'ENTITY_DELETE_ERROR', 500);
    }
  }

  /**
   * 删除当前租户实体数据。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $entity_name
   *   实体名称。
   * @param int $id
   *   实体ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function deleteCurrentTenantEntity(Request $request, string $entity_name, int $id): JsonResponse {
    // 获取当前租户
    $tenant = $this->tenantResolver->getCurrentTenant();

    if (!$tenant) {
      return $this->createErrorResponse(
        '无法识别当前租户',
        'TENANT_NOT_RESOLVED',
        404
      );
    }

    // 调用现有方法删除实体数据
    return $this->deleteEntity($request, $tenant['tenant_id'], $entity_name, $id);
  }

  /**
   * 搜索可引用的实体。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $tenant_id
   *   租户ID。
   * @param string $entity_name
   *   实体名称。
   * @param string $field_name
   *   引用字段名称。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function searchReferencableEntities(Request $request, string $tenant_id, string $entity_name, string $field_name): JsonResponse {
    try {
      // 验证租户权限
      $tenant_validation = $this->validateTenantAccess($tenant_id, 'view');
      if (!$tenant_validation['valid']) {
        return $this->createErrorResponse(
          $tenant_validation['error'],
          $tenant_validation['code'],
          $tenant_validation['code'] === 'TENANT_NOT_FOUND' ? 404 : 403
        );
      }

      // 验证实体名称格式
      $entity_validation = $this->validateEntityName($entity_name);
      if (!$entity_validation['valid']) {
        return $this->createErrorResponse(
          'Invalid entity name format',
          'INVALID_ENTITY_NAME',
          400
        );
      }

      // 获取搜索参数
      $search_string = $request->query->get('q', '');
      $limit = (int) $request->query->get('limit', 10);

      // 获取实体模板
      $template = $this->templateManager->getTemplateByName($tenant_id, $entity_name);
      if (!$template) {
        return $this->createErrorResponse(
          '实体模板不存在: ' . $entity_name,
          'ENTITY_TEMPLATE_NOT_FOUND',
          404
        );
      }

      // 获取引用字段设置
      $field = $this->templateManager->getFieldByName($template->id, $field_name);
      if (!$field || $field->type !== 'reference') {
        return $this->createErrorResponse(
          '引用字段不存在: ' . $field_name,
          'REFERENCE_FIELD_NOT_FOUND',
          404
        );
      }

      $field_settings = $field->settings;
      $target_type = $field_settings['target_type'] ?? 'node';

      // 搜索可引用的实体
      $results = $this->entityReferenceResolver->searchReferencableEntities(
        $target_type,
        $search_string,
        $field_settings,
        $tenant_id,
        $limit
      );

      $meta = [
        'search_string' => $search_string,
        'target_type' => $target_type,
        'limit' => $limit,
        'count' => count($results)
      ];

      return $this->createSuccessResponse($results, '搜索可引用实体成功', $meta);

    } catch (\Exception $e) {
      $this->getLogger('baas_entity')->error('搜索可引用实体失败: @error', [
        '@error' => $e->getMessage(),
        '@tenant' => $tenant_id,
        '@entity' => $entity_name,
        '@field' => $field_name,
      ]);
      return $this->createErrorResponse('搜索失败', 'ENTITY_SEARCH_ERROR', 500);
    }
  }

  /**
   * 搜索当前租户的可引用实体。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $entity_name
   *   实体名称。
   * @param string $field_name
   *   引用字段名称。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function searchCurrentTenantReferencableEntities(Request $request, string $entity_name, string $field_name): JsonResponse {
    // 获取当前租户
    $tenant = $this->tenantResolver->getCurrentTenant();

    if (!$tenant) {
      return $this->createErrorResponse(
        '无法识别当前租户',
        'TENANT_NOT_RESOLVED',
        404
      );
    }

    // 调用现有方法搜索可引用实体
    return $this->searchReferencableEntities($request, $tenant['tenant_id'], $entity_name, $field_name);
  }

}
