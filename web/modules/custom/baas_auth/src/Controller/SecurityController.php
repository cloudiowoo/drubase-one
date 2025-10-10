<?php

namespace Drupal\baas_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * 安全管理控制器。
 */
class SecurityController extends ControllerBase
{

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static();
  }

  /**
   * 获取安全日志。
   */
  public function getSecurityLogs(Request $request)
  {
    return new JsonResponse([
      'data' => [
        'logs' => [
          [
            'id' => 1,
            'event' => 'login_success',
            'user_id' => 1,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0...',
            'timestamp' => date('c'),
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
   * 管理员访问控制检查。
   */
  public static function adminAccess(AccountInterface $account)
  {
    return AccessResult::allowedIf($account->hasPermission('administer baas auth'));
  }
}
