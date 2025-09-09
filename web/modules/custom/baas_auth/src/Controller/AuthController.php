<?php

namespace Drupal\baas_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * 认证控制器。
 */
class AuthController extends ControllerBase
{

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static();
  }

  /**
   * 用户登录。
   */
  public function login(Request $request)
  {
    try {
      $data = json_decode($request->getContent(), TRUE);

      // 基础验证
      if (empty($data['username']) || empty($data['password'])) {
        return new JsonResponse([
          'error' => 'INVALID_CREDENTIALS',
          'message' => '用户名和密码不能为空',
        ], 400);
      }

      // TODO: 实现登录逻辑

      return new JsonResponse([
        'data' => [
          'access_token' => 'dummy_token',
          'refresh_token' => 'dummy_refresh_token',
          'expires_in' => 3600,
        ],
        'meta' => [
          'timestamp' => date('c'),
          'version' => '1.0',
        ],
        'error' => NULL,
      ]);
    } catch (\Exception $e) {
      return new JsonResponse([
        'data' => NULL,
        'meta' => [
          'timestamp' => date('c'),
          'version' => '1.0',
        ],
        'error' => [
          'code' => 'LOGIN_ERROR',
          'message' => '登录失败',
        ],
      ], 500);
    }
  }

  /**
   * 刷新令牌。
   */
  public function refresh(Request $request)
  {
    return new JsonResponse([
      'data' => [
        'access_token' => 'new_dummy_token',
        'expires_in' => 3600,
      ],
      'meta' => [
        'timestamp' => date('c'),
        'version' => '1.0',
      ],
      'error' => NULL,
    ]);
  }

  /**
   * 用户注销。
   */
  public function logout(Request $request)
  {
    return new JsonResponse([
      'data' => [
        'message' => '注销成功',
      ],
      'meta' => [
        'timestamp' => date('c'),
        'version' => '1.0',
      ],
      'error' => NULL,
    ]);
  }

  /**
   * 获取当前用户信息。
   */
  public function me(Request $request)
  {
    $current_user = $this->currentUser();

    return new JsonResponse([
      'data' => [
        'id' => $current_user->id(),
        'username' => $current_user->getAccountName(),
        'email' => $current_user->getEmail(),
        'roles' => $current_user->getRoles(),
      ],
      'meta' => [
        'timestamp' => date('c'),
        'version' => '1.0',
      ],
      'error' => NULL,
    ]);
  }

  /**
   * 验证令牌。
   */
  public function verify(Request $request)
  {
    return new JsonResponse([
      'data' => [
        'valid' => TRUE,
        'message' => '令牌有效',
      ],
      'meta' => [
        'timestamp' => date('c'),
        'version' => '1.0',
      ],
      'error' => NULL,
    ]);
  }

  /**
   * 修改密码。
   */
  public function changePassword(Request $request)
  {
    return new JsonResponse([
      'data' => [
        'message' => '密码修改成功',
      ],
      'meta' => [
        'timestamp' => date('c'),
        'version' => '1.0',
      ],
      'error' => NULL,
    ]);
  }
}
