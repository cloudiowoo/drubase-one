<?php

declare(strict_types=1);

namespace Drupal\baas_tenant\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\Routing\Route;

/**
 * 菜单访问权限检查器。
 */
class MenuAccessChecker
{

  /**
   * 检查API密钥菜单的访问权限。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   当前用户账户。
   *
   * @return \Drupal\Core\Access\AccessResult
   *   访问结果。
   */
  public function checkApiKeyMenuAccess(AccountInterface $account): AccessResult
  {
    // 检查用户是否有租户权限
    if ($account->hasPermission('create baas project') || 
        $account->hasPermission('view baas project') ||
        in_array('project_manager', $account->getRoles())) {
      return AccessResult::allowed()->cachePerUser();
    }

    return AccessResult::forbidden('用户没有租户权限')->cachePerUser();
  }

  /**
   * 通用的租户菜单访问权限检查。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   当前用户账户。
   *
   * @return \Drupal\Core\Access\AccessResult
   *   访问结果。
   */
  public function checkTenantMenuAccess(AccountInterface $account): AccessResult
  {
    // 加载用户实体
    $user_storage = \Drupal::entityTypeManager()->getStorage('user');
    $user_entity = $user_storage->load($account->id());
    
    if (!$user_entity) {
      return AccessResult::forbidden('用户不存在')->cachePerUser();
    }

    // 使用baas_tenant模块的函数检查用户是否是租户
    if (function_exists('baas_tenant_is_user_tenant') && baas_tenant_is_user_tenant($user_entity)) {
      return AccessResult::allowed()->cachePerUser();
    }

    return AccessResult::forbidden('用户不是租户')->cachePerUser();
  }
}