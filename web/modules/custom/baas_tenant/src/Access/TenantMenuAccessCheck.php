<?php

declare(strict_types=1);

namespace Drupal\baas_tenant\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\Access\AccessInterface;

/**
 * 租户菜单访问检查服务.
 */
class TenantMenuAccessCheck implements AccessInterface {

  /**
   * 实体类型管理器.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * 构造函数.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   实体类型管理器.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * 检查租户菜单访问权限.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   当前用户.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果.
   */
  public function access(AccountInterface $account): AccessResultInterface {
    // 未认证用户无权限
    if (!$account->isAuthenticated()) {
      return AccessResult::forbidden('用户未认证');
    }

    // 系统管理员和超级管理员有全权访问
    if ($account->hasPermission('administer baas tenants') || 
        $account->hasPermission('administer site configuration') ||
        $account->id() == 1) {
      return AccessResult::allowed()->cachePerUser();
    }

    try {
      // 加载用户实体
      $user_storage = $this->entityTypeManager->getStorage('user');
      $user = $user_storage->load($account->id());

      if (!$user) {
        return AccessResult::forbidden('无法加载用户实体');
      }

      // 检查是否是项目管理员（租户）
      if (baas_tenant_is_user_tenant($user)) {
        return AccessResult::allowed()->cachePerUser();
      }

      return AccessResult::forbidden('用户不是项目管理员')->cachePerUser();
    }
    catch (\Exception $e) {
      \Drupal::logger('baas_tenant')->error('检查租户菜单权限时发生错误: @error', [
        '@error' => $e->getMessage(),
      ]);
      return AccessResult::forbidden('权限检查失败');
    }
  }

}