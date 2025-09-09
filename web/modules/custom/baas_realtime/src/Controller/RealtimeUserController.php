<?php

declare(strict_types=1);

namespace Drupal\baas_realtime\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_tenant\TenantManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * 实时功能用户控制器。
 */
class RealtimeUserController extends ControllerBase
{

  /**
   * Logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly TenantManagerInterface $tenantManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('baas_realtime');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('baas_project.manager'),
      $container->get('baas_tenant.manager'),
      $container->get('logger.factory')
    );
  }

  /**
   * 显示用户的实时项目列表。
   *
   * @return array
   *   渲染数组。
   */
  public function userProjects(): array {
    $user_id = (int) $this->currentUser()->id();
    
    // 获取用户的项目列表 - 直接从数据库查询
    $user_projects = $this->getUserProjectsFromDatabase($user_id);
    
    $projects_with_realtime = [];
    
    foreach ($user_projects as $project) {
      // 获取项目的实时配置
      $realtime_config = $this->database->select('baas_realtime_project_config', 'c')
        ->fields('c')
        ->condition('project_id', $project['project_id'])
        ->condition('tenant_id', $project['tenant_id'])
        ->execute()
        ->fetchAssoc();
      
      // 获取租户信息
      $tenant = $this->tenantManager->getTenant($project['tenant_id']);
      
      $projects_with_realtime[] = [
        'project' => $project,
        'tenant' => $tenant,
        'realtime_config' => $realtime_config,
        'realtime_enabled' => !empty($realtime_config) && $realtime_config['enabled'],
        'enabled_entities_count' => $realtime_config ? count(json_decode($realtime_config['enabled_entities'] ?? '[]', true)) : 0,
        'manage_url' => "/tenant/{$project['tenant_id']}/project/{$project['project_id']}/realtime",
      ];
    }

    $build = [
      '#theme' => 'baas_realtime_user_projects',
      '#projects' => $projects_with_realtime,
      '#attached' => [
        'library' => ['baas_realtime/admin'],
      ],
    ];

    return $build;
  }

  /**
   * 从数据库获取用户的项目列表。
   *
   * @param int $user_id
   *   用户ID。
   *
   * @return array
   *   项目列表数组。
   */
  protected function getUserProjectsFromDatabase(int $user_id): array {
    $query = $this->database->select('baas_project_members', 'pm');
    $query->join('baas_project_config', 'pc', 'pm.project_id = pc.project_id');
    $query->fields('pc', ['project_id', 'tenant_id', 'name', 'machine_name', 'description', 'created', 'updated']);
    $query->addField('pm', 'role');
    $query->condition('pm.user_id', $user_id);
    $query->condition('pm.status', 1);
    $query->condition('pc.status', 1);
    $query->orderBy('pc.updated', 'DESC');
    
    $results = $query->execute()->fetchAll();
    
    $projects = [];
    foreach ($results as $row) {
      $projects[] = [
        'project_id' => $row->project_id,
        'tenant_id' => $row->tenant_id,
        'name' => $row->name,
        'machine_name' => $row->machine_name,
        'description' => $row->description,
        'role' => $row->role,
        'created' => $row->created,
        'updated' => $row->updated,
      ];
    }
    
    return $projects;
  }

}