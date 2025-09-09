<?php

declare(strict_types=1);

namespace Drupal\baas_api\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * 公开端点访问检查器。
 *
 * 此访问检查器确保某些API端点（如/api/health）可以被公开访问，
 * 即使系统中注册了身份验证提供者。
 */
class PublicEndpointAccessChecker implements AccessInterface {

  /**
   * 公开端点列表。
   *
   * @var array
   */
  protected array $publicEndpoints = [
    '/api/health',
    '/api/docs',
    '/api/auth/login',
    '/api/auth/register',
    '/api/auth/refresh',
    '/api/auth/reset-password',
    '/api/auth/verify-email',
  ];

  /**
   * 检查公开端点访问权限。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   * @param \Symfony\Component\Routing\Route $route
   *   路由对象。
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果。
   */
  public function access(AccountInterface $account, Route $route): AccessResultInterface {
    $path = $route->getPath();
    
    // 检查是否为公开端点
    foreach ($this->publicEndpoints as $endpoint) {
      if ($path === $endpoint || strpos($path, $endpoint . '/') === 0) {
        // 公开端点总是允许访问
        return AccessResult::allowed()
          ->setCacheMaxAge(0); // 不缓存，确保实时检查
      }
    }
    
    // 非公开端点需要身份验证
    return AccessResult::neutral();
  }

}