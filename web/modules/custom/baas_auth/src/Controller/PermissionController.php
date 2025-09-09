<?php

namespace Drupal\baas_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * 权限管理控制器。
 */
class PermissionController extends ControllerBase
{

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static();
  }

  /**
   * 获取权限列表。
   */
  public function getPermissions(Request $request)
  {
    return new JsonResponse([
      'data' => [
        'permissions' => [
          'read' => '读取权限',
          'write' => '写入权限',
          'delete' => '删除权限',
          'admin' => '管理员权限',
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
   * 获取角色列表。
   */
  public function getRoles(Request $request)
  {
    return new JsonResponse([
      'data' => [
        'roles' => [
          'admin' => '管理员',
          'editor' => '编辑者',
          'viewer' => '查看者',
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
   * 分配角色。
   */
  public function assignRole(Request $request)
  {
    return new JsonResponse([
      'data' => [
        'message' => '角色分配成功',
      ],
      'meta' => [
        'timestamp' => date('c'),
        'version' => '1.0',
      ],
      'error' => NULL,
    ]);
  }

  /**
   * 撤销角色。
   */
  public function revokeRole(Request $request)
  {
    return new JsonResponse([
      'data' => [
        'message' => '角色撤销成功',
      ],
      'meta' => [
        'timestamp' => date('c'),
        'version' => '1.0',
      ],
      'error' => NULL,
    ]);
  }

  /**
   * 获取用户权限。
   */
  public function getUserPermissions(Request $request)
  {
    $current_user = $this->currentUser();

    return new JsonResponse([
      'data' => [
        'user_id' => $current_user->id(),
        'permissions' => ['read', 'write'],
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
