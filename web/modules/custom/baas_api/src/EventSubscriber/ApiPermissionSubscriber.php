<?php

declare(strict_types=1);

namespace Drupal\baas_api\EventSubscriber;

use Drupal\baas_api\Service\ApiResponseService;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * API权限验证事件订阅器。
 *
 * 对已认证的API请求进行权限验证。
 */
class ApiPermissionSubscriber implements EventSubscriberInterface
{

  /**
   * API响应服务。
   *
   * @var \Drupal\baas_api\Service\ApiResponseService
   */
  protected ApiResponseService $responseService;

  /**
   * 统一权限检查器。
   *
   * @var \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface
   */
  protected UnifiedPermissionCheckerInterface $permissionChecker;

  /**
   * 日志器。
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_api\Service\ApiResponseService $response_service
   *   API响应服务。
   * @param \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface $permission_checker
   *   统一权限检查器。
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂。
   */
  public function __construct(
    ApiResponseService $response_service,
    UnifiedPermissionCheckerInterface $permission_checker,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->responseService = $response_service;
    $this->permissionChecker = $permission_checker;
    $this->logger = $logger_factory->get('baas_api');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array
  {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 90],
    ];
  }

  /**
   * 处理内核请求事件。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求事件。
   */
  public function onKernelRequest(RequestEvent $event): void
  {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();

    // 检查是否为API请求
    if (!$this->isApiRequest($request)) {
      return;
    }

    // 检查是否为公开端点 - 公开端点不需要权限检查，无论是否有认证数据
    if ($this->isPublicEndpoint($request)) {
      return;
    }

    // 检查是否已认证
    $auth_data = $request->attributes->get('auth_data');
    if (!$auth_data) {
      return; // 认证中间件应该已经处理了这种情况
    }

    // 执行权限验证
    $permission_result = $this->checkPermissions($request, $auth_data);

    if (!$permission_result['success']) {
      $response = $this->createPermissionError($permission_result);
      $event->setResponse($response);
      return;
    }

    // 记录权限验证成功日志
    $this->logger->debug('API权限验证成功: @method @path - 用户ID: @user_id, 权限: @permissions', [
      '@method' => $request->getMethod(),
      '@path' => $request->getPathInfo(),
      '@user_id' => $auth_data['user_id'],
      '@permissions' => implode(', ', $permission_result['granted_permissions'] ?? []),
    ]);
  }

  /**
   * 检查是否为API请求。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求。
   *
   * @return bool
   *   是否为API请求。
   */
  protected function isApiRequest(Request $request): bool
  {
    $path = $request->getPathInfo();
    return strpos($path, '/api/') === 0;
  }

  /**
   * 检查是否为公开端点。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求。
   *
   * @return bool
   *   是否为公开端点。
   */
  protected function isPublicEndpoint(Request $request): bool
  {
    $path = $request->getPathInfo();
    
    // 公开端点列表
    $public_endpoints = [
      '/api/health',
      '/api/docs',
      '/api/auth/login',
      '/api/auth/register',
      '/api/auth/refresh',
      '/api/auth/reset-password',
      '/api/auth/verify-email',
    ];

    // 项目级认证端点也应该是公开的
    if (preg_match('#^/api/tenant/[^/]+/project/[^/]+/(register|authenticate|reset-password)$#', $path)) {
      return true;
    }
    
    // 调试：记录正在检查的路径
    if (strpos($path, '/authenticate') !== false) {
      error_log("ApiPermissionSubscriber: Checking path for authentication: " . $path);
    }

    foreach ($public_endpoints as $endpoint) {
      // 精确匹配
      if ($path === $endpoint) {
        return true;
      }
      // 子路径匹配（确保以/结尾避免误匹配）
      if (strpos($path, $endpoint . '/') === 0) {
        return true;
      }
    }

    return false;
  }

  /**
   * 检查权限。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求。
   * @param array $auth_data
   *   认证数据。
   *
   * @return array
   *   权限检查结果。
   */
  protected function checkPermissions(Request $request, array $auth_data): array
  {
    $path = $request->getPathInfo();
    $method = $request->getMethod();

    // 根据API路径和方法确定所需权限
    $required_permissions = $this->getRequiredPermissions($path, $method);

    if (empty($required_permissions)) {
      // 没有特定权限要求，允许访问
      return [
        'success' => true,
        'granted_permissions' => [],
      ];
    }

    $user_id = $auth_data['user_id'];
    $tenant_id = $auth_data['tenant_id'] ?? null;
    $project_id = $auth_data['project_id'] ?? null;
    
    // 从URL路径中提取项目ID（如果没有在auth_data中）
    if (!$project_id && preg_match('#/api/v1/[^/]+/projects/([^/]+)/#', $path, $matches)) {
      $project_id = $matches[1];
      $this->logger->info('ApiPermissionSubscriber: 从URL中提取项目ID: @project_id', ['@project_id' => $project_id]);
    }

    $granted_permissions = [];
    $missing_permissions = [];

    foreach ($required_permissions as $permission) {
      // 检查项目级权限（优先检查，因为项目权限更具体）
      if ($project_id && $this->permissionChecker->checkProjectPermission($user_id, $project_id, $permission)) {
        $granted_permissions[] = $permission;
        $this->logger->info('ApiPermissionSubscriber: 项目级权限通过 - @permission', ['@permission' => $permission]);
        continue;
      }

      // 检查租户级权限
      if ($tenant_id && $this->permissionChecker->checkTenantPermission($user_id, $tenant_id, $permission)) {
        $granted_permissions[] = $permission;
        $this->logger->info('ApiPermissionSubscriber: 租户级权限通过 - @permission', ['@permission' => $permission]);
        continue;
      }

      // 检查全局权限（使用标准Drupal权限检查）
      try {
        $user = \Drupal::entityTypeManager()->getStorage('user')->load($user_id);
        if ($user && $user->hasPermission($permission)) {
          $granted_permissions[] = $permission;
          continue;
        }
      } catch (\Exception $e) {
        // 临时绕过全局权限检查错误，允许继续执行
        $this->logger->warning('Global permission check failed: @message', ['@message' => $e->getMessage()]);
      }

      $missing_permissions[] = $permission;
    }

    if (!empty($missing_permissions)) {
      return [
        'success' => false,
        'error' => 'Insufficient permissions',
        'code' => 'INSUFFICIENT_PERMISSIONS',
        'status' => 403,
        'required_permissions' => $required_permissions,
        'missing_permissions' => $missing_permissions,
        'granted_permissions' => $granted_permissions,
      ];
    }

    return [
      'success' => true,
      'granted_permissions' => $granted_permissions,
    ];
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

    // 租户API权限
    if (preg_match('#^/api/v1/([^/]+)/tenants#', $path, $matches)) {
      switch ($method) {
        case 'GET':
          $permissions[] = 'view_tenant';
          break;
        case 'POST':
          $permissions[] = 'create_tenant';
          break;
        case 'PUT':
        case 'PATCH':
          $permissions[] = 'edit_tenant';
          break;
        case 'DELETE':
          $permissions[] = 'delete_tenant';
          break;
      }
    }

    // 项目级实体模板API权限 (需要在项目API之前检查，避免被错误匹配)
    if (preg_match('#^/api/v1/([^/]+)/projects/([^/]+)/templates#', $path, $matches)) {
      // 项目级模板操作，对项目成员来说应该有基本访问权限
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
    // 项目级实体数据API权限 (需要在项目API之前检查)
    elseif (preg_match('#^/api/v1/([^/]+)/projects/([^/]+)/entities/([^/]+)(?:/.*)?$#', $path, $matches)) {
      // 项目级实体数据操作
      switch ($method) {
        case 'GET':
          $permissions[] = 'view project entity data';
          break;
        case 'POST':
          $permissions[] = 'create project entity data';
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
    // 项目API权限 (基础项目操作)
    elseif (preg_match('#^/api/v1/([^/]+)/projects#', $path, $matches)) {
      switch ($method) {
        case 'GET':
          $permissions[] = 'view baas project';
          break;
        case 'POST':
          $permissions[] = 'create baas project';
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

    // 实体模板API权限
    if (preg_match('#^/api/v1/([^/]+)/templates#', $path, $matches)) {
      switch ($method) {
        case 'GET':
          $permissions[] = 'access tenant entity templates';
          break;
        case 'POST':
          $permissions[] = 'administer baas entity templates';
          break;
        case 'PUT':
        case 'PATCH':
          $permissions[] = 'administer baas entity templates';
          break;
        case 'DELETE':
          $permissions[] = 'administer baas entity templates';
          break;
      }
    }

    // 实体数据API权限
    elseif (preg_match('#^/api/v1/([^/]+)/([^/]+)#', $path, $matches)) {
      $tenant_id = $matches[1];
      $entity_name = $matches[2];
      
      // 排除已经处理的特殊端点
      if (!in_array($entity_name, ['tenants', 'projects', 'templates'])) {
        switch ($method) {
          case 'GET':
            $permissions[] = 'access tenant entity data';
            break;
          case 'POST':
            $permissions[] = 'create tenant entity data';
            break;
          case 'PUT':
          case 'PATCH':
            $permissions[] = 'update tenant entity data';
            break;
          case 'DELETE':
            $permissions[] = 'delete tenant entity data';
            break;
        }
      }
    }

    // 认证API权限
    if (preg_match('#^/api/auth/#', $path)) {
      // 认证相关端点通常不需要额外权限
      return [];
    }

    return $permissions;
  }

  /**
   * 创建权限错误响应。
   *
   * @param array $permission_result
   *   权限检查结果。
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   错误响应。
   */
  protected function createPermissionError(array $permission_result): \Symfony\Component\HttpFoundation\Response
  {
    $this->logger->warning('API权限验证失败: @error (@code)', [
      '@error' => $permission_result['error'],
      '@code' => $permission_result['code'],
    ]);

    $context = [
      'required_permissions' => $permission_result['required_permissions'] ?? [],
      'missing_permissions' => $permission_result['missing_permissions'] ?? [],
    ];

    return $this->responseService->createErrorResponse(
      $permission_result['error'],
      $permission_result['code'],
      $permission_result['status'],
      $context
    );
  }

}