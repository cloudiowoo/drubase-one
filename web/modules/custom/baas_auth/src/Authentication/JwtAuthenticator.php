<?php

declare(strict_types=1);

namespace Drupal\baas_auth\Authentication;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\baas_auth\Service\JwtTokenManagerInterface;
use Drupal\baas_auth\Service\JwtBlacklistServiceInterface;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * JWT认证提供者。
 */
class JwtAuthenticator implements AuthenticationProviderInterface
{

  /**
   * JWT令牌管理服务。
   *
   * @var \Drupal\baas_auth\Service\JwtTokenManagerInterface
   */
  protected readonly JwtTokenManagerInterface $jwtTokenManager;

  /**
   * JWT黑名单服务。
   *
   * @var \Drupal\baas_auth\Service\JwtBlacklistServiceInterface
   */
  protected readonly JwtBlacklistServiceInterface $jwtBlacklist;

  /**
   * 统一权限检查器。
   *
   * @var \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface
   */
  protected readonly UnifiedPermissionCheckerInterface $permissionChecker;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_auth\Service\JwtTokenManagerInterface $jwt_token_manager
   *   JWT令牌管理器。
   * @param \Drupal\baas_auth\Service\JwtBlacklistServiceInterface $jwt_blacklist
   *   JWT黑名单服务。
   * @param \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface $permission_checker
   *   统一权限检查器。
   */
  public function __construct(
    JwtTokenManagerInterface $jwt_token_manager,
    JwtBlacklistServiceInterface $jwt_blacklist,
    UnifiedPermissionCheckerInterface $permission_checker
  ) {
    $this->jwtTokenManager = $jwt_token_manager;
    $this->jwtBlacklist = $jwt_blacklist;
    $this->permissionChecker = $permission_checker;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(Request $request)
  {
    $path = $request->getPathInfo();
    
    // API相关路径列表
    $api_paths = [
      '/api/',
      '/openapi.json',
      '/swagger-ui',
    ];
    
    // 检查是否为API相关路径
    $is_api_path = FALSE;
    foreach ($api_paths as $api_path) {
      if (strpos($path, $api_path) === 0 || $path === rtrim($api_path, '/')) {
        $is_api_path = TRUE;
        break;
      }
    }
    
    if (!$is_api_path) {
      return FALSE;
    }
    
    // 如果是公开端点，则不应用认证
    if ($this->isPublicEndpoint($request)) {
      return FALSE;
    }
    
    // 检查是否存在Bearer token
    $authorization = $request->headers->get('Authorization');
    return $authorization && strpos($authorization, 'Bearer ') === 0;
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(Request $request)
  {
    $authorization = $request->headers->get('Authorization');

    if (!$authorization || strpos($authorization, 'Bearer ') !== 0) {
      return NULL;
    }

    $token = substr($authorization, 7); // 移除 "Bearer " 前缀

    try {
      // 验证JWT令牌
      $payload = $this->jwtTokenManager->validateToken($token);

      if (!$payload) {
        return NULL;
      }

      // 检查令牌是否在黑名单中
      if ($this->jwtBlacklist->isBlacklisted($payload['jti'])) {
        return NULL;
      }

      // 创建用户账户对象
      $account = new BaasAuthenticatedUser($payload, $this->permissionChecker);

      return $account;
    } catch (\Exception $e) {
      return NULL;
    }
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
      // '/api/auth/login',  // 已移除：现在需要API Key认证
      '/api/auth/register',
      // '/api/auth/refresh',  // 已移除：现在需要API Key认证
      '/api/auth/reset-password',
      '/api/auth/verify-email',
    ];

    foreach ($public_endpoints as $endpoint) {
      // 精确匹配
      if ($path === $endpoint) {
        return TRUE;
      }
      // 子路径匹配（确保以/结尾避免误匹配）
      if (strpos($path, $endpoint . '/') === 0) {
        return TRUE;
      }
    }

    return FALSE;
  }
}
