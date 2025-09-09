<?php

declare(strict_types=1);

namespace Drupal\baas_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\baas_api\Service\ApiResponseService;
use Drupal\baas_api\Service\ApiValidationService;
use Drupal\baas_api\Service\RateLimitService;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Drupal\baas_auth\Authentication\BaasAuthenticatedUser;
use Drupal\baas_project\ProjectUsageTrackerInterface;
use Drupal\Core\Access\AccessDeniedException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base API控制器类.
 *
 * 提供统一的API响应格式和错误处理方法。
 */
abstract class BaseApiController extends ControllerBase
{

  /**
   * API响应服务.
   *
   * @var \Drupal\baas_api\Service\ApiResponseService
   */
  protected ApiResponseService $responseService;

  /**
   * API验证服务.
   *
   * @var \Drupal\baas_api\Service\ApiValidationService
   */
  protected ApiValidationService $validationService;

  /**
   * 统一权限检查器.
   *
   * @var \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface
   */
  protected UnifiedPermissionCheckerInterface $permissionChecker;

  /**
   * 限流服务.
   *
   * @var \Drupal\baas_api\Service\RateLimitService|null
   */
  protected ?RateLimitService $rateLimitService;

  /**
   * 项目使用统计跟踪器.
   *
   * @var \Drupal\baas_project\ProjectUsageTrackerInterface|null
   */
  protected ?ProjectUsageTrackerInterface $projectUsageTracker;

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_api\Service\ApiResponseService $response_service
   *   API响应服务.
   * @param \Drupal\baas_api\Service\ApiValidationService $validation_service
   *   API验证服务.
   * @param \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface $permission_checker
   *   统一权限检查器.
   * @param \Drupal\baas_api\Service\RateLimitService|null $rate_limit_service
   *   限流服务（可选，用于向后兼容）.
   * @param \Drupal\baas_project\ProjectUsageTrackerInterface|null $project_usage_tracker
   *   项目使用统计跟踪器（可选，用于向后兼容）.
   */
  public function __construct(
    ApiResponseService $response_service,
    ApiValidationService $validation_service,
    UnifiedPermissionCheckerInterface $permission_checker,
    ?RateLimitService $rate_limit_service = null,
    ?ProjectUsageTrackerInterface $project_usage_tracker = null
  ) {
    $this->responseService = $response_service;
    $this->validationService = $validation_service;
    $this->permissionChecker = $permission_checker;
    $this->rateLimitService = $rate_limit_service;
    $this->projectUsageTracker = $project_usage_tracker;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    $rate_limit_service = null;
    $project_usage_tracker = null;

    // 安全地获取可选服务，避免在它们不存在时报错
    try {
      $rate_limit_service = $container->get('baas_api.rate_limit');
    } catch (\Exception $e) {
      // 服务不存在时忽略
    }

    try {
      $project_usage_tracker = $container->get('baas_project.usage_tracker');
    } catch (\Exception $e) {
      // 服务不存在时忽略
    }

    return new static(
      $container->get('baas_api.response'),
      $container->get('baas_api.validation'),
      $container->get('baas_auth.unified_permission_checker'),
      $rate_limit_service,
      $project_usage_tracker
    );
  }

  /**
   * 创建成功响应.
   *
   * @param mixed $data
   *   响应数据.
   * @param string $message
   *   成功消息.
   * @param array $meta
   *   额外的元数据.
   * @param array $pagination
   *   分页信息（可选）.
   * @param int $status
   *   HTTP状态码.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   标准化的成功响应.
   */
  protected function createSuccessResponse(
    $data,
    string $message = '',
    array $meta = [],
    array $pagination = [],
    int $status = 200
  ): JsonResponse {
    return $this->responseService->createSuccessResponse($data, $message, $meta, $pagination, $status);
  }

