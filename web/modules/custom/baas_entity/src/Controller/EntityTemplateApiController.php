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
use Drupal\baas_tenant\TenantManagerInterface;
use Drupal\baas_tenant\TenantResolver;

/**
 * 实体模板API控制器。
 */
class EntityTemplateApiController extends BaseApiController {

  /**
   * 模板管理服务。
   *
   * @var \Drupal\baas_entity\Service\TemplateManager
   */
  protected TemplateManager $templateManager;

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
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理服务。
   * @param \Drupal\baas_tenant\TenantResolver $tenant_resolver
   *   租户解析服务。
   */
  public function __construct(
    ApiResponseService $response_service,
    ApiValidationService $validation_service,
    UnifiedPermissionCheckerInterface $permission_checker,
    TemplateManager $template_manager,
    TenantManagerInterface $tenant_manager,
    TenantResolver $tenant_resolver
  ) {
    parent::__construct($response_service, $validation_service, $permission_checker);
    $this->templateManager = $template_manager;
    $this->tenantManager = $tenant_manager;
    $this->tenantResolver = $tenant_resolver;
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
      $container->get('baas_tenant.manager'),
      $container->get('baas_tenant.resolver')
    );
  }

  /**
   * 获取租户的所有模板。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function listTemplates(Request $request, string $tenant_id): JsonResponse {
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

      // 解析分页参数
      $pagination = $this->parsePaginationParams($request);
      
      // 获取租户的所有模板
      $templates = $this->templateManager->getTemplatesByTenant($tenant_id);
      
      // 如果没有模板，返回空数组
      if (empty($templates)) {
        return $this->createSuccessResponse([], '获取模板列表成功');
      }

      // 格式化模板数据
      $data = [];
      foreach ($templates as $template) {
        // 获取字段数量
        $fields = $this->templateManager->getTemplateFields($template->id);
        $field_count = count($fields);

        $data[] = [
          'id' => $template->id,
          'tenant_id' => $template->tenant_id,
          'name' => $template->name,
          'label' => $template->label,
          'description' => $template->description,
          'status' => (bool) $template->status,
          'field_count' => $field_count,
          'created' => $template->created,
        ];
      }

      return $this->createSuccessResponse($data, '获取模板列表成功');
      
    } catch (\Exception $e) {
      $this->getLogger('baas_entity')->error('获取模板列表失败: @error', ['@error' => $e->getMessage()]);
      return $this->createErrorResponse('获取模板列表失败', 'TEMPLATE_LIST_ERROR', 500);
    }
  }

  /**
   * 获取指定模板的详细信息。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $tenant_id
   *   租户ID。
   * @param string $template_name
   *   模板名称。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function getTemplate(Request $request, string $tenant_id, string $template_name): JsonResponse {
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

      // 验证模板名称格式
      $entity_validation = $this->validateEntityName($template_name);
      if (!$entity_validation['valid']) {
        return $this->createErrorResponse(
          'Invalid template name format',
          'INVALID_TEMPLATE_NAME',
          400
        );
      }

      // 获取指定名称的模板
      $template = $this->templateManager->getTemplateByName($tenant_id, $template_name);
      if (!$template) {
        return $this->createErrorResponse('模板不存在: ' . $template_name, 'TEMPLATE_NOT_FOUND', 404);
      }

      // 获取模板的所有字段
      $fields = $this->templateManager->getTemplateFields($template->id);
      $field_data = [];
      foreach ($fields as $field) {
        $field_data[] = [
          'id' => $field->id,
          'name' => $field->name,
          'label' => $field->label,
          'type' => $field->type,
          'description' => $field->description,
          'required' => (bool) $field->required,
          'multiple' => (bool) $field->multiple,
          'settings' => $field->settings,
          'weight' => $field->weight,
        ];
      }

      // 格式化模板数据
      $data = [
        'id' => $template->id,
        'tenant_id' => $template->tenant_id,
        'name' => $template->name,
        'label' => $template->label,
        'description' => $template->description,
        'status' => (bool) $template->status,
        'created' => $template->created,
        'updated' => $template->updated,
        'fields' => $field_data,
      ];

      return $this->createSuccessResponse($data, '获取模板详情成功');
      
    } catch (\Exception $e) {
      $this->getLogger('baas_entity')->error('获取模板详情失败: @error', ['@error' => $e->getMessage()]);
      return $this->createErrorResponse('获取模板详情失败', 'TEMPLATE_GET_ERROR', 500);
    }
  }

  /**
   * 获取指定模板的所有字段。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $tenant_id
   *   租户ID。
   * @param string $template_name
   *   模板名称。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function listTemplateFields(Request $request, $tenant_id, $template_name) {
    // 验证租户是否存在
    $tenant = $this->tenantManager->getTenant($tenant_id);
    if (!$tenant) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => '租户不存在: ' . $tenant_id
      ], 404);
    }

    // 获取指定名称的模板
    $template = $this->templateManager->getTemplateByName($tenant_id, $template_name);
    if (!$template) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => '模板不存在: ' . $template_name
      ], 404);
    }

    // 获取模板的所有字段
    $fields = $this->templateManager->getTemplateFields($template->id);
    $field_data = [];
    foreach ($fields as $field) {
      $field_data[] = [
        'id' => $field->id,
        'name' => $field->name,
        'label' => $field->label,
        'type' => $field->type,
        'description' => $field->description,
        'required' => (bool) $field->required,
        'multiple' => (bool) $field->multiple,
        'settings' => $field->settings,
        'weight' => $field->weight,
      ];
    }

    return new JsonResponse([
      'success' => TRUE,
      'data' => $field_data
    ]);
  }

  /**
   * 通过域名获取当前租户的模板列表。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function listTemplatesByDomain(Request $request) {
    // 获取当前租户
    $tenant = $this->tenantResolver->getCurrentTenant();

    if (!$tenant) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => '无法识别当前租户'
      ], 404);
    }

    // 调用现有方法获取模板列表
    return $this->listTemplates($request, $tenant['tenant_id']);
  }

  /**
   * 通过域名获取当前租户的指定模板。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $template_name
   *   模板名称。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function getTemplateByDomain(Request $request, $template_name) {
    // 获取当前租户
    $tenant = $this->tenantResolver->getCurrentTenant();

    if (!$tenant) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => '无法识别当前租户'
      ], 404);
    }

    // 调用现有方法获取模板详情
    return $this->getTemplate($request, $tenant['tenant_id'], $template_name);
  }

  /**
   * 通过域名获取当前租户指定模板的字段列表。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string $template_name
   *   模板名称。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function listTemplateFieldsByDomain(Request $request, $template_name) {
    // 获取当前租户
    $tenant = $this->tenantResolver->getCurrentTenant();

    if (!$tenant) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => '无法识别当前租户'
      ], 404);
    }

    // 调用现有方法获取字段列表
    return $this->listTemplateFields($request, $tenant['tenant_id'], $template_name);
  }

}
