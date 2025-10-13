<?php

declare(strict_types=1);

namespace Drupal\baas_project\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\baas_project\ProjectManagerInterface;

/**
 * 项目级实体生成器服务。
 *
 * 基于baas_entity的EntityGenerator，为项目级实体模板生成动态实体类文件。
 */
class ProjectEntityGenerator
{

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly ProjectTableNameGenerator $tableNameGenerator
  ) {}

  /**
   * 为项目实体模板生成动态实体类文件。
   *
   * @param string $template_id
   *   实体模板ID。
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE。
   */
  public function generateProjectEntityFiles(string $template_id): bool
  {
    try {
      // 加载实体模板
      $template = $this->loadEntityTemplate($template_id);
      if (!$template) {
        \Drupal::logger('baas_project')->error('实体模板不存在: @template_id', ['@template_id' => $template_id]);
        return FALSE;
      }

      // 检查是否为项目级模板
      if (empty($template['project_id'])) {
        \Drupal::logger('baas_project')->warning('尝试为非项目级模板生成实体文件: @template_id', ['@template_id' => $template_id]);
        return FALSE;
      }

      // 获取字段列表
      $fields = $this->getTemplateFields($template_id);

      // 生成实体类代码
      $entity_class_code = $this->generateProjectEntityClassCode($template, $fields);
      $entity_storage_code = $this->generateProjectEntityStorageCode($template);

      // 获取保存路径
      $base_path = $this->getSavePath($template['tenant_id'], $template['project_id']);
      $entity_dir = $base_path . '/Entity';
      $storage_dir = $base_path . '/Storage';

      // 确保目录存在
      $this->fileSystem->prepareDirectory($entity_dir, FileSystemInterface::CREATE_DIRECTORY);
      $this->fileSystem->prepareDirectory($storage_dir, FileSystemInterface::CREATE_DIRECTORY);

      // 保存文件
      $class_name = $this->getProjectEntityClassName($template);
      $entity_class_path = $entity_dir . '/' . $class_name . '.php';
      $storage_class_path = $storage_dir . '/' . $class_name . 'Storage.php';

      $this->fileSystem->saveData($entity_class_code, $entity_class_path, FileSystemInterface::EXISTS_REPLACE);
      $this->fileSystem->saveData($entity_storage_code, $storage_class_path, FileSystemInterface::EXISTS_REPLACE);

      // 记录文件位置信息
      $this->recordProjectEntityFiles($template, $class_name);

      \Drupal::logger('baas_project')->notice('已生成项目实体文件: @entity_name (项目: @project_id)', [
        '@entity_name' => $template['name'],
        '@project_id' => $template['project_id'],
      ]);

      return TRUE;
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('生成项目实体文件失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 获取项目实体文件保存路径。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return string
   *   保存路径。
   */
  protected function getSavePath(string $tenant_id, string $project_id): string
  {
    $directory = 'public://dynamic_entities/' . $tenant_id . '/projects/' . $project_id;
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    return $directory;
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
    // 移除特殊字符并转换为驼峰命名
    $tenant_parts = explode('_', $template['tenant_id']);
    $tenant_parts = array_map('ucfirst', $tenant_parts);
    $tenant_prefix = implode('', $tenant_parts);

    $entity_parts = explode('_', $template['name']);
    $entity_parts = array_map('ucfirst', $entity_parts);
    $entity_name = implode('', $entity_parts);

    return $tenant_prefix . 'Project' . $entity_name;
  }

  /**
   * 生成项目实体类代码。
   *
   * @param array $template
   *   实体模板数据。
   * @param array $fields
   *   字段列表。
   *
   * @return string
   *   实体类代码。
   */
  protected function generateProjectEntityClassCode(array $template, array $fields): string
  {
    $class_name = $this->getProjectEntityClassName($template);
    $tenant_id = $template['tenant_id'];
    $project_id = $template['project_id'];
    $entity_name = $template['name'];
    $entity_label = $template['label'];
    $timestamp = date('Y-m-d H:i:s');

    // 生成字段定义
    $fields_definitions = '';
    foreach ($fields as $field) {
      // 跳过系统字段
      if (in_array($field['name'], ['id', 'uuid', 'created', 'updated', 'tenant_id', 'project_id'])) {
        continue;
      }

      $fields_definitions .= $this->generateFieldDefinition($field);
    }

    // 生成精简的表名和实体类型ID
    $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);
    $entity_type_id = $this->tableNameGenerator->generateEntityTypeId($tenant_id, $project_id, $entity_name);

    // 验证必要的值不为空
    if (empty($entity_type_id) || empty($entity_label) || empty($table_name)) {
      \Drupal::logger('baas_project')->error('生成实体类时缺少必要参数: type_id=@type_id, label=@label, table=@table', [
        '@type_id' => $entity_type_id,
        '@label' => $entity_label,
        '@table' => $table_name,
      ]);
      return '';
    }

    // 清理和验证字符串值
    $entity_type_id = trim($entity_type_id);
    $entity_label = trim($entity_label);
    $table_name = trim($table_name);
    $tenant_id = trim($tenant_id);
    $project_id = trim($project_id);
    $entity_name = trim($entity_name);

    // 生成实体类代码
    $code = <<<EOT
<?php

namespace Drupal\\baas_project\\Entity\\Dynamic;

use Drupal\\Core\\Entity\\ContentEntityBase;
use Drupal\\Core\\Entity\\EntityTypeInterface;
use Drupal\\Core\\Entity\\EntityStorageInterface;
use Drupal\\Core\\Field\\BaseFieldDefinition;

/**
 * 定义项目级动态实体类: {$entity_label}.
 *
 * 此文件由BaaS项目系统自动生成。
 * 生成时间: {$timestamp}
 * 表名: {$table_name}
 * 实体类型ID: {$entity_type_id}
 *
 * 注意：此类不使用@ContentEntityType注解，
 * 实体类型定义通过ProjectEntityRegistry服务动态注册。
 *
 * @ingroup baas_project
 */
class {$class_name} extends ContentEntityBase {

  /**
   * 租户ID。
   */
  const TENANT_ID = '{$tenant_id}';

  /**
   * 项目ID。
   */
  const PROJECT_ID = '{$project_id}';

  /**
   * 实体名称。
   */
  const ENTITY_NAME = '{$entity_name}';

  /**
   * {@inheritdoc}
   */
  public function __construct(array \$values = [], \$entity_type_id = NULL, \$bundle = NULL, ?array \$translations = NULL) {
    parent::__construct(\$values, '{$entity_type_id}', NULL, \$translations);

    // 确保设置租户ID和项目ID
    if (empty(\$this->get('tenant_id')->value)) {
      \$this->set('tenant_id', self::TENANT_ID);
    }
    if (empty(\$this->get('project_id')->value)) {
      \$this->set('project_id', self::PROJECT_ID);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface \$storage, array &\$values) {
    parent::preCreate(\$storage, \$values);

    // 确保设置租户ID和项目ID
    if (!isset(\$values['tenant_id'])) {
      \$values['tenant_id'] = self::TENANT_ID;
    }
    if (!isset(\$values['project_id'])) {
      \$values['project_id'] = self::PROJECT_ID;
    }

    // 生成UUID
    if (!isset(\$values['uuid'])) {
      \$values['uuid'] = \\Drupal::service('uuid')->generate();
    }

    // 设置创建和更新时间
    \$current_time = time();
    if (!isset(\$values['created'])) {
      \$values['created'] = \$current_time;
    }
    \$values['updated'] = \$current_time;
  }

  /**
   * 获取租户ID。
   *
   * @return string
   *   租户ID。
   */
  public function getTenantId(): string {
    return \$this->get('tenant_id')->value ?? self::TENANT_ID;
  }

  /**
   * 获取项目ID。
   *
   * @return string
   *   项目ID。
   */
  public function getProjectId(): string {
    return \$this->get('project_id')->value ?? self::PROJECT_ID;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface \$entity_type) {
    \$fields = parent::baseFieldDefinitions(\$entity_type);

    \$fields['tenant_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('租户ID'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::TENANT_ID)
      ->setReadOnly(TRUE)
      ->setSettings([
        'max_length' => 64,
      ]);

    \$fields['project_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('项目ID'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::PROJECT_ID)
      ->setReadOnly(TRUE)
      ->setSettings([
        'max_length' => 64,
      ]);

    \$fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE);

    \$fields['created'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('创建时间'))
      ->setRequired(TRUE)
      ->setDefaultValueCallback('time')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    \$fields['updated'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('更新时间'))
      ->setRequired(TRUE)
      ->setDefaultValueCallback('time')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 21,
      ])
      ->setDisplayConfigurable('view', TRUE);

{$fields_definitions}

    return \$fields;
  }

}

EOT;

    return $code;
  }

  /**
   * 生成字段定义代码。
   *
   * @param array $field
   *   字段数据。
   *
   * @return string
   *   字段定义代码。
   */
  protected function generateFieldDefinition(array $field): string
  {
    $settings = is_string($field['settings']) ? json_decode($field['settings'], TRUE) : ($field['settings'] ?? []);
    $required = $field['required'] ? 'TRUE' : 'FALSE';
    $weight = $field['weight'] ?? 0;
    
    // 确保字段描述不为空
    $description = !empty($field['description']) ? $field['description'] : $field['label'];
    $label = !empty($field['label']) ? $field['label'] : $field['name'];
    
    // 检查是否有唯一性约束
    $is_unique = !empty($settings['unique']) && $settings['unique'] == 1;

    switch ($field['type']) {
      case 'string':
        $max_length = $settings['max_length'] ?? 255;
        $unique_constraint = $is_unique ? "      ->addConstraint('UniqueField', [])" : '';
        return "
    \$fields['{$field['name']}'] = BaseFieldDefinition::create('string')
      ->setLabel(t('{$label}'))
      ->setDescription(t('{$description}'))
      ->setRequired({$required})
      ->setTranslatable(FALSE)
      ->setSettings([
        'max_length' => {$max_length},
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => {$weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => {$weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE){$unique_constraint};";

      case 'text':
        return "
    \$fields['{$field['name']}'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('{$label}'))
      ->setDescription(t('{$description}'))
      ->setRequired({$required})
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => {$weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => {$weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";

      case 'integer':
        $min_value = $settings['min_value'] ?? '';
        $max_value = $settings['max_value'] ?? '';
        $min_setting = $min_value !== '' ? "'min' => {$min_value}," : '';
        $max_setting = $max_value !== '' ? "'max' => {$max_value}," : '';

        return "
    \$fields['{$field['name']}'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('{$label}'))
      ->setDescription(t('{$description}'))
      ->setRequired({$required})
      ->setSettings([
        {$min_setting}
        {$max_setting}
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => {$weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => {$weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";

      case 'decimal':
        $precision = $settings['precision'] ?? 10;
        $scale = $settings['scale'] ?? 2;

        return "
    \$fields['{$field['name']}'] = BaseFieldDefinition::create('decimal')
      ->setLabel(t('{$label}'))
      ->setDescription(t('{$description}'))
      ->setRequired({$required})
      ->setSettings([
        'precision' => {$precision},
        'scale' => {$scale},
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_decimal',
        'weight' => {$weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => {$weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";

      case 'boolean':
        // 从字段设置中获取默认值
        $settings = json_decode($field['settings'] ?? '{}', TRUE);
        $default_value = 'FALSE';

        // 检查设置中是否有默认值
        if (isset($settings['default_value'])) {
          if ($settings['default_value'] === '1' || $settings['default_value'] === 1 || $settings['default_value'] === true || $settings['default_value'] === 'true') {
            $default_value = 'TRUE';
          } elseif ($settings['default_value'] === '0' || $settings['default_value'] === 0 || $settings['default_value'] === false || $settings['default_value'] === 'false') {
            $default_value = 'FALSE';
          }
        }

        return "
    \$fields['{$field['name']}'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('{$label}'))
      ->setDescription(t('{$description}'))
      ->setRequired({$required})
      ->setDefaultValue({$default_value})
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => {$weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => {$weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";

      case 'email':
        $unique_constraint = $is_unique ? "      ->addConstraint('UniqueField', [])" : '';
        return "
    \$fields['{$field['name']}'] = BaseFieldDefinition::create('email')
      ->setLabel(t('{$label}'))
      ->setDescription(t('{$description}'))
      ->setRequired({$required})
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'basic_string',
        'weight' => {$weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'email_default',
        'weight' => {$weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE){$unique_constraint};";

      case 'datetime':
        return "
    \$fields['{$field['name']}'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('{$label}'))
      ->setDescription(t('{$description}'))
      ->setRequired({$required})
      ->setSettings([
        'datetime_type' => 'datetime',
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'datetime_default',
        'weight' => {$weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => {$weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";

      case 'json':
        return "
    \$fields['{$field['name']}'] = BaseFieldDefinition::create('map')
      ->setLabel(t('{$label}'))
      ->setDescription(t('{$description}'))
      ->setRequired({$required})
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'map_default',
        'weight' => {$weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'map_default',
        'weight' => {$weight},
        'settings' => [
          'placeholder' => 'Enter valid JSON data',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";

      case 'list_string':
        $allowed_values = $settings['allowed_values'] ?? [];
        $multiple = $settings['multiple'] ?? FALSE;
        $cardinality = $multiple ? -1 : 1;
        
        // 格式化允许值
        $allowed_values_str = '';
        foreach ($allowed_values as $key => $label) {
          $allowed_values_str .= "        '{$key}' => t('{$label}'),\n";
        }
        
        return "
    \$fields['{$field['name']}'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('{$label}'))
      ->setDescription(t('{$description}'))
      ->setRequired({$required})
      ->setCardinality({$cardinality})
      ->setSettings([
        'allowed_values' => [
{$allowed_values_str}        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => {$weight},
      ])
      ->setDisplayOptions('form', [
        'type' => " . ($multiple ? "'options_buttons'" : "'options_select'") . ",
        'weight' => {$weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";

      case 'list_integer':
        $allowed_values = $settings['allowed_values'] ?? [];
        $multiple = $settings['multiple'] ?? FALSE;
        $cardinality = $multiple ? -1 : 1;
        
        // 格式化允许值
        $allowed_values_str = '';
        foreach ($allowed_values as $key => $label) {
          $allowed_values_str .= "        {$key} => t('{$label}'),\n";
        }
        
        return "
    \$fields['{$field['name']}'] = BaseFieldDefinition::create('list_integer')
      ->setLabel(t('{$label}'))
      ->setDescription(t('{$description}'))
      ->setRequired({$required})
      ->setCardinality({$cardinality})
      ->setSettings([
        'allowed_values' => [
{$allowed_values_str}        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => {$weight},
      ])
      ->setDisplayOptions('form', [
        'type' => " . ($multiple ? "'options_buttons'" : "'options_select'") . ",
        'weight' => {$weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";

      case 'reference':
        // 实体引用字段 - 使用integer存储引用的实体ID
        $target_type = $settings['target_type'] ?? 'node';
        $handler = $settings['handler'] ?? 'default';
        $handler_settings = $settings['handler_settings'] ?? [];
        
        // 格式化handler_settings
        $handler_settings_str = '';
        if (!empty($handler_settings)) {
          foreach ($handler_settings as $key => $value) {
            if (is_array($value)) {
              $value_str = "['" . implode("', '", $value) . "']";
            } else {
              $value_str = "'{$value}'";
            }
            $handler_settings_str .= "        '{$key}' => {$value_str},\n";
          }
        }
        
        return "
    \$fields['{$field['name']}'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('{$label}'))
      ->setDescription(t('{$description}'))
      ->setRequired({$required})
      ->setSettings([
        'target_type' => '{$target_type}',
        'handler' => '{$handler}',
        'handler_settings' => [
{$handler_settings_str}        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => {$weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => {$weight},
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";

      default:
        // 默认作为字符串字段处理
        return "
    \$fields['{$field['name']}'] = BaseFieldDefinition::create('string')
      ->setLabel(t('{$label}'))
      ->setDescription(t('{$description}'))
      ->setRequired({$required})
      ->setTranslatable(FALSE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => {$weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => {$weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";
    }
  }

  /**
   * 生成项目实体存储类代码。
   *
   * @param array $template
   *   实体模板数据。
   *
   * @return string
   *   存储类代码。
   */
  protected function generateProjectEntityStorageCode(array $template): string
  {
    $class_name = $this->getProjectEntityClassName($template);
    $tenant_id = $template['tenant_id'];
    $project_id = $template['project_id'];
    $entity_name = $template['name'];
    $entity_label = $template['label'];
    $timestamp = date('Y-m-d H:i:s');

    // 验证必要的值不为空
    if (empty($class_name) || empty($tenant_id) || empty($project_id) || empty($entity_name) || empty($entity_label)) {
      \Drupal::logger('baas_project')->error('生成存储类时缺少必要参数: class=@class, tenant=@tenant, project=@project, entity=@entity, label=@label', [
        '@class' => $class_name,
        '@tenant' => $tenant_id,
        '@project' => $project_id,
        '@entity' => $entity_name,
        '@label' => $entity_label,
      ]);
      return '';
    }

    // 清理和验证字符串值
    $class_name = trim($class_name);
    $tenant_id = trim($tenant_id);
    $project_id = trim($project_id);
    $entity_name = trim($entity_name);
    $entity_label = trim($entity_label);

    $code = <<<EOT
<?php

namespace Drupal\\baas_project\\Storage\\Dynamic;

use Drupal\\Core\\Entity\\Sql\\SqlContentEntityStorage;
use Drupal\\Core\\Session\\AccountInterface;
use Drupal\\Core\\Language\\LanguageInterface;

/**
 * 项目级动态实体存储类: {$entity_label}.
 *
 * 此文件由BaaS项目系统自动生成。
 * 生成时间: {$timestamp}
 */
class {$class_name}Storage extends SqlContentEntityStorage {

  /**
   * 租户ID。
   */
  const TENANT_ID = '{$tenant_id}';

  /**
   * 项目ID。
   */
  const PROJECT_ID = '{$project_id}';

  /**
   * 实体名称。
   */
  const ENTITY_NAME = '{$entity_name}';

  /**
   * {@inheritdoc}
   */
  public function loadByProperties(array \$values = []) {
    // 确保只加载当前租户和项目的实体
    \$values['tenant_id'] = self::TENANT_ID;
    \$values['project_id'] = self::PROJECT_ID;

    return parent::loadByProperties(\$values);
  }

  /**
   * 根据租户和项目过滤查询。
   *
   * @param \Drupal\Core\Database\Query\SelectInterface \$query
   *   查询对象。
   */
  protected function applyTenantProjectFilter(\$query) {
    \$query->condition('tenant_id', self::TENANT_ID);
    \$query->condition('project_id', self::PROJECT_ID);
  }

  /**
   * 获取租户专用的实体列表。
   *
   * @param int \$limit
   *   限制数量。
   * @param int \$offset
   *   偏移量。
   *
   * @return array
   *   实体列表。
   */
  public function loadTenantProjectEntities(int \$limit = 50, int \$offset = 0): array {
    \$query = \$this->getQuery()
      ->condition('tenant_id', self::TENANT_ID)
      ->condition('project_id', self::PROJECT_ID)
      ->range(\$offset, \$limit)
      ->sort('created', 'DESC');

    \$entity_ids = \$query->execute();

    return \$entity_ids ? \$this->loadMultiple(\$entity_ids) : [];
  }

  /**
   * 统计租户项目实体数量。
   *
   * @return int
   *   实体数量。
   */
  public function countTenantProjectEntities(): int {
    \$query = \$this->getQuery()
      ->condition('tenant_id', self::TENANT_ID)
      ->condition('project_id', self::PROJECT_ID)
      ->count();

    return (int) \$query->execute();
  }

}

EOT;

    return $code;
  }

  /**
   * 记录项目实体文件信息。
   *
   * @param array $template
   *   实体模板数据。
   * @param string $class_name
   *   类名。
   */
  protected function recordProjectEntityFiles(array $template, string $class_name): void
  {
    // 检查baas_entity_class_files表是否存在
    if (!$this->database->schema()->tableExists('baas_entity_class_files')) {
      return;
    }

    $entity_type_id = $this->tableNameGenerator->generateEntityTypeId($template['tenant_id'], $template['project_id'], $template['name']);
    $current_time = time();

    // 记录实体类文件位置
    $this->database->merge('baas_entity_class_files')
      ->key('entity_type_id', $entity_type_id)
      ->key('class_type', 'ProjectEntity')
      ->fields([
        'tenant_id' => $template['tenant_id'],
        'class_name' => $class_name,
        'file_path' => 'dynamic_entities/' . $template['tenant_id'] . '/projects/' . $template['project_id'] . '/Entity/' . $class_name . '.php',
        'created' => $current_time,
        'updated' => $current_time,
      ])
      ->execute();

    // 记录存储类文件位置
    $this->database->merge('baas_entity_class_files')
      ->key('entity_type_id', $entity_type_id)
      ->key('class_type', 'ProjectStorage')
      ->fields([
        'tenant_id' => $template['tenant_id'],
        'class_name' => $class_name . 'Storage',
        'file_path' => 'dynamic_entities/' . $template['tenant_id'] . '/projects/' . $template['project_id'] . '/Storage/' . $class_name . 'Storage.php',
        'created' => $current_time,
        'updated' => $current_time,
      ])
      ->execute();
  }

  /**
   * 加载实体模板。
   *
   * @param string $template_id
   *   模板ID。
   *
   * @return array|null
   *   模板数据。
   */
  protected function loadEntityTemplate(string $template_id): ?array
  {
    $template = $this->database->select('baas_entity_template', 'e')
      ->fields('e')
      ->condition('id', $template_id)
      ->execute()
      ->fetch(\PDO::FETCH_ASSOC);

    return $template ?: NULL;
  }

  /**
   * 获取模板字段。
   *
   * @param string $template_id
   *   模板ID。
   *
   * @return array
   *   字段列表。
   */
  protected function getTemplateFields(string $template_id): array
  {
    if (!$this->database->schema()->tableExists('baas_entity_field')) {
      return [];
    }

    $fields = $this->database->select('baas_entity_field', 'f')
      ->fields('f')
      ->condition('template_id', $template_id)
      ->orderBy('weight')
      ->orderBy('name')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    return $fields;
  }
}