  /**
   * 创建错误响应.
   *
   * @param string $error
   *   错误消息.
   * @param string $code
   *   错误代码.
   * @param int $status
   *   HTTP状态码.
   * @param array $context
   *   错误上下文信息.
   * @param array $meta
   *   额外的元数据.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   标准化的错误响应.
   */
  protected function createErrorResponse(
    string $error,
    string $code,
    int $status = 400,
    array $context = [],
    array $meta = []
  ): JsonResponse {
    return $this->responseService->createErrorResponse($error, $code, $status, $context, $meta);
  }

  /**
   * 创建分页响应.
   *
   * @param array $items
   *   数据项列表.
   * @param int $total
   *   总记录数.
   * @param int $page
   *   当前页码.
   * @param int $limit
   *   每页记录数.
   * @param string $message
   *   成功消息.
   * @param array $meta
   *   额外的元数据.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   带分页信息的响应.
   */
  protected function createPaginatedResponse(
    array $items,
    int $total,
    int $page,
    int $limit,
    string $message = '',
    array $meta = []
  ): JsonResponse {
    return $this->responseService->createPaginatedResponse($items, $total, $page, $limit, $message, $meta);
  }

  /**
   * 创建验证错误响应.
   *
   * @param array $errors
   *   验证错误列表.
   * @param string $message
   *   主错误消息.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   验证错误响应.
   */
  protected function createValidationErrorResponse(
    array $errors,
    string $message = 'Validation failed'
  ): JsonResponse {
    return $this->responseService->createValidationErrorResponse($errors, $message);
  }

  /**
   * 创建无内容响应（204 No Content）.
   *
   * @param string $message
   *   操作成功消息.
   * @param array $meta
   *   额外的元数据.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   无内容响应.
   */
  protected function createNoContentResponse(
    string $message = 'Operation completed successfully',
    array $meta = []
  ): JsonResponse {
    return $this->responseService->createNoContentResponse($message, $meta);
  }

  /**
   * 创建创建成功响应（201 Created）.
   *
   * @param mixed $data
   *   创建的资源数据.
   * @param string $message
   *   创建成功消息.
   * @param array $meta
   *   额外的元数据.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   创建成功响应.
   */
  protected function createCreatedResponse(
    $data,
    string $message = 'Resource created successfully',
    array $meta = []
  ): JsonResponse {
    return $this->responseService->createCreatedResponse($data, $message, $meta);
  }

  /**
   * 验证JSON请求体.
   *
   * @param string $content
   *   请求内容.
   * @param array $required_fields
   *   必需字段列表.
   * @param array $allowed_fields
   *   允许的字段列表（空表示允许所有字段）.
   *
   * @return array
   *   包含验证结果的数组.
   */
  protected function validateJsonRequest(
    string $content,
    array $required_fields = [],
    array $allowed_fields = []
  ): array {
    return $this->responseService->validateJsonRequest($content, $required_fields, $allowed_fields);
  }

  /**
   * 解析分页参数.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象.
   * @param int $default_limit
   *   默认每页记录数.
   * @param int $max_limit
   *   最大每页记录数.
   *
   * @return array
   *   包含page、limit和offset的数组.
   */
  protected function parsePaginationParams(
    Request $request,
    int $default_limit = 20,
    int $max_limit = 100
  ): array {
    return $this->responseService->parsePaginationParams($request, $default_limit, $max_limit);
  }

  /**
   * 解析排序参数.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象.
   * @param array $allowed_fields
   *   允许排序的字段列表.
   * @param string $default_field
   *   默认排序字段.
   * @param string $default_direction
   *   默认排序方向.
   *
   * @return array
   *   包含field和direction的数组.
   */
  protected function parseSortParams(
    Request $request,
    array $allowed_fields = ['id', 'created', 'updated'],
    string $default_field = 'id',
    string $default_direction = 'DESC'
  ): array {
    return $this->responseService->parseSortParams($request, $allowed_fields, $default_field, $default_direction);
  }

