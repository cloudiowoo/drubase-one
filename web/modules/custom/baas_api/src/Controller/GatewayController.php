<?php

declare(strict_types=1);

namespace Drupal\baas_api\Controller;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API网关控制器.
 *
 * 负责路由分发、统一认证、监控和限流等网关功能。
 */
class GatewayController extends BaseApiController
{

  /**
   * 路由映射配置.
   *
   * @var array
   */
  protected array $routeMap = [
    // 项目相关API -> baas_project模块
    'projects' => [
      'module' => 'baas_project',
      'controller' => 'ProjectController',
      'patterns' => [
        'GET:projects' => 'listProjects',
        'POST:projects' => 'createProject',
        'GET:projects/{id}' => 'getProject',
        'PUT:projects/{id}' => 'updateProject',
        'PATCH:projects/{id}' => 'updateProject',
        'POST:projects/{id}' => 'updateProject',
        'DELETE:projects/{id}' => 'deleteProject',
        'GET:projects/{id}/members' => 'getProjectMembers',
        'POST:projects/{id}/members' => 'addProjectMember',
        'PUT:projects/{id}/members/{user_id}' => 'updateProjectMember',
        'DELETE:projects/{id}/members/{user_id}' => 'removeProjectMember',
        'POST:projects/{id}/transfer-ownership' => 'transferOwnership',
        'GET:projects/{id}/usage' => 'getProjectUsage',
      ],
    ],

    // 项目实体相关API -> baas_project模块
    'project_entities' => [
      'module' => 'baas_project',
      'controller' => 'ProjectEntityApiController',
      'patterns' => [
        // 直接项目路径
        'GET:projects/{project_id}/templates' => 'listTemplates',
        'GET:projects/{project_id}/templates/{template_name}' => 'getTemplate',
        'GET:projects/{project_id}/entities/{entity_name}' => 'listEntities',
        'GET:projects/{project_id}/entities/{entity_name}/{id}' => 'getEntity',
        'POST:projects/{project_id}/entities/{entity_name}' => 'createEntity',
        'PUT:projects/{project_id}/entities/{entity_name}/{id}' => 'updateEntity',
        'PATCH:projects/{project_id}/entities/{entity_name}/{id}' => 'updateEntity',
        'POST:projects/{project_id}/entities/{entity_name}/{id}' => 'updateEntity',
        'DELETE:projects/{project_id}/entities/{entity_name}/{id}' => 'deleteEntity',
        // 租户级项目路径
        'GET:{tenant_id}/projects/{project_id}/templates' => 'listTemplates',
        'GET:{tenant_id}/projects/{project_id}/templates/{template_name}' => 'getTemplate',
        'GET:{tenant_id}/projects/{project_id}/entities/{entity_name}' => 'listEntities',
        'GET:{tenant_id}/projects/{project_id}/entities/{entity_name}/{id}' => 'getEntity',
        'POST:{tenant_id}/projects/{project_id}/entities/{entity_name}' => 'createEntity',
        'PUT:{tenant_id}/projects/{project_id}/entities/{entity_name}/{id}' => 'updateEntity',
        'PATCH:{tenant_id}/projects/{project_id}/entities/{entity_name}/{id}' => 'updateEntity',
        'POST:{tenant_id}/projects/{project_id}/entities/{entity_name}/{id}' => 'updateEntity',
        'DELETE:{tenant_id}/projects/{project_id}/entities/{entity_name}/{id}' => 'deleteEntity',
      ],
    ],

    // 注意：全局实体和模板API已移除
    // 所有实体操作现在都通过项目级API进行：
    // /{tenant_id}/projects/{project_id}/entities/{entity_name}
    // /{tenant_id}/projects/{project_id}/templates

    // 租户相关API -> baas_tenant模块
    'tenants' => [
      'module' => 'baas_tenant',
      'controller' => 'TenantApiController',
      'patterns' => [
        'GET:tenants' => 'listTenants',
        'POST:tenants' => 'createTenant',
        'GET:tenants/{id}' => 'getTenant',
        'PUT:tenants/{id}' => 'updateTenant',
        'DELETE:tenants/{id}' => 'deleteTenant',
      ],
    ],

    // 认证相关API -> baas_auth模块
    'auth' => [
      'module' => 'baas_auth',
      'controller' => 'AuthApiController',
      'patterns' => [
        'POST:auth/login' => 'login',
        'POST:auth/refresh' => 'refresh',
        'POST:auth/logout' => 'logout',
        'POST:auth/verify' => 'verify',
        'GET:auth/me' => 'me',
        'GET:auth/permissions' => 'permissions',
        'POST:auth/change-password' => 'changePassword',
        'GET:auth/roles' => 'roles',
        'POST:auth/assign-role' => 'assignRole',
        'POST:auth/revoke-role' => 'revokeRole',
        'POST:auth/switch-tenant' => 'switchTenant',
        'GET:auth/current-user' => 'getCurrentUser',
        'GET:auth/user-tenants' => 'getUserTenants',
      ],
    ],

    // 实时功能相关API -> baas_realtime模块
    'realtime' => [
      'module' => 'baas_realtime',
      'controller' => 'WebSocketController',
      'patterns' => [
        // 租户级实时API
        'POST:{tenant_id}/realtime/auth' => 'authenticateConnection',
        'POST:{tenant_id}/realtime/filter' => 'filterMessage',
        'POST:{tenant_id}/realtime/subscribe' => 'subscribe',
        'POST:{tenant_id}/realtime/unsubscribe' => 'unsubscribe',
        'POST:{tenant_id}/realtime/broadcast' => 'broadcast',
        // 项目级实时API
        'POST:{tenant_id}/projects/{project_id}/realtime/auth' => 'authenticateConnection',
        'POST:{tenant_id}/projects/{project_id}/realtime/filter' => 'filterMessage',
        'POST:{tenant_id}/projects/{project_id}/realtime/subscribe' => 'subscribe',
        'POST:{tenant_id}/projects/{project_id}/realtime/unsubscribe' => 'unsubscribe',
        'POST:{tenant_id}/projects/{project_id}/realtime/broadcast' => 'broadcast',
      ],
    ],
  ];

