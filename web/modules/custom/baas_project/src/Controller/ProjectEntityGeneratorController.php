<?php

declare(strict_types=1);

namespace Drupal\baas_project\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_project\Service\ProjectEntityGenerator;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * 项目实体生成器控制器。
 */
class ProjectEntityGeneratorController extends ControllerBase
{

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly ProjectEntityGenerator $entityGenerator
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('database'),
      $container->get('baas_project.entity_generator')
    );
  }

  /**
   * 为所有项目实体模板生成动态实体文件。
   *
   * @return JsonResponse
   *   JSON响应。
   */
  public function generateAllProjectEntities(): JsonResponse
  {
    $results = [];
    $success_count = 0;
    $error_count = 0;

    // 获取所有项目级实体模板
    $templates = $this->database->select('baas_entity_template', 'et')
      ->fields('et', ['id', 'tenant_id', 'project_id', 'name', 'label'])
      ->condition('project_id', '', '!=')
      ->condition('project_id', NULL, 'IS NOT NULL')
      ->condition('status', 1)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($templates as $template) {
      $template_id = $template['id'];
      $entity_name = $template['name'];
      $project_id = $template['project_id'];
      
      $result = $this->entityGenerator->generateProjectEntityFiles($template_id);
      
      if ($result) {
        $success_count++;
        $results[] = [
          'template_id' => $template_id,
          'entity_name' => $entity_name,
          'project_id' => $project_id,
          'status' => 'success',
          'message' => '动态实体文件生成成功',
        ];
      } else {
        $error_count++;
        $results[] = [
          'template_id' => $template_id,
          'entity_name' => $entity_name,
          'project_id' => $project_id,
          'status' => 'error',
          'message' => '动态实体文件生成失败',
        ];
      }
    }

    return new JsonResponse([
      'success' => $error_count === 0,
      'total' => count($templates),
      'success_count' => $success_count,
      'error_count' => $error_count,
      'results' => $results,
    ]);
  }

  /**
   * 为指定的项目实体模板生成动态实体文件。
   *
   * @param string $template_id
   *   模板ID。
   *
   * @return JsonResponse
   *   JSON响应。
   */
  public function generateProjectEntity(string $template_id): JsonResponse
  {
    // 验证模板是否存在
    $template = $this->database->select('baas_entity_template', 'et')
      ->fields('et', ['id', 'tenant_id', 'project_id', 'name', 'label'])
      ->condition('id', $template_id)
      ->condition('project_id', '', '!=')
      ->condition('project_id', NULL, 'IS NOT NULL')
      ->condition('status', 1)
      ->execute()
      ->fetch(\PDO::FETCH_ASSOC);

    if (!$template) {
      return new JsonResponse([
        'success' => FALSE,
        'message' => '指定的项目实体模板不存在或已禁用',
      ], 404);
    }

    $result = $this->entityGenerator->generateProjectEntityFiles($template_id);

    if ($result) {
      return new JsonResponse([
        'success' => TRUE,
        'message' => '动态实体文件生成成功',
        'template_id' => $template_id,
        'entity_name' => $template['name'],
        'project_id' => $template['project_id'],
      ]);
    } else {
      return new JsonResponse([
        'success' => FALSE,
        'message' => '动态实体文件生成失败',
        'template_id' => $template_id,
        'entity_name' => $template['name'],
        'project_id' => $template['project_id'],
      ], 500);
    }
  }

  /**
   * 显示项目实体生成状态页面。
   *
   * @return array
   *   渲染数组。
   */
  public function generationStatus(): array
  {
    // 获取所有项目级实体模板
    $templates = $this->database->select('baas_entity_template', 'et')
      ->fields('et', ['id', 'tenant_id', 'project_id', 'name', 'label', 'created'])
      ->condition('project_id', '', '!=')
      ->condition('project_id', NULL, 'IS NOT NULL')
      ->condition('status', 1)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $rows = [];
    foreach ($templates as $template) {
      $template_id = $template['id'];
      $entity_name = $template['name'];
      $project_id = $template['project_id'];
      $tenant_id = $template['tenant_id'];
      
      // 检查文件是否存在
      $class_name = $this->getProjectEntityClassName($template);
      $entity_file_exists = $this->checkEntityFileExists($tenant_id, $project_id, $class_name);
      $storage_file_exists = $this->checkStorageFileExists($tenant_id, $project_id, $class_name);
      
      $status = ($entity_file_exists && $storage_file_exists) ? '已生成' : '未生成';
      $status_class = ($entity_file_exists && $storage_file_exists) ? 'color-success' : 'color-error';
      
      $rows[] = [
        'template_id' => $template_id,
        'project_id' => $project_id,
        'entity_name' => $entity_name,
        'entity_label' => $template['label'],
        'class_name' => $class_name,
        'status' => [
          'data' => [
            '#markup' => '<span class="' . $status_class . '">' . $status . '</span>',
          ],
        ],
        'files' => [
          'entity' => $entity_file_exists ? '✓' : '✗',
          'storage' => $storage_file_exists ? '✓' : '✗',
        ],
        'created' => $this->dateFormatter->format($template['created'], 'short'),
      ];
    }

    $build = [];
    
    $build['description'] = [
      '#markup' => '<div class="messages messages--info">' . 
        $this->t('此页面显示项目实体模板的动态文件生成状态。') . 
        '</div>',
    ];

    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form-actions']],
    ];

    $build['actions']['generate_all'] = [
      '#type' => 'link',
      '#title' => $this->t('生成所有项目实体文件'),
      '#url' => \Drupal\Core\Url::fromRoute('baas_project.generate_all_entities'),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
        'data-confirm' => $this->t('确定要为所有项目实体模板生成动态文件吗？'),
      ],
    ];

    $build['status_table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('模板ID'),
        $this->t('项目ID'),
        $this->t('实体名称'),
        $this->t('显示名称'),
        $this->t('类名'),
        $this->t('状态'),
        $this->t('实体文件'),
        $this->t('存储文件'),
        $this->t('创建时间'),
      ],
      '#empty' => $this->t('没有找到项目实体模板。'),
    ];

    foreach ($rows as $row) {
      $build['status_table'][] = [
        '#markup' => $row['template_id'],
        '#markup' => $row['project_id'],
        '#markup' => $row['entity_name'],
        '#markup' => $row['entity_label'],
        '#markup' => $row['class_name'],
        $row['status'],
        '#markup' => $row['files']['entity'],
        '#markup' => $row['files']['storage'],
        '#markup' => $row['created'],
      ];
    }

    return $build;
  }

  /**
   * 生成项目实体类名。
   *
   * @param array $template
   *   实体模板数据。
   *
   * @return string
   *   类名。
   */
  protected function getProjectEntityClassName(array $template): string
  {
    // 验证输入数据
    $tenant_id = trim($template['tenant_id'] ?? '');
    $project_id = trim($template['project_id'] ?? '');
    $entity_name = trim($template['name'] ?? '');

    if (empty($tenant_id) || empty($project_id) || empty($entity_name)) {
      \Drupal::logger('baas_project')->warning('生成类名时缺少必要数据: tenant_id=@tenant_id, project_id=@project_id, name=@name', [
        '@tenant_id' => $tenant_id,
        '@project_id' => $project_id,
        '@name' => $entity_name,
      ]);
      return 'UnknownProjectEntity';
    }

    // 1. 转换为短格式（如果输入是长格式）
    $tenant_id = str_replace('tenant_', '', $tenant_id);
    $project_id = preg_replace('/^tenant_(.+?)_project_/', '$1_', $project_id);

    // 2. 处理 tenant_id：移除下划线
    $tenant_clean = str_replace('_', '', $tenant_id);

    // 3. 处理 project_id：提取 UUID 部分，移除下划线
    $project_parts = explode('_', $project_id);
    $project_uuid = $project_parts[1] ?? str_replace('_', '', $project_id);

    // 4. 处理实体名称：转换为驼峰命名
    $entity_parts = explode('_', $entity_name);
    $entity_parts = array_map('ucfirst', $entity_parts);
    $entity_name_formatted = implode('', $entity_parts);

    // 5. 组合最终类名
    // 格式: Project{tenant_id}{project_uuid}{EntityName}
    $class_name = 'Project' . $tenant_clean . $project_uuid . $entity_name_formatted;

    return $class_name;
  }

  /**
   * 检查实体文件是否存在。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $class_name
   *   类名。
   *
   * @return bool
   *   存在返回TRUE，否则返回FALSE。
   */
  protected function checkEntityFileExists(string $tenant_id, string $project_id, string $class_name): bool
  {
    $file_path = \Drupal::service('file_system')->realpath(
      'public://dynamic_entities/' . $tenant_id . '/projects/' . $project_id . '/Entity/' . $class_name . '.php'
    );

    return $file_path && file_exists($file_path);
  }

  /**
   * 检查存储文件是否存在。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $class_name
   *   类名。
   *
   * @return bool
   *   存在返回TRUE，否则返回FALSE。
   */
  protected function checkStorageFileExists(string $tenant_id, string $project_id, string $class_name): bool
  {
    $file_path = \Drupal::service('file_system')->realpath(
      'public://dynamic_entities/' . $tenant_id . '/projects/' . $project_id . '/Storage/' . $class_name . 'Storage.php'
    );

    return $file_path && file_exists($file_path);
  }

}