  /**
   * 解析筛选参数.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象.
   * @param array $allowed_filters
   *   允许的筛选字段列表.
   *
   * @return array
   *   筛选参数数组.
   */
  protected function parseFilterParams(Request $request, array $allowed_filters = []): array
  {
    return $this->responseService->parseFilterParams($request, $allowed_filters);
  }

  /**
   * 验证数据.
   *
   * @param array $data
   *   要验证的数据.
   * @param array $rules
   *   验证规则.
   *
   * @return array
   *   验证结果.
   */
  protected function validateData(array $data, array $rules): array
  {
    return $this->validationService->validateData($data, $rules);
  }

  /**
   * 验证租户ID.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array
   *   验证结果.
   */
  protected function validateTenantId(string $tenant_id): array
  {
    return $this->validationService->validateTenantId($tenant_id);
  }

  /**
   * 验证项目ID.
   *
   * @param string $project_id
   *   项目ID.
   *
   * @return array
   *   验证结果.
   */
  protected function validateProjectId(string $project_id): array
  {
    return $this->validationService->validateProjectId($project_id);
  }

  /**
   * 验证实体名称.
   *
   * @param string $entity_name
   *   实体名称.
   *
   * @return array
   *   验证结果.
   */
  protected function validateEntityName(string $entity_name): array
  {
    return $this->validationService->validateEntityName($entity_name);
  }

  /**
   * 记录API调用.
   *
   * @param string $endpoint
   *   API端点.
   * @param string $method
   *   HTTP方法.
   * @param int $status_code
   *   响应状态码.
   * @param float $response_time
   *   响应时间（毫秒）.
   */
  protected function logApiCall(string $endpoint, string $method, int $status_code, float $response_time = 0): void
  {
    $this->getLogger('baas_api')->info('API调用: @method @endpoint - @status (@time ms)', [
      '@method' => $method,
      '@endpoint' => $endpoint,
      '@status' => $status_code,
      '@time' => round($response_time, 2),
    ]);
  }

  /**
   * 验证租户权限.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param string $operation
   *   操作类型.
   *
   * @return array
   *   验证结果.
   */
  protected function validateTenantAccess(string $tenant_id, string $operation = 'view'): array
  {
    // 验证租户ID格式
    $tenant_validation = $this->validateTenantId($tenant_id);
    if (!$tenant_validation['valid']) {
      return [
        'valid' => false,
        'error' => 'Invalid tenant ID format',
        'code' => 'INVALID_TENANT_ID',
      ];
    }

    // 获取租户管理服务
    $tenant_manager = \Drupal::service('baas_tenant.manager');
    
    // 验证租户是否存在
    $tenant = $tenant_manager->getTenant($tenant_id);
    if (!$tenant) {
      return [
        'valid' => false,
        'error' => 'Tenant not found',
        'code' => 'TENANT_NOT_FOUND',
      ];
    }

    // 获取认证数据
    $auth_data = $this->getAuthData();
    if (!$auth_data) {
      return [
        'valid' => false,
        'error' => 'Authentication required',
        'code' => 'AUTHENTICATION_REQUIRED',
      ];
    }

    // 验证用户权限
    $permission_checker = \Drupal::service('baas_auth.unified_permission_checker');
    
    if (!$permission_checker->checkTenantPermission($auth_data['user_id'], $tenant_id, $operation)) {
      return [
        'valid' => false,
        'error' => 'Access denied',
        'code' => 'ACCESS_DENIED',
      ];
    }

    return [
      'valid' => true,
      'tenant' => $tenant,
    ];
  }

  /**
   * 获取当前请求的认证数据。
   *
   * @return array|null
   *   认证数据或null。
   */
  protected function getAuthData(): ?array
  {
    $request = \Drupal::request();
    return $request->attributes->get('auth_data');
  }

  /**
   * 获取当前认证用户ID。
   *
   * @return int|null
   *   用户ID或null。
   */
  protected function getCurrentUserId(): ?int
  {
    $auth_data = $this->getAuthData();
    return $auth_data['user_id'] ?? null;
  }

