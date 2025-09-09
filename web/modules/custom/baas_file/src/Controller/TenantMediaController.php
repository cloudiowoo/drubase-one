<?php

declare(strict_types=1);

namespace Drupal\baas_file\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\baas_file\Service\ProjectFileManagerInterface;
use Drupal\baas_file\Service\FileUsageTracker;
use Drupal\baas_tenant\Service\TenantManagerInterface;
use Drupal\baas_project\Service\ProjectManagerInterface;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * 租户媒体文件管理控制器。
 *
 * 提供租户级别的媒体文件统一管理界面。
 */
class TenantMediaController extends ControllerBase
{

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly ProjectFileManagerInterface $fileManager,
    protected readonly FileUsageTracker $usageTracker,
    protected readonly TenantManagerInterface $tenantManager,
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
      $container->get('baas_tenant.manager'),
      $container->get('baas_project.manager'),
      $container->get('baas_auth.unified_permission_checker'),
    );
  }

  /**
   * 租户媒体文件管理页面。
   *
   * @param string $tenant_id
   *   租户ID。
   *
   * @return array
   *   渲染数组。
   */
  public function mediaManager(string $tenant_id): array
  {
    // 验证租户存在
    if (!$this->tenantManager->tenantExists($tenant_id)) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('租户不存在');
    }

    // 获取租户信息
    $tenant_info = $this->tenantManager->getTenant($tenant_id);
    
    // 获取租户下的项目列表
    $projects = $this->projectManager->getProjectsByTenant($tenant_id);
    
    // 获取媒体统计数据
    $media_stats = $this->usageTracker->getTenantMediaStats($tenant_id, '30days');

    $build = [
      '#theme' => 'baas_file_tenant_media_manager',
      '#tenant_id' => $tenant_id,
      '#tenant_info' => $tenant_info,
      '#projects' => $projects,
      '#media_stats' => $media_stats,
      '#attached' => [
        'library' => [
          'baas_file/tenant-media-manager',
        ],
        'drupalSettings' => [
          'baasFile' => [
            'tenantId' => $tenant_id,
            'apiEndpoints' => [
              'mediaList' => "/api/v1/{$tenant_id}/media",
              'mediaStats' => "/api/v1/{$tenant_id}/media/stats",
              'mediaDelete' => "/api/v1/{$tenant_id}/media",
            ],
          ],
        ],
      ],
    ];

    return $build;
  }

  /**
   * 访问权限检查。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   *
   * @return \Drupal\Core\Access\AccessResult
   *   访问结果。
   */
  public function access(string $tenant_id, AccountInterface $account): AccessResult
  {
    // 检查租户媒体管理权限
    $has_permission = $this->permissionChecker->checkPermission(
      'manage_tenant_media',
      'tenant',
      ['tenant_id' => $tenant_id]
    );

    if ($has_permission) {
      return AccessResult::allowed();
    }

    // 如果没有管理权限，检查查看权限
    $has_view_permission = $this->permissionChecker->checkPermission(
      'view_tenant_media',
      'tenant',
      ['tenant_id' => $tenant_id]
    );

    return $has_view_permission ? AccessResult::allowed() : AccessResult::forbidden();
  }
}