<?php

declare(strict_types=1);

namespace Drupal\baas_file\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * BaaS文件访问控制处理器。
 *
 * 为文件实体提供基于项目的访问控制。
 */
class FileAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\file\FileInterface $entity */
    
    // 获取文件URI
    $uri = $entity->getFileUri();
    
    // 如果不是BaaS项目文件，使用默认访问控制
    if (strpos($uri, 'public://baas/') !== 0) {
      return parent::checkAccess($entity, $operation, $account);
    }

    // 解析项目ID
    $project_id = $this->extractProjectIdFromUri($uri);
    if (!$project_id) {
      return AccessResult::forbidden('无法识别项目ID');
    }

    // 检查项目文件访问权限
    $access_checker = \Drupal::service('baas_file.access_checker');
    
    switch ($operation) {
      case 'view':
      case 'download':
        $access = $access_checker->canAccessProjectFiles($project_id);
        break;
        
      case 'update':
      case 'delete':
        $access = $access_checker->canManageProjectFiles($project_id);
        break;
        
      default:
        $access = FALSE;
        break;
    }

    return $access ? AccessResult::allowed() : AccessResult::forbidden('项目文件访问被拒绝');
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    // 文件创建通过项目上传API控制，这里默认允许
    return AccessResult::allowed();
  }

  /**
   * 从文件URI中提取项目ID。
   *
   * @param string $uri
   *   文件URI。
   *
   * @return string|null
   *   项目ID，如果无法提取则返回NULL。
   */
  protected function extractProjectIdFromUri(string $uri): ?string {
    // URI格式: public://baas/{tenant_id}/{project_id}/filename
    $path = str_replace('public://baas/', '', $uri);
    $parts = explode('/', $path);
    
    if (count($parts) >= 2) {
      return $parts[0] . '_project_' . $parts[1];
    }
    
    return NULL;
  }
}