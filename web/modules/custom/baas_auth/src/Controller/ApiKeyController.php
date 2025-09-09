<?php

namespace Drupal\baas_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * API密钥管理控制器。
 */
class ApiKeyController extends ControllerBase
{

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static();
  }

  /**
   * 获取API密钥列表。
   */
  public function index(Request $request)
  {
    return new JsonResponse([
      'data' => [
        'api_keys' => [
          [
            'id' => 1,
            'name' => 'Production Key',
            'status' => 'active',
            'last_used' => date('c'),
            'created' => date('c'),
          ],
        ],
      ],
      'meta' => [
        'timestamp' => date('c'),
        'version' => '1.0',
      ],
      'error' => NULL,
    ]);
  }

  /**
   * 创建API密钥。
   */
  public function createApiKey(Request $request)
  {
    return new JsonResponse([
      'data' => [
        'api_key' => [
          'id' => 1,
          'key' => 'baas_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
          'name' => 'New API Key',
          'status' => 'active',
          'created' => date('c'),
        ],
      ],
      'meta' => [
        'timestamp' => date('c'),
        'version' => '1.0',
      ],
      'error' => NULL,
    ], 201);
  }

  /**
   * 更新API密钥。
   */
  public function update(Request $request, $id)
  {
    return new JsonResponse([
      'data' => [
        'message' => 'API密钥更新成功',
      ],
      'meta' => [
        'timestamp' => date('c'),
        'version' => '1.0',
      ],
      'error' => NULL,
    ]);
  }

  /**
   * 删除API密钥。
   */
  public function delete(Request $request, $id)
  {
    return new JsonResponse([
      'data' => [
        'message' => 'API密钥删除成功',
      ],
      'meta' => [
        'timestamp' => date('c'),
        'version' => '1.0',
      ],
      'error' => NULL,
    ]);
  }

  /**
   * 重新生成API密钥。
   */
  public function regenerate(Request $request, $id)
  {
    return new JsonResponse([
      'data' => [
        'api_key' => 'baas_live_yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy',
        'message' => 'API密钥重新生成成功',
      ],
      'meta' => [
        'timestamp' => date('c'),
        'version' => '1.0',
      ],
      'error' => NULL,
    ]);
  }

  /**
   * 访问控制检查。
   */
  public static function access(AccountInterface $account)
  {
    return AccessResult::allowedIf($account->isAuthenticated());
  }

  /**
   * 管理员访问控制检查。
   */
  public static function adminAccess(AccountInterface $account)
  {
    return AccessResult::allowedIf($account->hasPermission('administer baas auth'));
  }
}