  /**
   * 获取当前租户ID。
   *
   * @return string|null
   *   租户ID或null。
   */
  protected function getCurrentTenantId(): ?string
  {
    $auth_data = $this->getAuthData();
    return $auth_data['tenant_id'] ?? null;
  }

  /**
   * 获取当前项目ID。
   *
   * @return string|null
   *   项目ID或null。
   */
  protected function getCurrentProjectId(): ?string
  {
    $auth_data = $this->getAuthData();
    return $auth_data['project_id'] ?? null;
  }

  /**
   * 检查当前用户是否具有指定权限。
   *
   * @param string $permission
   *   权限名称。
   * @param string|null $tenant_id
   *   租户ID（可选）。
   * @param string|null $project_id
   *   项目ID（可选）。
   *
   * @return bool
   *   是否具有权限。
   */
  protected function hasPermission(string $permission, ?string $tenant_id = null, ?string $project_id = null): bool
  {
    $auth_data = $this->getAuthData();
    if (!$auth_data) {
      return false;
    }

    $user_id = $auth_data['user_id'];
    $permission_checker = \Drupal::service('baas_auth.unified_permission_checker');

    // 检查项目级权限
    if ($project_id && $permission_checker->checkProjectPermission($user_id, $project_id, $permission)) {
      return true;
    }

    // 检查租户级权限
    if ($tenant_id && $permission_checker->checkTenantPermission($user_id, $tenant_id, $permission)) {
      return true;
    }

    // 如果没有项目或租户上下文，返回false
    return false;
  }

  /**
   * 要求用户具有指定权限，否则返回错误响应。
   *
   * @param string $permission
   *   权限名称。
   * @param string|null $tenant_id
   *   租户ID（可选）。
   * @param string|null $project_id
   *   项目ID（可选）。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   错误响应或null（如果有权限）。
   */
  protected function requirePermission(string $permission, ?string $tenant_id = null, ?string $project_id = null): ?\Symfony\Component\HttpFoundation\JsonResponse
  {
    if (!$this->hasPermission($permission, $tenant_id, $project_id)) {
      return $this->createErrorResponse(
        'Insufficient permissions',
        'INSUFFICIENT_PERMISSIONS',
        403,
        ['required_permission' => $permission]
      );
    }

    return null;
  }

