<?php

namespace Drupal\baas_entity\Service;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * 字段映射服务.
 *
 * 负责定义和管理实体字段类型与Drupal字段类型之间的映射关系.
 */
class FieldMapper
{
  use StringTranslationTrait;

  /**
   * 字段类型映射表
   */
  protected $fieldTypeMap = [
    'string' => 'string',
    'text' => 'text_long',
    'number' => 'integer',
    'decimal' => 'decimal',
    'boolean' => 'boolean',
    'date' => 'datetime',
    'datetime' => 'datetime',
    'email' => 'email',
    'url' => 'link',
    'link' => 'link',
    'json' => 'map',
    'file' => 'file',
    'image' => 'image',
    'reference' => 'entity_reference',
  ];

  /**
   * 获取所有支持的字段类型。
   *
   * @return array
   *   支持的字段类型列表。
   */
  public function getSupportedFieldTypes(): array
  {
    return [
      'string' => '文本',
      'text' => '长文本',
      'integer' => '整数',
      'float' => '小数',
      'boolean' => '布尔值',
      'datetime' => '日期时间',
      'email' => '电子邮件',
      'url' => 'URL链接',
      'reference' => '实体引用',
      'list_string' => '文本列表',
      'list_integer' => '整数列表',
      'json' => 'JSON',
      'uuid' => 'UUID',
    ];
  }

  /**
   * 获取字段类型对应的Drupal字段类型.
   *
   * @param string $type
   *   自定义字段类型.
   *
   * @return string|null
   *   对应的Drupal字段类型，不存在返回NULL.
   */
  public function getDrupalFieldType(string $type): ?string
  {
    $mapping = [
      'string' => 'string',
      'text' => 'text_long',
      'integer' => 'integer',
      'float' => 'float',
      'boolean' => 'boolean',
      'datetime' => 'datetime',
      'email' => 'email',
      'url' => 'link',
      'reference' => 'entity_reference',
      'list_string' => 'list_string',
      'list_integer' => 'list_integer',
      'json' => 'string_long',
      'uuid' => 'uuid',
    ];

    return $mapping[$type] ?? NULL;
  }

  /**
   * 获取字段类型对应的存储类型.
   *
   * @param string $type
   *   自定义字段类型.
   *
   * @return string
   *   数据库字段类型.
   */
  public function getStorageType(string $type): string
  {
    $mapping = [
      'string' => 'varchar',
      'text' => 'text',
      'integer' => 'int',
      'float' => 'float',
      'boolean' => 'int_tiny',
      'datetime' => 'datetime',
      'email' => 'varchar',
      'url' => 'varchar',
      'reference' => 'int',
      'list_string' => 'varchar',
      'list_integer' => 'int',
      'json' => 'jsonb',
      'uuid' => 'varchar',
    ];

    return $mapping[$type] ?? 'varchar';
  }

  /**
   * 获取存储类型对应的长度.
   *
   * @param string $storage_type
   *   存储类型.
   *
   * @return int|null
   *   长度，无需指定长度返回NULL.
   */
  public function getStorageLength(string $storage_type): ?int
  {
    $mapping = [
      'varchar' => 255,
      'char' => 32,
    ];

    return $mapping[$storage_type] ?? NULL;
  }

  /**
   * 获取字段Schema定义.
   *
   * @param string $type
   *   字段类型.
   * @param bool $is_required
   *   是否必填.
   * @param bool $is_multiple
   *   是否多值.
   * @param array $settings
   *   字段设置.
   *
   * @return array
   *   字段Schema定义.
   */
  public function getFieldSchema(string $type, bool $is_required, bool $is_multiple = false, array $settings = []): array
  {
    $storage_type = $this->getStorageType($type);
    $length = $this->getStorageLength($storage_type);

    $schema = [
      'type' => $storage_type,
      'not null' => $is_required,
    ];

    if ($length !== NULL) {
      $schema['length'] = $length;
    }

    // 根据字段类型添加特定设置
    switch ($type) {
      case 'integer':
      case 'list_integer':
        $schema['size'] = 'normal';
        break;

      case 'boolean':
        $schema['size'] = 'tiny';
        break;

      case 'text':
      case 'json':
        $schema['size'] = 'medium';
        break;
    }

    return $schema;
  }

  /**
   * 获取字段Widget类型.
   *
   * @param string $type
   *   字段类型.
   *
   * @return string
   *   Widget类型.
   */
  public function getWidgetType(string $type): string
  {
    $mapping = [
      'string' => 'string_textfield',
      'text' => 'text_textarea',
      'integer' => 'number',
      'float' => 'number',
      'boolean' => 'boolean_checkbox',
      'datetime' => 'datetime_default',
      'email' => 'email_default',
      'url' => 'link_default',
      'reference' => 'entity_reference_autocomplete',
      'list_string' => 'options_select',
      'list_integer' => 'options_select',
      'json' => 'text_textarea',
      'uuid' => 'string_textfield',
    ];

    return $mapping[$type] ?? 'string_textfield';
  }

