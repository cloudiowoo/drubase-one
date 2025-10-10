<?php

declare(strict_types=1);

namespace Drupal\baas_project\Access;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * 项目实体访问控制处理器。
 */
class ProjectEntityAccessControlHandler extends EntityAccessControlHandler
{

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult
  {
    switch ($operation) {
      case 'view':
        return AccessResult::allowedIfHasPermission($account, 'view baas project content');

      case 'update':
        return AccessResult::allowedIfHasPermission($account, 'edit baas project content');

      case 'delete':
        return AccessResult::allowedIfHasPermission($account, 'delete baas project content');

      default:
        return AccessResult::neutral();
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult
  {
    return AccessResult::allowedIfHasPermission($account, 'create baas project content');
  }

}