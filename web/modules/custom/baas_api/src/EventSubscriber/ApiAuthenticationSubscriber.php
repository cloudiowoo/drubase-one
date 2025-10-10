<?php

declare(strict_types=1);

namespace Drupal\baas_api\EventSubscriber;

use Drupal\baas_api\Service\ApiResponseService;
use Drupal\baas_auth\Service\JwtTokenManagerInterface;
use Drupal\baas_auth\Service\JwtBlacklistServiceInterface;
use Drupal\baas_auth\Service\ApiKeyManagerInterface;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * 统一API认证事件订阅器。
 *
 * 处理所有API请求的认证和授权，支持JWT、API Key等多种认证方式。
 */
class ApiAuthenticationSubscriber implements EventSubscriberInterface
{

  /**
   * API响应服务。
   *
   * @var \Drupal\baas_api\Service\ApiResponseService
   */
  protected ApiResponseService $responseService;

  /**
   * JWT令牌管理器。
   *
   * @var \Drupal\baas_auth\Service\JwtTokenManagerInterface
   */
  protected JwtTokenManagerInterface $jwtTokenManager;

  /**
   * JWT黑名单服务。
   *
   * @var \Drupal\baas_auth\Service\JwtBlacklistServiceInterface
   */
  protected JwtBlacklistServiceInterface $jwtBlacklist;

