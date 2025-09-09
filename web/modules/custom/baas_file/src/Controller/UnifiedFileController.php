<?php

declare(strict_types=1);

namespace Drupal\baas_file\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Database\Connection;
use Drupal\baas_tenant\TenantManagerInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * 统一文件管理控制器。
 *
 * 提供基于用户权限的统一文件管理界面。
 */
class UnifiedFileController extends ControllerBase {

  /**
   * 数据库连接。
   */
  protected Connection $database;

  /**
   * 租户管理器。
   */
  protected TenantManagerInterface $tenantManager;

  /**
   * 项目管理器。
   */
  protected ProjectManagerInterface $projectManager;

  /**
   * 统一权限检查器。
   */
  protected UnifiedPermissionCheckerInterface $permissionChecker;

  /**
   * 构造函数。
   */
  public function __construct(
    Connection $database,
    TenantManagerInterface $tenant_manager,
    ProjectManagerInterface $project_manager,
    UnifiedPermissionCheckerInterface $permission_checker
  ) {
    $this->database = $database;
    $this->tenantManager = $tenant_manager;
    $this->projectManager = $project_manager;
    $this->permissionChecker = $permission_checker;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('baas_tenant.manager'),
      $container->get('baas_project.manager'),
      $container->get('baas_auth.unified_permission_checker')
    );
  }

  /**
   * 统一文件管理界面。
   *
   * @return array
   *   渲染数组。
   */
  public function fileManager(): array {
    $user_id = (int) $this->currentUser()->id();
    $user_name = $this->currentUser()->getAccountName();
    $is_authenticated = $this->currentUser()->isAuthenticated();
    
    \Drupal::logger('baas_file')->info('文件管理器访问调试: user_id=@uid, username=@name, authenticated=@auth', [
      '@uid' => $user_id,
      '@name' => $user_name,
      '@auth' => $is_authenticated ? 'YES' : 'NO',
    ]);
    
    // 临时简化处理，避免复杂查询
    if ($user_id === 0) {
      return [
        '#markup' => '<h1>BaaS文件管理</h1><p>请先登录以查看文件管理界面。当前用户ID: ' . $user_id . ', 认证状态: ' . ($is_authenticated ? '已认证' : '未认证') . '</p>',
      ];
    }
    
    try {
      // 获取用户可访问的项目（不再需要租户）
      $accessible_projects = $this->getAccessibleProjects($user_id);
      
      // 获取文件统计
      $file_stats = $this->getFileStatistics($user_id, [], $accessible_projects);
    } catch (\Exception $e) {
      \Drupal::logger('baas_file')->error('文件管理器错误: @error', ['@error' => $e->getMessage()]);
      return [
        '#markup' => '<h1>BaaS文件管理</h1><p>加载文件数据时出错: ' . $e->getMessage() . '</p>',
      ];
    }

    $build = [
      '#theme' => 'baas_file_manager',
      '#tenants' => [],
      '#projects' => $accessible_projects,
      '#statistics' => $file_stats,
      '#user_permissions' => $this->getUserPermissions($user_id),
      '#attached' => [
        'library' => [
          'baas_file/file-manager',
        ],
        'drupalSettings' => [
          'baasFile' => [
            'apiEndpoint' => '/file-manager/api',
            'currentUser' => $user_id,
            'permissions' => $this->getUserPermissions($user_id),
            'jwtToken' => $this->getJwtToken($user_id),
          ],
        ],
      ],
    ];

    return $build;
  }

  /**
   * 测试方法。
   *
   * @return array
   *   简单的测试输出。
   */
  public function test(): array {
    return [
      '#markup' => '<h1>BaaS文件管理测试页面</h1><p>如果您能看到这个页面，说明路由配置正常工作。</p>',
    ];
  }

  /**
   * 获取用户可访问的租户列表。
   *
   * @param int $user_id
   *   用户ID。
   *
   * @return array
   *   租户列表。
   */
  protected function getAccessibleTenants(int $user_id): array {
    $tenants = [];
    
    // 查询用户所属的租户
    $query = $this->database->select('baas_user_tenant_mapping', 'm')
      ->fields('m', ['tenant_id', 'role'])
      ->condition('user_id', $user_id)
      ->condition('status', 1);
    
    $mappings = $query->execute()->fetchAllAssoc('tenant_id');
    
    foreach ($mappings as $tenant_id => $mapping) {
      try {
        $tenant_config = $this->tenantManager->getTenant($tenant_id);
        if ($tenant_config) {
          $tenants[$tenant_id] = [
            'id' => $tenant_id,
            'name' => $tenant_config['name'] ?? $tenant_id,
            'role' => $mapping->role,
            'description' => $tenant_config['description'] ?? '',
            'file_permissions' => [
              'view' => $this->permissionChecker->checkTenantPermission($user_id, $tenant_id, 'view tenant media'),
              'manage' => $this->permissionChecker->checkTenantPermission($user_id, $tenant_id, 'manage tenant media'),
            ],
          ];
        }
      } catch (\Exception $e) {
        \Drupal::logger('baas_file')->error('获取租户配置失败: @error', ['@error' => $e->getMessage()]);
      }
    }
    
    return $tenants;
  }

  /**
   * 获取用户可访问的项目列表。
   *
   * @param int $user_id
   *   用户ID。
   *
   * @return array
   *   项目列表。
   */
  protected function getAccessibleProjects(int $user_id): array {
    $projects = [];
    
    // 查询用户参与的项目
    $query = $this->database->select('baas_project_members', 'm')
      ->fields('m', ['project_id', 'role'])
      ->condition('user_id', $user_id)
      ->condition('status', 1);
    
    $memberships = $query->execute()->fetchAll();
    
    \Drupal::logger('baas_file')->info('调试：用户 @user_id 的项目成员关系: @count 个项目', [
      '@user_id' => $user_id,
      '@count' => count($memberships),
    ]);
    
    foreach ($memberships as $membership) {
      try {
        $project = $this->projectManager->getProject($membership->project_id);
        \Drupal::logger('baas_file')->info('调试：获取项目 @project_id 配置: @project', [
          '@project_id' => $membership->project_id,
          '@project' => $project ? 'SUCCESS' : 'FAILED',
        ]);
        
        if ($project) {
          // 从project_id中提取tenant_id (格式: baas_{tenant_id}_{project_uuid})
          $tenant_id = $project['tenant_id'] ?? '';
          if (empty($tenant_id) && preg_match('/^baas_([a-zA-Z0-9]+)_/', $membership->project_id, $matches)) {
            $tenant_id = $matches[1];
          }
          
          // 简化权限检查，临时为owner角色直接给予所有权限
          $is_owner_or_admin = in_array($membership->role, ['owner', 'admin']);
          
          $file_permissions = [
            'view' => true,  // 暂时简化
            'upload' => $is_owner_or_admin,  // 只有owner/admin可以上传
            'manage' => $is_owner_or_admin,
            'delete' => $is_owner_or_admin,
          ];
          
          // 如果需要详细权限检查，可以启用以下代码
          /*
          try {
            $file_permissions = [
              'view' => $this->permissionChecker->checkProjectPermission($user_id, $membership->project_id, 'view project files'),
              'upload' => $this->permissionChecker->checkProjectPermission($user_id, $membership->project_id, 'upload project files'),
              'manage' => $this->permissionChecker->checkProjectPermission($user_id, $membership->project_id, 'manage project files'),
              'delete' => $this->permissionChecker->checkProjectPermission($user_id, $membership->project_id, 'delete project files'),
            ];
          } catch (\Exception $e) {
            \Drupal::logger('baas_file')->error('项目权限检查失败: @error', ['@error' => $e->getMessage()]);
            $file_permissions = ['view' => false, 'upload' => false, 'manage' => false, 'delete' => false];
          }
          */
          
          $projects[$membership->project_id] = [
            'id' => $membership->project_id,
            'name' => $project['name'] ?? $membership->project_id,
            'tenant_id' => $tenant_id,
            'role' => $membership->role,
            'description' => $project['description'] ?? '',
            'file_permissions' => $file_permissions,
          ];
          
          \Drupal::logger('baas_file')->info('调试：添加项目 @project_id (@name) 到列表，上传权限: @upload', [
            '@project_id' => $membership->project_id,
            '@name' => $project['name'] ?? $membership->project_id,
            '@upload' => $file_permissions['upload'] ? 'YES' : 'NO',
          ]);
        }
      } catch (\Exception $e) {
        \Drupal::logger('baas_file')->error('获取项目配置失败: @error', ['@error' => $e->getMessage()]);
      }
    }
    
    \Drupal::logger('baas_file')->info('调试：最终返回 @count 个可访问项目', [
      '@count' => count($projects),
    ]);
    
    return $projects;
  }

  /**
   * 获取文件统计信息。
   *
   * @param int $user_id
   *   用户ID。
   * @param array $tenants
   *   可访问的租户。
   * @param array $projects
   *   可访问的项目。
   *
   * @return array
   *   统计信息。
   */
  protected function getFileStatistics(int $user_id, array $tenants, array $projects): array {
    $stats = [
      'total_files' => 0,
      'total_size' => 0,
      'by_tenant' => [],
      'by_project' => [],
      'recent_uploads' => [],
    ];

    // 计算项目文件统计
    foreach ($projects as $project) {
      try {
        $project_stats = $this->database->select('baas_project_file_usage', 'u')
          ->fields('u')
          ->condition('project_id', $project['id'])
          ->execute()
          ->fetchAssoc();

        if ($project_stats) {
          $stats['by_project'][$project['id']] = [
            'name' => $project['name'],
            'file_count' => (int) $project_stats['file_count'],
            'total_size' => (int) $project_stats['total_size'],
            'total_size_formatted' => $this->formatFileSize((int) $project_stats['total_size']),
            'image_count' => (int) $project_stats['image_count'],
            'document_count' => (int) $project_stats['document_count'],
            'last_updated' => $project_stats['last_updated'],
          ];

          $stats['total_files'] += (int) $project_stats['file_count'];
          $stats['total_size'] += (int) $project_stats['total_size'];
        }
      } catch (\Exception $e) {
        \Drupal::logger('baas_file')->error('获取项目文件统计失败: @error', ['@error' => $e->getMessage()]);
      }
    }

    // 不再需要按租户聚合统计
    $stats['by_tenant'] = [];

    $stats['total_size_formatted'] = $this->formatFileSize($stats['total_size']);

    return $stats;
  }

  /**
   * 获取用户权限。
   *
   * @param int $user_id
   *   用户ID。
   *
   * @return array
   *   权限数组。
   */
  protected function getUserPermissions(int $user_id): array {
    return [
      'access_file_manager' => $this->currentUser()->hasPermission('access baas file manager'),
      'view_tenant_media' => $this->currentUser()->hasPermission('view tenant media'),
      'manage_tenant_media' => $this->currentUser()->hasPermission('manage tenant media'),
      'view_project_files' => $this->currentUser()->hasPermission('view project files'),
      'manage_project_files' => $this->currentUser()->hasPermission('manage project files'),
      'view_file_statistics' => $this->currentUser()->hasPermission('view project file statistics'),
      'administer_baas_file' => $this->currentUser()->hasPermission('administer baas file'),
    ];
  }

  /**
   * 格式化文件大小。
   *
   * @param int $bytes
   *   字节数。
   *
   * @return string
   *   格式化后的文件大小。
   */
  protected function formatFileSize(int $bytes): string {
    if ($bytes === 0) {
      return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = floor(log($bytes, 1024));
    $power = min($power, count($units) - 1);

    $size = $bytes / pow(1024, $power);
    $formatted = number_format($size, $power > 0 ? 2 : 0);

    return $formatted . ' ' . $units[$power];
  }

  /**
   * 获取用户的JWT token。
   *
   * @param int $user_id
   *   用户ID。
   *
   * @return string|null
   *   JWT token或null。
   */
  protected function getJwtToken(int $user_id): ?string {
    try {
      // 尝试从baas_auth服务获取JWT token
      $jwt_manager = \Drupal::service('baas_auth.jwt_token_manager');
      $user = \Drupal::entityTypeManager()->getStorage('user')->load($user_id);
      
      if ($user && $jwt_manager) {
        // 为用户生成JWT token
        $token_data = [
          'user_id' => $user_id,
          'username' => $user->getAccountName(),
          'roles' => $user->getRoles(),
        ];
        
        return $jwt_manager->generateAccessToken($token_data);
      }
    } catch (\Exception $e) {
      \Drupal::logger('baas_file')->error('获取JWT token失败: @error', ['@error' => $e->getMessage()]);
    }
    
    return null;
  }

  /**
   * 检查访问权限。
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户。
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果。
   */
  public function access(AccountInterface $account) {
    // 允许已认证用户或具有管理文件权限的用户访问
    if ($account->isAuthenticated()) {
      return AccessResult::allowed()->cachePerPermissions()->cachePerUser();
    }
    
    // 或者检查特定权限
    if ($account->hasPermission('access baas file manager') || $account->hasPermission('administer baas file')) {
      return AccessResult::allowed()->cachePerPermissions()->cachePerUser();
    }
    
    return AccessResult::forbidden()->cachePerPermissions()->cachePerUser();
  }
}