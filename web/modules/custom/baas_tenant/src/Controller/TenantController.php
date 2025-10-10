<?php

namespace Drupal\baas_tenant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_tenant\TenantManagerInterface;
use Drupal\baas_tenant\Service\TenantPermissionChecker;
use Drupal\baas_auth\Service\UserTenantMappingInterface;

/**
 * 租户管理控制器.
 */
class TenantController extends ControllerBase
{

  /**
   * 租户管理服务.
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected $tenantManager;

  /**
   * 日期格式化服务.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * 租户权限检查服务.
   *
   * @var \Drupal\baas_tenant\Service\TenantPermissionChecker
   */
  protected $permissionChecker;

  /**
   * 用户-租户映射服务.
   *
   * @var \Drupal\baas_auth\Service\UserTenantMappingInterface
   */
  protected $userTenantMapping;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('baas_tenant.manager'),
      $container->get('date.formatter'),
      $container->get('baas_tenant.permission_checker'),
      $container->get('baas_auth.user_tenant_mapping')
    );
  }

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理服务.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   日期格式化服务.
   * @param \Drupal\baas_tenant\Service\TenantPermissionChecker $permission_checker
   *   租户权限检查服务.
   * @param \Drupal\baas_auth\Service\UserTenantMappingInterface $user_tenant_mapping
   *   用户-租户映射服务.
   */
  public function __construct(
    TenantManagerInterface $tenant_manager,
    DateFormatterInterface $date_formatter,
    TenantPermissionChecker $permission_checker,
    UserTenantMappingInterface $user_tenant_mapping,
  ) {
    $this->tenantManager = $tenant_manager;
    $this->dateFormatter = $date_formatter;
    $this->permissionChecker = $permission_checker;
    $this->userTenantMapping = $user_tenant_mapping;
  }

  /**
   * BaaS平台管理概览页面.
   *
   * @return array
   *   渲染数组.
   */
  public function adminOverview() {
    $build = [];
    
    $build['header'] = [
      '#markup' => '<h2>' . $this->t('BaaS平台管理') . '</h2>',
    ];
    
    $build['description'] = [
      '#markup' => '<p>' . $this->t('Backend-as-a-Service平台管理中心，您可以在这里管理租户、实体、项目和API设置。') . '</p>',
    ];
    
    // 管理菜单
    $menu_items = [
      [
        'title' => $this->t('租户管理'),
        'description' => $this->t('管理系统中的所有租户'),
        'url' => Url::fromRoute('baas_tenant.list'),
      ],
      [
        'title' => $this->t('实体管理'),
        'description' => $this->t('管理动态实体模板'),
        'url' => Url::fromRoute('baas_entity.list'),
      ],
      [
        'title' => $this->t('项目管理'),
        'description' => $this->t('管理租户下的项目'),
        'url' => Url::fromRoute('baas_project.admin.list'),
      ],
      [
        'title' => $this->t('API设置'),
        'description' => $this->t('配置API相关设置'),
        'url' => Url::fromRoute('baas_api.settings'),
      ],
    ];
    
    $build['menu'] = [
      '#theme' => 'item_list',
      '#items' => [],
    ];
    
    foreach ($menu_items as $item) {
      if ($item['url']->access()) {
        $build['menu']['#items'][] = [
          '#markup' => '<strong>' . $item['url']->toString() . '</strong><br>' . $item['description'],
        ];
      }
    }
    
    return $build;
  }

  /**
   * 显示租户列表页面（系统管理员视图）.
   *
   * @return array
   *   渲染数组.
   */
  public function listTenants()
  {
    $tenants = $this->tenantManager->listTenants();

    // 添加说明信息.
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['tenant-admin-list']],
    ];

    $build['info'] = [
      '#type' => 'item',
      '#markup' => '<div class="messages messages--info">' .
        $this->t('租户管理界面用于查看和管理现有租户。新租户通过用户提权系统自动创建。') .
        '</div>',
    ];

    // 创建表头.
    $header = [
      'id' => $this->t('租户ID'),
      'name' => $this->t('名称'),
      'owner' => $this->t('所有者'),
      'creation_method' => $this->t('创建方式'),
      'status' => $this->t('状态'),
      'created' => $this->t('创建时间'),
      'operations' => $this->t('操作'),
    ];

    // 创建行数据.
    $rows = [];
    foreach ($tenants as $tenant) {
      $row = [];
      $row['id'] = $tenant['tenant_id'];
      $row['name'] = $tenant['name'];

      // 获取租户所有者信息.
      $owner = \Drupal::entityTypeManager()->getStorage('user')->load($tenant['owner_uid'] ?? 1);
      $row['owner'] = ($owner !== NULL) ? $owner->name->value : $this->t('未知');

      // 获取租户创建方式.
      $created_by_promotion = isset($tenant['settings']['created_by_promotion']) && $tenant['settings']['created_by_promotion'];
      $row['creation_method'] = $created_by_promotion ? $this->t('用户提权') : $this->t('手动创建');

      $row['status'] = $tenant['status'] ? $this->t('启用') : $this->t('禁用');
      $row['created'] = $this->dateFormatter->format($tenant['created'], 'short');

      // 操作链接（仅编辑设置）.
      $operations = [];
      $operations['edit'] = [
        'title' => $this->t('编辑设置'),
        'url' => Url::fromRoute('baas_tenant.edit', ['tenant_id' => $tenant['tenant_id']]),
      ];

      // 仅对手动创建的租户显示删除选项.
      if (!$created_by_promotion) {
        $operations['delete'] = [
          'title' => $this->t('删除'),
          'url' => Url::fromRoute('baas_tenant.delete', ['tenant_id' => $tenant['tenant_id']]),
          'attributes' => [
            'onclick' => 'return confirm("确定删除此租户吗？");',
          ],
        ];
      }

      // 添加API密钥管理操作.
      $operations['api_key'] = [
        'title' => $this->t('API密钥'),
        'url' => Url::fromRoute('baas_tenant.api_key', ['tenant_id' => $tenant['tenant_id']]),
      ];

      $row['operations'] = [
        'data' => [
          '#type' => 'operations',
          '#links' => $operations,
        ],
      ];

      $rows[] = $row;
    }

    // 构建表格.
    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('暂无租户'),
    ];

    // 添加创建新租户按钮（仅用于特殊情况）.
    $build['add_tenant'] = [
      '#type' => 'link',
      '#title' => $this->t('手动创建租户'),
      '#url' => Url::fromRoute('baas_tenant.add'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
      '#prefix' => '<div class="baas-tenant-actions"><p>' . $this->t('注意：通常情况下，租户通过用户提权系统自动创建。仅在特殊情况下使用手动创建。') . '</p>',
      '#suffix' => '</div>',
    ];

    return $build;
  }

  /**
   * 已废弃：显示用户自己的租户列表.
   *
   * 根据架构重构，用户应直接管理项目而非租户。
   * 此方法保留以防向后兼容需要，但不应在新的路由中使用。
   *
   * @deprecated 用户应使用项目管理界面而非租户列表
   *
   * @return array
   *   渲染数组.
   */
  public function userTenantList()
  {
    $account = $this->currentUser();
    $tenants = $this->permissionChecker->getUserAccessibleTenants($account);

    // 创建表头.
    $header = [
      'name' => $this->t('租户名称'),
      'role' => $this->t('我的角色'),
      'status' => $this->t('状态'),
      'created' => $this->t('加入时间'),
      'operations' => $this->t('操作'),
    ];

    // 创建行数据.
    $rows = [];
    foreach ($tenants as $tenant) {
      $row = [];
      $row['name'] = $tenant['name'] ?? $tenant['tenant_id'];
      $row['role'] = $this->formatUserRole($tenant['role'] ?? '', $tenant['is_owner'] ?? FALSE);
      $row['status'] = $tenant['status'] ? $this->t('活跃') : $this->t('已禁用');
      $row['created'] = $this->dateFormatter->format($tenant['created'], 'short');

      // 操作链接.
      $operations = [];

      // 查看权限.
      if ($this->permissionChecker->canViewTenant($account, $tenant['tenant_id'])) {
        $operations['view'] = [
          'title' => $this->t('查看'),
          'url' => Url::fromRoute('baas_tenant.view', ['tenant_id' => $tenant['tenant_id']]),
        ];
      }

      // 编辑权限（仅所有者）.
      if ($this->permissionChecker->canEditTenant($account, $tenant['tenant_id'])) {
        $operations['edit'] = [
          'title' => $this->t('编辑'),
          'url' => Url::fromRoute('baas_tenant.edit', ['tenant_id' => $tenant['tenant_id']]),
        ];
      }

      // 成员管理权限.
      if ($this->permissionChecker->canManageTenantMembers($account, $tenant['tenant_id'])) {
        $operations['members'] = [
          'title' => $this->t('成员管理'),
          'url' => Url::fromRoute('baas_tenant.manage_members', ['tenant_id' => $tenant['tenant_id']]),
        ];
      }

      // API密钥管理权限.
      if ($this->permissionChecker->canManageTenantApiKeys($account, $tenant['tenant_id'])) {
        $operations['api_key'] = [
          'title' => $this->t('API密钥'),
          'url' => Url::fromRoute('baas_tenant.api_key', ['tenant_id' => $tenant['tenant_id']]),
        ];
      }

      // 项目管理权限.
      if ($account->hasPermission('view baas project')) {
        $operations['projects'] = [
          'title' => $this->t('项目管理'),
          'url' => Url::fromRoute('baas_project.user_tenant_projects', ['tenant_id' => $tenant['tenant_id']]),
        ];
      }

      $row['operations'] = [
        'data' => [
          '#type' => 'operations',
          '#links' => $operations,
        ],
      ];

      $rows[] = $row;
    }

    // 构建表格.
    $build['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('您还没有任何租户'),
    ];

    // 添加创建新租户按钮（如果有权限）.
    if ($this->permissionChecker->canCreateTenant($account)) {
      $build['add_tenant'] = [
        '#type' => 'link',
        '#title' => $this->t('创建新租户'),
        '#url' => Url::fromRoute('baas_tenant.user_add'),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
        '#prefix' => '<div class="baas-tenant-actions">',
        '#suffix' => '</div>',
      ];
    }

    return $build;
  }

  /**
   * 显示租户详情页面.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   渲染数组或重定向响应.
   */
  public function viewTenant($tenant_id)
  {
    $tenant = $this->tenantManager->getTenant($tenant_id);

    if (!$tenant) {
      $this->messenger()->addError($this->t('租户不存在'));
      return $this->redirect('baas_tenant.list');
    }

    $build = [];

    $build['tenant_info'] = [
      '#type' => 'details',
      '#title' => $this->t('租户信息'),
      '#open' => TRUE,
    ];

    $build['tenant_info']['id'] = [
      '#type' => 'item',
      '#title' => $this->t('租户ID'),
      '#markup' => $tenant['tenant_id'],
    ];

    $build['tenant_info']['name'] = [
      '#type' => 'item',
      '#title' => $this->t('名称'),
      '#markup' => $tenant['name'],
    ];

    $build['tenant_info']['status'] = [
      '#type' => 'item',
      '#title' => $this->t('状态'),
      '#markup' => $tenant['status'] ? $this->t('启用') : $this->t('禁用'),
    ];

    $build['tenant_info']['created'] = [
      '#type' => 'item',
      '#title' => $this->t('创建时间'),
      '#markup' => $this->dateFormatter->format($tenant['created'], 'long'),
    ];

    $build['tenant_info']['updated'] = [
      '#type' => 'item',
      '#title' => $this->t('更新时间'),
      '#markup' => $this->dateFormatter->format($tenant['updated'], 'long'),
    ];

    // 租户设置信息.
    $build['tenant_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('租户设置'),
      '#open' => TRUE,
    ];

    $settings = $tenant['settings'];

    $build['tenant_settings']['max_entities'] = [
      '#type' => 'item',
      '#title' => $this->t('最大实体数量'),
      '#markup' => $settings['max_entities'] ?? '100',
    ];

    $build['tenant_settings']['max_storage'] = [
      '#type' => 'item',
      '#title' => $this->t('最大存储空间 (MB)'),
      '#markup' => $settings['max_storage'] ?? '1024',
    ];

    $build['tenant_settings']['max_requests'] = [
      '#type' => 'item',
      '#title' => $this->t('最大每日请求数'),
      '#markup' => $settings['max_requests'] ?? '10000',
    ];

    // 添加操作按钮.
    $account = $this->currentUser();
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['baas-tenant-actions'],
      ],
    ];

    if ($this->permissionChecker->canEditTenant($account, $tenant_id)) {
      $build['actions']['edit'] = [
        '#type' => 'link',
        '#title' => $this->t('编辑'),
        '#url' => Url::fromRoute('baas_tenant.edit', ['tenant_id' => $tenant_id]),
        '#attributes' => [
          'class' => ['button'],
        ],
      ];
    }

    if ($this->permissionChecker->canManageTenantApiKeys($account, $tenant_id)) {
      $build['actions']['api_key'] = [
        '#type' => 'link',
        '#title' => $this->t('管理API密钥'),
        '#url' => Url::fromRoute('baas_tenant.api_key', ['tenant_id' => $tenant_id]),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ];
    }

    if ($this->permissionChecker->canDeleteTenant($account, $tenant_id)) {
      $build['actions']['delete'] = [
        '#type' => 'link',
        '#title' => $this->t('删除'),
        '#url' => Url::fromRoute('baas_tenant.delete', ['tenant_id' => $tenant_id]),
        '#attributes' => [
          'class' => ['button', 'button--danger'],
        ],
      ];
    }

    $build['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('返回管理列表'),
      '#url' => Url::fromRoute('baas_tenant.admin_list'),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $build;
  }

  /**
   * 显示租户成员列表.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   渲染数组或重定向响应.
   */
  public function listMembers($tenant_id)
  {
    $tenant = $this->tenantManager->getTenant($tenant_id);
    if (!$tenant) {
      $this->messenger()->addError($this->t('租户不存在'));
      return $this->redirect('baas_tenant.admin_list');
    }

    // 获取租户成员列表.
    $members = $this->userTenantMapping->getTenantUsers($tenant_id);

    $build['info'] = [
      '#markup' => '<h2>' . $this->t('租户 @name 的成员', ['@name' => $tenant['name']]) . '</h2>',
    ];

    // 创建表头.
    $header = [
      'username' => $this->t('用户名'),
      'email' => $this->t('邮箱'),
      'role' => $this->t('角色'),
      'joined' => $this->t('加入时间'),
      'operations' => $this->t('操作'),
    ];

    // 创建行数据.
    $rows = [];
    foreach ($members as $member) {
      $row = [];
      $row['username'] = $member['username'] ?? $this->t('未知用户');
      $row['email'] = $member['email'] ?? '';
      $row['role'] = $this->formatUserRole($member['role'], $member['is_owner']);
      $row['joined'] = $this->dateFormatter->format($member['created'], 'short');

      // 操作链接.
      $operations = [];

      // 检查当前用户是否有管理成员的权限.
      $current_user = $this->currentUser();
      if ($this->permissionChecker->canManageTenantMembers($current_user, $tenant_id)) {
        // 不能对自己进行操作.
        if ($member['user_id'] != (int) $current_user->id()) {
          $operations['edit'] = [
            'title' => $this->t('编辑角色'),
            'url' => Url::fromRoute('baas_tenant.manage_members', [
              'tenant_id' => $tenant_id,
            ], [
              'query' => ['user_id' => $member['user_id']],
            ]),
          ];

          // 不能移除所有者.
          if (!$member['is_owner']) {
            $operations['remove'] = [
              'title' => $this->t('移除'),
              'url' => Url::fromRoute('baas_tenant.manage_members', [
                'tenant_id' => $tenant_id,
              ], [
                'query' => ['user_id' => $member['user_id'], 'action' => 'remove'],
              ]),
            ];
          }
        }
      }

      $row['operations'] = [
        'data' => [
          '#type' => 'operations',
          '#links' => $operations,
        ],
      ];

      $rows[] = $row;
    }

    $build['members_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('此租户暂无成员'),
    ];

    // 添加邀请新成员按钮.
    if ($this->permissionChecker->canManageTenantMembers($current_user, $tenant_id)) {
      $build['add_member'] = [
        '#type' => 'link',
        '#title' => $this->t('邀请新成员'),
        '#url' => Url::fromRoute('baas_tenant.manage_members', ['tenant_id' => $tenant_id]),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
        '#prefix' => '<div class="baas-tenant-member-actions">',
        '#suffix' => '</div>',
      ];
    }

    return $build;
  }

  /**
   * 格式化用户角色显示.
   *
   * @param string $role
   *   角色名称.
   * @param bool $is_owner
   *   是否为所有者.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   格式化的角色显示.
   */
  protected function formatUserRole($role, $is_owner)
  {
    if ($is_owner) {
      return $this->t('所有者');
    }

    $role_labels = [
      'tenant_admin' => $this->t('管理员'),
      'tenant_manager' => $this->t('管理者'),
      'tenant_user' => $this->t('用户'),
    ];

    return $role_labels[$role] ?? $this->t('未知角色');
  }
}
