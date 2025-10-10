<?php

declare(strict_types=1);

namespace Drupal\baas_project\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\baas_project\ProjectMigrationService;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;

/**
 * 项目管理员控制器。
 *
 * 提供项目系统管理功能，包括数据迁移等。
 */
class ProjectAdminController extends ControllerBase implements ContainerInjectionInterface {

  /**
   * 日期格式化服务。
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly ProjectMigrationService $migrationService,
    protected readonly ProjectManagerInterface $projectManager,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    DateFormatterInterface $dateFormatter,
  ) {
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->dateFormatter = $dateFormatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_project.migration'),
      $container->get('baas_project.manager'),
      $container->get('logger.factory'),
      $container->get('messenger'),
      $container->get('date.formatter'),
    );
  }

  /**
   * 项目管理列表页面。
   *
   * @return array
   *   渲染数组。
   */
  public function list(): array {
    $build = [];
    $build['#attached']['library'][] = 'baas_project/admin';

    // 页面标题和描述
    $build['header'] = [
      '#markup' => '<div class="admin-header">
        <h1>' . $this->t('项目管理') . '</h1>
        <p>' . $this->t('管理BaaS平台中所有租户的项目，包括项目创建、配置、成员管理和数据迁移等功能。') . '</p>
      </div>',
    ];

    // 获取所有项目的统计信息
    $statistics = $this->getProjectStatistics();
    
    $build['statistics'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['project-statistics-grid']],
      'total_projects' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['stat-card', 'stat-card--primary']],
        '#markup' => '<div class="stat-number">' . $statistics['total'] . '</div><div class="stat-label">' . $this->t('总项目数') . '</div><div class="stat-description">' . $this->t('包含所有状态的项目') . '</div>',
      ],
      'active_projects' => [
        '#type' => 'container', 
        '#attributes' => ['class' => ['stat-card', 'stat-card--success']],
        '#markup' => '<div class="stat-number">' . $statistics['active'] . '</div><div class="stat-label">' . $this->t('活跃项目') . '</div><div class="stat-description">' . $this->t('正在运行的项目') . '</div>',
      ],
      'total_tenants' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['stat-card', 'stat-card--info']],
        '#markup' => '<div class="stat-number">' . $statistics['tenants'] . '</div><div class="stat-label">' . $this->t('租户数量') . '</div><div class="stat-description">' . $this->t('系统中的租户总数') . '</div>',
      ],
      'project_managers' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['stat-card', 'stat-card--warning']],
        '#markup' => '<div class="stat-number">' . $statistics['project_managers'] . '</div><div class="stat-label">' . $this->t('项目管理员') . '</div><div class="stat-description">' . $this->t('拥有项目管理权限的用户') . '</div>',
      ],
    ];

    // 快速操作面板
    $build['quick_actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['admin-actions-panel']],
      'title' => [
        '#markup' => '<h2>' . $this->t('快速操作') . '</h2>',
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['admin-actions-grid']],
        'all_projects' => [
          '#type' => 'link',
          '#title' => $this->t('查看所有项目'),
          '#url' => Url::fromRoute('baas_project.admin.all_projects'),
          '#attributes' => ['class' => ['button', 'button--primary', 'admin-action-button']],
        ],
        // 'migrate' => [
        //   '#type' => 'link',
        //   '#title' => $this->t('数据迁移'),
        //   '#url' => Url::fromRoute('baas_project.admin.migrate'),
        //   '#attributes' => ['class' => ['button', 'admin-action-button']],
        // ],
        // 'status' => [
        //   '#type' => 'link', 
        //   '#title' => $this->t('系统状态'),
        //   '#url' => Url::fromRoute('baas_project.admin.status'),
        //   '#attributes' => ['class' => ['button', 'admin-action-button']],
        // ],
        'manage_users' => [
          '#type' => 'link',
          '#title' => $this->t('用户管理'),
          '#url' => Url::fromRoute('entity.user.collection'),
          '#attributes' => ['class' => ['button', 'admin-action-button']],
        ],
      ],
    ];

    // 最近项目表格
    $build['recent_projects'] = [
      '#type' => 'details',
      '#title' => $this->t('最近创建的项目'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['admin-section']],
    ];

    $recent_projects = $this->getRecentProjects(10);
    if (!empty($recent_projects)) {
      $header = [
        $this->t('项目名称'),
        $this->t('租户'),
        $this->t('拥有者'),
        $this->t('状态'),
        $this->t('创建时间'),
        $this->t('操作'),
      ];

      $rows = [];
      foreach ($recent_projects as $project) {
        $status_class = $project['status'] ? 'status-active' : 'status-inactive';
        $status_text = $project['status'] ? $this->t('活跃') : $this->t('已停用');
        
        $operations = [
          '#type' => 'container',
          '#attributes' => ['class' => ['admin-operations']],
          'view' => [
            '#type' => 'link',
            '#title' => $this->t('查看'),
            '#url' => Url::fromRoute('baas_project.user_view', [
              'tenant_id' => $project['tenant_id'],
              'project_id' => $project['project_id'],
            ]),
            '#attributes' => ['class' => ['button', 'button--small']],
          ],
          'edit' => [
            '#type' => 'link',
            '#title' => $this->t('编辑'),
            '#url' => Url::fromRoute('baas_project.user_edit', [
              'tenant_id' => $project['tenant_id'],
              'project_id' => $project['project_id'],
            ]),
            '#attributes' => ['class' => ['button', 'button--small']],
          ],
        ];
        
        $rows[] = [
          $project['name'],
          $this->getTenantDisplayName($project['tenant_id']),
          $project['owner_name'] ?? $this->t('未知'),
          [
            'data' => [
              '#markup' => '<span class="project-status ' . $status_class . '">' . $status_text . '</span>',
            ],
          ],
          $this->dateFormatter->format($project['created'], 'short'),
          ['data' => $operations],
        ];
      }

      $build['recent_projects']['table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('暂无项目。'),
        '#attributes' => ['class' => ['admin-projects-table']],
      ];
      
      $build['recent_projects']['view_all'] = [
        '#type' => 'link',
        '#title' => $this->t('查看所有项目'),
        '#url' => Url::fromRoute('baas_project.admin.all_projects'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    } else {
      $build['recent_projects']['empty'] = [
        '#markup' => '<div class="empty-state">
          <h3>' . $this->t('暂无项目') . '</h3>
          <p>' . $this->t('系统中还没有创建任何项目。') . '</p>
        </div>',
      ];
    }

    // 系统健康状态
    $build['system_health'] = [
      '#type' => 'details',
      '#title' => $this->t('系统健康状态'),
      '#open' => FALSE,
      '#attributes' => ['class' => ['admin-section']],
    ];
    
    $health_checks = $this->performHealthChecks();
    $build['system_health']['checks'] = $this->buildHealthChecksDisplay($health_checks);

    return $build;
  }

  /**
   * 执行项目数据迁移。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return array|\Symfony\Component\HttpFoundation\JsonResponse
   *   渲染数组或JSON响应。
   */
  public function migrate(Request $request) {
    // 如果是AJAX请求，返回JSON响应
    if ($request->isXmlHttpRequest() || $request->getContentTypeFormat() === 'json') {
      return $this->executeMigration();
    }

    // 构建管理页面
    $build = [
      '#theme' => 'baas_project_admin_migrate',
      '#attached' => [
        'library' => ['baas_project/admin'],
      ],
    ];

    // 检查迁移状态
    $migration_status = $this->getMigrationStatus();
    $build['#migration_status'] = $migration_status;

    // 添加操作按钮
    if (!$migration_status['is_migrated']) {
      $build['#migrate_button'] = [
        '#type' => 'button',
        '#value' => $this->t('Execute Migration'),
        '#attributes' => [
          'class' => ['button', 'button--primary', 'js-migrate-button'],
          'data-url' => Url::fromRoute('baas_project.admin.migrate')->toString(),
        ],
      ];
    }

    if ($migration_status['is_migrated']) {
      $build['#rollback_button'] = [
        '#type' => 'button',
        '#value' => $this->t('Rollback Migration'),
        '#attributes' => [
          'class' => ['button', 'button--danger', 'js-rollback-button'],
          'data-url' => Url::fromRoute('baas_project.admin.rollback')->toString(),
        ],
      ];
    }

    return $build;
  }

  /**
   * 执行迁移回滚。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return array|\Symfony\Component\HttpFoundation\JsonResponse
   *   渲染数组或JSON响应。
   */
  public function rollback(Request $request) {
    // 如果是AJAX请求，返回JSON响应
    if ($request->isXmlHttpRequest() || $request->getContentTypeFormat() === 'json') {
      return $this->executeRollback();
    }

    // 构建确认页面
    $build = [
      '#theme' => 'baas_project_admin_rollback',
      '#attached' => [
        'library' => ['baas_project/admin'],
      ],
    ];

    // 检查迁移状态
    $migration_status = $this->getMigrationStatus();
    $build['#migration_status'] = $migration_status;

    if ($migration_status['is_migrated']) {
      $build['#rollback_button'] = [
        '#type' => 'button',
        '#value' => $this->t('Confirm Rollback'),
        '#attributes' => [
          'class' => ['button', 'button--danger', 'js-rollback-confirm-button'],
          'data-url' => Url::fromRoute('baas_project.admin.rollback')->toString(),
        ],
      ];
    }

    return $build;
  }

  /**
   * 显示项目系统状态。
   *
   * @return array
   *   渲染数组。
   */
  public function status(): array {
    $build = [
      '#theme' => 'baas_project_admin_status',
      '#attached' => [
        'library' => ['baas_project/admin'],
      ],
    ];

    // 获取系统状态信息
    $status = $this->getSystemStatus();
    $build['#status'] = $status;

    // 添加导航链接
    $build['#navigation'] = [
      'migrate' => Link::fromTextAndUrl(
        $this->t('Migration Management'),
        Url::fromRoute('baas_project.admin.migrate')
      ),
      'rollback' => Link::fromTextAndUrl(
        $this->t('Rollback Migration'),
        Url::fromRoute('baas_project.admin.rollback')
      ),
    ];

    return $build;
  }

  /**
   * 执行数据迁移。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  protected function executeMigration(): JsonResponse {
    try {
      // 检查是否已经迁移
      if ($this->migrationService->isMigrated()) {
        return new JsonResponse([
          'success' => false,
          'error' => 'Migration has already been executed',
          'code' => 'ALREADY_MIGRATED',
        ], Response::HTTP_BAD_REQUEST);
      }

      // 执行迁移
      $result = $this->migrationService->executeMigration();

      $this->loggerFactory->get('baas_project_admin')->info('Project migration executed successfully: @result', [
        '@result' => json_encode($result),
      ]);

      return new JsonResponse([
        'success' => true,
        'data' => $result,
        'message' => 'Migration executed successfully',
      ]);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('baas_project_admin')->error('Error executing migration: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => 'MIGRATION_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 执行迁移回滚。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  protected function executeRollback(): JsonResponse {
    try {
      // 检查是否可以回滚
      if (!$this->migrationService->isMigrated()) {
        return new JsonResponse([
          'success' => false,
          'error' => 'No migration to rollback',
          'code' => 'NO_MIGRATION',
        ], Response::HTTP_BAD_REQUEST);
      }

      // 执行回滚
      $result = $this->migrationService->rollbackMigration();

      $this->loggerFactory->get('baas_project_admin')->info('Project migration rollback executed successfully: @result', [
        '@result' => json_encode($result),
      ]);

      return new JsonResponse([
        'success' => true,
        'data' => $result,
        'message' => 'Migration rollback executed successfully',
      ]);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('baas_project_admin')->error('Error executing rollback: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => 'ROLLBACK_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 获取迁移状态。
   *
   * @return array
   *   迁移状态信息。
   */
  protected function getMigrationStatus(): array {
    try {
      $is_migrated = $this->migrationService->isMigrated();
      $validation_result = $this->migrationService->validateMigration();

      return [
        'is_migrated' => $is_migrated,
        'validation' => $validation_result,
        'timestamp' => $is_migrated ? $this->getMigrationTimestamp() : null,
      ];
    }
    catch (\Exception $e) {
      return [
        'is_migrated' => false,
        'validation' => ['valid' => false, 'errors' => [$e->getMessage()]],
        'timestamp' => null,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * 获取系统状态。
   *
   * @return array
   *   系统状态信息。
   */
  protected function getSystemStatus(): array {
    try {
      $database = \Drupal::database();

      // 统计项目数量
      $project_count = $database->select('baas_project_config', 'p')
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      // 统计租户数量
      $tenant_count = $database->select('baas_tenant_config', 't')
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      // 统计项目成员数量
      $member_count = $database->select('baas_project_members', 'm')
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      // 统计实体模板数量
      $template_count = $database->select('baas_entity_template', 'e')
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      // 统计已迁移的实体模板数量
      $migrated_template_count = $database->select('baas_entity_template', 'e')
        ->condition('status', 1)
        ->isNotNull('project_id')
        ->countQuery()
        ->execute()
        ->fetchField();

      return [
        'migration_status' => $this->getMigrationStatus(),
        'statistics' => [
          'projects' => $project_count,
          'tenants' => $tenant_count,
          'members' => $member_count,
          'templates' => $template_count,
          'migrated_templates' => $migrated_template_count,
        ],
        'health_checks' => $this->performHealthChecks(),
      ];
    }
    catch (\Exception $e) {
      return [
        'error' => $e->getMessage(),
        'migration_status' => ['is_migrated' => false, 'validation' => ['valid' => false]],
        'statistics' => [],
        'health_checks' => [],
      ];
    }
  }

  /**
   * 执行健康检查。
   *
   * @return array
   *   健康检查结果。
   */
  protected function performHealthChecks(): array {
    $checks = [];

    try {
      $database = \Drupal::database();

      // 检查数据库表是否存在
      $required_tables = [
        'baas_project_config',
        'baas_project_members',
        'baas_project_usage',
      ];

      foreach ($required_tables as $table) {
        $exists = $database->schema()->tableExists($table);
        $checks['table_' . $table] = [
          'name' => "Table: {$table}",
          'status' => $exists ? 'pass' : 'fail',
          'message' => $exists ? 'Table exists' : 'Table missing',
        ];
      }

      // 检查项目ID字段是否已添加到实体模板表
      $project_id_field_exists = $database->schema()->fieldExists('baas_entity_template', 'project_id');
      $checks['entity_template_project_id'] = [
        'name' => 'Entity Template Project ID Field',
        'status' => $project_id_field_exists ? 'pass' : 'fail',
        'message' => $project_id_field_exists ? 'Project ID field exists' : 'Project ID field missing',
      ];

      // 检查是否有孤立的实体模板（没有项目ID）
      if ($project_id_field_exists) {
        $orphaned_templates = $database->select('baas_entity_template', 'e')
          ->condition('status', 1)
          ->isNull('project_id')
          ->countQuery()
          ->execute()
          ->fetchField();

        $checks['orphaned_templates'] = [
          'name' => 'Orphaned Entity Templates',
          'status' => $orphaned_templates == 0 ? 'pass' : 'warning',
          'message' => $orphaned_templates == 0 ? 'No orphaned templates' : "{$orphaned_templates} templates without project ID",
        ];
      }

      // 检查默认项目是否存在
      $tenants_without_default_project = $database->query("
        SELECT COUNT(*) FROM (
          SELECT t.tenant_id
          FROM {baas_tenant_config} t
          LEFT JOIN {baas_project_config} p ON t.tenant_id = p.tenant_id AND p.machine_name = 'default'
          WHERE t.status = 1 AND p.project_id IS NULL
        ) subquery
      ")->fetchField();

      $checks['default_projects'] = [
        'name' => 'Default Projects',
        'status' => $tenants_without_default_project == 0 ? 'pass' : 'warning',
        'message' => $tenants_without_default_project == 0 ? 'All tenants have default projects' : "{$tenants_without_default_project} tenants missing default projects",
      ];

    }
    catch (\Exception $e) {
      $checks['database_error'] = [
        'name' => 'Database Connection',
        'status' => 'fail',
        'message' => 'Database error: ' . $e->getMessage(),
      ];
    }

    return $checks;
  }

  /**
   * 获取迁移时间戳。
   *
   * @return int|null
   *   迁移时间戳。
   */
  protected function getMigrationTimestamp(): ?int {
    try {
      $state = \Drupal::state();
      return $state->get('baas_project.migration_timestamp');
    }
    catch (\Exception $e) {
      return null;
    }
  }

  /**
   * 获取项目统计信息。
   *
   * @return array
   *   包含统计数据的数组。
   */
  protected function getProjectStatistics(): array {
    try {
      $database = \Drupal::database();

      // 总项目数
      $total = $database->select('baas_project_config', 'p')
        ->countQuery()
        ->execute()
        ->fetchField();

      // 活跃项目数
      $active = $database->select('baas_project_config', 'p')
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      // 租户数
      $tenants = $database->select('baas_tenant_config', 't')
        ->countQuery()
        ->execute()
        ->fetchField();
        
      // 项目管理员数量 (拥有project_manager角色的用户)
      $project_managers = $database->select('user__roles', 'ur')
        ->condition('roles_target_id', 'project_manager')
        ->countQuery()
        ->execute()
        ->fetchField();

      return [
        'total' => (int) $total,
        'active' => (int) $active,
        'tenants' => (int) $tenants,
        'project_managers' => (int) $project_managers,
      ];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('baas_project')->error('Failed to get project statistics: @error', [
        '@error' => $e->getMessage(),
      ]);
      // 发生异常时也显示错误给用户
      \Drupal::messenger()->addError($this->t('统计数据获取失败: @error', ['@error' => $e->getMessage()]));
      return [
        'total' => 0,
        'active' => 0,
        'tenants' => 0,
        'project_managers' => 0,
      ];
    }
  }

  /**
   * 获取最近的项目列表。
   *
   * @param int $limit
   *   返回记录的数量限制。
   *
   * @return array
   *   项目数据数组。
   */
  protected function getRecentProjects(int $limit = 10): array {
    try {
      $database = \Drupal::database();

      if (!$database->schema()->tableExists('baas_project_config')) {
        return [];
      }

      $query = $database->select('baas_project_config', 'p')
        ->fields('p', ['project_id', 'name', 'tenant_id', 'status', 'owner_uid', 'created'])
        ->orderBy('created', 'DESC')
        ->range(0, $limit);

      $results = $query->execute()->fetchAll();

      $projects = [];
      foreach ($results as $result) {
        $owner_name = null;
        if ($result->owner_uid) {
          $user = \Drupal::entityTypeManager()->getStorage('user')->load((int) $result->owner_uid);
          if ($user) {
            $owner_name = $user->getDisplayName();
          }
        }

        $projects[] = [
          'project_id' => $result->project_id,
          'name' => $result->name,
          'tenant_id' => $result->tenant_id,
          'status' => (bool) $result->status,
          'owner_uid' => $result->owner_uid,
          'owner_name' => $owner_name,
          'created' => $result->created,
        ];
      }

      return $projects;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('baas_project')->error('Failed to get recent projects: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * 获取租户显示名称.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return string
   *   租户显示名称.
   */
  protected function getTenantDisplayName(string $tenant_id): string {
    try {
      $database = \Drupal::database();
      
      if (!$database->schema()->tableExists('baas_tenant_config')) {
        return $tenant_id;
      }
      
      $name = $database->select('baas_tenant_config', 't')
        ->fields('t', ['name'])
        ->condition('tenant_id', $tenant_id)
        ->execute()
        ->fetchField();
      
      return $name ?: $tenant_id;
    }
    catch (\Exception $e) {
      return $tenant_id;
    }
  }

  /**
   * 构建健康检查显示.
   *
   * @param array $health_checks
   *   健康检查结果.
   *
   * @return array
   *   渲染数组.
   */
  protected function buildHealthChecksDisplay(array $health_checks): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['health-checks']],
    ];
    
    if (empty($health_checks)) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('无法获取健康检查信息。') . '</p>',
      ];
      return $build;
    }
    
    foreach ($health_checks as $check_id => $check) {
      $status_class = 'health-check--' . $check['status'];
      $status_icon = $this->getHealthCheckIcon($check['status']);
      
      $build[$check_id] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['health-check-item', $status_class]],
        '#markup' => '<div class="health-check-content">
          <span class="health-check-icon">' . $status_icon . '</span>
          <div class="health-check-info">
            <span class="health-check-name">' . $check['name'] . '</span>
            <span class="health-check-message">' . $check['message'] . '</span>
          </div>
        </div>',
      ];
    }
    
    return $build;
  }

  /**
   * 获取健康检查图标.
   *
   * @param string $status
   *   检查状态.
   *
   * @return string
   *   图标HTML.
   */
  protected function getHealthCheckIcon(string $status): string {
    switch ($status) {
      case 'pass':
        return '✓';
      case 'warning':
        return '⚠';
      case 'fail':
        return '✗';
      default:
        return '?';
    }
  }

  /**
   * 显示所有项目列表.
   *
   * @return array
   *   渲染数组.
   */
  public function allProjects(): array {
    $build = [];
    $build['#attached']['library'][] = 'baas_project/admin';
    
    // 页面标题
    $build['header'] = [
      '#markup' => '<div class="admin-header">
        <h1>' . $this->t('所有项目') . '</h1>
        <p>' . $this->t('查看和管理系统中的所有项目。') . '</p>
      </div>',
    ];
    
    // 搜索和过滤
    $build['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['admin-filters']],
      'search' => [
        '#type' => 'search',
        '#title' => $this->t('搜索项目'),
        '#placeholder' => $this->t('输入项目名称或租户ID...'),
        '#attributes' => ['class' => ['admin-search']],
      ],
      'tenant_filter' => [
        '#type' => 'select',
        '#title' => $this->t('按租户过滤'),
        '#options' => $this->getTenantOptions(),
        '#empty_option' => $this->t('所有租户'),
        '#attributes' => ['class' => ['admin-filter']],
      ],
      'status_filter' => [
        '#type' => 'select',
        '#title' => $this->t('按状态过滤'),
        '#options' => [
          '1' => $this->t('活跃'),
          '0' => $this->t('已停用'),
        ],
        '#empty_option' => $this->t('所有状态'),
        '#attributes' => ['class' => ['admin-filter']],
      ],
    ];
    
    // 项目列表
    $all_projects = $this->getAllProjects();
    if (!empty($all_projects)) {
      $header = [
        $this->t('项目名称'),
        $this->t('机器名'),
        $this->t('租户'),
        $this->t('拥有者'),
        $this->t('状态'),
        $this->t('成员数'),
        $this->t('创建时间'),
        $this->t('操作'),
      ];
      
      $rows = [];
      foreach ($all_projects as $project) {
        $status_class = $project['status'] ? 'status-active' : 'status-inactive';
        $status_text = $project['status'] ? $this->t('活跃') : $this->t('已停用');
        
        $operations = [
          '#type' => 'container',
          '#attributes' => ['class' => ['admin-operations']],
          'view' => [
            '#type' => 'link',
            '#title' => $this->t('查看'),
            '#url' => Url::fromRoute('baas_project.user_view', [
              'tenant_id' => $project['tenant_id'],
              'project_id' => $project['project_id'],
            ]),
            '#attributes' => ['class' => ['button', 'button--small']],
          ],
          'edit' => [
            '#type' => 'link',
            '#title' => $this->t('编辑'),
            '#url' => Url::fromRoute('baas_project.user_edit', [
              'tenant_id' => $project['tenant_id'],
              'project_id' => $project['project_id'],
            ]),
            '#attributes' => ['class' => ['button', 'button--small']],
          ],
          'members' => [
            '#type' => 'link',
            '#title' => $this->t('成员'),
            '#url' => Url::fromRoute('baas_project.user_members', [
              'tenant_id' => $project['tenant_id'],
              'project_id' => $project['project_id'],
            ]),
            '#attributes' => ['class' => ['button', 'button--small']],
          ],
        ];
        
        $rows[] = [
          $project['name'],
          $project['machine_name'],
          $this->getTenantDisplayName($project['tenant_id']),
          $project['owner_name'] ?? $this->t('未知'),
          [
            'data' => [
              '#markup' => '<span class="project-status ' . $status_class . '">' . $status_text . '</span>',
            ],
          ],
          $project['member_count'] ?? 0,
          $this->dateFormatter->format($project['created'], 'short'),
          ['data' => $operations],
        ];
      }
      
      $build['projects_table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('暂无项目。'),
        '#attributes' => ['class' => ['admin-projects-table', 'admin-projects-table--full']],
      ];
    } else {
      $build['empty'] = [
        '#markup' => '<div class="empty-state">
          <h3>' . $this->t('暂无项目') . '</h3>
          <p>' . $this->t('系统中还没有创建任何项目。') . '</p>
        </div>',
      ];
    }
    
    return $build;
  }

  /**
   * 获取所有项目.
   *
   * @return array
   *   项目数组.
   */
  protected function getAllProjects(): array {
    try {
      $database = \Drupal::database();
      
      if (!$database->schema()->tableExists('baas_project_config')) {
        return [];
      }
      
      $query = $database->select('baas_project_config', 'p')
        ->fields('p', ['project_id', 'name', 'machine_name', 'tenant_id', 'status', 'owner_uid', 'created'])
        ->orderBy('created', 'DESC');
      
      $results = $query->execute()->fetchAll();
      
      $projects = [];
      foreach ($results as $result) {
        $owner_name = null;
        if ($result->owner_uid) {
          $user = \Drupal::entityTypeManager()->getStorage('user')->load((int) $result->owner_uid);
          if ($user) {
            $owner_name = $user->getDisplayName();
          }
        }
        
        // 获取项目成员数
        $member_count = 0;
        if ($database->schema()->tableExists('baas_project_members')) {
          $member_count = $database->select('baas_project_members', 'm')
            ->condition('project_id', $result->project_id)
            ->condition('status', 1)
            ->countQuery()
            ->execute()
            ->fetchField();
        }
        
        $projects[] = [
          'project_id' => $result->project_id,
          'name' => $result->name,
          'machine_name' => $result->machine_name,
          'tenant_id' => $result->tenant_id,
          'status' => (bool) $result->status,
          'owner_uid' => $result->owner_uid,
          'owner_name' => $owner_name,
          'member_count' => (int) $member_count,
          'created' => $result->created,
        ];
      }
      
      return $projects;
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('baas_project')->error('Failed to get all projects: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * 获取租户选项.
   *
   * @return array
   *   租户选项数组.
   */
  protected function getTenantOptions(): array {
    try {
      $database = \Drupal::database();
      
      if (!$database->schema()->tableExists('baas_tenant_config')) {
        return [];
      }
      
      $results = $database->select('baas_tenant_config', 't')
        ->fields('t', ['tenant_id', 'name'])
        ->orderBy('name')
        ->execute()
        ->fetchAll();
      
      $options = [];
      foreach ($results as $result) {
        $options[$result->tenant_id] = $result->name;
      }
      
      return $options;
    }
    catch (\Exception $e) {
      return [];
    }
  }

}