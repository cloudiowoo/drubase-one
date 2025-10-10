<?php

declare(strict_types=1);

namespace Drupal\baas_project\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\baas_project\Service\ProjectEntityGenerator;
use Drupal\baas_project\Service\ProjectEntityTemplateManager;
use Drupal\baas_entity\Service\FieldTypeManager;

/**
 * 项目实体字段创建/编辑表单。
 */
class ProjectEntityFieldForm extends FormBase
{
  use StringTranslationTrait;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly Connection $database,
    protected readonly ProjectEntityGenerator $entityGenerator,
    protected readonly ProjectEntityTemplateManager $entityTemplateManager,
    protected readonly FieldTypeManager $fieldTypeManager
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_project.manager'),
      $container->get('database'),
      $container->get('baas_project.entity_generator'),
      $container->get('baas_project.entity_template_manager'),
      $container->get('baas_entity.field_type_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'baas_project_entity_field_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $tenant_id = NULL, ?string $project_id = NULL, ?string $template_id = NULL, ?string $field_id = NULL): array
  {
    // 存储参数到表单状态
    $form_state->set('tenant_id', $tenant_id);
    $form_state->set('project_id', $project_id);
    $form_state->set('template_id', $template_id);
    $form_state->set('field_id', $field_id);

    // 验证实体模板
    $template = $this->loadEntityTemplate($template_id);
    if (!$template || $template['project_id'] !== $project_id) {
      $this->messenger()->addError($this->t('找不到指定的实体模板。'));
      return $form;
    }

    // 如果是编辑模式，加载现有字段
    $field = NULL;
    if ($field_id) {
      $field = $this->loadEntityField($field_id);
      if (!$field || $field['template_id'] !== $template_id) {
        $this->messenger()->addError($this->t('找不到指定的实体字段。'));
        return $form;
      }
    }

    $form['#attributes']['class'][] = 'project-entity-field-form';

    $form['template_info'] = [
      '#markup' => '<div class="template-info"><h3>' . $this->t('实体：@name', ['@name' => $template['name']]) . '</h3></div>',
    ];

    // 基本信息
    $form['basic_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('字段基本信息'),
      '#tree' => TRUE,
    ];

    $form['basic_info']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('字段名称'),
      '#description' => $this->t('字段的显示名称，如"标题"、"内容"等。'),
      '#required' => TRUE,
      '#maxlength' => 64,
      '#default_value' => $field['name'] ?? '',
    ];

    $form['basic_info']['machine_name'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('字段机器名'),
      '#description' => $this->t('字段的机器名，用于数据库存储。只能包含小写字母、数字和下划线。'),
      '#required' => TRUE,
      '#maxlength' => 32,
      '#machine_name' => [
        'exists' => [$this, 'machineNameExists'],
        'source' => ['basic_info', 'name'],
      ],
      '#default_value' => $field['name'] ?? '',
      '#disabled' => !empty($field_id), // 编辑时不允许修改机器名
    ];