  /**
   * 权限检查器。
   */
  protected UnifiedPermissionCheckerInterface $permissionChecker;

  /**
   * 构造函数.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   日志工厂.
   * @param \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface $permissionChecker
   *   统一权限检查器.
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerFactory,
    UnifiedPermissionCheckerInterface $permissionChecker
  ) {
    $this->logger = $loggerFactory->get('baas_api_gateway');
    $this->permissionChecker = $permissionChecker;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('logger.factory'),
      $container->get('baas_auth.unified_permission_checker')
    );
  }

  /**
   * 处理API网关路由分发.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象.
   * @param string $path
   *   请求路径.
   * @param string $tenant_id
   *   租户ID（用于租户级路由）.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   API响应.
   */
  public function route(Request $request, string $path = '', string $tenant_id = ''): JsonResponse
  {
    $start_time = microtime(true);
    $method = $request->getMethod();
    
    // 专门处理multipart/form-data请求
    $content_type = $request->headers->get('content-type', '');
    if (strpos($content_type, 'multipart/form-data') !== false) {
      $this->logger->info('Gateway检测到multipart请求，启用特殊处理: tenant_id=@tenant_id, path=@path', [
        '@tenant_id' => $tenant_id,
        '@path' => $path,
      ]);
    }
    
    // 调试：记录原始请求参数
    $this->logger->info('GatewayController: 原始请求参数 - tenant_id=@tenant_id, path=@path, method=@method', [
      '@tenant_id' => $tenant_id,
      '@path' => $path,
      '@method' => $method,
    ]);
    
    // 如果有tenant_id参数，说明是租户级路由，需要重构path
    if ($tenant_id && $path) {
      $path = $tenant_id . '/' . $path;
    }
    
    $full_path = $method . ':' . $path;
    
    // 调试：记录重构后的路径
    $this->logger->info('GatewayController: 重构后路径 - full_path=@full_path', [
      '@full_path' => $full_path,
    ]);

    // 记录请求开始
    $this->logger->info('API Gateway接收请求: @path (URI: @uri, 租户: @tenant, Content-Type: @content_type)', [
      '@path' => $full_path,
      '@uri' => $request->getRequestUri(),
      '@tenant' => $tenant_id ?: 'none',
      '@content_type' => $request->headers->get('content-type', 'unknown'),
    ]);

    try {
      // 统一认证检查
      $auth_result = $this->checkAuthentication($request);
      
      // 特殊处理：记录multipart请求的详细信息
      if (strpos($request->headers->get('content-type', ''), 'multipart/form-data') !== false) {
        $this->logger->info('Gateway处理multipart请求: 认证结果=@auth, 文件数量=@files_count', [
          '@auth' => $auth_result['valid'] ? 'valid' : 'invalid',
          '@files_count' => count($request->files->all()),
        ]);
      }
      
      if (!$auth_result['valid']) {
        $response = $this->createErrorResponse(
          $auth_result['error'],
          $auth_result['code'],
          401
        );
        $this->recordApiCall($full_path, $response->getStatusCode(), microtime(true) - $start_time);
        return $response;
      }

      // API限流检查
      $rate_limit_result = $this->checkRateLimit($request, $auth_result['user_id'] ?? null);
      if (!$rate_limit_result['valid']) {
        $response = $this->createErrorResponse(
          $rate_limit_result['error'],
          $rate_limit_result['code'],
          429
        );
        $this->recordApiCall($full_path, $response->getStatusCode(), microtime(true) - $start_time);
        return $response;
      }

      // 路由解析和分发
      $route_result = $this->resolveRoute($path, $method);
      $this->logger->info('Gateway路由解析结果: @result', [
        '@result' => json_encode($route_result),
      ]);
      
      if (!$route_result['valid']) {
        $this->logger->warning('Gateway路由解析失败: path=@path, method=@method', [
          '@path' => $path,
          '@method' => $method,
        ]);
        
        $response = $this->createErrorResponse(
          $route_result['error'],
          $route_result['code'],
          404
        );
        $this->recordApiCall($full_path, $response->getStatusCode(), microtime(true) - $start_time);
        return $response;
      }

      // 权限检查
      if (!$auth_result['public'] ?? false) {
        $permission_result = $this->checkPermissions($request, $path, $method, $auth_result);
        if (!$permission_result['valid']) {
          $response = $this->createErrorResponse(
            $permission_result['error'],
            $permission_result['code'],
            403,
            $permission_result['context'] ?? []
          );
          $this->recordApiCall($full_path, $response->getStatusCode(), microtime(true) - $start_time);
          return $response;
        }
      }

      // 分发到目标模块
      $response = $this->dispatchToModule($request, $route_result);
      $this->recordApiCall($full_path, $response->getStatusCode(), microtime(true) - $start_time);
      
      return $response;

    } catch (\Exception $e) {
      $this->logger->error('API Gateway错误: @error', [
        '@error' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);

      $response = $this->createErrorResponse(
        'Internal gateway error',
        'GATEWAY_ERROR',
        500,
        ['detail' => $e->getMessage()]
      );
      
      $this->recordApiCall($full_path, $response->getStatusCode(), microtime(true) - $start_time);
      return $response;
    }
  }

  /**
   * 检查用户认证.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象.
   *
   * @return array
   *   认证结果.
   */
  protected function checkAuthentication(Request $request): array
  {
    // 检查是否为公开端点
    $public_endpoints = [
      '/api/v1/auth/login',
      '/api/v1/auth/register',
      '/api/v1/health',
      '/api/v1/docs',
      '/api/health',  // 直接健康检查端点
      '/api/docs',    // 直接文档端点
    ];

    $request_uri = $request->getRequestUri();
    foreach ($public_endpoints as $public_endpoint) {
      if (strpos($request_uri, $public_endpoint) !== false) {
        return ['valid' => true, 'public' => true];
      }
    }

    // API Key认证 (优先检查，因为用户明确提供了API Key)
    $api_key = $request->headers->get('X-API-Key');
    if ($api_key) {
      $this->logger->info('Gateway: Found API Key header, attempting validation');
      $result = $this->validateApiKey($api_key);
      $this->logger->info('Gateway: API Key validation result: @result', ['@result' => $result['valid'] ? 'SUCCESS' : 'FAILED - ' . ($result['error'] ?? 'unknown')]);
      return $result;
    }

    // JWT Token认证
    $auth_header = $request->headers->get('Authorization');
    if ($auth_header && strpos($auth_header, 'Bearer ') === 0) {
      $token = substr($auth_header, 7);
      $this->logger->info('Gateway: Found JWT Bearer token, attempting validation');
      return $this->validateJwtToken($token);
    }

    // Session认证（用于Web界面）
    $current_user = $this->currentUser();
    if ($current_user->isAuthenticated()) {
      return [
        'valid' => true,
        'user_id' => $current_user->id(),
        'auth_type' => 'session',
      ];
    }

    $this->logger->warning('Gateway: No valid authentication found in request');
    return [
      'valid' => false,
      'error' => 'Authentication required',
      'code' => 'AUTHENTICATION_REQUIRED',
    ];
  }

  /**
   * 验证JWT令牌.
   *
   * @param string $token
   *   JWT令牌.
   *
   * @return array
   *   验证结果.
   */
  protected function validateJwtToken(string $token): array
  {
    try {
      $auth_service = \Drupal::service('baas_auth.authentication_service');
      $token_data = $auth_service->verifyToken($token);
      
      if ($token_data) {
        return [
          'valid' => true,
          'user_id' => $token_data['sub'],
          'tenant_id' => $token_data['tenant_id'] ?? null,
          'auth_type' => 'jwt',
        ];
      }

      return [
        'valid' => false,
        'error' => 'Invalid or expired token',
        'code' => 'INVALID_TOKEN',
      ];
    } catch (\Exception $e) {
      return [
        'valid' => false,
        'error' => 'Token validation failed',
        'code' => 'TOKEN_VALIDATION_ERROR',
      ];
    }
  }

  /**
   * 验证API密钥.
   *
   * @param string $api_key
   *   API密钥.
   *
   * @return array
   *   验证结果.
   */
  protected function validateApiKey(string $api_key): array
  {
    try {
      $api_service = \Drupal::service('baas_auth.api_key_manager');
      $key_data = $api_service->validateApiKey($api_key);
      
      if ($key_data) {
        return [
          'valid' => true,
          'user_id' => (int) $key_data['user_id'],
          'tenant_id' => $key_data['tenant_id'],
          'auth_type' => 'api_key',
        ];
      }

      $this->logger->warning('API Key validation failed: key not found or invalid');
      return [
        'valid' => false,
        'error' => 'Invalid API key',
        'code' => 'INVALID_API_KEY',
      ];
    } catch (\Exception $e) {
      $this->logger->error('API Key validation exception: @message', ['@message' => $e->getMessage()]);
      return [
        'valid' => false,
        'error' => 'API key validation failed: ' . $e->getMessage(),
        'code' => 'API_KEY_VALIDATION_ERROR',
      ];
    }
  }

  /**
   * 检查API限流.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象.
   * @param int|null $user_id
   *   用户ID.
   *
   * @return array
   *   限流检查结果.
   */
  protected function checkRateLimit(Request $request, ?int $user_id = null): array
  {
    try {
      $rate_limiter = \Drupal::service('baas_api.rate_limiter');
      
      // 基于用户ID或IP的限流检查
      $identifier = $user_id ? "user:$user_id" : "ip:" . $request->getClientIp();
      
      // 使用checkRateLimit方法而不是isAllowed，避免双重消耗令牌
      $result = $rate_limiter->checkRateLimit($identifier, $request->getPathInfo());
      
      if (!$result['allowed']) {
        $this->logger->info('Gateway rate limit exceeded for identifier: @identifier', [
          '@identifier' => $identifier,
        ]);
        return [
          'valid' => false,
          'error' => 'Rate limit exceeded',
          'code' => 'RATE_LIMIT_EXCEEDED',
        ];
      }

      $this->logger->info('Gateway rate limit check passed for identifier: @identifier', [
        '@identifier' => $identifier,
      ]);

      return ['valid' => true];
    } catch (\Exception $e) {
      // 如果限流服务不可用，允许请求继续
      $this->logger->warning('Rate limiter error: @error', ['@error' => $e->getMessage()]);
      return ['valid' => true];
    }
  }

  /**
   * 解析API路由.
   *
   * @param string $path
   *   请求路径.
   * @param string $method
   *   HTTP方法.
   *
   * @return array
   *   路由解析结果.
   */
  protected function resolveRoute(string $path, string $method): array
  {
    $path_parts = array_filter(explode('/', trim($path, '/')));
    
    if (empty($path_parts)) {
      return [
        'valid' => false,
        'error' => 'Invalid API path',
        'code' => 'INVALID_PATH',
      ];
    }

    // 尝试匹配路由模式
    foreach ($this->routeMap as $prefix => $config) {
      foreach ($config['patterns'] as $pattern => $action) {
        $match_result = $this->matchRoutePattern($method . ':' . $path, $pattern);
        if ($match_result['matched']) {
          return [
            'valid' => true,
            'module' => $config['module'],
            'controller' => $config['controller'],
            'action' => $action,
            'parameters' => $match_result['parameters'],
          ];
        }
      }
    }

    return [
      'valid' => false,
      'error' => 'Route not found',
      'code' => 'ROUTE_NOT_FOUND',
    ];
  }

  /**
   * 匹配路由模式.
   *
   * @param string $request_path
   *   请求路径.
   * @param string $pattern
   *   路由模式.
   *
   * @return array
   *   匹配结果.
   */
  protected function matchRoutePattern(string $request_path, string $pattern): array
  {
    // 记录匹配尝试
    $this->logger->info('GatewayController: 尝试匹配路由模式 - 请求路径: @request_path, 模式: @pattern', [
      '@request_path' => $request_path,
      '@pattern' => $pattern,
    ]);
    
    // 将模式转换为正则表达式
    $pattern_regex = preg_replace('/\{([^}]+)\}/', '([^/]+)', $pattern);
    $pattern_regex = '/^' . str_replace('/', '\/', $pattern_regex) . '$/';

    if (preg_match($pattern_regex, $request_path, $matches)) {
      // 提取参数名
      preg_match_all('/\{([^}]+)\}/', $pattern, $param_names);
      
      $parameters = [];
      for ($i = 1; $i < count($matches); $i++) {
        $param_name = $param_names[1][$i - 1] ?? "param$i";
        $parameters[$param_name] = $matches[$i];
      }

      $this->logger->info('GatewayController: 路由匹配成功 - 参数: @parameters', [
        '@parameters' => json_encode($parameters),
      ]);

      return [
        'matched' => true,
        'parameters' => $parameters,
      ];
    }

    $this->logger->debug('GatewayController: 路由匹配失败 - 正则: @regex', [
      '@regex' => $pattern_regex,
    ]);
    
    return ['matched' => false];
  }

  /**
   * 分发请求到目标模块.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象.
   * @param array $route_info
   *   路由信息.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   模块响应.
   */
  protected function dispatchToModule(Request $request, array $route_info): JsonResponse
  {
    try {
      $module = $route_info['module'];
      $controller_class = "\\Drupal\\{$module}\\Controller\\{$route_info['controller']}";
      $action = $route_info['action'];

      // 检查控制器类是否存在
      if (!class_exists($controller_class)) {
        return $this->createErrorResponse(
          'Controller not found',
          'CONTROLLER_NOT_FOUND',
          500
        );
      }

      // 创建控制器实例
      $container = \Drupal::getContainer();
      $controller = $controller_class::create($container);

      // 检查方法是否存在
      if (!method_exists($controller, $action)) {
        return $this->createErrorResponse(
          'Action not found',
          'ACTION_NOT_FOUND',
          500
        );
      }

      // 构建方法参数
      $parameters = $this->buildMethodParameters($request, $route_info['parameters']);

      // 调用目标方法
      $response = call_user_func_array([$controller, $action], $parameters);

      // 确保返回JsonResponse
      if (!$response instanceof JsonResponse) {
        return $this->createErrorResponse(
          'Invalid response type from module',
          'INVALID_RESPONSE_TYPE',
          500
        );
      }

      return $response;

    } catch (\Exception $e) {
      $this->logger->error('Module dispatch error: @error', [
        '@error' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);

      return $this->createErrorResponse(
        'Module execution failed',
        'MODULE_EXECUTION_ERROR',
        500,
        ['detail' => $e->getMessage()]
      );
    }
  }

  /**
   * 构建方法参数.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象.
   * @param array $route_parameters
   *   路由参数.
   *
   * @return array
   *   方法参数数组.
   */
  protected function buildMethodParameters(Request $request, array $route_parameters): array
  {
    $parameters = [$request];

    // 添加路由参数
    foreach ($route_parameters as $value) {
      $parameters[] = $value;
    }

    return $parameters;
  }

  /**
   * 记录API调用统计.
   *
   * @param string $endpoint
   *   API端点.
   * @param int $status_code
   *   状态码.
   * @param float $response_time
   *   响应时间.
   */
  protected function recordApiCall(string $endpoint, int $status_code, float $response_time): void
  {
    try {
      $stats_service = \Drupal::service('baas_api.stats');
      $stats_service->recordCall($endpoint, $status_code, $response_time * 1000);
    } catch (\Exception $e) {
      // 记录失败不应影响API响应
      $this->logger->warning('Stats recording failed: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * 检查API权限。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   * @param string $path
   *   请求路径。
   * @param string $method
   *   HTTP方法。
   * @param array $auth_data
   *   认证数据。
   *
   * @return array
   *   权限检查结果。
   */
  protected function checkPermissions(Request $request, string $path, string $method, array $auth_data): array
  {
    $full_path = '/api/v1/' . $path;
    
    // 调试日志
    $this->logger->info('GatewayController权限检查 - Path: @path, Auth data: @auth', [
      '@path' => $full_path,
      '@auth' => json_encode($auth_data),
    ]);
    
    // 根据API路径和方法确定所需权限
    $required_permissions = $this->getRequiredPermissions($full_path, $method);
    
    if (empty($required_permissions)) {
      // 没有特定权限要求，允许访问
      return ['valid' => true];
    }
    
    $user_id = (int) $auth_data['user_id'];
    $tenant_id = $auth_data['tenant_id'] ?? null;
    $project_id = null;
    
    // 从路径中提取项目ID
    if (preg_match('#projects/([^/]+)#', $path, $matches)) {
      $project_id = $matches[1];
    }
    
    $granted_permissions = [];
    $missing_permissions = [];
    
    foreach ($required_permissions as $permission) {
      $has_permission = false;
      
      // 调试日志
      $this->logger->info('检查权限: @permission, 用户: @user, 项目: @project, 租户: @tenant', [
        '@permission' => $permission,
        '@user' => $user_id,
        '@project' => $project_id,
        '@tenant' => $tenant_id,
      ]);
      
      // 检查项目级权限
      if ($project_id && $this->permissionChecker->checkProjectPermission($user_id, $project_id, $permission)) {
        $has_permission = true;
        $this->logger->info('项目级权限检查通过: @permission', ['@permission' => $permission]);
      }
      // 检查租户级权限
      elseif ($tenant_id && $this->permissionChecker->checkTenantPermission($user_id, $tenant_id, $permission)) {
        $has_permission = true;
        $this->logger->info('租户级权限检查通过: @permission', ['@permission' => $permission]);
      }
      // 检查全局权限
      else {
        try {
          $user = \Drupal::entityTypeManager()->getStorage('user')->load($user_id);
          if ($user && $user->hasPermission($permission)) {
            $has_permission = true;
            $this->logger->info('全局权限检查通过: @permission', ['@permission' => $permission]);
          }
        } catch (\Exception $e) {
          $this->logger->error('权限检查失败: @error', ['@error' => $e->getMessage()]);
        }
      }
      
      if (!$has_permission) {
        $this->logger->warning('权限检查失败: @permission', ['@permission' => $permission]);
      }
      
      if ($has_permission) {
        $granted_permissions[] = $permission;
      } else {
        $missing_permissions[] = $permission;
      }
    }
    
    if (!empty($missing_permissions)) {
      return [
        'valid' => false,
        'error' => 'Insufficient permissions',
        'code' => 'INSUFFICIENT_PERMISSIONS',
        'context' => [
          'required_permissions' => $required_permissions,
          'missing_permissions' => $missing_permissions,
        ],
      ];
    }
    
    return ['valid' => true];
  }

  /**
   * 根据API路径和方法获取所需权限。
   *
   * @param string $path
   *   API路径。
   * @param string $method
   *   HTTP方法。
   *
   * @return array
   *   所需权限列表。
   */
  protected function getRequiredPermissions(string $path, string $method): array
  {
    $permissions = [];
    
    // 项目级实体模板API权限
    if (preg_match('#^/api/v1/([^/]+)/projects/([^/]+)/templates#', $path, $matches)) {
      switch ($method) {
        case 'GET':
          $permissions[] = 'view project entity templates';
          break;
        case 'POST':
          $permissions[] = 'create project entity templates';
          break;
        case 'PUT':
        case 'PATCH':
          $permissions[] = 'edit project entity templates';
          break;
        case 'DELETE':
          $permissions[] = 'delete project entity templates';
          break;
      }
    }
    // 项目级实体数据API权限
    elseif (preg_match('#^/api/v1/([^/]+)/projects/([^/]+)/entities/([^/]+)#', $path, $matches)) {
      switch ($method) {
        case 'GET':
          $permissions[] = 'view project entity data';
          break;
        case 'POST':
          // 根据路径判断是创建还是更新
          if (preg_match('#/entities/[^/]+/[^/]+$#', $path)) {
            // 带ID的路径是更新操作
            $permissions[] = 'edit project entity data';
          } else {
            // 不带ID的路径是创建操作
            $permissions[] = 'create project entity data';
          }
          break;
        case 'PUT':
        case 'PATCH':
          $permissions[] = 'edit project entity data';
          break;
        case 'DELETE':
          $permissions[] = 'delete project entity data';
          break;
      }
    }
    // 项目API权限
    elseif (preg_match('#^/api/v1/([^/]+)/projects#', $path, $matches)) {
      switch ($method) {
        case 'GET':
          $permissions[] = 'view baas project';
          break;
        case 'POST':
          // 根据路径判断是创建还是更新项目
          if (preg_match('#/projects/[^/]+$#', $path)) {
            // 带项目ID的路径是更新操作
            $permissions[] = 'edit baas project';
          } else {
            // 不带项目ID的路径是创建操作
            $permissions[] = 'create baas project';
          }
          break;
        case 'PUT':
        case 'PATCH':
          $permissions[] = 'edit baas project';
          break;
        case 'DELETE':
          $permissions[] = 'delete baas project';
          break;
      }
    }
    // 租户级实时API权限
    elseif (preg_match('#^/api/v1/([^/]+)/realtime#', $path, $matches)) {
      switch ($method) {
        case 'POST':
          if (strpos($path, '/auth') !== false) {
            $permissions[] = 'access realtime';
          } elseif (strpos($path, '/subscribe') !== false || strpos($path, '/unsubscribe') !== false) {
            $permissions[] = 'access realtime';
          } elseif (strpos($path, '/broadcast') !== false) {
            $permissions[] = 'broadcast realtime';
          } else {
            $permissions[] = 'access realtime';
          }
          break;
      }
    }
    // 项目级实时API权限
    elseif (preg_match('#^/api/v1/([^/]+)/projects/([^/]+)/realtime#', $path, $matches)) {
      switch ($method) {
        case 'POST':
          if (strpos($path, '/auth') !== false) {
            $permissions[] = 'access realtime';
          } elseif (strpos($path, '/subscribe') !== false || strpos($path, '/unsubscribe') !== false) {
            $permissions[] = 'access realtime';
          } elseif (strpos($path, '/broadcast') !== false) {
            $permissions[] = 'broadcast realtime';
          } else {
            $permissions[] = 'access realtime';
          }
          break;
      }
    }
    
    return $permissions;
  }

}