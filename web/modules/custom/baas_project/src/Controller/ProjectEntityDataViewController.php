<?php

declare(strict_types=1);

namespace Drupal\baas_project\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_project\Access\EntityAccessChecker;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\baas_project\Service\ProjectTableNameGenerator;

/**
 * 项目实体数据查看控制器。
 *
 * 提供实体数据的详细视图。
 */
class ProjectEntityDataViewController extends ControllerBase
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
   */
  public function __construct(
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly EntityAccessChecker $entityAccessChecker,
    protected readonly DateFormatterInterface $dateFormatter,
    protected readonly ProjectTableNameGenerator $tableNameGenerator
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
      $container->get('baas_project.table_name_generator')
    );
  }

  /**
   * 查看单条实体数据。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   * @param string $data_id
   *   数据ID。
   *
   * @return array
   *   渲染数组。
   */
  public function viewEntityData(string $tenant_id, string $project_id, string $entity_name, string $data_id): array
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

    // 获取实体数据
    $entity_data = $this->getEntityData($tenant_id, $project_id, $entity_name, $data_id);
    if (!$entity_data) {
      throw new NotFoundHttpException('找不到指定的数据记录。');
    }

    // 如果用户没有项目角色但有管理权限，设置为owner角色
    $effective_role = $user_role ?: ($has_admin_permission ? 'owner' : null);

    $build = [];
    $build['#attached']['library'][] = 'baas_project/entity-management';

    // 页面标题
    $build['header'] = [
      '#markup' => '<div class="entity-data-header">
        <h2>' . $this->t('数据详情：@title', ['@title' => $entity_data['title'] ?? $entity_data['id']]) . '</h2>
        <p>' . $this->t('实体：@name', ['@name' => $entity_template['label']]) . '</p>
      </div>',
    ];

    // 数据详情表格
    $build['data_details'] = $this->buildEntityDataDetails($entity_data, $tenant_id, $project_id, $entity_name);

    // 操作按钮
    $build['actions'] = [
      '#type' => 'actions',
      '#attributes' => ['class' => ['entity-data-actions']],
    ];

    if ($this->checkRolePermission($effective_role, 'update')) {
      $build['actions']['edit'] = [
        '#type' => 'link',
        '#title' => $this->t('编辑'),
        '#url' => Url::fromRoute('baas_project.entity_data_edit', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
          'entity_name' => $entity_name,
          'data_id' => $data_id,
        ]),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }

    if ($this->checkRolePermission($effective_role, 'delete')) {
      $build['actions']['delete'] = [
        '#type' => 'link',
        '#title' => $this->t('删除'),
        '#url' => Url::fromRoute('baas_project.entity_data_delete', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
          'entity_name' => $entity_name,
          'data_id' => $data_id,
        ]),
        '#attributes' => ['class' => ['button', 'button--danger']],
      ];
    }

    // 返回链接
    $build['back'] = [
      '#type' => 'link',
      '#title' => $this->t('返回数据列表'),
      '#url' => Url::fromRoute('baas_project.entity_data', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
        'entity_name' => $entity_name,
      ]),
      '#attributes' => ['class' => ['button']],
    ];

    return $build;
  }

  /**
   * 构建实体数据详情表格。
   *
   * @param array $data
   *   实体数据。
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
  protected function buildEntityDataDetails(array $data, string $tenant_id, string $project_id, string $entity_name): array
  {
    // 获取实体字段信息
    $entity_fields = $this->getEntityFields($tenant_id, $project_id, $entity_name);
    $field_info = [];
    foreach ($entity_fields as $field) {
      $field_info[$field['name']] = $field;
    }

    // 优化字段顺序，将系统字段放在后面
    $system_fields = ['id', 'uuid', 'tenant_id', 'project_id', 'created', 'updated'];
    $all_fields = array_keys($data);

    // 分离业务字段和系统字段
    $business_fields = array_diff($all_fields, $system_fields);
    $ordered_fields = array_merge(['id', 'title'], $business_fields, ['created', 'updated']);
    $ordered_fields = array_intersect($ordered_fields, $all_fields); // 确保所有字段都存在

    $rows = [];
    foreach ($ordered_fields as $field) {
      $value = $data[$field] ?? '';

      // 格式化特殊字段
      if ($field == 'created' || $field == 'updated') {
        // 格式化日期时间
        $value = !empty($value) ? $this->dateFormatter->format($value, 'medium') : '';
      } elseif (isset($field_info[$field]) && $field_info[$field]['type'] === 'reference') {
        // 处理引用字段
        $value = $this->formatReferenceField($value, $field_info[$field], $tenant_id, $project_id);
      } elseif ($this->isBoolean($field, $value)) {
        // 格式化布尔值
        $bool_value = (bool) $value;

        // 特殊处理void字段，0表示有效，1表示无效
        if ($field === 'void') {
          $value = $bool_value ? $this->t('否') : $this->t('是');
        } else {
          // 其他布尔字段，1表示是/真，0表示否/假
          $value = $bool_value ? $this->t('是') : $this->t('否');
        }
      }

      $rows[] = [
        'label' => [
          'data' => $this->getFieldLabel($field),
          'header' => TRUE,
        ],
        'value' => $value,
      ];
    }

    return [
      '#type' => 'table',
      '#rows' => $rows,
      '#attributes' => [
        'class' => ['entity-data-details'],
      ],
    ];
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
   * 获取单条实体数据。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   * @param string $data_id
   *   数据ID。
   *
   * @return array|null
   *   实体数据。
   */
  protected function getEntityData(string $tenant_id, string $project_id, string $entity_name, string $data_id): ?array
  {
    $database = \Drupal::database();
    $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);

    if (!$database->schema()->tableExists($table_name)) {
      \Drupal::logger('baas_project')->warning('实体数据表不存在: @table', ['@table' => $table_name]);
      return null;
    }

    try {
      $result = $database->select($table_name, 'e')
        ->fields('e')
        ->condition('id', $data_id)
        ->execute()
        ->fetch(\PDO::FETCH_ASSOC);

      return $result ?: null;
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('获取实体数据失败: @error', ['@error' => $e->getMessage()]);
      return null;
    }
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
   * 获取实体字段信息。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   *
   * @return array
   *   实体字段信息数组。
   */
  protected function getEntityFields(string $tenant_id, string $project_id, string $entity_name): array
  {
    $database = \Drupal::database();

    try {
      // 获取实体模板
      $template = $database->select('baas_entity_template', 'e')
        ->fields('e', ['id'])
        ->condition('tenant_id', $tenant_id)
        ->condition('project_id', $project_id)
        ->condition('name', $entity_name)
        ->condition('status', 1)
        ->execute()
        ->fetch(\PDO::FETCH_ASSOC);

      if (!$template) {
        return [];
      }

      // 获取字段信息
      $fields = $database->select('baas_entity_field', 'f')
        ->fields('f')
        ->condition('template_id', $template['id'])
        ->orderBy('weight')
        ->orderBy('name')
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      return $fields;
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('获取实体字段失败: @error', ['@error' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * 格式化引用字段的显示值。
   *
   * @param mixed $value
   *   引用字段值。
   * @param array $field_info
   *   字段信息。
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return string
   *   格式化后的显示值。
   */
  protected function formatReferenceField($value, array $field_info, string $tenant_id, string $project_id): string
  {
    if (empty($value)) {
      return '';
    }

    $field_settings = is_string($field_info['settings']) ? json_decode($field_info['settings'], TRUE) : ($field_info['settings'] ?? []);
    $target_entity = $field_settings['target_entity'] ?? '';
    $multiple = $field_settings['multiple'] ?? FALSE;

    if (empty($target_entity)) {
      return (string) $value;
    }

    try {
      if ($multiple) {
        // 多值引用字段
        $reference_ids = is_string($value) ? json_decode($value, TRUE) : $value;
        if (!is_array($reference_ids)) {
          return '';
        }

        $titles = [];
        foreach ($reference_ids as $ref_id) {
          if (is_numeric($ref_id)) {
            $title = $this->getReferencedEntityTitle($tenant_id, $project_id, $target_entity, (int) $ref_id);
            if ($title) {
              $titles[] = $title;
            }
          }
        }

        return implode(', ', $titles);
      } else {
        // 单值引用字段
        if (is_numeric($value)) {
          $title = $this->getReferencedEntityTitle($tenant_id, $project_id, $target_entity, (int) $value);
          return $title ?: (string) $value;
        }

        return (string) $value;
      }
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('格式化引用字段失败: @error', ['@error' => $e->getMessage()]);
      return (string) $value;
    }
  }

  /**
   * 获取引用实体的标题。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $target_entity
   *   目标实体名称。
   * @param int $entity_id
   *   实体ID。
   *
   * @return string|null
   *   实体标题。
   */
  protected function getReferencedEntityTitle(string $tenant_id, string $project_id, string $target_entity, int $entity_id): ?string
  {
    $database = \Drupal::database();

    try {
      // 生成目标实体的表名
      $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $target_entity);

      // 检查表是否存在
      if (!$database->schema()->tableExists($table_name)) {
        return null;
      }

      // 查询实体标题
      $title = $database->select($table_name, 'e')
        ->fields('e', ['title'])
        ->condition('id', $entity_id)
        ->condition('tenant_id', $tenant_id)
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchField();

      return $title ?: null;
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('获取引用实体标题失败: @error', [
        '@error' => $e->getMessage(),
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
        'target_entity' => $target_entity,
        'entity_id' => $entity_id,
      ]);
      return null;
    }
  }
}
