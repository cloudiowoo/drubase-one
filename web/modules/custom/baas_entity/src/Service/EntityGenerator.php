<?php

namespace Drupal\baas_entity\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\baas_tenant\TenantManagerInterface;

/**
 * 实体生成器服务.
 *
 * 根据实体模板动态生成实体类型与表结构.
 */
class EntityGenerator
{

  /**
   * 实体类型管理器服务。
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * 数据库连接服务。
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * 文件系统服务。
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * 模块处理器服务。
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * 租户管理服务。
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected $tenantManager;

  /**
   * 表名生成器服务（可选）。
   *
   * @var \Drupal\baas_project\Service\ProjectTableNameGenerator|null
   */
  protected $tableNameGenerator;

  /**
   * 构造函数.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   实体类型管理器服务。
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接服务。
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   文件系统服务。
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   模块处理器服务。
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理服务。
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    FileSystemInterface $file_system,
    ModuleHandlerInterface $module_handler,
    TenantManagerInterface $tenant_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->fileSystem = $file_system;
    $this->moduleHandler = $module_handler;
    $this->tenantManager = $tenant_manager;

    // 尝试获取表名生成器服务（如果baas_project模块存在）
    if (\Drupal::hasService('baas_project.table_name_generator')) {
      $this->tableNameGenerator = \Drupal::service('baas_project.table_name_generator');
    }
  }

  /**
   * 获取动态实体文件保存路径.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return string
   *   保存路径.
   */
  protected function getSavePath(string $tenant_id): string
  {
    $directory = 'public://dynamic_entities/' . $tenant_id;
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    return $directory;
  }

