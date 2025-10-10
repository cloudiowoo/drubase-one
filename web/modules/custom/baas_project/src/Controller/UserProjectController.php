<?php

declare(strict_types=1);

namespace Drupal\baas_project\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_tenant\TenantManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * 用户项目管理控制器。
 */
class UserProjectController extends ControllerBase
{

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_project\ProjectManagerInterface $projectManager
   *   项目管理服务。
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenantManager
   *   租户管理服务。
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   日期格式化服务。
   */
  public function __construct(
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly TenantManagerInterface $tenantManager,
    protected readonly DateFormatterInterface $dateFormatter
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_project.manager'),
      $container->get('baas_tenant.manager'),
      $container->get('date.formatter')
    );
  }

  /**
   * 显示用户的所有项目列表。
   *
   * 重构后的逻辑：
   * 1. 检查用户是否是项目管理员
   * 2. 自动为项目管理员创建租户空间（如果不存在）
   * 3. 统一显示所有项目，不再按租户分组
   *
   * @return array
   *   渲染数组。
   */
  public function userProjectList(): array
  {
    $current_user = $this->currentUser();
    $user_id = (int) $current_user->id();

    // 加载用户实体
    $user_entity = $this->entityTypeManager()->getStorage('user')->load($user_id);

    // 检查用户是否是项目管理员
    $is_project_manager = baas_tenant_is_user_tenant($user_entity);

    // 检查用户是否是任何项目的成员
    $database = \Drupal::database();
    $is_project_member = FALSE;

    if ($database->schema()->tableExists('baas_project_members')) {
      $member_count = $database->select('baas_project_members', 'm')
        ->condition('user_id', $user_id)
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      $is_project_member = (bool) $member_count;
    }

    // 如果用户既不是项目管理员，也不是任何项目的成员，也没有管理权限，则拒绝访问
    if (!$is_project_manager && !$is_project_member && !$current_user->hasPermission('administer baas project')) {
      throw new AccessDeniedHttpException('您没有权限访问项目管理。');
    }

    // 获取用户的租户映射
    $user_tenants = [];

    if ($database->schema()->tableExists('baas_user_tenant_mapping')) {
      $tenant_mappings = $database->select('baas_user_tenant_mapping', 'm')
        ->fields('m', ['tenant_id'])
        ->condition('user_id', $user_id)
        ->condition('status', 1)
        ->execute()
        ->fetchCol();

      foreach ($tenant_mappings as $tenant_id) {
        $tenant = $this->tenantManager->getTenant($tenant_id);
        if ($tenant) {
          $user_tenants[] = $tenant;
        }
      }
    }

    // 如果没有租户但是项目管理员，显示引导信息
    if (empty($user_tenants) && $is_project_manager && !$is_project_member) {
      return [
        '#markup' => '<div class="empty-state">
          <h3>' . $this->t('欢迎使用项目管理') . '</h3>
          <p>' . $this->t('系统已为您分配项目管理员权限。请联系管理员完成租户空间配置，或者您可以直接开始创建项目。') . '</p>
          <div class="baas-project-actions">
            <a href="' . Url::fromRoute('baas_project.user_create_simple')->toString() . '" class="button button--primary">' . $this->t('创建我的第一个项目') . '</a>
          </div>
        </div>',
        '#attached' => ['library' => ['baas_project/user-projects']],
      ];
    }

    // 获取用户的所有项目（跨租户）
    $all_projects = $this->getUserAllProjects($user_id);

    return $this->buildUnifiedProjectsList($all_projects, $user_tenants);
  }

  /**
   * 获取用户的所有项目（跨租户）.
   *
   * @param int $user_id
   *   用户ID.
   *
   * @return array
   *   项目列表.
   */
  protected function getUserAllProjects(int $user_id): array
  {
    $database = \Drupal::database();
    $projects = [];

    // 检查项目配置表是否存在
    if (!$database->schema()->tableExists('baas_project_config')) {
      return [];
    }

    // 获取用户拥有的项目
    $owned_projects = $database->select('baas_project_config', 'p')
      ->fields('p')
      ->condition('owner_uid', $user_id)
      ->condition('status', 1)
      ->orderBy('updated', 'DESC')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($owned_projects as $project) {
      $project['user_role'] = 'owner';
      $project['settings'] = $project['settings'] ? json_decode($project['settings'], true) : [];
      $projects[] = $project;
    }

    // 获取用户作为成员的项目
    if ($database->schema()->tableExists('baas_project_members')) {
      $member_projects = $database->select('baas_project_members', 'm')
        ->fields('m', ['project_id', 'role'])
        ->condition('user_id', $user_id)
        ->condition('status', 1)
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      foreach ($member_projects as $member) {
        $project = $database->select('baas_project_config', 'p')
          ->fields('p')
          ->condition('project_id', $member['project_id'])
          ->condition('status', 1)
          ->execute()
          ->fetch(\PDO::FETCH_ASSOC);

        if ($project) {
          $project['user_role'] = $member['role'];
          $project['settings'] = $project['settings'] ? json_decode($project['settings'], true) : [];
          $projects[] = $project;
        }
      }
    }

    return $projects;
  }

  /**
   * 构建统一的项目列表显示.
   *
   * @param array $projects
   *   项目列表.
   * @param array $user_tenants
   *   用户的租户列表.
   *
   * @return array
   *   渲染数组.
   */
  protected function buildUnifiedProjectsList(array $projects, array $user_tenants): array
  {
    $build = [];
    $build['#attached']['library'][] = 'baas_project/user-projects';

    // 页面标题和描述
    $build['header'] = [
      '#markup' => '<div class=\"project-header\">
        <h2>' . $this->t('我的项目') . '</h2>
        <p>' . $this->t('管理您创建和参与的所有项目。') . '</p>
      </div>',
    ];

    // 快速操作按钮
    $build['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['project-quick-actions']],
      'create' => [
        '#type' => 'link',
        '#title' => $this->t('创建新项目'),
        '#url' => Url::fromRoute('baas_project.user_create_simple'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'api_keys' => [
        '#type' => 'link',
        '#title' => $this->t('API密钥管理'),
        '#url' => Url::fromRoute('baas_tenant.user_api_keys'),
        '#attributes' => ['class' => ['button', 'button--secondary']],
      ],
    ];

    if (empty($projects)) {
      $build['empty'] = [
        '#markup' => '<div class=\"empty-state\">
          <h3>' . $this->t('还没有项目') . '</h3>
          <p>' . $this->t('创建您的第一个项目来开始使用。') . '</p>
        </div>',
      ];
    } else {
      $build['projects'] = $this->buildProjectGrid($projects, $user_tenants);
    }

    return $build;
  }

  /**
   * 构建项目网格显示.
   *
   * @param array $projects
   *   项目列表.
   * @param array $user_tenants
   *   用户租户列表.
   *
   * @return array
   *   渲染数组.
   */
  protected function buildProjectGrid(array $projects, array $user_tenants): array
  {
    $build = [];

    // 按租户分组项目
    $tenant_names = [];
    foreach ($user_tenants as $tenant) {
      $tenant_names[$tenant['tenant_id']] = $tenant['name'];
    }

    $build['#type'] = 'container';
    $build['#attributes'] = ['class' => ['projects-grid']];

    foreach ($projects as $project) {
      $tenant_name = $tenant_names[$project['tenant_id']] ?? $project['tenant_id'];
      $settings = $project['settings'] ?? [];

      $build[$project['project_id']] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['project-card']],
        'content' => [
          '#type' => 'container',
          'header' => [
            '#markup' => '<div class=\"project-card-header\">
              <h3>' . $project['name'] . '</h3>
              <span class=\"project-role\">' . $this->formatUserRole($project['user_role']) . '</span>
            </div>',
          ],
          'info' => [
            '#markup' => '<div class=\"project-info\">
              <p class=\"project-description\">' . ($project['description'] ?: $this->t('无描述')) . '</p>
              <div class=\"project-meta\">
                <span class=\"tenant-info\">' . $this->t('租户：@name', ['@name' => $tenant_name]) . '</span>
                <span class=\"project-updated\">' . $this->t('更新：@date', ['@date' => $this->dateFormatter->format($project['updated'], 'short')]) . '</span>
              </div>
            </div>',
          ],
          'actions' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['project-actions']],
            'entities' => [
              '#type' => 'link',
              '#title' => $this->t('实体'),
              '#url' => Url::fromRoute('baas_project.entities', [
                'tenant_id' => $project['tenant_id'],
                'project_id' => $project['project_id'],
              ]),
              '#attributes' => ['class' => ['button', 'button--small', 'button--primary']],
            ],
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
          ] + $this->buildProjectDeleteAction($project),
        ],
      ];
    }

    return $build;
  }

  /**
   * 构建项目删除操作。
   *
   * @param array $project
   *   项目信息数组。
   *
   * @return array
   *   删除操作数组，如果用户无权限则返回空数组。
   */
  protected function buildProjectDeleteAction(array $project): array
  {
    $current_user = $this->currentUser();
    $user_role = $project['user_role'] ?? '';
    
    // 检查用户是否有删除项目的权限
    $has_permission = false;
    
    // 1. 检查系统级权限
    if ($current_user->hasPermission('delete baas project')) {
      $has_permission = true;
    }
    
    // 2. 检查项目级权限（只有项目拥有者可以删除项目）
    if ($user_role === 'owner') {
      $has_permission = true;
    }
    
    // 3. 检查租户管理员权限
    if ($current_user->hasPermission('administer baas project')) {
      $has_permission = true;
    }
    
    if (!$has_permission) {
      return [];
    }
    
    return [
      'delete' => [
        '#type' => 'link',
        '#title' => $this->t('删除'),
        '#url' => Url::fromRoute('baas_project.user_delete', [
          'tenant_id' => $project['tenant_id'],
          'project_id' => $project['project_id'],
        ]),
        '#attributes' => [
          'class' => ['button', 'button--small', 'button--danger'],
          'onclick' => 'return confirm("' . $this->t('确定要删除此项目吗？此操作不可撤销。') . '")',
        ],
      ],
    ];
  }

  /**
   * 格式化用户角色显示.
   *
   * @param string $role
   *   用户角色.
   *
   * @return string
   *   格式化的角色名称.
   */
  protected function formatUserRole(string $role): string
  {
    $role_labels = [
      'owner' => $this->t('拥有者'),
      'admin' => $this->t('管理员'),
      'editor' => $this->t('编辑者'),
      'viewer' => $this->t('查看者'),
      'member' => $this->t('成员'),
    ];

    $label = $role_labels[$role] ?? $role;
    return is_string($label) ? $label : (string) $label;
  }

  /**
   * 构建租户项目列表显示.
   *
   * @deprecated 使用 buildUnifiedProjectsList 替代
   * @param array $user_tenants
   *   用户的租户列表.
   *
   * @return array
   *   渲染数组.
   */
  protected function buildTenantProjectsList(array $user_tenants): array
  {
    $build = [];
    $build['#attached']['library'][] = 'baas_project/user-projects';

    $build['description'] = [
      '#markup' => '<p>' . $this->t('这里显示您在各个租户下创建和管理的项目。') . '</p>',
    ];

    // 为每个租户显示项目
    foreach ($user_tenants as $tenant) {
      $tenant_id = $tenant['tenant_id'];
      $projects = $this->projectManager->listTenantProjects($tenant_id);

      $build[$tenant_id] = [
        '#type' => 'details',
        '#title' => $this->t('租户：@name', ['@name' => $tenant['name']]),
        '#open' => TRUE,
      ];

      if (empty($projects)) {
        $build[$tenant_id]['empty'] = [
          '#markup' => '<p>' . $this->t('此租户下还没有项目。') . '</p>',
        ];
      } else {
        $build[$tenant_id]['table'] = $this->buildProjectTable($projects, $tenant_id);
      }

      // 添加创建项目链接
      $build[$tenant_id]['actions'] = [
        '#type' => 'actions',
        'create' => [
          '#type' => 'link',
          '#title' => $this->t('创建项目'),
          '#url' => Url::fromRoute('baas_project.user_create', ['tenant_id' => $tenant_id]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
      ];
    }

    return $build;
  }

  /**
   * 获取项目的实体数量.
   *
   * @param string $project_id
   *   项目ID.
   *
   * @return int
   *   实体数量.
   */
  protected function getProjectEntityCount($project_id)
  {
    $database = \Drupal::database();

    // 检查baas_entity_template表是否存在
    if (!$database->schema()->tableExists('baas_entity_template')) {
      return 0;
    }

    return (int) $database->select('baas_entity_template', 'e')
      ->condition('project_id', $project_id)
      ->condition('status', 1)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * 显示指定租户下的项目列表。
   *
   * @param string $tenant_id
   *   租户ID。
   *
   * @return array
   *   渲染数组。
   */
  public function tenantProjectList(string $tenant_id): array
  {
    // 验证用户对租户的访问权限
    if (!$this->checkTenantAccess($tenant_id)) {
      throw new AccessDeniedHttpException();
    }

    $tenant = $this->tenantManager->getTenant($tenant_id);
    if (!$tenant) {
      throw new NotFoundHttpException();
    }

    $projects = $this->projectManager->listTenantProjects($tenant_id);

    $build = [];
    $build['#attached']['library'][] = 'baas_project/user-projects';

    $build['header'] = [
      '#markup' => '<h2>' . $this->t('租户 "@name" 的项目管理', ['@name' => $tenant['name']]) . '</h2>',
    ];

    if (empty($projects)) {
      $build['empty'] = [
        '#markup' => '<div class="empty-state">
          <h3>' . $this->t('还没有项目') . '</h3>
          <p>' . $this->t('创建您的第一个项目来开始使用。') . '</p>
        </div>',
      ];
    } else {
      $build['table'] = $this->buildProjectTable($projects, $tenant_id);
    }

    // 操作按钮
    $build['actions'] = [
      '#type' => 'actions',
      'create' => [
        '#type' => 'link',
        '#title' => $this->t('创建项目'),
        '#url' => Url::fromRoute('baas_project.user_create', ['tenant_id' => $tenant_id]),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('返回项目列表'),
        '#url' => Url::fromRoute('baas_project.user_list'),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    return $build;
  }

  /**
   * 构建项目表格。
   *
   * @param array $projects
   *   项目列表。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return array
   *   表格渲染数组。
   */
  protected function buildProjectTable(array $projects, string $tenant_id): array
  {
    $header = [
      $this->t('项目名称'),
      $this->t('机器名'),
      $this->t('描述'),
      $this->t('状态'),
      $this->t('创建时间'),
      $this->t('操作'),
    ];

    $rows = [];
    foreach ($projects as $project) {
      $settings = $project['settings'] ?? [];
      $is_default = $settings['is_default'] ?? false;

      $status_text = $project['status'] ? $this->t('启用') : $this->t('禁用');
      if ($is_default) {
        $status_text .= ' (' . $this->t('默认') . ')';
      }

      $operations = [];

      // 查看项目
      $operations['view'] = [
        '#type' => 'link',
        '#title' => $this->t('查看'),
        '#url' => Url::fromRoute('baas_project.user_view', [
          'tenant_id' => $tenant_id,
          'project_id' => $project['project_id'],
        ]),
      ];

      // 编辑项目
      $operations['edit'] = [
        '#type' => 'link',
        '#title' => $this->t('编辑'),
        '#url' => Url::fromRoute('baas_project.user_edit', [
          'tenant_id' => $tenant_id,
          'project_id' => $project['project_id'],
        ]),
      ];

      // 成员管理
      $operations['members'] = [
        '#type' => 'link',
        '#title' => $this->t('成员'),
        '#url' => Url::fromRoute('baas_project.user_members', [
          'tenant_id' => $tenant_id,
          'project_id' => $project['project_id'],
        ]),
      ];

      // 默认项目不能删除
      if (!$is_default) {
        $operations['delete'] = [
          '#type' => 'link',
          '#title' => $this->t('删除'),
          '#url' => Url::fromRoute('baas_project.user_delete', [
            'tenant_id' => $tenant_id,
            'project_id' => $project['project_id'],
          ]),
          '#attributes' => [
            'class' => ['button', 'button--danger', 'button--small'],
          ],
        ];
      }

      $rows[] = [
        $project['name'],
        $project['machine_name'],
        $project['description'] ?: $this->t('无描述'),
        $status_text,
        $this->dateFormatter->format($project['created']),
        [
          'data' => [
            '#type' => 'operations',
            '#links' => $operations,
          ],
        ],
      ];
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('没有找到项目。'),
    ];
  }

  /**
   * 检查用户对租户的访问权限。
   *
   * @param string $tenant_id
   *   租户ID。
   *
   * @return bool
   *   是否有权限。
   */
  protected function checkTenantAccess(string $tenant_id): bool
  {
    $current_user = $this->currentUser();

    // 管理员有全部权限
    if ($current_user->hasPermission('administer baas tenants')) {
      return true;
    }

    $database = \Drupal::database();

    // 检查用户是否属于此租户
    $mapping = $database->select('baas_user_tenant_mapping', 'm')
      ->condition('user_id', (int) $current_user->id())
      ->condition('tenant_id', $tenant_id)
      ->condition('status', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($mapping) {
      return true;
    }

    // 如果不是租户成员，检查是否为租户下任何项目的成员
    if (
      $database->schema()->tableExists('baas_project_members') &&
      $database->schema()->tableExists('baas_project_config')
    ) {

      $query = $database->select('baas_project_members', 'm');
      $query->leftJoin('baas_project_config', 'p', 'm.project_id = p.project_id');
      $query->condition('m.user_id', (int) $current_user->id())
        ->condition('p.tenant_id', $tenant_id)
        ->condition('m.status', 1)
        ->condition('p.status', 1);

      $project_member_count = $query->countQuery()
        ->execute()
        ->fetchField();

      return (bool) $project_member_count;
    }

    return false;
  }

  /**
   * 显示项目详情。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   渲染数组。
   */
  public function viewProject(string $tenant_id, string $project_id): array
  {
    // 获取当前用户ID
    $current_user = $this->currentUser();
    $user_id = (int) $current_user->id();

    // 验证租户权限
    if (!$this->checkTenantAccess($tenant_id)) {
      // 检查是否是项目成员，即使不是租户成员
      if (!$this->projectManager->isProjectMember($project_id, $user_id)) {
        throw new AccessDeniedHttpException('您没有权限访问此项目。');
      }
    }

    $project = $this->projectManager->getProject($project_id);
    if (!$project || $project['tenant_id'] !== $tenant_id) {
      throw new NotFoundHttpException('找不到项目或项目不属于指定租户。');
    }

    // 检查用户是否是项目成员
    $is_project_member = $this->projectManager->isProjectMember($project_id, $user_id);
    if (!$is_project_member && !$current_user->hasPermission('administer baas project')) {
      throw new AccessDeniedHttpException('您不是此项目的成员。');
    }

    $tenant = $this->tenantManager->getTenant($tenant_id);
    $settings = $project['settings'] ?? [];

    $build = [];
    $build['#attached']['library'][] = 'baas_project/user-projects';

    // 项目基本信息
    $build['basic_info'] = [
      '#type' => 'details',
      '#title' => $this->t('基本信息'),
      '#open' => TRUE,
    ];

    $build['basic_info']['info'] = [
      '#type' => 'table',
      '#rows' => [
        [$this->t('项目ID'), $project['project_id']],
        [$this->t('项目名称'), $project['name']],
        [$this->t('机器名'), $project['machine_name']],
        [$this->t('所属租户'), $tenant['name'] . ' (' . $tenant_id . ')'],
        [$this->t('状态'), $project['status'] ? $this->t('启用') : $this->t('禁用')],
        [$this->t('描述'), $project['description'] ?: $this->t('无描述')],
        [$this->t('创建时间'), $this->dateFormatter->format($project['created'])],
        [$this->t('更新时间'), $this->dateFormatter->format($project['updated'])],
      ],
    ];

    // 项目设置
    if (!empty($settings)) {
      $build['settings'] = [
        '#type' => 'details',
        '#title' => $this->t('项目设置'),
        '#open' => FALSE,
      ];

      $settings_rows = [];
      if (isset($settings['visibility'])) {
        $visibility_labels = [
          'private' => $this->t('私有项目'),
          'tenant' => $this->t('租户内可见'),
          'public' => $this->t('公开项目'),
        ];
        $settings_rows[] = [$this->t('可见性'), $visibility_labels[$settings['visibility']] ?? $settings['visibility']];
      }

      if (isset($settings['max_entities'])) {
        $settings_rows[] = [$this->t('最大实体数'), $settings['max_entities']];
      }

      if (isset($settings['max_storage'])) {
        $settings_rows[] = [$this->t('最大存储空间'), $settings['max_storage'] . ' MB'];
      }

      if (isset($settings['max_api_calls'])) {
        $settings_rows[] = [$this->t('每日API调用限制'), $settings['max_api_calls']];
      }

      $build['settings']['table'] = [
        '#type' => 'table',
        '#rows' => $settings_rows,
      ];
    }

    // 获取用户在项目中的角色
    $user_role = $this->projectManager->getUserProjectRole($project_id, $user_id);

    // 操作按钮
    $build['actions'] = [
      '#type' => 'actions',
      'entities' => [
        '#type' => 'link',
        '#title' => $this->t('实体管理'),
        '#url' => Url::fromRoute('baas_project.entities', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
        ]),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'edit' => [
        '#type' => 'link',
        '#title' => $this->t('编辑项目'),
        '#url' => Url::fromRoute('baas_project.user_edit', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
        ]),
        '#attributes' => ['class' => ['button']],
      ],
      'members' => [
        '#type' => 'link',
        '#title' => $this->t('管理成员'),
        '#url' => Url::fromRoute('baas_project.user_members', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
        ]),
        '#attributes' => ['class' => ['button']],
      ],
      'resource_limits' => [
        '#type' => 'link',
        '#title' => $this->t('资源限制配置'),
        '#url' => Url::fromRoute('baas_project.resource_limits_simple', [
          'project_id' => $project_id,
        ]),
        '#attributes' => ['class' => ['button']],
      ],
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('返回项目列表'),
        '#url' => Url::fromRoute('baas_project.user_list'),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    // 添加项目统计信息
    $entity_count = $this->getProjectEntityCount($project_id);
    $build['statistics'] = [
      '#type' => 'details',
      '#title' => $this->t('项目统计'),
      '#open' => TRUE,
    ];

    $build['statistics']['stats'] = [
      '#type' => 'table',
      '#rows' => [
        [$this->t('实体数量'), $entity_count],
        [$this->t('您的角色'), $this->formatUserRole($user_role ?: 'viewer')],
        [$this->t('项目拥有者'), $this->getUserDisplayName((int) $project['owner_uid'])],
      ],
    ];

    return $build;
  }

  /**
   * 获取用户显示名称。
   *
   * @param int $uid
   *   用户ID。
   *
   * @return string
   *   用户显示名称。
   */
  protected function getUserDisplayName(int $uid): string
  {
    /** @var \Drupal\user\UserInterface $user */
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    if ($user) {
      return $user->getAccountName();
    }
    return (string) $this->t('未知用户');
  }
}