  /**
   * 检查项目资源限制（包括限流）.
   *
   * @param string $project_id
   *   项目ID.
   * @param string $resource_type
   *   资源类型（如 'api_calls', 'storage_used' 等）.
   * @param int $amount
   *   请求的资源数量.
   * @param string|null $endpoint
   *   API端点（用于限流检查）.
   *
   * @return bool
   *   是否允许使用资源.
   */
  protected function checkResourceLimits(string $project_id, string $resource_type, int $amount = 1, ?string $endpoint = null): bool
  {
    try {
      // 检查是否启用了限流
      $config = \Drupal::config('baas_api.settings');
      if (!$config->get('enable_rate_limiting')) {
        return true; // 限流未启用，允许所有请求
      }

      // 特殊处理API调用限制
      if ($resource_type === 'api_calls' && $this->rateLimitService && $endpoint) {
        $allowed = $this->rateLimitService->checkProjectRateLimit($project_id, $endpoint, $amount);
        if (!$allowed) {
          $this->getLogger('baas_api')->warning('API rate limit exceeded for project', [
            'project_id' => $project_id,
            'endpoint' => $endpoint,
            'amount' => $amount,
          ]);
          return false;
        }
        return true;
      }

      // 其他资源类型使用ProjectUsageTracker检查
      if (!$this->projectUsageTracker) {
        // 如果没有项目使用统计跟踪器，默认允许
        $this->getLogger('baas_api')->debug('Project usage tracker not available, allowing resource use', [
          'project_id' => $project_id,
          'resource_type' => $resource_type,
        ]);
        return true;
      }

      $check = $this->projectUsageTracker->checkUsageLimit($project_id, $resource_type, $amount);

      if (!$check['allowed']) {
        $this->getLogger('baas_api')->warning('Resource limit exceeded', [
          'project_id' => $project_id,
          'resource_type' => $resource_type,
          'current' => $check['current_usage'] ?? 0,
          'limit' => $check['limit'] ?? 0,
          'requested' => $amount,
        ]);
      }

      return $check['allowed'];
    } catch (\Exception $e) {
      $this->getLogger('baas_api')->error('Resource limit check failed: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
        'resource_type' => $resource_type,
      ]);
      // 错误时默认允许，避免阻塞正常请求
      return true;
    }
  }

  /**
   * 要求通过资源限制检查，否则返回错误响应.
   *
   * @param string $project_id
   *   项目ID.
   * @param string $resource_type
   *   资源类型.
   * @param int $amount
   *   请求的资源数量.
   * @param string|null $endpoint
   *   API端点.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|null
   *   错误响应或null（如果通过检查）.
   */
  protected function requireResourceLimits(string $project_id, string $resource_type, int $amount = 1, ?string $endpoint = null): ?\Symfony\Component\HttpFoundation\JsonResponse
  {
    if (!$this->checkResourceLimits($project_id, $resource_type, $amount, $endpoint)) {
      // 根据资源类型返回相应的错误信息
      $error_messages = [
        'api_calls' => 'API call limit exceeded',
        'storage_used' => 'Storage limit exceeded',
        'entity_instances' => 'Entity instance limit exceeded',
        'file_uploads' => 'File upload limit exceeded',
      ];

      $error_message = $error_messages[$resource_type] ?? 'Resource limit exceeded';

      return $this->createErrorResponse(
        $error_message,
        'RESOURCE_LIMIT_EXCEEDED',
        $resource_type === 'api_calls' ? 429 : 413, // 429 for rate limit, 413 for payload too large
        [
          'resource_type' => $resource_type,
          'requested_amount' => $amount,
        ]
      );
    }

    return null;
  }

  /**
   * 获取项目的资源使用统计.
   *
   * @param string $project_id
   *   项目ID.
   *
   * @return array
   *   资源使用统计信息.
   */
  protected function getProjectResourceUsage(string $project_id): array
  {
    try {
      if (!$this->projectUsageTracker) {
        return [
          'available' => false,
          'message' => 'Resource tracking not available',
        ];
      }

      $current_usage = $this->projectUsageTracker->getCurrentUsage($project_id);
      $usage_limits = $this->projectUsageTracker->getUsageLimits($project_id);

      $stats = [
        'available' => true,
        'project_id' => $project_id,
        'resources' => [],
      ];

      foreach ($current_usage as $resource_type => $usage_data) {
        $limit = $usage_limits[$resource_type] ?? 0;
        $current = $usage_data['total_count'] ?? 0;

        $stats['resources'][$resource_type] = [
          'current_usage' => $current,
          'limit' => $limit,
          'remaining' => max(0, $limit - $current),
          'usage_percentage' => $limit > 0 ? round(($current / $limit) * 100, 2) : 0,
          'unit' => $usage_data['unit'] ?? 'count',
          'last_updated' => $usage_data['last_updated'] ?? 0,
        ];
      }

      // 如果有限流服务，添加API限流统计
      if ($this->rateLimitService) {
        $rate_limit_stats = $this->rateLimitService->getProjectRateLimitStats($project_id);
        if ($rate_limit_stats['available']) {
          $stats['rate_limiting'] = $rate_limit_stats;
        }
      }

      return $stats;
    } catch (\Exception $e) {
      $this->getLogger('baas_api')->error('Failed to get project resource usage: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
      ]);
      return [
        'available' => false,
        'error' => $e->getMessage(),
      ];
    }
  }

}