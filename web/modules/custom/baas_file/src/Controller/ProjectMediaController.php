<?php

declare(strict_types=1);

namespace Drupal\baas_file\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\baas_file\Service\ProjectFileManagerInterface;
use Drupal\baas_file\Service\FileUsageTracker;
use Drupal\baas_project\Service\ProjectManagerInterface;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * 项目媒体文件管理控制器。
 *
 * 提供项目级别的媒体文件管理界面。
 */
class ProjectMediaController extends ControllerBase
{

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly ProjectFileManagerInterface $fileManager,
    protected readonly FileUsageTracker $usageTracker,
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly UnifiedPermissionCheckerInterface $permissionChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_file.manager'),
      $container->get('baas_file.usage_tracker'),
      $container->get('baas_project.manager'),
      $container->get('baas_auth.unified_permission_checker'),
    );
  }

  /**
   * 项目媒体文件管理页面。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   渲染数组。
   */
  public function mediaManager(string $tenant_id, string $project_id): array
  {
    // 验证项目存在
    if (!$this->projectManager->projectExists($project_id)) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('项目不存在');
    }

    // 获取项目信息
    $project_info = $this->projectManager->getProject($project_id);
    
    // 获取项目媒体统计数据
    $media_stats = $this->usageTracker->getProjectMediaStats($project_id, '30days');

    // 获取当前用户在项目中的权限
    $user_permissions = $this->getUserProjectPermissions($tenant_id, $project_id);

    $build = [
      '#theme' => 'baas_file_project_media_manager',
      '#tenant_id' => $tenant_id,
      '#project_id' => $project_id,
      '#project_info' => $project_info,
      '#media_stats' => $media_stats,
      '#user_permissions' => $user_permissions,
      '#attached' => [
        'library' => [
          'baas_file/project-media-manager',
        ],
        'drupalSettings' => [
          'baasFile' => [
            'tenantId' => $tenant_id,
            'projectId' => $project_id,
            'userPermissions' => $user_permissions,
            'apiEndpoints' => [
              'mediaList' => "/api/v1/{$tenant_id}/project/{$project_id}/media",
              'mediaUpload' => "/api/v1/{$tenant_id}/project/{$project_id}/media/upload",
              'mediaDelete' => "/api/v1/{$tenant_id}/media",
              'mediaStats' => "/api/v1/{$tenant_id}/media/stats?project_id={$project_id}",
            ],
          ],
        ],
      ],
    ];

    return $build;
  }

  /**
   * 获取用户在项目中的媒体文件权限。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   权限数组。
   */
  protected function getUserProjectPermissions(string $tenant_id, string $project_id): array
  {
    $context = [
      'tenant_id' => $tenant_id,
      'project_id' => $project_id,
    ];

    return [
      'view' => $this->permissionChecker->checkPermission('view_project_files', 'project', $context),
      'upload' => $this->permissionChecker->checkPermission('upload_project_files', 'project', $context),
      'manage' => $this->permissionChecker->checkPermission('manage_project_files', 'project', $context),
      'delete' => $this->permissionChecker->checkPermission('delete_project_files', 'project', $context),
      'view_stats' => $this->permissionChecker->checkPermission('view_project_file_statistics', 'project', $context),
    ];
  }

  /**
   * 访问权限检查。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   *
   * @return \Drupal\Core\Access\AccessResult
   *   访问结果。
   */
  public function access(string $tenant_id, string $project_id, AccountInterface $account): AccessResult
  {
    // 检查项目文件查看权限
    $has_permission = $this->permissionChecker->checkPermission(
      'view_project_files',
      'project',
      [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
      ]
    );

    return $has_permission ? AccessResult::allowed() : AccessResult::forbidden();
  }
}