  /**
   * 获取字段Formatter类型.
   *
   * @param string $type
   *   字段类型.
   *
   * @return string
   *   Formatter类型.
   */
  public function getFormatterType(string $type): string
  {
    $mapping = [
      'string' => 'string',
      'text' => 'text_default',
      'integer' => 'number_integer',
      'float' => 'number_decimal',
      'boolean' => 'boolean',
      'datetime' => 'datetime_default',
      'email' => 'email_mailto',
      'url' => 'link',
      'reference' => 'entity_reference_label',
      'list_string' => 'list_default',
      'list_integer' => 'list_default',
      'json' => 'text_default',
      'uuid' => 'string',
    ];

    return $mapping[$type] ?? 'string';
  }

  /**
   * 获取字段配置设置.
   *
   * @param string $type
   *   字段类型.
   * @param array $settings
   *   自定义设置.
   *
   * @return array
   *   字段配置.
   */
  public function getFieldConfig(string $type, array $settings = []): array
  {
    $config = [
      'field_type' => $this->getDrupalFieldType($type),
      'settings' => [],
    ];

    // 根据字段类型添加默认设置
    switch ($type) {
      case 'string':
        $config['settings']['max_length'] = $settings['max_length'] ?? 255;
        break;

      case 'text':
        $config['settings']['allowed_formats'] = $settings['allowed_formats'] ?? ['basic_html'];
        break;

      case 'integer':
        $config['settings']['min'] = $settings['min'] ?? NULL;
        $config['settings']['max'] = $settings['max'] ?? NULL;
        break;

      case 'float':
        $config['settings']['min'] = $settings['min'] ?? NULL;
        $config['settings']['max'] = $settings['max'] ?? NULL;
        $config['settings']['precision'] = $settings['precision'] ?? 10;
        $config['settings']['scale'] = $settings['scale'] ?? 2;
        break;

      case 'datetime':
        $config['settings']['datetime_type'] = $settings['datetime_type'] ?? 'datetime';
        break;

      case 'url':
        $config['settings']['link_type'] = $settings['link_type'] ?? 17; // 允许外部和内部链接
        $config['settings']['title'] = $settings['title'] ?? 0; // 不需要标题
        break;

      case 'reference':
        $config['settings']['target_type'] = $settings['target_type'] ?? 'node';
        
        // 配置目标bundle限制
        if (isset($settings['target_bundles']) && is_array($settings['target_bundles'])) {
          $config['settings']['handler_settings']['target_bundles'] = $settings['target_bundles'];
        }
        
        // 配置排序设置
        if (isset($settings['sort']['field'])) {
          $config['settings']['handler_settings']['sort']['field'] = $settings['sort']['field'];
          $config['settings']['handler_settings']['sort']['direction'] = $settings['sort']['direction'] ?? 'ASC';
        }
        
        // 配置自动创建设置
        if (isset($settings['auto_create'])) {
          $config['settings']['handler_settings']['auto_create'] = (bool) $settings['auto_create'];
          
          // 如果允许自动创建，需要指定bundle
          if ($settings['auto_create'] && isset($settings['auto_create_bundle'])) {
            $config['settings']['handler_settings']['auto_create_bundle'] = $settings['auto_create_bundle'];
          }
        }
        
        // 配置引用选择处理器
        $config['settings']['handler'] = $settings['handler'] ?? 'default:' . ($settings['target_type'] ?? 'node');
        break;

      case 'list_string':
        $config['settings']['allowed_values'] = $settings['allowed_values'] ?? [];
        break;

      case 'list_integer':
        $config['settings']['allowed_values'] = $settings['allowed_values'] ?? [];
        break;

      case 'json':
        $config['settings']['schema'] = $settings['schema'] ?? NULL;
        break;
    }

    return $config;
  }