  /**
   * 创建实体类型.
   *
   * @param object $template
   *   实体模板.
   * @param array $fields
   *   字段列表.
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE.
   */
  public function createEntityType(object $template, array $fields): bool
  {
    try {
      // 生成实体类代码
      $entity_class_code = $this->generateEntityClassCode($template);
      $entity_storage_code = $this->generateEntityStorageCode($template);

      // 获取保存路径
      $base_path = $this->getSavePath($template->tenant_id);
      $entity_dir = $base_path . '/Entity';
      $storage_dir = $base_path . '/Storage';

      // 确保目录存在
      $this->fileSystem->prepareDirectory($entity_dir, FileSystemInterface::CREATE_DIRECTORY);
      $this->fileSystem->prepareDirectory($storage_dir, FileSystemInterface::CREATE_DIRECTORY);

      // 保存文件
      $class_name = $this->getEntityClassName($template);
      $entity_class_path = $entity_dir . '/' . $class_name . '.php';
      $storage_class_path = $storage_dir . '/' . $class_name . 'Storage.php';

      $this->fileSystem->saveData($entity_class_code, $entity_class_path, FileSystemInterface::EXISTS_REPLACE);
      $this->fileSystem->saveData($entity_storage_code, $storage_class_path, FileSystemInterface::EXISTS_REPLACE);

      // 记录文件位置信息 - 使用正确的实体类型ID
      if ($this->tableNameGenerator && !empty($template->project_id)) {
        $entity_type_id = $this->tableNameGenerator->generateEntityTypeId(
          $template->tenant_id,
          $template->project_id,
          $template->name
        );
      } else {
        // 回退到旧格式
        $entity_type_id = $template->tenant_id . '_' . $template->name;
      }
      $current_time = time();

      // 检查baas_entity_class_files表是否存在
      if ($this->database->schema()->tableExists('baas_entity_class_files')) {
        // 记录实体类文件位置
        $this->database->merge('baas_entity_class_files')
          ->key('entity_type_id', $entity_type_id)
          ->key('class_type', 'Entity')
          ->fields([
            'tenant_id' => $template->tenant_id,
            'class_name' => $class_name,
            'file_path' => 'dynamic_entities/' . $template->tenant_id . '/Entity/' . $class_name . '.php',
            'created' => $current_time,
            'updated' => $current_time,
          ])
          ->execute();

        // 记录存储类文件位置
        $this->database->merge('baas_entity_class_files')
          ->key('entity_type_id', $entity_type_id)
          ->key('class_type', 'Storage')
          ->fields([
            'tenant_id' => $template->tenant_id,
            'class_name' => $class_name . 'Storage',
            'file_path' => 'dynamic_entities/' . $template->tenant_id . '/Storage/' . $class_name . 'Storage.php',
            'created' => $current_time,
            'updated' => $current_time,
          ])
          ->execute();
      }

      // 创建数据库表
      $this->createEntityTable($template, $fields);

      return TRUE;
    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('创建实体类型失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 生成实体类代码.
   *
   * @param object $template
   *   实体模板.
   *
   * @return string
   *   实体类代码.
   */
  protected function generateEntityClassCode(object $template): string
  {
    $class_name = $this->getEntityClassName($template);
    $tenant_id = $template->tenant_id;
    $timestamp = date('Y-m-d');

    // 获取模板的所有字段，用于生成字段定义
    $fields_definitions = '';

    // 获取模板字段
    $template_fields = \Drupal::service('baas_entity.template_manager')->getTemplateFields($template->id);

    // 生成自定义字段定义
    foreach ($template_fields as $field) {
      // 跳过系统字段
      if (in_array($field->name, ['id', 'uuid', 'title', 'created', 'updated', 'tenant_id'])) {
        continue;
      }

      // 根据字段类型创建不同的字段定义
      switch ($field->type) {
        case 'string':
          $max_length = isset($field->settings['max_length']) ? (int) $field->settings['max_length'] : 255;
          $fields_definitions .= "
    \$fields['{$field->name}'] = BaseFieldDefinition::create('string')
      ->setLabel(t('{$field->label}'))
      ->setDescription(t('{$field->description}'))
      ->setRequired(" . ($field->required ? 'TRUE' : 'FALSE') . ")
      ->setTranslatable(FALSE)
      ->setSettings([
        'max_length' => {$max_length},
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => {$field->weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => {$field->weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";
          break;

        case 'text':
          $fields_definitions .= "
    \$fields['{$field->name}'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('{$field->label}'))
      ->setDescription(t('{$field->description}'))
      ->setRequired(" . ($field->required ? 'TRUE' : 'FALSE') . ")
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => {$field->weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => {$field->weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";
          break;

        case 'integer':
          $fields_definitions .= "
    \$fields['{$field->name}'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('{$field->label}'))
      ->setDescription(t('{$field->description}'))
      ->setRequired(" . ($field->required ? 'TRUE' : 'FALSE') . ")
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'integer',
        'weight' => {$field->weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => {$field->weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";
          break;

        case 'float':
          $fields_definitions .= "
    \$fields['{$field->name}'] = BaseFieldDefinition::create('float')
      ->setLabel(t('{$field->label}'))
      ->setDescription(t('{$field->description}'))
      ->setRequired(" . ($field->required ? 'TRUE' : 'FALSE') . ")
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'float',
        'weight' => {$field->weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => {$field->weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";
          break;

        case 'boolean':
          $fields_definitions .= "
    \$fields['{$field->name}'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('{$field->label}'))
      ->setDescription(t('{$field->description}'))
      ->setRequired(" . ($field->required ? 'TRUE' : 'FALSE') . ")
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => {$field->weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => {$field->weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";
          break;

        case 'email':
          $fields_definitions .= "
    \$fields['{$field->name}'] = BaseFieldDefinition::create('email')
      ->setLabel(t('{$field->label}'))
      ->setDescription(t('{$field->description}'))
      ->setRequired(" . ($field->required ? 'TRUE' : 'FALSE') . ")
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'email_mailto',
        'weight' => {$field->weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'email_default',
        'weight' => {$field->weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";
          break;

        case 'url':
          $fields_definitions .= "
    \$fields['{$field->name}'] = BaseFieldDefinition::create('link')
      ->setLabel(t('{$field->label}'))
      ->setDescription(t('{$field->description}'))
      ->setRequired(" . ($field->required ? 'TRUE' : 'FALSE') . ")
      ->setTranslatable(FALSE)
      ->setSettings([
        'link_type' => 17,
        'title' => 1,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'link',
        'weight' => {$field->weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'link_default',
        'weight' => {$field->weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";
          break;

        case 'datetime':
          $fields_definitions .= "
    \$fields['{$field->name}'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('{$field->label}'))
      ->setDescription(t('{$field->description}'))
      ->setRequired(" . ($field->required ? 'TRUE' : 'FALSE') . ")
      ->setTranslatable(FALSE)
      ->setSettings([
        'datetime_type' => 'datetime',
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'datetime_default',
        'weight' => {$field->weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => {$field->weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";
          break;

        case 'list_string':
          // 处理允许值设置
          $allowed_values_code = '';
          if (isset($field->settings['allowed_values']) && is_array($field->settings['allowed_values'])) {
            $allowed_values_array = [];
            foreach ($field->settings['allowed_values'] as $key => $label) {
              $escaped_key = addslashes($key);
              $escaped_label = addslashes($label);
              $allowed_values_array[] = "        '{$escaped_key}' => '{$escaped_label}'";
            }
            $allowed_values_code = "[\n" . implode(",\n", $allowed_values_array) . "\n      ]";
          } else {
            $allowed_values_code = "[]";
          }

          $multiple = isset($field->settings['multiple']) && $field->settings['multiple'] ? 'TRUE' : 'FALSE';
          $cardinality = $multiple === 'TRUE' ? '-1' : '1';

          $fields_definitions .= "
    \$fields['{$field->name}'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('{$field->label}'))
      ->setDescription(t('{$field->description}'))
      ->setRequired(" . ($field->required ? 'TRUE' : 'FALSE') . ")
      ->setTranslatable(FALSE)
      ->setCardinality({$cardinality})
      ->setSettings([
        'allowed_values' => {$allowed_values_code},
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => {$field->weight},
      ])
      ->setDisplayOptions('form', [
        'type' => " . ($multiple === 'TRUE' ? "'options_buttons'" : "'options_select'") . ",
        'weight' => {$field->weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";
          break;

        case 'list_integer':
          // 处理允许值设置
          $allowed_values_code = '';
          if (isset($field->settings['allowed_values']) && is_array($field->settings['allowed_values'])) {
            $allowed_values_array = [];
            foreach ($field->settings['allowed_values'] as $key => $label) {
              $escaped_key = (int) $key;
              $escaped_label = addslashes($label);
              $allowed_values_array[] = "        {$escaped_key} => '{$escaped_label}'";
            }
            $allowed_values_code = "[\n" . implode(",\n", $allowed_values_array) . "\n      ]";
          } else {
            $allowed_values_code = "[]";
          }

          $multiple = isset($field->settings['multiple']) && $field->settings['multiple'] ? 'TRUE' : 'FALSE';
          $cardinality = $multiple === 'TRUE' ? '-1' : '1';

          $fields_definitions .= "
    \$fields['{$field->name}'] = BaseFieldDefinition::create('list_integer')
      ->setLabel(t('{$field->label}'))
      ->setDescription(t('{$field->description}'))
      ->setRequired(" . ($field->required ? 'TRUE' : 'FALSE') . ")
      ->setTranslatable(FALSE)
      ->setCardinality({$cardinality})
      ->setSettings([
        'allowed_values' => {$allowed_values_code},
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => {$field->weight},
      ])
      ->setDisplayOptions('form', [
        'type' => " . ($multiple === 'TRUE' ? "'options_buttons'" : "'options_select'") . ",
        'weight' => {$field->weight},
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";
          break;

        case 'reference':
          // 获取引用字段设置
          $target_type = isset($field->settings['target_type']) ? $field->settings['target_type'] : 'node';
          $multiple = isset($field->settings['multiple']) && $field->settings['multiple'] ? 'TRUE' : 'FALSE';
          $cardinality = $multiple === 'TRUE' ? '-1' : '1';
          
          // 构建handler设置
          $handler_settings = [];
          if (isset($field->settings['target_bundles']) && is_array($field->settings['target_bundles'])) {
            $target_bundles_array = [];
            foreach ($field->settings['target_bundles'] as $bundle) {
              $target_bundles_array[] = "        '{$bundle}' => '{$bundle}'";
            }
            $handler_settings[] = "        'target_bundles' => [\n" . implode(",\n", $target_bundles_array) . "\n        ]";
          }
          
          if (isset($field->settings['sort']['field'])) {
            $sort_field = $field->settings['sort']['field'];
            $sort_direction = isset($field->settings['sort']['direction']) ? $field->settings['sort']['direction'] : 'ASC';
            $handler_settings[] = "        'sort' => [\n          'field' => '{$sort_field}',\n          'direction' => '{$sort_direction}'\n        ]";
          }
          
          if (isset($field->settings['auto_create']) && $field->settings['auto_create']) {
            $handler_settings[] = "        'auto_create' => TRUE";
            if (isset($field->settings['auto_create_bundle'])) {
              $handler_settings[] = "        'auto_create_bundle' => '{$field->settings['auto_create_bundle']}'";
            }
          }
          
          $handler_settings_code = '';
          if (!empty($handler_settings)) {
            $handler_settings_code = ",\n      'handler_settings' => [\n" . implode(",\n", $handler_settings) . "\n      ]";
          }
          
          $fields_definitions .= "
    \$fields['{$field->name}'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('{$field->label}'))
      ->setDescription(t('{$field->description}'))
      ->setRequired(" . ($field->required ? 'TRUE' : 'FALSE') . ")
      ->setTranslatable(FALSE)
      ->setCardinality({$cardinality})
      ->setSettings([
        'target_type' => '{$target_type}'{$handler_settings_code}
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => {$field->weight},
        'settings' => [
          'link' => TRUE,
        ],
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => {$field->weight},
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => 60,
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";
          break;

        case 'json':
          $fields_definitions .= "
    \$fields['{$field->name}'] = BaseFieldDefinition::create('map')
      ->setLabel(t('{$field->label}'))
      ->setDescription(t('{$field->description}'))
      ->setRequired(" . ($field->required ? 'TRUE' : 'FALSE') . ")
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'map_default',
        'weight' => {$field->weight},
      ])
      ->setDisplayOptions('form', [
        'type' => 'map_default',
        'weight' => {$field->weight},
        'settings' => [
          'placeholder' => 'Enter valid JSON data',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);";
          break;
      }
    }

    $code = <<<EOT
<?php

namespace Drupal\\baas_entity\\Entity\\Dynamic;

use Drupal\\Core\\Entity\\ContentEntityBase;
use Drupal\\Core\\Entity\\EntityTypeInterface;
use Drupal\\Core\\Entity\\EntityStorageInterface;
use Drupal\\Core\\Field\\BaseFieldDefinition;

/**
 * 定义动态生成的实体类: {$template->label}.
 *
 * @ingroup baas_entity
 *
 * @ContentEntityType(
 *   id = "{$tenant_id}_{$template->name}",
 *   label = @Translation("{$template->label}"),
 *   base_table = "tenant_{$tenant_id}_{$template->name}",
 *   admin_permission = "administer {$tenant_id}_{$template->name} entities",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "title",
 *   },
 *   handlers = {
 *     "storage" = "Drupal\\baas_entity\\Storage\\Dynamic\\{$class_name}Storage",
 *     "form" = {
 *       "default" = "Drupal\\baas_entity\\Form\\Dynamic\\{$class_name}Form",
 *       "delete" = "Drupal\\baas_entity\\Form\\Dynamic\\{$class_name}DeleteForm",
 *     },
 *     "access" = "Drupal\\baas_entity\\Access\\DynamicEntityAccessControlHandler",
 *     "list_builder" = "Drupal\\baas_entity\\Controller\\Dynamic\\{$class_name}ListBuilder",
 *   },
 *   links = {
 *     "canonical" = "/{$tenant_id}/{$template->name}/{{entity}}",
 *     "collection" = "/{$tenant_id}/{$template->name}",
 *     "add-form" = "/{$tenant_id}/{$template->name}/add",
 *     "edit-form" = "/{$tenant_id}/{$template->name}/{{entity}}/edit",
 *     "delete-form" = "/{$tenant_id}/{$template->name}/{{entity}}/delete",
 *   },
 * )
 */
class {$class_name} extends ContentEntityBase {

  /**
   * {@inheritdoc}
   */
  public function __construct(array \$values = [], \$entity_type_id = NULL, \$bundle = NULL, ?array \$translations = NULL) {
    // 如果使用旧的参数格式，适配新格式
    if (\$entity_type_id instanceof EntityTypeInterface) {
      parent::__construct(\$values, '{$tenant_id}_{$template->name}');
    } else {
      parent::__construct(\$values, '{$tenant_id}_{$template->name}', NULL, \$translations);
    }

    // 确保设置租户ID
    if (empty(\$this->get('tenant_id')->value)) {
      \$this->set('tenant_id', '{$tenant_id}');
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface \$storage, array &\$values) {
    parent::preCreate(\$storage, \$values);
    // 确保设置租户ID
    if (!isset(\$values['tenant_id'])) {
      \$values['tenant_id'] = '{$tenant_id}';
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface \$entity_type) {
    \$fields = parent::baseFieldDefinitions(\$entity_type);

    \$fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Title'))
      ->setRequired(TRUE)
      ->setTranslatable(FALSE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    \$fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time the entity was created.'))
      ->setTranslatable(FALSE)
      ->setDisplayConfigurable('view', TRUE);

    \$fields['updated'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('updated'))
      ->setDescription(t('The time the entity was last edited.'))
      ->setTranslatable(FALSE)
      ->setDisplayConfigurable('view', TRUE);

    \$fields['tenant_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Tenant ID'))
      ->setDescription(t('The tenant ID this entity belongs to.'))
      ->setDefaultValue('{$tenant_id}')
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayConfigurable('form', FALSE);
{$fields_definitions}

    return \$fields;
  }

}
EOT;

    return $code;
  }

  /**
   * 生成实体存储类代码.
   *
   * @param object $template
   *   实体模板.
   *
   * @return string
   *   实体存储类代码.
   */
  protected function generateEntityStorageCode(object $template): string
  {
    $class_name = $this->getEntityClassName($template);
    $timestamp = date('Y-m-d');

    $code = <<<EOT
<?php

namespace Drupal\\baas_entity\\Storage\\Dynamic;

use Drupal\\Core\\Entity\\Sql\\SqlContentEntityStorage;

/**
 * 定义动态生成的实体存储: {$template->label}.
 *
 * @ingroup baas_entity
 */
class {$class_name}Storage extends SqlContentEntityStorage {

  /**
   * {@inheritdoc}
   */
  protected function doCreate(array \$values) {
    // 确保设置租户ID
    if (!isset(\$values['tenant_id'])) {
      \$values['tenant_id'] = '{$template->tenant_id}';
    }
    return parent::doCreate(\$values);
  }

  /**
   * {@inheritdoc}
   */
  protected function doSave(\$id, \$entity) {
    // 保存前确保租户ID正确
    if (\$entity->tenant_id->value !== '{$template->tenant_id}') {
      \$entity->tenant_id->value = '{$template->tenant_id}';
    }
    return parent::doSave(\$id, \$entity);
  }

}
EOT;

    return $code;
  }

  /**
   * 创建实体数据表.
   *
   * @param object $template
   *   实体模板.
   * @param array $fields
   *   字段列表.
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE.
   */
  protected function createEntityTable(object $template, array $fields): bool
  {
    try {
      // 使用项目表名生成器（如果可用）来生成正确的表名
      if ($this->tableNameGenerator && !empty($template->project_id)) {
        $table_name = $this->tableNameGenerator->generateTableName(
          $template->tenant_id,
          $template->project_id,
          $template->name
        );
      } else {
        // 回退到旧格式
        $table_name = "tenant_{$template->tenant_id}_{$template->name}";
      }

      // 检查表是否已存在
      if ($this->database->schema()->tableExists($table_name)) {
        \Drupal::logger('baas_entity')->notice('实体表已存在: @table', [
          '@table' => $table_name,
        ]);
        return TRUE;
      }

      // 创建基本表结构
      $schema = [
        'description' => "存储{$template->label}实体数据",
        'fields' => [
          'id' => [
            'type' => 'serial',
            'not null' => TRUE,
            'description' => '主键',
          ],
          'uuid' => [
            'type' => 'varchar',
            'length' => 128,
            'not null' => TRUE,
            'description' => 'UUID标识',
          ],
          'title' => [
            'type' => 'varchar',
            'length' => 255,
            'not null' => TRUE,
            'description' => '标题',
          ],
          'created' => [
            'type' => 'int',
            'not null' => TRUE,
            'description' => '创建时间',
            'default' => 0,
          ],
          'updated' => [
            'type' => 'int',
            'not null' => TRUE,
            'description' => '修改时间',
            'default' => 0,
          ],
          'tenant_id' => [
            'type' => 'varchar',
            'length' => 64,
            'not null' => TRUE,
            'description' => '租户ID',
            'default' => $template->tenant_id,
          ],
        ],
        'primary key' => ['id'],
        'unique keys' => [
          'uuid' => ['uuid'],
        ],
        'indexes' => [
          'tenant_id' => ['tenant_id'],
        ],
      ];

      // 添加自定义字段到表结构
      foreach ($fields as $field) {
        $field_name = $field->name;
        $field_type = $field->type;

        // 跳过已存在的基础字段
        if (in_array($field_name, ['id', 'uuid', 'title', 'created', 'updated', 'tenant_id'])) {
          continue;
        }

        // 获取字段数据库定义
        $field_schema = $this->getFieldSchema($field_type, $field);
        if ($field_schema) {
          // 处理可能返回多个列的情况（如text_long类型和url类型）
          if (is_array($field_schema) && !isset($field_schema['type'])) {
            // 这是一个复合字段，包含多个列定义
            foreach ($field_schema as $column_name => $column_def) {
              $schema['fields'][$column_name] = $column_def;
            }
          } else {
            // 处理单一字段
            $schema['fields'][$field_name] = $field_schema;
          }
        }
      }

      // 创建表
      $this->database->schema()->createTable($table_name, $schema);

      return TRUE;
    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('创建实体表失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 获取实体类名.
   *
   * @param object $template
   *   实体模板.
   *
   * @return string
   *   实体类名.
   */
  protected function getEntityClassName(object $template): string
  {
    // 生成Pascal格式的类名
    $name_parts = explode('_', $template->name);
    $name_parts = array_map('ucfirst', $name_parts);
    $class_base = implode('', $name_parts);

    // 加上租户标识
    $tenant_parts = explode('_', $template->tenant_id);
    $tenant_parts = array_map('ucfirst', $tenant_parts);
    $tenant_prefix = implode('', $tenant_parts);

    return $tenant_prefix . $class_base;
  }

  /**
   * 删除实体类型.
   *
   * @param object $template
   *   实体模板.
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE.
   */
  public function deleteEntityType(object $template): bool
  {
    try {
      $table_name = "tenant_{$template->tenant_id}_{$template->name}";

      // 删除表
      if ($this->database->schema()->tableExists($table_name)) {
        $this->database->schema()->dropTable($table_name);
      }

      // 删除类文件
      $class_name = $this->getEntityClassName($template);
      $base_path = $this->getSavePath($template->tenant_id);

      $entity_class_path = $base_path . '/Entity/' . $class_name . '.php';
      $storage_class_path = $base_path . '/Storage/' . $class_name . 'Storage.php';

      if (file_exists($entity_class_path)) {
        unlink($entity_class_path);
      }

      if (file_exists($storage_class_path)) {
        unlink($storage_class_path);
      }

      return TRUE;
    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('删除实体类型失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 更新实体类型.
   *
   * @param object $template
   *   实体模板.
   * @param array $fields
   *   字段列表.
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE.
   */
  public function updateEntityType(object $template, array $fields): bool
  {
    try {
      // 重新生成实体类
      $entity_class_code = $this->generateEntityClassCode($template);
      $entity_storage_code = $this->generateEntityStorageCode($template);

      // 获取保存路径
      $base_path = $this->getSavePath($template->tenant_id);
      $entity_dir = $base_path . '/Entity';
      $storage_dir = $base_path . '/Storage';

      // 确保目录存在
      $this->fileSystem->prepareDirectory($entity_dir, FileSystemInterface::CREATE_DIRECTORY);
      $this->fileSystem->prepareDirectory($storage_dir, FileSystemInterface::CREATE_DIRECTORY);

      // 保存文件
      $class_name = $this->getEntityClassName($template);
      $entity_class_path = $entity_dir . '/' . $class_name . '.php';
      $storage_class_path = $storage_dir . '/' . $class_name . 'Storage.php';

      $this->fileSystem->saveData($entity_class_code, $entity_class_path, FileSystemInterface::EXISTS_REPLACE);
      $this->fileSystem->saveData($entity_storage_code, $storage_class_path, FileSystemInterface::EXISTS_REPLACE);

      // 更新数据库表结构
      $table_name = "tenant_{$template->tenant_id}_{$template->name}";

      // 检查表是否存在
      if (!$this->database->schema()->tableExists($table_name)) {
        // 表不存在，创建新表
        $this->createEntityTable($template, $fields);
        \Drupal::logger('baas_entity')->notice('创建新实体表: @table', [
          '@table' => $table_name,
        ]);
      } else {
        // 获取现有的表字段
        $existing_fields = [];
        try {
          $schema = $this->database->schema();
          // 直接查询数据库获取字段信息，避免使用fieldNames方法
          $existing_fields_query = $this->database->query("SELECT column_name FROM information_schema.columns WHERE table_name = :table", [
            ':table' => $table_name,
          ]);
          foreach ($existing_fields_query as $record) {
            $existing_fields[] = $record->column_name;
          }
          \Drupal::logger('baas_entity')->notice('获取表字段成功: @table, 共 @count 个字段', [
            '@table' => $table_name,
            '@count' => count($existing_fields),
          ]);
        } catch (\Exception $e) {
          \Drupal::logger('baas_entity')->error('获取表字段失败: @error', [
            '@error' => $e->getMessage(),
          ]);
        }

        // 创建当前字段列表，用于检测删除的字段
        $current_field_names = ['id', 'uuid', 'title', 'created', 'updated', 'tenant_id'];
        $composite_field_names = [];

        foreach ($fields as $field) {
          $current_field_names[] = $field->name;
          // 记录复合字段类型，它们会有特殊的后缀
          if ($field->type === 'text') {
            $composite_field_names[] = $field->name . '__value';
            $composite_field_names[] = $field->name . '__format';
          } elseif ($field->type === 'url') {
            $composite_field_names[] = $field->name . '__uri';
            $composite_field_names[] = $field->name . '__title';
          }
        }

        // 合并普通字段名和复合字段名
        $all_field_names = array_merge($current_field_names, $composite_field_names);

        // 处理删除的字段：如果现有字段不在当前字段列表中，也不是复合字段的一部分，则删除它
        foreach ($existing_fields as $existing_field) {
          // 检查是否是普通字段或复合字段的一部分
          if (!in_array($existing_field, $all_field_names)) {
            // 进一步检查是否是复合字段的后缀
            $is_composite_field = false;
            foreach ($current_field_names as $field_name) {
              if (
                $existing_field === $field_name . '__value' ||
                $existing_field === $field_name . '__format' ||
                $existing_field === $field_name . '__uri' ||
                $existing_field === $field_name . '__title'
              ) {
                $is_composite_field = true;
                break;
              }
            }

            // 如果不是复合字段的一部分，则删除
            if (!$is_composite_field) {
              try {
                $schema->dropField($table_name, $existing_field);
                \Drupal::logger('baas_entity')->notice('删除字段: @table.@field', [
                  '@table' => $table_name,
                  '@field' => $existing_field,
                ]);
              } catch (\Exception $e) {
                \Drupal::logger('baas_entity')->error('删除字段失败: @error', [
                  '@error' => $e->getMessage(),
                ]);
              }
            }
          }
        }

        // 处理字段的添加和更新
        foreach ($fields as $field) {
          $field_name = $field->name;
          $field_type = $field->type;

          // 跳过已存在的基础字段
          if (in_array($field_name, ['id', 'uuid', 'title', 'created', 'updated', 'tenant_id'])) {
            continue;
          }

          // 获取字段数据库定义
          $field_schema = $this->getFieldSchema($field_type, $field);

          // 处理可能返回多个列的情况（如text_long类型和url类型）
          if (is_array($field_schema) && !isset($field_schema['type'])) {
            // 这是一个复合字段，包含多个列定义
            foreach ($field_schema as $column_name => $column_def) {
              // 检查字段是否存在
              $field_exists = in_array($column_name, $existing_fields);

              // 如果字段不存在，先检查是否存在带前缀的字段（兼容旧表结构）
              if (!$field_exists && strpos($column_name, '__') !== false) {
                // 对于复合字段，检查是否存在不带后缀的旧格式字段
                $base_field_name = substr($column_name, 0, strpos($column_name, '__'));
                $field_exists = in_array($base_field_name, $existing_fields);

                // 如果存在旧格式字段，先删除它，再创建新格式字段
                if ($field_exists) {
                  try {
                    $schema->dropField($table_name, $base_field_name);
                    \Drupal::logger('baas_entity')->notice('删除旧格式字段以升级: @table.@field', [
                      '@table' => $table_name,
                      '@field' => $base_field_name,
                    ]);
                    $field_exists = false; // 标记为不存在，以便后续创建新字段
                  } catch (\Exception $e) {
                    \Drupal::logger('baas_entity')->error('删除旧格式字段失败: @error', [
                      '@error' => $e->getMessage(),
                    ]);
                  }
                }
              }

              if ($field_exists) {
                // 更新字段
                try {
                  $schema->changeField($table_name, $column_name, $column_name, $column_def);
                  \Drupal::logger('baas_entity')->notice('更新复合字段: @table.@field', [
                    '@table' => $table_name,
                    '@field' => $column_name,
                  ]);
                } catch (\Exception $e) {
                  \Drupal::logger('baas_entity')->error('更新复合字段失败: @error', [
                    '@error' => $e->getMessage(),
                  ]);
                }
              } else {
                // 添加新字段
                try {
                  $schema->addField($table_name, $column_name, $column_def);
                  \Drupal::logger('baas_entity')->notice('添加复合字段: @table.@field', [
                    '@table' => $table_name,
                    '@field' => $column_name,
                  ]);
                } catch (\Exception $e) {
                  \Drupal::logger('baas_entity')->error('添加复合字段失败: @error', [
                    '@error' => $e->getMessage(),
                  ]);
                }
              }
            }
          } else {
            // 处理单一字段
            if (in_array($field_name, $existing_fields)) {
              // 更新字段
              try {
                $schema->changeField($table_name, $field_name, $field_name, $field_schema);
                \Drupal::logger('baas_entity')->notice('更新字段: @table.@field', [
                  '@table' => $table_name,
                  '@field' => $field_name,
                ]);
              } catch (\Exception $e) {
                \Drupal::logger('baas_entity')->error('更新字段失败: @error', [
                  '@error' => $e->getMessage(),
                ]);
              }
            } else {
              // 添加新字段
              try {
                $schema->addField($table_name, $field_name, $field_schema);
                \Drupal::logger('baas_entity')->notice('添加新字段: @table.@field', [
                  '@table' => $table_name,
                  '@field' => $field_name,
                ]);
              } catch (\Exception $e) {
                \Drupal::logger('baas_entity')->error('添加字段失败: @error', [
                  '@error' => $e->getMessage(),
                ]);
              }
            }
          }
        }
      }

      // 记录到类文件记录表
      if ($this->database->schema()->tableExists('baas_entity_class_files')) {
        // 更新或插入实体类文件记录
        $entity_type_id = $template->tenant_id . '_' . $template->name;
        $entity_file_path = str_replace('public://', '', $entity_class_path);
        $storage_file_path = str_replace('public://', '', $storage_class_path);

        // 检查是否已存在记录
        $existing_record = $this->database->select('baas_entity_class_files', 'f')
          ->fields('f', ['id'])
          ->condition('entity_type_id', $entity_type_id)
          ->condition('class_type', 'Entity')
          ->execute()
          ->fetchField();

        if ($existing_record) {
          // 更新记录
          $this->database->update('baas_entity_class_files')
            ->fields([
              'class_name' => $class_name,
              'file_path' => $entity_file_path,
              'updated' => time(),
            ])
            ->condition('id', $existing_record)
            ->execute();
        } else {
          // 插入记录
          $this->database->insert('baas_entity_class_files')
            ->fields([
              'entity_type_id' => $entity_type_id,
              'class_type' => 'Entity',
              'class_name' => $class_name,
              'file_path' => $entity_file_path,
              'created' => time(),
              'updated' => time(),
            ])
            ->execute();
        }

        // 同样处理存储类
        $existing_record = $this->database->select('baas_entity_class_files', 'f')
          ->fields('f', ['id'])
          ->condition('entity_type_id', $entity_type_id)
          ->condition('class_type', 'Storage')
          ->execute()
          ->fetchField();

        if ($existing_record) {
          // 更新记录
          $this->database->update('baas_entity_class_files')
            ->fields([
              'class_name' => $class_name . 'Storage',
              'file_path' => $storage_file_path,
              'updated' => time(),
            ])
            ->condition('id', $existing_record)
            ->execute();
        } else {
          // 插入记录
          $this->database->insert('baas_entity_class_files')
            ->fields([
              'entity_type_id' => $entity_type_id,
              'class_type' => 'Storage',
              'class_name' => $class_name . 'Storage',
              'file_path' => $storage_file_path,
              'created' => time(),
              'updated' => time(),
            ])
            ->execute();
        }
      }

      return TRUE;
    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('更新实体类型失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 获取字段的数据库模式定义。
   *
   * @param string $field_type
   *   字段类型。
   * @param object $field
   *   字段定义。
   *
   * @return array
   *   字段的数据库模式定义。
   */
  protected function getFieldSchema(string $field_type, object $field): array
  {
    switch ($field_type) {
      case 'string':
        return [
          'type' => 'varchar',
          'length' => isset($field->settings['max_length']) ? (int) $field->settings['max_length'] : 255,
          'not null' => !empty($field->required),
          'default' => isset($field->default_value) ? $field->default_value : '',
          'description' => $field->label,
        ];
      case 'text':
        // 为text_long类型添加两个字段：一个存储值，一个存储格式
        $columns = [];
        $columns[$field->name . '__value'] = [
          'type' => 'text',
          'size' => 'big',
          'not null' => FALSE,
          'description' => $field->label . ' - 值',
        ];
        $columns[$field->name . '__format'] = [
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
          'description' => $field->label . ' - 格式',
          'default' => '',
        ];
        // 不再尝试删除旧字段，在外部处理
        return $columns;
      case 'integer':
        return [
          'type' => 'int',
          'not null' => !empty($field->required),
          'default' => isset($field->default_value) ? (int) $field->default_value : 0,
          'description' => $field->label,
        ];
      case 'float':
        return [
          'type' => 'float',
          'not null' => !empty($field->required),
          'default' => isset($field->default_value) ? (float) $field->default_value : 0,
          'description' => $field->label,
        ];
      case 'boolean':
        return [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => isset($field->default_value) ? (int) $field->default_value : 0,
          'description' => $field->label,
        ];
      case 'email':
        return [
          'type' => 'varchar',
          'length' => 255,
          'not null' => !empty($field->required),
          'default' => isset($field->default_value) ? $field->default_value : '',
          'description' => $field->label,
        ];
      case 'url':
        // URL字段使用link类型，需要三个列：uri、title和options
        $columns = [];
        $columns[$field->name . '__uri'] = [
          'type' => 'varchar',
          'length' => 2048,
          'not null' => !empty($field->required),
          'default' => '',
          'description' => $field->label . ' - URI',
        ];
        $columns[$field->name . '__title'] = [
          'type' => 'varchar',
          'length' => 255,
          'not null' => FALSE,
          'default' => '',
          'description' => $field->label . ' - 标题',
        ];
        $columns[$field->name . '__options'] = [
          'type' => 'blob',
          'size' => 'big',
          'not null' => FALSE,
          'description' => $field->label . ' - 选项',
        ];
        return $columns;
      case 'datetime':
        return [
          'type' => 'varchar',
          'length' => 20,
          'not null' => !empty($field->required),
          'default' => isset($field->default_value) ? $field->default_value : NULL,
          'description' => $field->label,
        ];
      case 'list_string':
        return [
          'type' => 'varchar',
          'length' => 255,
          'not null' => !empty($field->required),
          'default' => isset($field->default_value) ? $field->default_value : '',
          'description' => $field->label,
        ];
      case 'list_integer':
        return [
          'type' => 'int',
          'not null' => !empty($field->required),
          'default' => isset($field->default_value) ? (int) $field->default_value : 0,
          'description' => $field->label,
        ];
      case 'reference':
        // 实体引用字段需要存储目标实体ID
        $multiple = isset($field->settings['multiple']) && $field->settings['multiple'];
        
        if ($multiple) {
          // 多值引用字段需要使用专门的表存储
          // 这里返回主表的结构，多值数据会存储在单独的字段表中
          return [
            $field->name . '_target_id' => [
              'type' => 'int',
              'unsigned' => TRUE,
              'not null' => FALSE,
              'description' => $field->label . ' - Target ID',
            ],
          ];
        } else {
          // 单值引用字段直接存储在主表中
          return [
            'type' => 'int',
            'unsigned' => TRUE,
            'not null' => !empty($field->required),
            'default' => isset($field->default_value) ? (int) $field->default_value : NULL,
            'description' => $field->label . ' - Referenced entity ID',
          ];
        }
      case 'json':
        return [
          'type' => 'text',
          'size' => 'big',
          'pgsql_type' => 'jsonb',
          'not null' => !empty($field->required),
          'default' => isset($field->default_value) ? $field->default_value : NULL,
          'description' => $field->label,
        ];
      default:
        return [
          'type' => 'varchar',
          'length' => 255,
          'not null' => !empty($field->required),
          'default' => isset($field->default_value) ? $field->default_value : '',
          'description' => $field->label,
        ];
    }
  }
}