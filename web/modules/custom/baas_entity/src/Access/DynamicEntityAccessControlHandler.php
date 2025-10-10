<?php

declare(strict_types=1);

namespace Drupal\baas_entity\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * 动态实体访问控制处理器。
 * 
 * 与项目权限系统集成，控制用户对动态实体的访问权限。
 */
class DynamicEntityAccessControlHandler extends EntityAccessControlHandler
{

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account)
  {
    // 尝试获取实体访问检查器服务
    try {
      $entity_access_checker = \Drupal::service('baas_project.entity_access_checker');
      if ($entity_access_checker) {
        return $entity_access_checker->checkEntityAccess($entity, $operation, $account);
      }
    } catch (\Exception $e) {
      // 如果服务不可用，使用默认的访问控制逻辑
      \Drupal::logger('baas_entity')->warning('项目实体访问检查器服务不可用: @error', ['@error' => $e->getMessage()]);
    }

    // 如果服务不可用，使用默认的访问控制逻辑
    return $this->checkDefaultAccess($entity, $operation, $account);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL)
  {
    // 获取实体类型ID
    $entity_type_id = $this->entityTypeId;
    
    // 尝试获取实体访问检查器服务
    try {
      $entity_access_checker = \Drupal::service('baas_project.entity_access_checker');
      if ($entity_access_checker) {
        return $entity_access_checker->checkEntityCreateAccess($entity_type_id, $account);
      }
    } catch (\Exception $e) {
      // 如果服务不可用，使用默认的访问控制逻辑
      \Drupal::logger('baas_entity')->warning('项目实体访问检查器服务不可用: @error', ['@error' => $e->getMessage()]);
    }

    // 如果服务不可用，使用默认的访问控制逻辑
    return $this->checkDefaultCreateAccess($account, $context, $entity_bundle);
  }

  /**
   * 默认的实体访问控制逻辑。
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   实体对象。
   * @param string $operation
   *   操作类型。
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果。
   */
  protected function checkDefaultAccess(EntityInterface $entity, string $operation, AccountInterface $account)
  {
    // 管理员有全部权限
    if ($account->hasPermission('administer baas project') || $account->hasPermission('administer baas entity templates')) {
      return AccessResult::allowed()->cachePerUser();
    }

    // 根据操作类型检查权限
    switch ($operation) {
      case 'view':
        if ($account->hasPermission('view baas project') || $account->hasPermission('access baas project content')) {
          return AccessResult::allowed()->cachePerUser();
        }
        // 保持向后兼容性
        if ($account->hasPermission('access tenant entity data')) {
          return AccessResult::allowed()->cachePerUser();
        }
        break;

      case 'update':
        if ($account->hasPermission('edit baas project') || $account->hasPermission('edit baas project content')) {
          return AccessResult::allowed()->cachePerUser();
        }
        // 保持向后兼容性
        if ($account->hasPermission('update tenant entity data')) {
          return AccessResult::allowed()->cachePerUser();
        }
        break;

      case 'delete':
        if ($account->hasPermission('delete baas project') || $account->hasPermission('delete baas project content')) {
          return AccessResult::allowed()->cachePerUser();
        }
        // 保持向后兼容性
        if ($account->hasPermission('delete tenant entity data')) {
          return AccessResult::allowed()->cachePerUser();
        }
        break;
    }

    return AccessResult::forbidden()->cachePerUser();
  }

  /**
   * 默认的创建访问控制逻辑。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   * @param array $context
   *   上下文数组。
   * @param string|null $entity_bundle
   *   实体束。
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果。
   */
  protected function checkDefaultCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL)
  {
    // 管理员有全部权限
    if ($account->hasPermission('administer baas project') || $account->hasPermission('administer baas entity templates')) {
      return AccessResult::allowed()->cachePerUser();
    }

    // 检查创建权限
    if ($account->hasPermission('create baas project') || $account->hasPermission('create baas project content')) {
      return AccessResult::allowed()->cachePerUser();
    }

    // 检查项目级实体创建权限
    if ($account->hasPermission('create project entity data')) {
      return AccessResult::allowed()->cachePerUser();
    }

    // 保持向后兼容性
    if ($account->hasPermission('create tenant entity data')) {
      return AccessResult::allowed()->cachePerUser();
    }

    return AccessResult::forbidden()->cachePerUser();
  }
}
