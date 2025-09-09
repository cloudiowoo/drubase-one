<?php

declare(strict_types=1);

namespace Drupal\baas_auth\Authentication;

use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\baas_auth\Service\ApiKeyManagerInterface;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Drupal\baas_auth\Authentication\BaasAuthenticatedUser;
use Symfony\Component\HttpFoundation\Request;

/**
 * API密钥认证提供者。
 */
class ApiKeyAuthenticator implements AuthenticationProviderInterface
{

  /**
   * API密钥管理服务。
   *
   * @var \Drupal\baas_auth\Service\ApiKeyManagerInterface
   */
  protected readonly ApiKeyManagerInterface $apiKeyManager;

  /**
   * 统一权限检查器。
   *
   * @var \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface
   */
  protected readonly UnifiedPermissionCheckerInterface $permissionChecker;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_auth\Service\ApiKeyManagerInterface $api_key_manager
   *   API密钥管理器。
   * @param \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface $permission_checker
   *   统一权限检查器。
   */
  public function __construct(ApiKeyManagerInterface $api_key_manager, UnifiedPermissionCheckerInterface $permission_checker)
  {
    $this->apiKeyManager = $api_key_manager;
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
    
    // 如果是公开端点，完全不应用此认证器
    if ($this->isPublicEndpoint($request)) {
      return FALSE;
    }
    
    // 检查是否存在 X-API-Key 头
    return $request->headers->has('X-API-Key');
  }

  /**
   * {@inheritdoc}
   */
  public function authenticate(Request $request)
  {
    $api_key = $request->headers->get('X-API-Key');

    if (!$api_key) {
      return NULL;
    }

    try {
      // 验证API密钥
      $key_data = $this->apiKeyManager->validateApiKey($api_key);

      if (!$key_data) {
        return NULL;
      }

      // 创建用户账户对象
      $payload = [
        'sub' => $key_data['user_id'],
        'tenant_id' => $key_data['tenant_id'],
        'username' => 'api_client',
        'permissions' => is_array($key_data['permissions']) ? $key_data['permissions'] : (json_decode($key_data['permissions'], TRUE) ?: []),
        'api_key_id' => $key_data['id'],
      ];

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
      // '/api/auth/verify',  // 已移除：现在需要API Key认证
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
