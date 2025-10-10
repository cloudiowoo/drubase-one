<?php

namespace Drupal\baas_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * 会话管理控制器。
 */
class SessionController extends ControllerBase
{

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static();
  }

  /**
   * 获取用户会话列表。
   */
  public function getSessions(Request $request)
  {
    return new JsonResponse([
      'data' => [
        'sessions' => [
          [
            'id' => 1,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0...',
            'last_activity' => date('c'),
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
   * 删除指定会话。
   */
  public function deleteSession(Request $request, $id)
  {
    return new JsonResponse([
      'data' => [
        'message' => '会话删除成功',
      ],
      'meta' => [
        'timestamp' => date('c'),
        'version' => '1.0',
      ],
      'error' => NULL,
    ]);
  }

  /**
   * 删除所有会话。
   */
  public function deleteAllSessions(Request $request)
  {
    return new JsonResponse([
      'data' => [
        'message' => '所有会话删除成功',
      ],
      'meta' => [
        'timestamp' => date('c'),
        'version' => '1.0',
      ],
      'error' => NULL,
    ]);
  }
}
