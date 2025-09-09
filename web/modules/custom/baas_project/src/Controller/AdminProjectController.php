<?php

declare(strict_types=1);

namespace Drupal\baas_project\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * 管理员项目管理控制器。
 */
class AdminProjectController extends ControllerBase
{

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly Connection $database,
    protected readonly DateFormatterInterface $dateFormatter
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_project.manager'),
      $container->get('database'),
      $container->get('date.formatter')
    );
  }

  /**
   * 显示项目列表页面。
   *
   * @return array
   *   渲染数组。
   */
  public function listProjects(): array
  {
    // 查询所有项目
    $query = $this->database->select('baas_project_config', 'p');
    $query->fields('p', ['project_id', 'tenant_id', 'name', 'description', 'status', 'created', 'updated']);
    $query->orderBy('tenant_id');
    $query->orderBy('name');
    $result = $query->execute();

    // 构建表头
    $header = [
      $this->t('项目ID'),
      $this->t('租户ID'),
      $this->t('项目名称'),
      $this->t('描述'),
      $this->t('状态'),
      $this->t('创建时间'),
      $this->t('更新时间'),
      $this->t('操作'),
    ];

    // 构建表格行
    $rows = [];
    foreach ($result as $record) {
      // 获取项目中的实体数量
      $entity_count = $this->getProjectEntityCount($record->project_id);

      // 构建操作链接
      $operations = [
        'view_entities' => [
          'title' => $this->t('管理实体 (@count)', ['@count' => $entity_count]),
          'url' => Url::fromRoute('baas_project.entities', [
            'tenant_id' => $record->tenant_id,
            'project_id' => $record->project_id,
          ]),
        ],
        'view_details' => [
          'title' => $this->t('项目详情'),
          'url' => Url::fromRoute('baas_project.user_view', [
            'tenant_id' => $record->tenant_id,
            'project_id' => $record->project_id,
          ]),
        ],
        'edit' => [
          'title' => $this->t('编辑'),
          'url' => Url::fromRoute('baas_project.user_edit', [
            'tenant_id' => $record->tenant_id,
            'project_id' => $record->project_id,
          ]),
        ],
      ];

      $operations_markup = [
        'data' => [
          '#type' => 'operations',
          '#links' => $operations,
        ],
      ];

      // 添加行
      $rows[] = [
        'data' => [
          $record->project_id,
          $record->tenant_id,
          $record->name,
          $record->description ?: $this->t('无描述'),
          $record->status ? $this->t('启用') : $this->t('禁用'),
          $this->dateFormatter->format($record->created, 'short'),
          $this->dateFormatter->format($record->updated, 'short'),
          $operations_markup,
        ],
      ];
    }

    // 构建页面
    $build = [];
    
    // 添加说明
    $build['description'] = [
      '#markup' => '<div class="messages messages--info">' . 
        $this->t('此页面显示所有租户的项目。您可以查看每个项目中的实体并进行管理。') . 
        '</div>',
    ];

    $build['projects_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('没有找到项目。'),
    ];

    // 添加链接到实体模板列表
    $build['entity_templates'] = [
      '#type' => 'link',
      '#title' => $this->t('查看所有实体模板'),
      '#url' => Url::fromRoute('baas_entity.list'),
      '#attributes' => [
        'class' => ['button', 'button--secondary'],
      ],
    ];

    return $build;
  }

  /**
   * 获取项目中的实体数量。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return int
   *   实体数量。
   */
  protected function getProjectEntityCount(string $project_id): int
  {
    if (!$this->database->schema()->tableExists('baas_entity_template')) {
      return 0;
    }

    try {
      return (int) $this->database->select('baas_entity_template', 'e')
        ->condition('project_id', $project_id)
        ->countQuery()
        ->execute()
        ->fetchField();
    } catch (\Exception $e) {
      return 0;
    }
  }
}