<?php

declare(strict_types=1);

namespace Drupal\baas_project\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_project\Access\EntityAccessChecker;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\baas_project\Service\ProjectTableNameGenerator;
use Drupal\baas_project\Service\ProjectEntityTemplateManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * 项目实体管理控制器。
 *
 * 提供基于项目角色的实体操作界面。
 */
class ProjectEntityController extends ControllerBase
{

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_project\ProjectManagerInterface $projectManager
   *   项目管理服务。
   * @param \Drupal\baas_project\Access\EntityAccessChecker $entityAccessChecker
   *   实体访问权限检查器。
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   日期格式化服务。
   * @param \Drupal\baas_project\Service\ProjectTableNameGenerator $tableNameGenerator
   *   表名生成器服务。
   * @param \Drupal\baas_project\Service\ProjectEntityTemplateManager $entityTemplateManager
   *   实体模板管理服务。
   */
  public function __construct(
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly EntityAccessChecker $entityAccessChecker,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly ProjectTableNameGenerator $tableNameGenerator,
    protected readonly ProjectEntityTemplateManager $entityTemplateManager
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_project.manager'),
      $container->get('baas_project.entity_access_checker'),
      $container->get('date.formatter'),
      $container->get('baas_project.table_name_generator'),
      $container->get('baas_project.entity_template_manager')
    );
  }

  /**
   * 显示项目中的实体管理界面。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   渲染数组。
   */
  public function projectEntities(string $tenant_id, string $project_id): array
  {
    $current_user = $this->currentUser();
    $user_id = (int) $current_user->id();

    // 验证项目访问权限
    $project = $this->projectManager->getProject($project_id);
    if (!$project || $project['tenant_id'] !== $tenant_id) {
      throw new NotFoundHttpException('找不到指定的项目。');
    }

    // 检查用户是否是项目成员或有管理权限
    $user_role = $this->projectManager->getUserProjectRole($project_id, $user_id);
    $has_admin_permission = $current_user->hasPermission('administer baas project') ||
      $current_user->hasPermission('administer baas entity templates') ||
      $current_user->hasPermission('view baas project');

    if (!$user_role && !$has_admin_permission) {
      throw new AccessDeniedHttpException('您不是此项目的成员。');
    }

    // 获取项目中的实体模板
    $entities = $this->getProjectEntities($project_id);

    $build = [];
    $build['#attached']['library'][] = 'baas_project/entity-management';

    // 页面标题和描述
    $build['header'] = [
      '#markup' => '<div class="entity-header">
        <h2>' . $this->t('项目实体管理') . '</h2>
        <p>' . $this->t('管理项目 "@name" 中的数据实体。您的角色：@role', [
        '@name' => $project['name'],
        '@role' => $this->formatUserRole($user_role ?: 'viewer'),
      ]) . '</p>
      </div>',
    ];

    // 视图切换按钮
    $build['view_toggle'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['view-toggle']],
      'grid' => [
        '#type' => 'link',
        '#title' => $this->t('网格视图'),
        '#url' => Url::fromRoute('baas_project.entities', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
        ]),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'schema' => [
        '#type' => 'link',
        '#title' => $this->t('关系图'),
        '#url' => Url::fromRoute('baas_project.entity_schema', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
        ]),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    // 快速操作按钮（基于角色权限）
    // 如果用户没有项目角色但有管理权限，设置为owner角色
    $effective_role = $user_role ?: ($has_admin_permission ? 'owner' : null);
    $build['actions'] = $this->buildEntityActions($tenant_id, $project_id, $effective_role);

    if (empty($entities)) {
      $build['empty'] = [
        '#markup' => '<div class="empty-state">
          <h3>' . $this->t('还没有实体') . '</h3>
          <p>' . $this->t('在此项目中创建您的第一个数据实体。') . '</p>
        </div>',
      ];
    } else {
      $build['entities'] = $this->buildEntityGrid($entities, $tenant_id, $project_id, $effective_role);
    }

    // 返回项目详情链接
    $build['back'] = [
      '#type' => 'actions',
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('返回项目详情'),
        '#url' => Url::fromRoute('baas_project.user_view', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
        ]),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    return $build;
  }

  /**
   * 显示特定实体的数据管理界面。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   *
   * @return array
   *   渲染数组。
   */
  public function entityDataManagement(string $tenant_id, string $project_id, string $entity_name): array
  {
    $current_user = $this->currentUser();
    $user_id = (int) $current_user->id();

    // 验证项目权限
    $project = $this->projectManager->getProject($project_id);
    if (!$project || $project['tenant_id'] !== $tenant_id) {
      throw new NotFoundHttpException('找不到指定的项目。');
    }

    // 检查用户是否是项目成员或有管理权限
    $user_role = $this->projectManager->getUserProjectRole($project_id, $user_id);
    $has_admin_permission = $current_user->hasPermission('administer baas project') ||
      $current_user->hasPermission('administer baas entity templates') ||
      $current_user->hasPermission('view baas project');

    if (!$user_role && !$has_admin_permission) {
      throw new AccessDeniedHttpException('您不是此项目的成员。');
    }

    // 获取实体模板信息
    $entity_template = $this->getEntityTemplate($tenant_id, $project_id, $entity_name);
    if (!$entity_template) {
      throw new NotFoundHttpException('找不到指定的实体模板。');
    }

    $build = [];
    $build['#attached']['library'][] = 'baas_project/entity-management';

    // 如果用户没有项目角色但有管理权限，设置为owner角色
    $effective_role = $user_role ?: ($has_admin_permission ? 'owner' : null);

    // 页面标题
    $build['header'] = [
      '#markup' => '<div class="entity-data-header">
        <h2>' . $this->t('实体数据：@name', ['@name' => $entity_template['label']]) . '</h2>
        <p>' . $this->t('您的操作权限：@permissions', [
        '@permissions' => $this->getUserPermissionText($effective_role),
      ]) . '</p>
      </div>',
    ];

    // 数据操作按钮
    $build['data_actions'] = $this->buildDataActions($tenant_id, $project_id, $entity_name, $effective_role);

    // 数据列表/网格
    $entity_data = $this->getEntityData($tenant_id, $project_id, $entity_name);
    $build['data_list'] = $this->buildEntityDataList($entity_data, $tenant_id, $project_id, $entity_name, $effective_role);

    // 返回链接
    $build['back'] = [
      '#type' => 'actions',
      'back' => [
        '#type' => 'link',
        '#title' => $this->t('返回实体管理'),
        '#url' => Url::fromRoute('baas_project.entities', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
        ]),
        '#attributes' => ['class' => ['button']],
      ],
    ];

    return $build;
  }

  /**
   * 获取项目中的实体模板列表。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   实体模板列表。
   */
  protected function getProjectEntities(string $project_id): array
  {
    $database = \Drupal::database();

    if (!$database->schema()->tableExists('baas_entity_template')) {
      return [];
    }

    return $database->select('baas_entity_template', 'e')
      ->fields('e')
      ->condition('project_id', $project_id)
      ->condition('status', 1)
      ->orderBy('name')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * 获取实体模板信息。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   *
   * @return array|null
   *   实体模板信息。
   */
  protected function getEntityTemplate(string $tenant_id, string $project_id, string $entity_name): ?array
  {
    $database = \Drupal::database();

    if (!$database->schema()->tableExists('baas_entity_template')) {
      return null;
    }

    $template = $database->select('baas_entity_template', 'e')
      ->fields('e')
      ->condition('tenant_id', $tenant_id)
      ->condition('project_id', $project_id)
      ->condition('name', $entity_name)
      ->condition('status', 1)
      ->execute()
      ->fetch(\PDO::FETCH_ASSOC);

    return $template ?: null;
  }

  /**
   * 获取实体数据。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   *
   * @return array
   *   实体数据列表。
   */
  protected function getEntityData(string $tenant_id, string $project_id, string $entity_name): array
  {
    $database = \Drupal::database();
    $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);

    if (!$database->schema()->tableExists($table_name)) {
      \Drupal::logger('baas_project')->warning('实体数据表不存在: @table', ['@table' => $table_name]);
      return [];
    }

    try {
      // 获取分页参数
      $page = \Drupal::request()->query->getInt('page', 0);
      $limit = \Drupal::request()->query->getInt('limit', 10);
      $sort_field = \Drupal::request()->query->get('sort', 'id');
      $sort_direction = \Drupal::request()->query->get('direction', 'DESC');

      // 验证排序字段和方向
      $valid_directions = ['ASC', 'DESC'];
      if (!in_array(strtoupper($sort_direction), $valid_directions)) {
        $sort_direction = 'DESC';
      }

      // 构建查询
      $query = $database->select($table_name, 'e')
        ->fields('e')
        ->orderBy($sort_field, $sort_direction)
        ->range($page * $limit, $limit);

      // 添加过滤条件
      $filters = \Drupal::request()->query->all();
      foreach ($filters as $key => $value) {
        if (strpos($key, 'filter_') === 0 && !empty($value)) {
          $field = substr($key, 7); // 移除 'filter_' 前缀

          // 检查字段是否存在
          if ($database->schema()->fieldExists($table_name, $field)) {
            // 获取字段类型
            $field_info = $database->schema()->getFieldTypeMap($table_name);
            $field_type = $field_info[$field] ?? 'varchar';

            // 根据字段类型使用不同的查询条件
            if (in_array($field_type, ['int', 'serial', 'numeric'])) {
              // 数字字段使用精确匹配
              if (is_numeric($value)) {
                $query->condition($field, $value);
              }
            } else {
              // 字符串字段使用模糊匹配
              $query->condition($field, '%' . $database->escapeLike($value) . '%', 'LIKE');
            }

            \Drupal::logger('baas_project')->debug('添加过滤条件: 字段=@field, 类型=@type, 值=@value', [
              '@field' => $field,
              '@type' => $field_type,
              '@value' => $value,
            ]);
          }
        }
      }

      $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

      \Drupal::logger('baas_project')->info('从表 @table 获取到 @count 条记录', [
        '@table' => $table_name,
        '@count' => count($results),
      ]);

      return $results;
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('获取实体数据失败: @error', ['@error' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * 构建实体操作按钮。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string|null $user_role
   *   用户角色。
   *
   * @return array
   *   操作按钮渲染数组。
   */
  protected function buildEntityActions(string $tenant_id, string $project_id, ?string $user_role): array
  {
    $actions = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['entity-quick-actions']],
    ];

    // 检查创建权限
    if ($this->checkRolePermission($user_role, 'create')) {
      $actions['create'] = [
        '#type' => 'link',
        '#title' => $this->t('创建新实体'),
        '#url' => Url::fromRoute('baas_project.entity_template_create', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
        ]),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }

    return $actions;
  }

  /**
   * 构建实体网格显示。
   *
   * @param array $entities
   *   实体列表。
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string|null $user_role
   *   用户角色。
   *
   * @return array
   *   实体网格渲染数组。
   */
  protected function buildEntityGrid(array $entities, string $tenant_id, string $project_id, ?string $user_role): array
  {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['entities-grid']],
    ];

    foreach ($entities as $entity) {
      $entity_count = $this->getEntityDataCount($tenant_id, $project_id, $entity['name']);

      $build[$entity['name']] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['entity-card']],
        'content' => [
          '#type' => 'container',
          'header' => [
            '#markup' => '<div class="entity-card-header">
              <h3>' . $entity['label'] . '</h3>
              <span class="entity-count">' . $this->t('@count 条记录', ['@count' => $entity_count]) . '</span>
            </div>',
          ],
          'info' => [
            '#markup' => '<div class="entity-info">
              <p class="entity-description">' . ($entity['description'] ?: $this->t('无描述')) . '</p>
              <div class="entity-meta">
                <span class="entity-machine-name">' . $this->t('机器名：@name', ['@name' => $entity['name']]) . '</span>
                <span class="entity-updated">' . $this->t('更新：@date', ['@date' => $this->dateFormatter->format($entity['updated'], 'short')]) . '</span>
              </div>
            </div>',
          ],
          'actions' => $this->buildEntityCardActions($entity, $tenant_id, $project_id, $user_role),
        ],
      ];
    }

    return $build;
  }

  /**
   * 构建实体卡片操作按钮。
   *
   * @param array $entity
   *   实体信息。
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string|null $user_role
   *   用户角色。
   *
   * @return array
   *   操作按钮渲染数组。
   */
  protected function buildEntityCardActions(array $entity, string $tenant_id, string $project_id, ?string $user_role): array
  {
    $actions = [
      '#type' => 'container',
      '#attributes' => ['class' => ['entity-actions']],
    ];

    // 查看数据（所有角色都可以）
    if ($this->checkRolePermission($user_role, 'view')) {
      $actions['view_data'] = [
        '#type' => 'link',
        '#title' => $this->t('查看数据'),
        '#url' => Url::fromRoute('baas_project.entity_data', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
          'entity_name' => $entity['name'],
        ]),
        '#attributes' => ['class' => ['button', 'button--small']],
      ];
    }

    // 编辑结构
    if ($this->checkRolePermission($user_role, 'update')) {
      $actions['edit'] = [
        '#type' => 'link',
        '#title' => $this->t('编辑结构'),
        '#url' => Url::fromRoute('baas_project.entity_template_edit', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
          'template_id' => $entity['id'],
        ]),
        '#attributes' => ['class' => ['button', 'button--small']],
      ];
    }

    // 删除实体
    if ($this->checkRolePermission($user_role, 'delete')) {
      $actions['delete'] = [
        '#type' => 'link',
        '#title' => $this->t('删除'),
        '#url' => Url::fromRoute('baas_project.entity_template_delete', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
          'template_id' => $entity['id'],
        ]),
        '#attributes' => ['class' => ['button', 'button--small', 'button--danger']],
      ];
    }

    return $actions;
  }

  /**
   * 构建数据操作按钮。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   * @param string|null $user_role
   *   用户角色。
   *
   * @return array
   *   数据操作按钮渲染数组。
   */
  protected function buildDataActions(string $tenant_id, string $project_id, string $entity_name, ?string $user_role): array
  {
    $actions = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['data-actions']],
    ];

    // 添加数据
    if ($this->checkRolePermission($user_role, 'create')) {
      $actions['add_data'] = [
        '#type' => 'link',
        '#title' => $this->t('添加数据'),
        '#url' => Url::fromRoute('baas_project.entity_data_add', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
          'entity_name' => $entity_name,
        ]),
        '#attributes' => [
          'class' => ['button', 'button--primary'],
        ],
      ];
    }

    // 批量操作（仅限管理员和拥有者）
    if ($this->checkRolePermission($user_role, 'delete')) {
      $actions['bulk_delete'] = [
        '#type' => 'link',
        '#title' => $this->t('批量删除'),
        '#url' => Url::fromRoute('baas_project.entity_data', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
          'entity_name' => $entity_name,
        ], ['fragment' => 'bulk-delete']),
        '#attributes' => [
          'class' => ['button', 'button--danger'],
          'onclick' => 'alert("批量删除功能正在开发中");return false;',
        ],
      ];
    }

    return $actions;
  }

  /**
   * 构建实体数据列表。
   *
   * @param array $data
   *   实体数据。
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   * @param string|null $user_role
   *   用户角色。
   *
   * @return array
   *   数据列表渲染数组。
   */
  protected function buildEntityDataList(array $data, string $tenant_id, string $project_id, string $entity_name, ?string $user_role): array
  {
    if (empty($data)) {
      return [
        '#markup' => '<div class="empty-state">
          <p>' . $this->t('此实体还没有数据。') . '</p>
        </div>',
      ];
    }

    // 获取请求参数
    $request = \Drupal::request();
    $current_sort = $request->query->get('sort', 'id');
    $current_direction = $request->query->get('direction', 'DESC');
    $page = $request->query->getInt('page', 0);
    $limit = $request->query->getInt('limit', 10);

    // 优化字段顺序，将系统字段放在后面
    $system_fields = ['id', 'uuid', 'tenant_id', 'project_id', 'created', 'updated'];
    $first_row = reset($data);
    $all_fields = array_keys($first_row);

    // 分离业务字段和系统字段
    $business_fields = array_diff($all_fields, $system_fields);
    $ordered_fields = array_merge(['id', 'title'], $business_fields, ['created', 'updated']);
    $ordered_fields = array_intersect($ordered_fields, $all_fields); // 确保所有字段都存在

    // 构建表头
    $header = [];
    foreach ($ordered_fields as $field) {
      // 为字段创建可排序的表头
      $label = $this->getFieldLabel($field);

      // 构建排序URL
      $sort_direction = ($field == $current_sort && $current_direction == 'ASC') ? 'DESC' : 'ASC';
      $sort_url = Url::fromRoute('baas_project.entity_data', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
        'entity_name' => $entity_name,
      ], [
        'query' => [
          'sort' => $field,
          'direction' => $sort_direction,
          'page' => $page,
          'limit' => $limit,
        ] + $request->query->all(),
      ])->toString();

      // 添加排序指示器
      $sort_indicator = '';
      if ($field == $current_sort) {
        $sort_indicator = $current_direction == 'ASC' ? ' ↑' : ' ↓';
      }

      $header[$field] = [
        'data' => [
          '#markup' => '<a href="' . $sort_url . '" title="' . $this->t('点击排序') . '">' . $label . $sort_indicator . '</a>',
        ],
        'class' => ['sortable'],
      ];
    }

    // 添加操作列
    $header['operations'] = $this->t('操作');

    // 构建过滤表单
    $filter_form = [
      '#type' => 'container',
      '#attributes' => ['class' => ['entity-data-filters', 'clearfix']],
      'form' => [
        '#type' => 'form',
        '#form_id' => 'entity_data_filter_form',
        '#id' => 'entity-data-filter-form',
        '#action' => Url::fromRoute('baas_project.entity_data', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
          'entity_name' => $entity_name,
        ])->toString(),
        '#method' => 'GET',
        'filters' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['filters-wrapper']],
        ],
        // 保留当前的排序和分页参数
        'sort' => [
          '#type' => 'hidden',
          '#value' => $current_sort,
          '#name' => 'sort',
        ],
        'direction' => [
          '#type' => 'hidden',
          '#value' => $current_direction,
          '#name' => 'direction',
        ],
        'limit' => [
          '#type' => 'hidden',
          '#value' => $limit,
          '#name' => 'limit',
        ],
        'actions' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['filter-actions']],
          'submit' => [
            '#type' => 'submit',
            '#value' => $this->t('筛选'),
            '#attributes' => ['class' => ['button']],
            '#name' => 'filter_submit',
          ],
          'reset' => [
            '#type' => 'link',
            '#title' => $this->t('重置'),
            '#url' => Url::fromRoute('baas_project.entity_data', [
              'tenant_id' => $tenant_id,
              'project_id' => $project_id,
              'entity_name' => $entity_name,
            ]),
            '#attributes' => ['class' => ['button']],
          ],
        ],
      ],
    ];

    // 添加过滤字段
    foreach (['title', 'id'] as $filter_field) {
      if (in_array($filter_field, $all_fields)) {
        $filter_value = $request->query->get('filter_' . $filter_field, '');
        $filter_form['form']['filters']['filter_' . $filter_field] = [
          '#type' => 'textfield',
          '#title' => $this->getFieldLabel($filter_field),
          '#default_value' => $filter_value,
          '#size' => 20,
          '#name' => 'filter_' . $filter_field,
          '#attributes' => [
            'placeholder' => $this->t('筛选 @field', ['@field' => $this->getFieldLabel($filter_field)]),
            'value' => $filter_value, // 确保值被设置为当前筛选值
          ],
        ];
      }
    }

    // 构建表格行
    $rows = [];
    foreach ($data as $row) {
      $table_row = [];

      foreach ($ordered_fields as $field) {
        $value = $row[$field] ?? '';

        // 格式化特殊字段
        if ($field == 'created' || $field == 'updated') {
          // 格式化日期时间
          $value = !empty($value) ? $this->dateFormatter->format($value, 'short') : '';
          $table_row[$field] = ['data' => $value, 'class' => ['date-cell']];
        } elseif ($this->isBoolean($field, $value)) {
          // 格式化布尔值
          $bool_value = (bool) $value;

          // 特殊处理void字段，0表示有效，1表示无效
          if ($field === 'void') {
            $icon = $bool_value ? '✗' : '✓';
            $class = $bool_value ? 'boolean-false' : 'boolean-true';
          } else {
            // 其他布尔字段，1表示是/真，0表示否/假
            $icon = $bool_value ? '✓' : '✗';
            $class = $bool_value ? 'boolean-true' : 'boolean-false';
          }

          $table_row[$field] = ['data' => $icon, 'class' => ['boolean-cell', $class]];
        } else {
          // 普通文本字段，截断过长的值
          $display_value = mb_strlen($value) > 100 ? mb_substr($value, 0, 97) . '...' : $value;
          $table_row[$field] = ['data' => $display_value, 'class' => []];
        }
      }

      // 添加操作列
      $operations = [];

      if ($this->checkRolePermission($user_role, 'view')) {
        $operations['view'] = [
          '#type' => 'link',
          '#title' => $this->t('查看'),
          '#url' => Url::fromRoute('baas_project.entity_data_view', [
            'tenant_id' => $tenant_id,
            'project_id' => $project_id,
            'entity_name' => $entity_name,
            'data_id' => $row['id'],
          ]),
          '#attributes' => ['class' => ['button', 'button--small']],
        ];
      }

      if ($this->checkRolePermission($user_role, 'update')) {
        $operations['edit'] = [
          '#type' => 'link',
          '#title' => $this->t('编辑'),
          '#url' => Url::fromRoute('baas_project.entity_data_edit', [
            'tenant_id' => $tenant_id,
            'project_id' => $project_id,
            'entity_name' => $entity_name,
            'data_id' => $row['id'],
          ]),
          '#attributes' => ['class' => ['button', 'button--small']],
        ];
      }

      if ($this->checkRolePermission($user_role, 'delete')) {
        $operations['delete'] = [
          '#type' => 'link',
          '#title' => $this->t('删除'),
          '#url' => Url::fromRoute('baas_project.entity_data_delete', [
            'tenant_id' => $tenant_id,
            'project_id' => $project_id,
            'entity_name' => $entity_name,
            'data_id' => $row['id'],
          ]),
          '#attributes' => [
            'class' => ['button', 'button--small', 'button--danger'],
            'onclick' => 'return confirm("' . $this->t('确定要删除此条数据吗？') . '");',
          ],
        ];
      }

      $operations_render = [];
      foreach ($operations as $operation) {
        $operations_render[] = $operation;
      }

      $table_row['operations'] = [
        'data' => $operations_render,
        'class' => ['operations'],
      ];
      $rows[] = $table_row;
    }

    // 构建分页
    $total = $this->getEntityDataCount($tenant_id, $project_id, $entity_name);
    $total_pages = ceil($total / $limit);

    $pager = [
      '#type' => 'container',
      '#attributes' => ['class' => ['entity-data-pager']],
      'summary' => [
        '#markup' => '<div class="pager-summary">' . $this->t('显示 @start-@end，共 @total 条', [
          '@start' => $page * $limit + 1,
          '@end' => min(($page + 1) * $limit, $total),
          '@total' => $total,
        ]) . '</div>',
      ],
      'links' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['pager-links']],
      ],
    ];

    // 添加分页链接
    if ($page > 0) {
      $pager['links']['first'] = [
        '#type' => 'link',
        '#title' => $this->t('首页'),
        '#url' => Url::fromRoute('baas_project.entity_data', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
          'entity_name' => $entity_name,
        ], [
          'query' => ['page' => 0, 'limit' => $limit, 'sort' => $current_sort, 'direction' => $current_direction] + $request->query->all(),
        ]),
        '#attributes' => ['class' => ['pager-link']],
      ];

      $pager['links']['prev'] = [
        '#type' => 'link',
        '#title' => $this->t('上一页'),
        '#url' => Url::fromRoute('baas_project.entity_data', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
          'entity_name' => $entity_name,
        ], [
          'query' => ['page' => $page - 1, 'limit' => $limit, 'sort' => $current_sort, 'direction' => $current_direction] + $request->query->all(),
        ]),
        '#attributes' => ['class' => ['pager-link']],
      ];
    }

    if ($page < $total_pages - 1) {
      $pager['links']['next'] = [
        '#type' => 'link',
        '#title' => $this->t('下一页'),
        '#url' => Url::fromRoute('baas_project.entity_data', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
          'entity_name' => $entity_name,
        ], [
          'query' => ['page' => $page + 1, 'limit' => $limit, 'sort' => $current_sort, 'direction' => $current_direction] + $request->query->all(),
        ]),
        '#attributes' => ['class' => ['pager-link']],
      ];

      $pager['links']['last'] = [
        '#type' => 'link',
        '#title' => $this->t('末页'),
        '#url' => Url::fromRoute('baas_project.entity_data', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
          'entity_name' => $entity_name,
        ], [
          'query' => ['page' => $total_pages - 1, 'limit' => $limit, 'sort' => $current_sort, 'direction' => $current_direction] + $request->query->all(),
        ]),
        '#attributes' => ['class' => ['pager-link']],
      ];
    }

    // 页面大小选择器
    $pager['page_size'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['page-size-selector']],
      'label' => [
        '#markup' => '<span>' . $this->t('每页显示：') . '</span>',
      ],
    ];

    foreach ([10, 20, 50, 100] as $size) {
      $pager['page_size']['size_' . $size] = [
        '#type' => 'link',
        '#title' => $size,
        '#url' => Url::fromRoute('baas_project.entity_data', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
          'entity_name' => $entity_name,
        ], [
          'query' => ['page' => 0, 'limit' => $size, 'sort' => $current_sort, 'direction' => $current_direction] + $request->query->all(),
        ]),
        '#attributes' => [
          'class' => ['page-size-link', $limit == $size ? 'active' : ''],
        ],
      ];
    }

    // 添加CSS
    $build = [
      '#attached' => [
        'library' => ['baas_project/entity-data-table'],
      ],
      'filter_form' => $filter_form,
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('没有找到数据。'),
        '#attributes' => [
          'class' => ['entity-data-table', 'responsive-enabled'],
        ],
        '#sticky' => true,
      ],
      'pager' => $pager,
    ];

    return $build;
  }

  /**
   * 获取字段的显示标签。
   *
   * @param string $field
   *   字段名称。
   *
   * @return string
   *   字段标签。
   */
  protected function getFieldLabel(string $field): string
  {
    $labels = [
      'id' => (string) $this->t('ID'),
      'title' => (string) $this->t('标题'),
      'uuid' => (string) $this->t('UUID'),
      'tenant_id' => (string) $this->t('租户ID'),
      'project_id' => (string) $this->t('项目ID'),
      'created' => (string) $this->t('创建时间'),
      'updated' => (string) $this->t('更新时间'),
      'description' => (string) $this->t('描述'),
      'status' => (string) $this->t('状态'),
      'void' => (string) $this->t('是否有效'),
      'num' => (string) $this->t('数量'),
    ];

    return $labels[$field] ?? $this->formatFieldName($field);
  }

  /**
   * 格式化字段名称为可读标签。
   *
   * @param string $field_name
   *   字段名称。
   *
   * @return string
   *   格式化后的标签。
   */
  protected function formatFieldName(string $field_name): string
  {
    // 将下划线替换为空格并将每个单词首字母大写
    return (string) ucwords(str_replace('_', ' ', $field_name));
  }

  /**
   * 判断字段是否为布尔类型。
   *
   * @param string $field_name
   *   字段名称。
   * @param mixed $value
   *   字段值。
   *
   * @return bool
   *   是否为布尔类型。
   */
  protected function isBoolean(string $field_name, $value): bool
  {
    // 检查字段名称是否暗示布尔类型
    $bool_prefixes = ['is_', 'has_', 'can_', 'should_', 'void'];
    foreach ($bool_prefixes as $prefix) {
      if (strpos($field_name, $prefix) === 0 || $field_name === $prefix) {
        return true;
      }
    }

    // 检查值是否只有0或1
    if ($value === '0' || $value === '1' || $value === 0 || $value === 1) {
      return true;
    }

    return false;
  }

  /**
   * 获取实体数据数量。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   *
   * @return int
   *   数据数量。
   */
  protected function getEntityDataCount(string $tenant_id, string $project_id, string $entity_name): int
  {
    $database = \Drupal::database();
    $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);

    if (!$database->schema()->tableExists($table_name)) {
      return 0;
    }

    try {
      // 构建查询
      $query = $database->select($table_name, 'e')->countQuery();

      // 添加过滤条件
      $filters = \Drupal::request()->query->all();
      foreach ($filters as $key => $value) {
        if (strpos($key, 'filter_') === 0 && !empty($value)) {
          $field = substr($key, 7); // 移除 'filter_' 前缀

          // 检查字段是否存在
          if ($database->schema()->fieldExists($table_name, $field)) {
            // 获取字段类型
            $field_info = $database->schema()->getFieldTypeMap($table_name);
            $field_type = $field_info[$field] ?? 'varchar';

            // 根据字段类型使用不同的查询条件
            if (in_array($field_type, ['int', 'serial', 'numeric'])) {
              // 数字字段使用精确匹配
              if (is_numeric($value)) {
                $query->condition($field, $value);
              }
            } else {
              // 字符串字段使用模糊匹配
              $query->condition($field, '%' . $database->escapeLike($value) . '%', 'LIKE');
            }
          }
        }
      }

      return (int) $query->execute()->fetchField();
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('获取实体数据数量失败: @error', ['@error' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * 检查用户角色是否有指定权限。
   *
   * @param string|null $role
   *   用户角色。
   * @param string $operation
   *   操作类型。
   *
   * @return bool
   *   是否有权限。
   */
  protected function checkRolePermission(?string $role, string $operation): bool
  {
    $current_user = $this->currentUser();

    // 管理员总是有权限
    if (
      $current_user->hasPermission('administer baas project') ||
      $current_user->hasPermission('administer baas entity templates')
    ) {
      return true;
    }

    if (!$role) {
      return false;
    }

    $allowed_operations = $this->entityAccessChecker->getOperationRequiredRoles($operation);
    return in_array($role, $allowed_operations);
  }

  /**
   * 格式化用户角色显示。
   *
   * @param string $role
   *   用户角色。
   *
   * @return string
   *   格式化的角色名称。
   */
  protected function formatUserRole(string $role): string
  {
    $role_labels = [
      'owner' => (string) $this->t('拥有者'),
      'admin' => (string) $this->t('管理员'),
      'editor' => (string) $this->t('编辑者'),
      'viewer' => (string) $this->t('查看者'),
      'member' => (string) $this->t('成员'),
    ];

    return $role_labels[$role] ?? $role;
  }

  /**
   * 获取用户权限文本描述。
   *
   * @param string|null $role
   *   用户角色。
   *
   * @return string
   *   权限描述文本。
   */
  protected function getUserPermissionText(?string $role): string
  {
    if (!$role) {
      return (string) $this->t('无权限');
    }

    $permissions = [
      'owner' => (string) $this->t('查看、创建、编辑、删除'),
      'admin' => (string) $this->t('查看、创建、编辑、删除'),
      'editor' => (string) $this->t('查看、创建、编辑'),
      'member' => (string) $this->t('查看'),
      'viewer' => (string) $this->t('查看'),
    ];

    return $permissions[$role] ?? (string) $this->t('未知');
  }

  /**
   * 显示项目实体关系图。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   渲染数组。
   */
  public function entitySchemaView(string $tenant_id, string $project_id): array
  {
    $current_user = $this->currentUser();
    $user_id = (int) $current_user->id();

    // 验证项目访问权限
    $project = $this->projectManager->getProject($project_id);
    if (!$project || $project['tenant_id'] !== $tenant_id) {
      throw new NotFoundHttpException('找不到指定的项目。');
    }

    // 检查用户是否是项目成员或有管理权限
    $user_role = $this->projectManager->getUserProjectRole($project_id, $user_id);
    $has_admin_permission = $current_user->hasPermission('administer baas project') ||
      $current_user->hasPermission('administer baas entity templates') ||
      $current_user->hasPermission('view baas project');

    if (!$user_role && !$has_admin_permission) {
      throw new AccessDeniedHttpException('您不是此项目的成员。');
    }

    // 获取实体和字段数据
    $entities = $this->getProjectEntitiesWithFields($tenant_id, $project_id);
    
    // 获取实体间关系
    $relationships = $this->getEntityRelationships($entities);

    return [
      '#theme' => 'baas_project_entity_schema',
      '#entities' => $entities,
      '#relationships' => $relationships,
      '#project' => $project,
      '#tenant_id' => $tenant_id,
      '#project_id' => $project_id,
      '#attached' => [
        'library' => ['baas_project/entity-schema'],
      ],
    ];
  }

  /**
   * 获取项目中的实体模板及其字段详情。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   实体及字段列表。
   */
  protected function getProjectEntitiesWithFields(string $tenant_id, string $project_id): array
  {
    $database = \Drupal::database();

    if (!$database->schema()->tableExists('baas_entity_template')) {
      return [];
    }

    // 获取实体模板
    $query = $database->select('baas_entity_template', 'bet')
      ->fields('bet', ['id', 'name', 'label', 'description'])
      ->condition('project_id', $project_id)
      ->condition('status', 1)
      ->orderBy('name');
      
    $entities = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    
    // 为每个实体获取字段信息
    foreach ($entities as &$entity) {
      // 获取字段
      $fields = [];
      if ($database->schema()->tableExists('baas_entity_field')) {
        $fields = $database->select('baas_entity_field', 'bef')
          ->fields('bef', ['name', 'label', 'type', 'required', 'settings'])
          ->condition('template_id', $entity['id'])
          ->orderBy('weight')
          ->execute()
          ->fetchAll(\PDO::FETCH_ASSOC);
      }
      
      $entity['fields'] = $fields;
      $entity['record_count'] = $this->getEntityDataCount($tenant_id, $project_id, $entity['name']);
    }
    
    return $entities;
  }

  /**
   * 获取实体间关系数据。
   *
   * @param array $entities
   *   实体列表。
   *
   * @return array
   *   关系数据。
   */
  protected function getEntityRelationships(array $entities): array
  {
    $relationships = [];
    $entity_names = array_column($entities, 'name');

    foreach ($entities as $entity) {
      foreach ($entity['fields'] as $field) {
        // 检查是否为引用类型字段
        if ($field['type'] === 'reference' && !empty($field['settings'])) {
          $settings = json_decode($field['settings'], true);
          if (isset($settings['target_entity'])) {
            $target_entity = $settings['target_entity'];
            
            // 确保目标实体存在于当前项目中
            if (in_array($target_entity, $entity_names)) {
              $relationship = [
                'source_entity' => $entity['name'],
                'target_entity' => $target_entity,
                'field_name' => $field['name'],
                'field_label' => $field['label'],
                'type' => 'reference',
                'multiple' => !empty($settings['multiple']) ? 'one_to_many' : 'one_to_one',
                'required' => !empty($field['required']),
              ];
              
              // 生成唯一的关系ID
              $relationship_id = $entity['name'] . '_' . $field['name'] . '_' . $target_entity;
              $relationships[$relationship_id] = $relationship;
            }
          }
        }
      }
    }

    return $relationships;
  }

  /**
   * 获取实体名称长度限制的API端点。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应，包含最大长度信息。
   */
  public function getEntityNameLengthLimit(string $tenant_id, string $project_id, Request $request): JsonResponse
  {
    try {
      // 验证项目权限
      $project = $this->projectManager->getProject($project_id);
      if (!$project || $project['tenant_id'] !== $tenant_id) {
        return new JsonResponse([
          'success' => false,
          'error' => 'Project not found',
          'code' => 'PROJECT_NOT_FOUND',
        ], 404);
      }

      // 计算最大实体名称长度
      $max_length = $this->entityTemplateManager->calculateMaxEntityNameLength($tenant_id, $project_id);
      
      // 获取当前前缀长度以供参考
      $combined_hash = substr(md5($tenant_id . '_' . $project_id), 0, 6);
      $prefix = "baas_{$combined_hash}_";
      $prefix_length = strlen($prefix);
      
      return new JsonResponse([
        'success' => true,
        'data' => [
          'max_entity_name_length' => $max_length,
          'drupal_limit' => 32,
          'prefix_length' => $prefix_length,
          'prefix' => $prefix,
          'validation_message' => "机器名长度不能超过 {$max_length} 个字符（受Drupal 32字符实体类型ID限制）",
        ],
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('Failed to get entity name length limit: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => false,
        'error' => 'Failed to calculate length limit',
        'code' => 'CALCULATION_ERROR',
      ], 500);
    }
  }
}