  /**
   * 获取表单字段定义.
   *
   * @param string $type
   *   字段类型.
   * @param string $label
   *   字段标签.
   * @param bool $required
   *   是否必填.
   * @param array $settings
   *   字段设置.
   *
   * @return array
   *   表单字段定义.
   */
  public function getFormField(string $type, string $label, bool $required, array $settings = []): array
  {
    $form_field = [
      '#type' => $this->getFormElementType($type),
      '#title' => $label,
      '#required' => $required,
    ];

    // 根据字段类型添加特定设置
    switch ($type) {
      case 'string':
        $form_field['#maxlength'] = $settings['max_length'] ?? 255;
        break;

      case 'text':
        $form_field['#rows'] = $settings['rows'] ?? 5;
        break;

      case 'integer':
        $form_field['#min'] = $settings['min'] ?? NULL;
        $form_field['#max'] = $settings['max'] ?? NULL;
        break;

      case 'float':
        $form_field['#min'] = $settings['min'] ?? NULL;
        $form_field['#max'] = $settings['max'] ?? NULL;
        $form_field['#step'] = $settings['step'] ?? 'any';
        break;

      case 'boolean':
        unset($form_field['#required']); // 布尔字段不需要required
        break;

      case 'list_string':
      case 'list_integer':
        $form_field['#options'] = $settings['allowed_values'] ?? [];
        break;

      case 'reference':
        $form_field['#target_type'] = $settings['target_type'] ?? 'node';
        
        // 配置选择设置
        $selection_settings = [];
        
        // 目标bundle限制
        if (isset($settings['target_bundles']) && is_array($settings['target_bundles'])) {
          $selection_settings['target_bundles'] = $settings['target_bundles'];
        }
        
        // 排序设置
        if (isset($settings['sort']['field'])) {
          $selection_settings['sort']['field'] = $settings['sort']['field'];
          $selection_settings['sort']['direction'] = $settings['sort']['direction'] ?? 'ASC';
        }
        
        // 自动创建设置
        if (isset($settings['auto_create'])) {
          $selection_settings['auto_create'] = (bool) $settings['auto_create'];
          if ($settings['auto_create'] && isset($settings['auto_create_bundle'])) {
            $selection_settings['auto_create_bundle'] = $settings['auto_create_bundle'];
          }
        }
        
        // 匹配设置
        if (isset($settings['match_operator'])) {
          $selection_settings['match_operator'] = $settings['match_operator'];
        }
        if (isset($settings['match_limit'])) {
          $selection_settings['match_limit'] = $settings['match_limit'];
        }
        
        $form_field['#selection_handler'] = $settings['handler'] ?? 'default';
        $form_field['#selection_settings'] = $selection_settings;

        // 处理多选
        if (!empty($settings['multiple'])) {
          $form_field['#multiple'] = TRUE;
        }
        break;
    }

    return $form_field;
  }

  /**
   * 获取表单元素类型。
   *
   * @param string $field_type
   *   字段类型。
   *
   * @return string
   *   表单元素类型。
   */
  public function getFormElementType(string $field_type): string
  {
    $mapping = [
      'string' => 'textfield',
      'text' => 'textarea',
      'integer' => 'number',
      'float' => 'number',
      'boolean' => 'checkbox',
      'datetime' => 'datetime',
      'email' => 'email',
      'url' => 'url',
      'reference' => 'entity_autocomplete',
      'list_string' => 'select',
      'list_integer' => 'select',
      'json' => 'textarea',
      'uuid' => 'textfield',
    ];

    return $mapping[$field_type] ?? 'textfield';
  }

  /**
   * 创建字段定义。
   *
   * @param string $name
   *   字段名称。
   * @param string $type
   *   字段类型。
   * @param array $settings
   *   字段设置。
   *
   * @return \Drupal\Core\Field\BaseFieldDefinition
   *   字段定义。
   */
  public function createFieldDefinition($name, $type, array $settings = [])
  {
    $drupal_type = $this->fieldTypeMap[$type] ?? 'string';

    $field = BaseFieldDefinition::create($drupal_type)
      ->setLabel($settings['label'] ?? $name)
      ->setRequired(!empty($settings['required']));

    // 应用其他设置
    if (!empty($settings['description'])) {
      $field->setDescription($settings['description']);
    }

    if (!empty($settings['default_value'])) {
      $field->setDefaultValue($settings['default_value']);
    }

    // 根据字段类型应用特定设置
    switch ($type) {
      case 'string':
        $field->setSettings([
          'max_length' => $settings['max_length'] ?? 255,
        ]);
        break;

      case 'decimal':
        $field->setSettings([
          'precision' => $settings['precision'] ?? 10,
          'scale' => $settings['scale'] ?? 2,
        ]);
        break;

      case 'datetime':
        $field->setSettings([
          'datetime_type' => $settings['datetime_type'] ?? 'datetime',
        ]);
        break;

      case 'reference':
        $reference_settings = [
          'target_type' => $settings['target_type'] ?? 'node',
        ];
        
        // 配置选择处理器
        if (isset($settings['handler'])) {
          $reference_settings['handler'] = $settings['handler'];
        }
        
        // 配置处理器设置
        $handler_settings = [];
        
        // 目标bundle限制
        if (isset($settings['target_bundles']) && is_array($settings['target_bundles'])) {
          $handler_settings['target_bundles'] = $settings['target_bundles'];
        }
        
        // 排序设置
        if (isset($settings['sort']['field'])) {
          $handler_settings['sort']['field'] = $settings['sort']['field'];
          $handler_settings['sort']['direction'] = $settings['sort']['direction'] ?? 'ASC';
        }
        
        // 自动创建设置
        if (isset($settings['auto_create'])) {
          $handler_settings['auto_create'] = (bool) $settings['auto_create'];
          if ($settings['auto_create'] && isset($settings['auto_create_bundle'])) {
            $handler_settings['auto_create_bundle'] = $settings['auto_create_bundle'];
          }
        }
        
        if (!empty($handler_settings)) {
          $reference_settings['handler_settings'] = $handler_settings;
        }
        
        $field->setSettings($reference_settings);
        
        // 设置基数
        if (isset($settings['multiple']) && $settings['multiple']) {
          $field->setCardinality(-1); // 无限制
        } else {
          $field->setCardinality(1); // 单值
        }
        break;
    }

    return $field;
  }
}
