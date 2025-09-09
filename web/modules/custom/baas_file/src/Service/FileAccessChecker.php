<?php

declare(strict_types=1);

namespace Drupal\baas_file\Service;

use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_project\ProjectMemberManagerInterface;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * 文件访问权限检查服务。
 */
class FileAccessChecker
{

  /**
   * 项目管理器。
   */
  protected ProjectManagerInterface $projectManager;

  /**
   * 项目成员管理器。
   */
  protected ProjectMemberManagerInterface $memberManager;

  /**
   * 统一权限检查器。
   */
  protected UnifiedPermissionCheckerInterface $permissionChecker;

  /**
   * 当前用户。
   */
  protected AccountInterface $currentUser;

  /**
   * 实体类型管理器。
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * 构造函数。
   */
  public function __construct(
    ProjectManagerInterface $project_manager,
    ProjectMemberManagerInterface $member_manager,
    UnifiedPermissionCheckerInterface $permission_checker,
    AccountInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->projectManager = $project_manager;
    $this->memberManager = $member_manager;
    $this->permissionChecker = $permission_checker;
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * 检查用户是否可以访问项目文件。
   */
  public function canAccessProjectFiles(string $project_id): bool
  {
    return $this->permissionChecker->checkProjectPermission(
      (int) $this->currentUser->id(),
      $project_id,
      'view_project_content'
    );
  }

  /**
   * 检查用户是否可以上传文件到项目。
   */
  public function canUploadToProject(string $project_id): bool
  {
    return $this->permissionChecker->checkProjectPermission(
      (int) $this->currentUser->id(),
      $project_id,
      'create_project_content'
    );
  }

  /**
   * 检查用户是否可以删除项目文件。
   */
  public function canDeleteFromProject(string $project_id): bool
  {
    return $this->permissionChecker->checkProjectPermission(
      (int) $this->currentUser->id(),
      $project_id,
      'delete_project_content'
    );
  }

  /**
   * 检查用户是否可以管理项目文件（更新、删除）。
   */
  public function canManageProjectFiles(string $project_id): bool
  {
    $user_id = (int) $this->currentUser->id();
    return $this->permissionChecker->checkProjectPermission(
      $user_id,
      $project_id,
      'update_project_content'
    ) || $this->permissionChecker->checkProjectPermission(
      $user_id,
      $project_id,
      'delete_project_content'
    );
  }
}