  /**
   * API Key管理器。
   *
   * @var \Drupal\baas_auth\Service\ApiKeyManagerInterface
   */
  protected ApiKeyManagerInterface $apiKeyManager;

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
   * @param \Drupal\baas_auth\Service\JwtTokenManagerInterface $jwt_token_manager
   *   JWT令牌管理器。
   * @param \Drupal\baas_auth\Service\JwtBlacklistServiceInterface $jwt_blacklist
   *   JWT黑名单服务。
   * @param \Drupal\baas_auth\Service\ApiKeyManagerInterface $api_key_manager
   *   API Key管理器。
   * @param \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface $permission_checker
   *   统一权限检查器。
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂。
   */
  public function __construct(
    ApiResponseService $response_service,
    JwtTokenManagerInterface $jwt_token_manager,
    JwtBlacklistServiceInterface $jwt_blacklist,
    ApiKeyManagerInterface $api_key_manager,
    UnifiedPermissionCheckerInterface $permission_checker,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->responseService = $response_service;
    $this->jwtTokenManager = $jwt_token_manager;
    $this->jwtBlacklist = $jwt_blacklist;
    $this->apiKeyManager = $api_key_manager;
    $this->permissionChecker = $permission_checker;
    $this->logger = $logger_factory->get('baas_api');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array
  {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 300],
    ];
  }

  /**
   * 处理内核请求事件。
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
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

    // 检查是否为公开端点 - 公开端点不需要认证，直接跳过
    if ($this->isPublicEndpoint($request)) {
      $this->logger->debug('公开端点无需认证，跳过ApiAuthenticationSubscriber: @path', [
        '@path' => $request->getPathInfo(),
      ]);
      // 公开端点完全跳过此认证检查
      return;
    }

    // 执行认证
    $auth_result = $this->authenticate($request);

    if (!$auth_result['success']) {
      $response = $this->createAuthenticationError($auth_result);
      $event->setResponse($response);
      return;
    }

    // 设置认证数据到请求中
    $request->attributes->set('auth_data', $auth_result['data']);

    // 记录认证成功日志
    $this->logger->info('API认证成功: @method @path - 用户ID: @user_id, 租户ID: @tenant_id', [
      '@method' => $request->getMethod(),
      '@path' => $request->getPathInfo(),
      '@user_id' => $auth_result['data']['user_id'],
      '@tenant_id' => $auth_result['data']['tenant_id'] ?? 'N/A',
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
      '/api/auth/verify',
      '/api/auth/reset-password',
      '/api/auth/verify-email',
      '/api/v1/realtime/auth',
      '/api/v1/realtime/filter',
      '/api/v1/realtime/subscribe',
      '/api/v1/realtime/unsubscribe',
      '/api/v1/realtime/broadcast',
      '/api/v1/realtime/project',
    ];

    foreach ($public_endpoints as $endpoint) {
      // 精确匹配
      if ($path === $endpoint) {
        $this->logger->debug('匹配到公开端点: @path -> @endpoint', [
          '@path' => $path,
          '@endpoint' => $endpoint,
        ]);
        return true;
      }
      // 子路径匹配（确保以/结尾避免误匹配）
      if (strpos($path, $endpoint . '/') === 0) {
        $this->logger->debug('匹配到公开端点子路径: @path -> @endpoint', [
          '@path' => $path,
          '@endpoint' => $endpoint,
        ]);
        return true;
      }
    }

    $this->logger->debug('未匹配到公开端点: @path', ['@path' => $path]);
    return false;
  }

  /**
   * 执行认证。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求。
   *
   * @return array
   *   认证结果。
   */
  protected function authenticate(Request $request): array
  {
    // 尝试JWT认证
    $jwt_result = $this->authenticateJwt($request);
    if ($jwt_result['success']) {
      return $jwt_result;
    }

    // 尝试API Key认证
    $api_key_result = $this->authenticateApiKey($request);
    if ($api_key_result['success']) {
      return $api_key_result;
    }

    // 所有认证方式都失败
    return [
      'success' => false,
      'error' => 'Authentication required',
      'code' => 'AUTHENTICATION_REQUIRED',
      'status' => 401,
    ];
  }

  /**
   * JWT认证。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求。
   *
   * @return array
   *   认证结果。
   */
  protected function authenticateJwt(Request $request): array
  {
    $authorization = $request->headers->get('Authorization');

    if (!$authorization || strpos($authorization, 'Bearer ') !== 0) {
      return [
        'success' => false,
        'error' => 'Missing or invalid Bearer token',
        'code' => 'MISSING_BEARER_TOKEN',
        'status' => 401,
      ];
    }

    $token = substr($authorization, 7); // 移除 "Bearer " 前缀

    try {
      // 验证JWT令牌
      $payload = $this->jwtTokenManager->validateToken($token);

      if (!$payload) {
        return [
          'success' => false,
          'error' => 'Invalid JWT token',
          'code' => 'INVALID_JWT_TOKEN',
          'status' => 401,
        ];
      }

      // 检查令牌是否在黑名单中
      if ($this->jwtBlacklist->isBlacklisted($payload['jti'])) {
        return [
          'success' => false,
          'error' => 'JWT token has been revoked',
          'code' => 'JWT_TOKEN_REVOKED',
          'status' => 401,
        ];
      }

      // 检查令牌是否过期
      if (isset($payload['exp']) && $payload['exp'] < time()) {
        return [
          'success' => false,
          'error' => 'JWT token has expired',
          'code' => 'JWT_TOKEN_EXPIRED',
          'status' => 401,
        ];
      }

      // 构建认证数据
      $auth_data = [
        'type' => 'jwt',
        'user_id' => (int) $payload['sub'],
        'tenant_id' => $payload['tenant_id'] ?? null,
        'project_id' => $payload['project_id'] ?? null,
        'username' => $payload['username'] ?? 'unknown',
        'email' => $payload['email'] ?? null,
        'roles' => $payload['roles'] ?? [],
        'permissions' => $payload['permissions'] ?? [],
        'token_type' => $payload['type'] ?? 'access',
        'token_jti' => $payload['jti'],
        'token_exp' => $payload['exp'],
      ];

      return [
        'success' => true,
        'data' => $auth_data,
      ];

    } catch (\Exception $e) {
      $this->logger->error('JWT认证失败: @error', ['@error' => $e->getMessage()]);
      return [
        'success' => false,
        'error' => 'JWT authentication failed',
        'code' => 'JWT_AUTHENTICATION_FAILED',
        'status' => 401,
      ];
    }
  }

  /**
   * API Key认证。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求。
   *
   * @return array
   *   认证结果。
   */
  protected function authenticateApiKey(Request $request): array
  {
    // 从头部获取API Key
    $api_key = $request->headers->get('X-API-Key');

    // 也可以从查询参数获取
    if (!$api_key) {
      $api_key = $request->query->get('api_key');
    }

    if (!$api_key) {
      return [
        'success' => false,
        'error' => 'Missing API key',
        'code' => 'MISSING_API_KEY',
        'status' => 401,
      ];
    }

    try {
      // 验证API Key
      $api_key_info = $this->apiKeyManager->validateApiKey($api_key);

      if (!$api_key_info) {
        return [
          'success' => false,
          'error' => 'Invalid API key',
          'code' => 'INVALID_API_KEY',
          'status' => 401,
        ];
      }

      // 检查API Key是否已过期
      if (isset($api_key_info['expires_at']) && $api_key_info['expires_at'] < time()) {
        return [
          'success' => false,
          'error' => 'API key has expired',
          'code' => 'API_KEY_EXPIRED',
          'status' => 401,
        ];
      }

      // 检查API Key是否被禁用
      if (empty($api_key_info['status']) || $api_key_info['status'] != 1) {
        return [
          'success' => false,
          'error' => 'API key is disabled',
          'code' => 'API_KEY_DISABLED',
          'status' => 401,
        ];
      }

      // 构建认证数据
      $auth_data = [
        'type' => 'api_key',
        'user_id' => (int) $api_key_info['user_id'],
        'tenant_id' => $api_key_info['tenant_id'] ?? null,
        'project_id' => $api_key_info['project_id'] ?? null,
        'api_key_id' => $api_key_info['id'],
        'api_key_name' => $api_key_info['name'],
        'permissions' => $api_key_info['permissions'] ?? [],
        'scopes' => $api_key_info['scopes'] ?? [],
      ];

      return [
        'success' => true,
        'data' => $auth_data,
      ];

    } catch (\Exception $e) {
      $this->logger->error('API Key认证失败: @error', ['@error' => $e->getMessage()]);
      return [
        'success' => false,
        'error' => 'API key authentication failed',
        'code' => 'API_KEY_AUTHENTICATION_FAILED',
        'status' => 401,
      ];
    }
  }

  /**
   * 创建认证错误响应。
   *
   * @param array $auth_result
   *   认证结果。
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   错误响应。
   */
  protected function createAuthenticationError(array $auth_result): \Symfony\Component\HttpFoundation\Response
  {
    $this->logger->warning('API认证失败: @error (@code)', [
      '@error' => $auth_result['error'],
      '@code' => $auth_result['code'],
    ]);

    return $this->responseService->createErrorResponse(
      $auth_result['error'],
      $auth_result['code'],
      $auth_result['status']
    );
  }

}