    $form['basic_info']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('字段描述'),
      '#description' => $this->t('对此字段的描述说明。'),
      '#rows' => 2,
      '#default_value' => $field['description'] ?? '',
    ];

    // 字段类型
    $form['field_type'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('字段类型'),
      '#tree' => TRUE,
    ];

    $form['field_type']['type'] = [
      '#type' => 'select',
      '#title' => $this->t('字段类型'),
      '#description' => $this->t('选择字段的数据类型。'),
      '#required' => TRUE,
      '#options' => $this->getFieldTypeOptions(),
      '#default_value' => $field['type'] ?? 'string',
      '#ajax' => [
        'callback' => '::updateFieldSettings',
        'wrapper' => 'field-settings-wrapper',
      ],
    ];

    // 字段设置
    $form['field_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('字段设置'),
      '#tree' => TRUE,
      '#prefix' => '<div id="field-settings-wrapper">',
      '#suffix' => '</div>',
    ];

    $selected_type = $form_state->getValue(['field_type', 'type']) ?? $field['type'] ?? 'string';
    $current_settings = $field['settings'] ?? [];
    if (is_string($current_settings)) {
      $current_settings = json_decode($current_settings, TRUE) ?: [];
    }

    // 根据字段类型显示不同的设置
    $this->buildFieldTypeSettings($form, $form_state, $selected_type, $current_settings);

    // 获取当前字段类型，为布尔字段类型特殊处理默认值
    $current_field_type = $form_state->getValue(['field_type', 'type']) ?? $field['type'] ?? 'string';

    // 默认值设置
    if ($current_field_type === 'boolean') {
      // 为布尔类型创建特殊的默认值选择
      $form['field_settings']['default_value'] = [
        '#type' => 'radios',
        '#title' => $this->t('默认值'),
        '#description' => $this->t('字段的默认值。'),
        '#options' => [
          '1' => $this->t('True (是)'),
          '0' => $this->t('False (否)'),
          '' => $this->t('未设置'),
        ],
        '#default_value' => isset($current_settings['default_value']) ? (string)$current_settings['default_value'] : '',
      ];
    } elseif ($current_field_type === 'datetime') {
      // 为日期时间类型创建特殊的默认值选择
      $form['field_settings']['default_value'] = [
        '#type' => 'radios',
        '#title' => $this->t('默认值'),
        '#description' => $this->t('设置日期字段的默认值行为。'),
        '#options' => [
          'now' => $this->t('当前时间'),
          'custom' => $this->t('自定义时间'),
          '' => $this->t('无默认值（用户必须选择）'),
        ],
        '#default_value' => $current_settings['default_value'] ?? '',
      ];
      
      // 自定义时间输入框（仅在选择"自定义时间"时显示）
      $form['field_settings']['custom_datetime'] = [
        '#type' => 'datetime',
        '#title' => $this->t('自定义默认时间'),
        '#description' => $this->t('选择具体的默认时间。'),
        '#states' => [
          'visible' => [
            ':input[name="field_settings[default_value]"]' => ['value' => 'custom'],
          ],
          'required' => [
            ':input[name="field_settings[default_value]"]' => ['value' => 'custom'],
          ],
        ],
      ];
      
      // 如果当前设置的默认值不是 'now' 或空，说明是自定义时间
      if (!empty($current_settings['default_value']) && $current_settings['default_value'] !== 'now') {
        try {
          $form['field_settings']['default_value']['#default_value'] = 'custom';
          $form['field_settings']['custom_datetime']['#default_value'] = new \Drupal\Core\Datetime\DrupalDateTime($current_settings['default_value']);
        } catch (\Exception $e) {
          // 如果解析失败，默认为当前时间
        }
      }
    } else {
      // 其他类型的默认值设置
      $form['field_settings']['default_value'] = [
        '#type' => $this->getDefaultValueFieldType($current_field_type),
        '#title' => $this->t('默认值'),
        '#description' => $this->t('字段的默认值。'),
        '#default_value' => $current_settings['default_value'] ?? '',
      ];
    }

    // 验证规则
    $form['validation'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('验证规则'),
      '#tree' => TRUE,
    ];

    $form['validation']['required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('必填字段'),
      '#description' => $this->t('选中后，此字段为必填项。'),
      '#default_value' => $field['required'] ?? FALSE,
    ];

    $form['validation']['unique'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('唯一值'),
      '#description' => $this->t('选中后，此字段的值必须在实体中唯一。'),
      '#default_value' => $current_settings['unique'] ?? FALSE,
    ];

    // 显示设置
    $form['display'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('显示设置'),
      '#tree' => TRUE,
    ];

    $form['display']['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('权重'),
      '#description' => $this->t('字段在表单中的显示顺序，数值越小越靠前。'),
      '#default_value' => $field['weight'] ?? 0,
      '#min' => -100,
      '#max' => 100,
    ];

    $form['display']['help_text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('帮助文本'),
      '#description' => $this->t('在表单中显示的帮助信息。'),
      '#rows' => 2,
      '#default_value' => $current_settings['help_text'] ?? '',
    ];

    // 操作按钮
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $field_id ? $this->t('更新字段') : $this->t('创建字段'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('取消'),
      '#url' => Url::fromRoute('baas_project.entity_template_edit', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
        'template_id' => $template_id,
      ]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * AJAX回调：更新字段设置。
   */
  public function updateFieldSettings(array &$form, FormStateInterface $form_state): array
  {
    return $form['field_settings'];
  }

  /**
   * 根据字段类型构建设置表单。
   */
  protected function buildFieldTypeSettings(array &$form, FormStateInterface $form_state, string $type, array $current_settings): void
  {
    // 根据字段类型显示不同的设置
    switch ($type) {
      case 'string':
        $form['field_settings']['max_length'] = [
          '#type' => 'number',
          '#title' => $this->t('最大长度'),
          '#description' => $this->t('字符串的最大长度，0表示不限制。'),
          '#default_value' => $current_settings['max_length'] ?? 255,
          '#min' => 0,
        ];
        break;

      case 'text':
        $form['field_settings']['text_format'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('启用文本格式'),
          '#description' => $this->t('允许用户选择文本格式（如HTML、纯文本等）。'),
          '#default_value' => $current_settings['text_format'] ?? FALSE,
        ];
        break;

      case 'integer':
        $form['field_settings']['min_value'] = [
          '#type' => 'number',
          '#title' => $this->t('最小值'),
          '#description' => $this->t('允许的最小值（可选）。'),
          '#default_value' => $current_settings['min_value'] ?? '',
        ];
        $form['field_settings']['max_value'] = [
          '#type' => 'number',
          '#title' => $this->t('最大值'),
          '#description' => $this->t('允许的最大值（可选）。'),
          '#default_value' => $current_settings['max_value'] ?? '',
        ];
        break;

      case 'decimal':
        $form['field_settings']['precision'] = [
          '#type' => 'number',
          '#title' => $this->t('精度'),
          '#description' => $this->t('总位数（包括小数位）。'),
          '#default_value' => $current_settings['precision'] ?? 10,
          '#min' => 1,
          '#max' => 10,
        ];
        $form['field_settings']['scale'] = [
          '#type' => 'number',
          '#title' => $this->t('小数位'),
          '#description' => $this->t('小数点后的位数。'),
          '#default_value' => $current_settings['scale'] ?? 2,
          '#min' => 0,
          '#max' => 10,
        ];
        break;

      case 'boolean':
        $form['field_settings']['on_label'] = [
          '#type' => 'textfield',
          '#title' => $this->t('开启标签'),
          '#description' => $this->t('当此字段为真时显示的标签。'),
          '#default_value' => $current_settings['on_label'] ?? $this->t('是'),
        ];
        $form['field_settings']['off_label'] = [
          '#type' => 'textfield',
          '#title' => $this->t('关闭标签'),
          '#description' => $this->t('当此字段为假时显示的标签。'),
          '#default_value' => $current_settings['off_label'] ?? $this->t('否'),
        ];
        $form['field_settings']['display_type'] = [
          '#type' => 'select',
          '#title' => $this->t('显示方式'),
          '#description' => $this->t('选择布尔值的显示方式。'),
          '#options' => [
            'checkbox' => $this->t('复选框'),
            'radio' => $this->t('单选按钮'),
            'toggle' => $this->t('开关按钮'),
          ],
          '#default_value' => $current_settings['display_type'] ?? 'checkbox',
        ];
        break;

      case 'reference':
        // 获取当前项目的所有实体模板（排除当前实体）
        $current_template = $this->loadEntityTemplate($form_state->get('template_id'));
        $current_entity_name = $current_template['name'] ?? '';
        $entity_templates = $this->getProjectEntityTemplates($form_state->get('project_id'), $current_entity_name);
        
        $form['field_settings']['target_entity'] = [
          '#type' => 'select',
          '#title' => $this->t('目标实体'),
          '#description' => $this->t('选择要引用的实体类型。只能引用同一项目内的实体，不能引用自身。'),
          '#options' => $entity_templates,
          '#default_value' => $current_settings['target_entity'] ?? '',
          '#required' => TRUE,
          '#empty_option' => $this->t('- 请选择 -'),
        ];
        
        $form['field_settings']['multiple'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('允许多选'),
          '#description' => $this->t('允许引用多个实体。'),
          '#default_value' => $current_settings['multiple'] ?? FALSE,
        ];
        
        $form['field_settings']['required_reference'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('必须选择引用'),
          '#description' => $this->t('创建实体时必须选择引用的实体。'),
          '#default_value' => $current_settings['required_reference'] ?? FALSE,
        ];
        break;

      case 'json':
        $form['field_settings']['schema'] = [
          '#type' => 'textarea',
          '#title' => $this->t('JSON Schema'),
          '#description' => $this->t('用于验证JSON数据结构的Schema（可选）。'),
          '#rows' => 5,
          '#default_value' => $current_settings['schema'] ?? '',
        ];
        break;

      case 'list_string':
        $form['field_settings']['allowed_values'] = [
          '#type' => 'textarea',
          '#title' => $this->t('允许的值'),
          '#description' => $this->t('输入允许的值列表，每行一个。格式：键|标签 或者 键（如果键和标签相同）。'),
          '#default_value' => $this->formatAllowedValuesForTextarea($current_settings['allowed_values'] ?? []),
          '#rows' => 10,
          '#required' => TRUE,
        ];
        $form['field_settings']['multiple'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('允许多选'),
          '#description' => $this->t('允许用户选择多个值。'),
          '#default_value' => $current_settings['multiple'] ?? FALSE,
        ];
        break;

      case 'list_integer':
        $form['field_settings']['allowed_values'] = [
          '#type' => 'textarea',
          '#title' => $this->t('允许的值'),
          '#description' => $this->t('每行一个值，格式为 "key|label"。键必须是整数。'),
          '#default_value' => $this->formatAllowedValuesForTextarea($current_settings['allowed_values'] ?? []),
          '#rows' => 10,
          '#required' => TRUE,
        ];
        $form['field_settings']['multiple'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('允许多选'),
          '#description' => $this->t('允许用户选择多个值。'),
          '#default_value' => $current_settings['multiple'] ?? FALSE,
        ];
        break;
    }
  }

  /**
   * 获取字段类型选项。
   */
  protected function getFieldTypeOptions(): array
  {
    // 从FieldTypeManager获取动态字段类型
    $dynamic_types = $this->fieldTypeManager->getAvailableTypes();
    
    // 合并静态字段类型（保留兼容性）
    $static_types = [
      'text' => $this->t('长文本'),
      'integer' => $this->t('整数'),
      'decimal' => $this->t('小数'),
      'boolean' => $this->t('布尔值'),
      'datetime' => $this->t('日期时间'),
      'email' => $this->t('邮箱'),
      'url' => $this->t('URL'),
      'json' => $this->t('JSON'),
      'reference' => $this->t('实体引用'),
    ];
    
    // 合并并返回所有字段类型
    return array_merge($dynamic_types, $static_types);
  }

  /**
   * 获取默认值字段类型。
   */
  protected function getDefaultValueFieldType(string $field_type): string
  {
    $type_mapping = [
      'string' => 'textfield',
      'text' => 'textarea',
      'integer' => 'number',
      'decimal' => 'number',
      'boolean' => 'radios',  // 改为radios，提供选项
      'datetime' => 'datetime',
      'email' => 'email',
      'url' => 'url',
      'json' => 'textarea',
      'reference' => 'textfield',
      'list_string' => 'select',
      'list_integer' => 'select',
    ];

    return $type_mapping[$field_type] ?? 'textfield';
  }

  /**
   * 验证机器名是否已存在。
   */
  public function machineNameExists($value, $element, FormStateInterface $form_state): bool
  {
    $template_id = $form_state->get('template_id');
    $field_id = $form_state->get('field_id');

    if (!$this->database->schema()->tableExists('baas_entity_field')) {
      return FALSE;
    }

    $query = $this->database->select('baas_entity_field', 'f')
      ->condition('template_id', $template_id)
      ->condition('name', $value);

    // 编辑时排除当前字段
    if ($field_id) {
      $query->condition('id', $field_id, '!=');
    }

    return (bool) $query->countQuery()->execute()->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $machine_name = $form_state->getValue(['basic_info', 'machine_name']);

    // 检查机器名是否为空
    if (empty($machine_name)) {
      $form_state->setErrorByName('basic_info][machine_name', $this->t('机器名不能为空。'));
      return;
    }

    // 验证机器名格式
    if (!preg_match('/^[a-z0-9_]+$/', $machine_name)) {
      $form_state->setErrorByName('basic_info][machine_name', $this->t('机器名只能包含小写字母、数字和下划线。'));
    }

    // 检查保留字 - 添加 UUID 到保留字列表
    $reserved_names = ['id', 'uuid', 'created', 'updated', 'tenant_id', 'project_id'];
    if (in_array($machine_name, $reserved_names)) {
      $form_state->setErrorByName('basic_info][machine_name', $this->t('机器名 "@name" 是保留字，请使用其他名称。', ['@name' => $machine_name]));
    }

    // 验证字段类型特定设置
    $field_type = $form_state->getValue(['field_type', 'type']);
    $this->validateFieldTypeSettings($form, $form_state, $field_type);
  }

  /**
   * 验证字段类型特定设置。
   */
  protected function validateFieldTypeSettings(array &$form, FormStateInterface $form_state, string $type): void
  {
    switch ($type) {
      case 'decimal':
        $precision = $form_state->getValue(['field_settings', 'precision']);
        $scale = $form_state->getValue(['field_settings', 'scale']);
        if ($scale > $precision) {
          $form_state->setErrorByName('field_settings][scale', $this->t('小数位数不能大于总精度。'));
        }
        break;

      case 'integer':
        $min = $form_state->getValue(['field_settings', 'min_value']);
        $max = $form_state->getValue(['field_settings', 'max_value']);
        if ($min !== '' && $max !== '' && $min > $max) {
          $form_state->setErrorByName('field_settings][max_value', $this->t('最大值必须大于或等于最小值。'));
        }
        break;

      case 'list_string':
      case 'list_integer':
        $allowed_values_text = $form_state->getValue(['field_settings', 'allowed_values']);
        
        if (empty(trim($allowed_values_text ?? ''))) {
          $form_state->setErrorByName('field_settings][allowed_values', 
            $this->t('列表字段必须定义允许的值。'));
        } else {
          $parsed_values = $this->parseAllowedValuesFromTextarea($allowed_values_text, $type);
          if (empty($parsed_values)) {
            $form_state->setErrorByName('field_settings][allowed_values', 
              $this->t('允许值格式不正确。'));
          }
        }
        break;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $tenant_id = $form_state->get('tenant_id');
    $project_id = $form_state->get('project_id');
    $template_id = $form_state->get('template_id');
    $field_id = $form_state->get('field_id');

    // 收集字段设置
    $field_settings = $form_state->getValue('field_settings') ?: [];
    $field_settings['unique'] = $form_state->getValue(['validation', 'unique']);
    $field_settings['help_text'] = $form_state->getValue(['display', 'help_text']);

    // 处理datetime字段的默认值
    $field_type = $form_state->getValue(['field_type', 'type']);
    if ($field_type === 'datetime') {
      $default_value_type = $field_settings['default_value'] ?? '';
      if ($default_value_type === 'custom') {
        // 如果选择了自定义时间，使用custom_datetime的值
        $custom_datetime = $field_settings['custom_datetime'] ?? NULL;
        if ($custom_datetime instanceof \Drupal\Core\Datetime\DrupalDateTime) {
          $field_settings['default_value'] = $custom_datetime->format('Y-m-d H:i:s');
        } else {
          $field_settings['default_value'] = '';
        }
        // 移除临时的custom_datetime字段
        unset($field_settings['custom_datetime']);
      }
      // 如果选择了"当前时间"，保持default_value为'now'
      // 如果选择了"无默认值"，保持default_value为空字符串
    }

    // 处理列表字段的允许值
    if (in_array($field_type, ['list_string', 'list_integer'])) {
      $allowed_values_text = $field_settings['allowed_values'] ?? '';
      $field_settings['allowed_values'] = $this->parseAllowedValuesFromTextarea($allowed_values_text, $field_type);
    }

    $values = [
      'template_id' => $template_id,
      'project_id' => $project_id,
      'name' => $form_state->getValue(['basic_info', 'machine_name']),
      'label' => $form_state->getValue(['basic_info', 'name']),
      'description' => $form_state->getValue(['basic_info', 'description']),
      'type' => $form_state->getValue(['field_type', 'type']),
      'settings' => json_encode($field_settings),
      'required' => $form_state->getValue(['validation', 'required']) ? 1 : 0,
      'weight' => $form_state->getValue(['display', 'weight']),
      'updated' => time(),
    ];

    try {
      if ($field_id) {
        // 使用Entity管理服务更新字段
        $result = $this->entityTemplateManager->updateEntityField($field_id, $values);
      } else {
        // 使用Entity管理服务创建字段
        $result = $this->entityTemplateManager->createEntityField($values);
      }

      // 处理结果消息
      if ($result['success']) {
        foreach ($result['messages'] as $message) {
          $this->messenger()->addStatus($message);
        }
      } else {
        foreach ($result['errors'] as $error) {
          $this->messenger()->addError($error);
        }
      }

      // 重定向到实体模板编辑页面
      $form_state->setRedirect('baas_project.entity_template_edit', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
        'template_id' => $template_id,
      ]);
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('保存字段时发生错误：@error', ['@error' => $e->getMessage()]));
      \Drupal::logger('baas_project')->error('Error saving entity field: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * 加载实体模板。
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
   * 加载实体字段。
   */
  protected function loadEntityField(string $field_id): ?array
  {
    if (!$this->database->schema()->tableExists('baas_entity_field')) {
      return NULL;
    }

    $field = $this->database->select('baas_entity_field', 'f')
      ->fields('f')
      ->condition('id', $field_id)
      ->execute()
      ->fetch(\PDO::FETCH_ASSOC);

    return $field ?: NULL;
  }

  /**
   * 更新动态实体表结构。
   */
  protected function updateDynamicEntityTable(string $tenant_id, string $project_id, string $template_id, array $field_values, string $operation): void
  {
    try {
      // 获取实体模板信息
      $template = $this->loadEntityTemplate($template_id);
      if (!$template) {
        return;
      }

      $table_name = "tenant_{$tenant_id}_project_{$project_id}_{$template['name']}";

      // 检查表是否存在
      if (!$this->database->schema()->tableExists($table_name)) {
        \Drupal::logger('baas_project')->warning('实体表不存在，无法更新字段结构: @table', ['@table' => $table_name]);
        return;
      }

      $field_name = $field_values['name'];
      $field_type = $field_values['type'];

      // 跳过系统字段
      if (in_array($field_name, ['id', 'uuid', 'created', 'updated', 'tenant_id', 'project_id'])) {
        return;
      }

      $schema = $this->database->schema();
      $field_schema = $this->getFieldSchema($field_type, $field_values);

      if ($operation === 'add') {
        // 添加新字段
        if (!$schema->fieldExists($table_name, $field_name)) {
          $schema->addField($table_name, $field_name, $field_schema);
          \Drupal::logger('baas_project')->notice('添加字段到实体表: @table.@field', [
            '@table' => $table_name,
            '@field' => $field_name,
          ]);
        }
      } elseif ($operation === 'update') {
        // 更新现有字段
        if ($schema->fieldExists($table_name, $field_name)) {
          $schema->changeField($table_name, $field_name, $field_name, $field_schema);
          \Drupal::logger('baas_project')->notice('更新实体表字段: @table.@field', [
            '@table' => $table_name,
            '@field' => $field_name,
          ]);
        } else {
          // 字段不存在，添加它
          $schema->addField($table_name, $field_name, $field_schema);
          \Drupal::logger('baas_project')->notice('添加缺失字段到实体表: @table.@field', [
            '@table' => $table_name,
            '@field' => $field_name,
          ]);
        }
      }
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('更新实体表结构失败: @error', ['@error' => $e->getMessage()]);
      $this->messenger()->addWarning($this->t('字段已保存，但更新数据表结构时出现问题：@error', ['@error' => $e->getMessage()]));
    }
  }

  /**
   * 获取字段的数据库模式定义。
   */
  protected function getFieldSchema(string $field_type, array $field): array
  {
    switch ($field_type) {
      case 'string':
        return [
          'type' => 'varchar',
          'length' => 255,
          'not null' => !empty($field['required']),
          'default' => '',
          'description' => $field['description'] ?? $field['name'],
        ];
      case 'text':
        return [
          'type' => 'text',
          'size' => 'big',
          'not null' => FALSE,
          'description' => $field['description'] ?? $field['name'],
        ];
      case 'integer':
        return [
          'type' => 'int',
          'not null' => !empty($field['required']),
          'default' => 0,
          'description' => $field['description'] ?? $field['name'],
        ];
      case 'decimal':
        return [
          'type' => 'float',
          'not null' => !empty($field['required']),
          'default' => 0,
          'description' => $field['description'] ?? $field['name'],
        ];
      case 'boolean':
        return [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
          'description' => $field['description'] ?? $field['name'],
        ];
      case 'datetime':
        return [
          'type' => 'varchar',
          'length' => 20,
          'not null' => !empty($field['required']),
          'default' => NULL,
          'description' => $field['description'] ?? $field['name'],
        ];
      case 'email':
        return [
          'type' => 'varchar',
          'length' => 255,
          'not null' => !empty($field['required']),
          'default' => '',
          'description' => $field['description'] ?? $field['name'],
        ];
      case 'url':
        return [
          'type' => 'varchar',
          'length' => 2048,
          'not null' => !empty($field['required']),
          'default' => '',
          'description' => $field['description'] ?? $field['name'],
        ];
      case 'json':
        return [
          'type' => 'jsonb',
          'not null' => FALSE,
          'default' => NULL,
          'description' => $field['description'] ?? $field['name'],
        ];
      case 'reference':
        return [
          'type' => 'int',
          'not null' => !empty($field['required']),
          'default' => 0,
          'description' => $field['description'] ?? $field['name'],
        ];
      case 'list_string':
        return [
          'type' => 'varchar',
          'length' => 255,
          'not null' => !empty($field['required']),
          'default' => '',
          'description' => $field['description'] ?? $field['name'],
        ];
      case 'list_integer':
        return [
          'type' => 'int',
          'not null' => !empty($field['required']),
          'default' => 0,
          'description' => $field['description'] ?? $field['name'],
        ];
      default:
        return [
          'type' => 'varchar',
          'length' => 255,
          'not null' => !empty($field['required']),
          'default' => '',
          'description' => $field['description'] ?? $field['name'],
        ];
    }
  }

  /**
   * 格式化允许值为文本区域格式。
   */
  protected function formatAllowedValuesForTextarea(array $allowed_values): string
  {
    $lines = [];
    foreach ($allowed_values as $key => $label) {
      if ($key === $label) {
        $lines[] = $key;
      } else {
        $lines[] = $key . '|' . $label;
      }
    }
    return implode("\n", $lines);
  }

  /**
   * 从文本区域解析允许值。
   */
  protected function parseAllowedValuesFromTextarea(string $text, string $field_type): array
  {
    $allowed_values = [];
    $lines = array_filter(array_map('trim', explode("\n", $text)));

    foreach ($lines as $line) {
      if (strpos($line, '|') !== FALSE) {
        // 格式：key|label
        list($key, $label) = array_map('trim', explode('|', $line, 2));
      } else {
        // 格式：key（key和label相同）
        $key = $label = trim($line);
      }

      // 验证键值
      if ($field_type === 'list_integer') {
        if (!is_numeric($key) || intval($key) != $key) {
          continue; // 跳过无效的整数键
        }
        $key = (int) $key;
      }

      if (!empty($key) && !empty($label)) {
        $allowed_values[$key] = $label;
      }
    }

    return $allowed_values;
  }

  /**
   * 获取项目的实体模板选项。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $exclude_entity_name
   *   要排除的实体名称（通常是当前实体）。
   *
   * @return array
   *   实体模板选项数组。
   */
  protected function getProjectEntityTemplates(string $project_id, string $exclude_entity_name = ''): array
  {
    try {
      if (!$this->database->schema()->tableExists('baas_entity_template')) {
        \Drupal::logger('baas_project')->warning('baas_entity_template 表不存在');
        return [];
      }

      $query = $this->database->select('baas_entity_template', 'et')
        ->fields('et', ['name', 'label'])
        ->condition('et.project_id', $project_id)
        ->condition('et.status', 1)
        ->orderBy('et.label', 'ASC');
      
      // 排除当前实体（防止自引用）
      if (!empty($exclude_entity_name)) {
        $query->condition('et.name', $exclude_entity_name, '!=');
      }
      
      $results = $query->execute();
      $options = [];
      
      foreach ($results as $row) {
        $options[$row->name] = $row->label;
      }
      
      return $options;
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('获取项目实体模板失败: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
        'exclude_entity_name' => $exclude_entity_name,
      ]);
      return [];
    }
  }
}
