<?php

namespace Drupal\baas_entity\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_entity\Service\TemplateManager;
use Drupal\baas_tenant\TenantManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\baas_project\ProjectManagerInterface;

/**
 * 实体模板控制器。
 */
class EntityTemplateController extends ControllerBase {

  /**
   * 模板管理服务。
   *
   * @var \Drupal\baas_entity\Service\TemplateManager
   */
  protected $templateManager;

  /**
   * 租户管理服务。
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected $tenantManager;

  /**
   * 数据库连接。
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * 项目管理服务。
   *
   * @var \Drupal\baas_project\ProjectManagerInterface
   */
  protected $projectManager;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_entity\Service\TemplateManager $template_manager
   *   模板管理服务。
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理服务。
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接。
   * @param \Drupal\baas_project\ProjectManagerInterface $project_manager
   *   项目管理服务。
   */
  public function __construct(
    TemplateManager $template_manager,
    TenantManagerInterface $tenant_manager,
    Connection $database,
    ProjectManagerInterface $project_manager
  ) {
    $this->templateManager = $template_manager;
    $this->tenantManager = $tenant_manager;
    $this->database = $database;
    $this->projectManager = $project_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('baas_entity.template_manager'),
      $container->get('baas_tenant.manager'),
      $container->get('database'),
      $container->get('baas_project.manager')
    );
  }

  /**
   * 显示实体模板列表。
   *
   * @return array
   *   渲染数组。
   */
  public function listTemplates() {
    // 查询模板列表，联接项目信息
    $query = $this->database->select('baas_entity_template', 't');
    $query->leftJoin('baas_project_config', 'p', 't.project_id = p.project_id');
    $query->fields('t', ['id', 'tenant_id', 'project_id', 'name', 'label', 'description', 'status', 'created', 'updated']);
    $query->addField('p', 'name', 'project_name');
    $query->orderBy('t.tenant_id');
    $query->orderBy('t.project_id');
    $query->orderBy('t.name');
    $result = $query->execute();

    // 构建表头
    $header = [
      $this->t('ID'),
      $this->t('租户ID'),
      $this->t('项目'),
      $this->t('实体名称'),
      $this->t('显示名称'),
      $this->t('状态'),
      $this->t('创建时间'),
      $this->t('更新时间'),
      $this->t('操作'),
    ];

    // 构建表格行
    $rows = [];
    foreach ($result as $record) {
      // 获取项目信息
      $project_display = $record->project_name ? 
        $record->project_name . ' (' . $record->project_id . ')' : 
        $record->project_id;

      // 构建操作链接
      $operations = [];
      
      // 如果有项目ID，使用项目级管理链接
      if ($record->project_id) {
        $operations['manage'] = [
          'title' => $this->t('项目管理'),
          'url' => Url::fromRoute('baas_project.entities', [
            'tenant_id' => $record->tenant_id,
            'project_id' => $record->project_id,
          ]),
        ];
        $operations['edit_structure'] = [
          'title' => $this->t('编辑结构'),
          'url' => Url::fromRoute('baas_project.entity_template_edit', [
            'tenant_id' => $record->tenant_id,
            'project_id' => $record->project_id,
            'template_id' => $record->id,
          ]),
        ];
        $operations['view_data'] = [
          'title' => $this->t('查看数据'),
          'url' => Url::fromRoute('baas_project.entity_data', [
            'tenant_id' => $record->tenant_id,
            'project_id' => $record->project_id,
            'entity_name' => $record->name,
          ]),
        ];
        $operations['delete'] = [
          'title' => $this->t('删除'),
          'url' => Url::fromRoute('baas_project.entity_template_delete', [
            'tenant_id' => $record->tenant_id,
            'project_id' => $record->project_id,
            'template_id' => $record->id,
          ]),
        ];
      } else {
        // 兼容旧的租户级实体
        $operations['view_fields'] = [
          'title' => $this->t('查看字段'),
          'url' => Url::fromRoute('baas_entity.fields', ['template_id' => $record->id]),
        ];
        $operations['edit'] = [
          'title' => $this->t('编辑'),
          'url' => Url::fromRoute('baas_entity.edit', ['template_id' => $record->id]),
        ];
        $operations['delete'] = [
          'title' => $this->t('删除'),
          'url' => Url::fromRoute('baas_entity.delete', ['template_id' => $record->id]),
        ];
      }

      $operations_markup = [
        'data' => [
          '#type' => 'operations',
          '#links' => $operations,
        ],
      ];

      // 添加行
      $rows[] = [
        'data' => [
          $record->id,
          $record->tenant_id,
          $project_display,
          $record->name,
          $record->label,
          $record->status ? $this->t('启用') : $this->t('禁用'),
          $this->formatDate($record->created),
          $this->formatDate($record->updated),
          $operations_markup,
        ],
      ];
    }

    // 构建页面
    $build = [];
    
    // 添加说明
    $build['description'] = [
      '#markup' => '<div class="messages messages--info">' . 
        $this->t('此页面显示所有租户和项目中的实体模板。新的实体应该在具体的项目中创建和管理。') . 
        '</div>',
    ];

    $build['templates_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('没有实体模板。建议在项目中创建实体。'),
    ];

    // 添加链接到项目管理
    $build['project_management'] = [
      '#type' => 'link',
      '#title' => $this->t('管理项目实体'),
      '#url' => Url::fromRoute('baas_project.admin_list'),
      '#attributes' => [
        'class' => ['button', 'button--action', 'button--primary'],
      ],
    ];

    // 保留添加实体模板链接（用于兼容性）
    $build['add_template'] = [
      '#type' => 'link',
      '#title' => $this->t('添加实体模板（旧方式）'),
      '#url' => Url::fromRoute('baas_entity.add'),
      '#attributes' => [
        'class' => ['button', 'button--secondary'],
      ],
    ];

    return $build;
  }

  /**
   * 格式化日期。
   *
   * @param int $timestamp
   *   时间戳。
   *
   * @return string
   *   格式化的日期。
   */
  protected function formatDate($timestamp) {
    return \Drupal::service('date.formatter')->format($timestamp, 'short');
  }

}
