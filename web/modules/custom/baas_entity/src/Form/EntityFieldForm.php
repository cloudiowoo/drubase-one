<?php

namespace Drupal\baas_entity\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_entity\Service\TemplateManager;
use Drupal\baas_entity\Service\FieldMapper;

/**
 * 实体字段表单。
 */
class EntityFieldForm extends FormBase
{

  /**
   * 模板管理服务。
   *
   * @var \Drupal\baas_entity\Service\TemplateManager
   */
  protected $templateManager;

  /**
   * 字段映射服务。
   *
   * @var \Drupal\baas_entity\Service\FieldMapper
   */
  protected $fieldMapper;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_entity\Service\TemplateManager $template_manager
   *   模板管理服务。
   * @param \Drupal\baas_entity\Service\FieldMapper $field_mapper
   *   字段映射服务。
   */
  public function __construct(
    TemplateManager $template_manager,
    FieldMapper $field_mapper
  ) {
    $this->templateManager = $template_manager;
    $this->fieldMapper = $field_mapper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('baas_entity.template_manager'),
      $container->get('baas_entity.field_mapper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'baas_entity_field_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $template_id = NULL, $field_id = NULL)
  {
    // 存储模板ID和字段ID
    $form_state->set('template_id', $template_id);
    $form_state->set('field_id', $field_id);

    // 获取模板详情
    $template = $this->templateManager->getTemplate($template_id);
    if (!$template) {
      $this->messenger()->addError($this->t('实体模板不存在。'));
      return $this->redirect('baas_entity.list');
    }

    // 如果提供了字段ID，获取字段详情
    $field = NULL;
    if ($field_id) {
      $field = $this->templateManager->getField($field_id);
      if (!$field) {
        $this->messenger()->addError($this->t('字段不存在。'));
        return $this->redirect('baas_entity.fields', ['template_id' => $template_id]);
      }
    }

    // 显示模板信息
    $form['template_info'] = [
      '#type' => 'item',
      '#title' => $this->t('模板信息'),
      '#markup' => $this->t('租户: @tenant, 实体: @entity', [
        '@tenant' => $template->tenant_id,
        '@entity' => $template->label,
      ]),
    ];

    // 字段标签
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('字段标签'),
      '#description' => $this->t('字段的显示名称。'),
      '#required' => TRUE,
      '#default_value' => $field ? $field->label : '',
    ];

    // 字段名称
    $form['name'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('字段名称'),
      '#description' => $this->t('字段的机器名称，只能包含小写字母、数字和下划线。'),
      '#required' => TRUE,
      '#default_value' => $field ? $field->name : '',
      '#disabled' => $field ? TRUE : FALSE,
      '#machine_name' => [
        'exists' => [$this, 'fieldNameExists'],
        'source' => ['label'],
      ],
    ];

    // 字段描述
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('字段描述'),
      '#description' => $this->t('字段的详细描述。'),
      '#default_value' => $field ? $field->description : '',
    ];

    // 字段类型
    $field_types = $this->fieldMapper->getSupportedFieldTypes();
    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('字段类型'),
      '#options' => $field_types,
      '#required' => TRUE,
      '#default_value' => $field ? $field->type : '',
      '#disabled' => $field ? TRUE : FALSE,
      '#ajax' => [
        'callback' => '::updateFieldSettingsForm',
        'wrapper' => 'field-settings-wrapper',
        'event' => 'change',
      ],
    ];

    // 字段设置（动态根据字段类型变化）
    $form['settings_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'field-settings-wrapper'],
    ];

    $field_type = $form_state->getValue('type') ?: ($field ? $field->type : '');
    $settings = $field ? $field->settings : [];

    // 在AJAX回调时，从用户输入中获取设置值
    if ($form_state->isRebuilding() && $form_state->getValue('settings_wrapper')) {
      $user_settings_wrapper = $form_state->getValue('settings_wrapper');
      if (isset($user_settings_wrapper['settings'])) {
        $settings = array_merge($settings, $user_settings_wrapper['settings']);
      }
    }

    // 根据字段类型渲染不同的设置表单
    $form['settings_wrapper']['settings'] = $this->buildFieldSettingsForm($field_type, $settings, $form_state);

    // 必填
    $form['required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('必填'),
      '#description' => $this->t('将此字段设为必填项。'),
      '#default_value' => $field ? $field->required : FALSE,
    ];

    // 字段权重
    $form['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('权重'),
      '#description' => $this->t('用于排序字段的顺序，数字越小越靠前。'),
      '#default_value' => $field ? $field->weight : 0,
    ];

    // 提交按钮
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $field ? $this->t('更新字段') : $this->t('添加字段'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('取消'),
      '#url' => $this->getCancelUrl($template_id),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * 构建字段设置表单。
   *
   * @param string $field_type
   *   字段类型。
   * @param array $settings
   *   现有设置。
   * @param \Drupal\Core\Form\FormStateInterface|null $form_state
   *   表单状态。
   *
   * @return array
   *   表单元素。
   */
  protected function buildFieldSettingsForm($field_type, array $settings = [], ?FormStateInterface $form_state = NULL)
  {
    $form = [
      '#type' => 'container',
    ];

    // 根据字段类型添加特定设置
    switch ($field_type) {
      case 'string':
        $form['max_length'] = [
          '#type' => 'number',
          '#title' => $this->t('最大长度'),
          '#description' => $this->t('字符串的最大长度，0表示不限制。'),
          '#default_value' => $settings['max_length'] ?? 255,
          '#min' => 0,
        ];
        break;

      case 'text':
        $form['rows'] = [
          '#type' => 'number',
          '#title' => $this->t('行数'),
          '#description' => $this->t('文本区域的行数。'),
          '#default_value' => $settings['rows'] ?? 5,
          '#min' => 1,
        ];
        break;

      case 'integer':
        $form['min'] = [
          '#type' => 'number',
          '#title' => $this->t('最小值'),
          '#description' => $this->t('允许的最小值。'),
          '#default_value' => $settings['min'] ?? NULL,
        ];
        $form['max'] = [
          '#type' => 'number',
          '#title' => $this->t('最大值'),
          '#description' => $this->t('允许的最大值。'),
          '#default_value' => $settings['max'] ?? NULL,
        ];
        break;

      case 'decimal':
        $form['precision'] = [
          '#type' => 'number',
          '#title' => $this->t('精度'),
          '#description' => $this->t('总位数（包括小数位）。'),
          '#default_value' => $settings['precision'] ?? 10,
          '#min' => 1,
        ];
        $form['scale'] = [
          '#type' => 'number',
          '#title' => $this->t('小数位'),
          '#description' => $this->t('小数点后的位数。'),
          '#default_value' => $settings['scale'] ?? 2,
          '#min' => 0,
        ];
        break;

      case 'boolean':
        $form['on_label'] = [
          '#type' => 'textfield',
          '#title' => $this->t('开启标签'),
          '#description' => $this->t('当此字段为真时显示的标签。'),
          '#default_value' => $settings['on_label'] ?? $this->t('是'),
        ];
        $form['off_label'] = [
          '#type' => 'textfield',
          '#title' => $this->t('关闭标签'),
          '#description' => $this->t('当此字段为假时显示的标签。'),
          '#default_value' => $settings['off_label'] ?? $this->t('否'),
        ];
        break;

      case 'date':
        $form['date_type'] = [
          '#type' => 'select',
          '#title' => $this->t('日期类型'),
          '#options' => [
            'date' => $this->t('日期'),
            'datetime' => $this->t('日期和时间'),
          ],
          '#default_value' => $settings['date_type'] ?? 'date',
        ];
        break;

      case 'list_string':
        // 在AJAX回调时，优先使用用户输入的值
        $current_allowed_values = '';
        if ($form_state->isRebuilding() && $form_state->getValue(['settings_wrapper', 'settings', 'allowed_values'])) {
          $current_allowed_values = $form_state->getValue(['settings_wrapper', 'settings', 'allowed_values']);
        } else {
          $current_allowed_values = $this->getFormattedAllowedValues($settings['allowed_values'] ?? null);
        }

        $form['allowed_values'] = [
          '#type' => 'textarea',
          '#title' => $this->t('允许的值'),
          '#description' => $this->t('输入允许的值列表，每行一个。格式：键|标签 或者 键（如果键和标签相同）。<br>例如：<br>option1|选项1<br>option2|选项2<br>或者：<br>选项1<br>选项2'),
          '#default_value' => $current_allowed_values,
          '#rows' => 10,
          '#required' => TRUE,
        ];

        $form['multiple'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('允许多选'),
          '#description' => $this->t('允许用户选择多个值。'),
          '#default_value' => $settings['multiple'] ?? FALSE,
        ];
        break;

      case 'list_integer':
        $form['allowed_values'] = [
          '#type' => 'textarea',
          '#title' => $this->t('允许的值'),
          '#description' => $this->t('每行一个值，格式为 "key|label"。'),
          '#default_value' => $this->formatAllowedValuesForTextarea($settings['allowed_values'] ?? []),
        ];
        break;

      case 'url':
        $form['link_type'] = [
          '#type' => 'select',
          '#title' => $this->t('链接类型'),
          '#description' => $this->t('允许的链接类型。'),
          '#options' => [
            1 => $this->t('仅内部链接'),
            16 => $this->t('仅外部链接'),
            17 => $this->t('内部和外部链接'),
          ],
          '#default_value' => $settings['link_type'] ?? 17,
        ];
        $form['title'] = [
          '#type' => 'select',
          '#title' => $this->t('链接标题'),
          '#description' => $this->t('是否允许输入链接标题。'),
          '#options' => [
            0 => $this->t('禁用'),
            1 => $this->t('可选'),
            2 => $this->t('必填'),
          ],
          '#default_value' => $settings['title'] ?? 0,
        ];
        $form['max_length'] = [
          '#type' => 'number',
          '#title' => $this->t('最大长度'),
          '#description' => $this->t('URL的最大长度。'),
          '#default_value' => $settings['max_length'] ?? 2048,
          '#min' => 1,
        ];
        break;

      case 'reference':
        $form['target_type'] = [
          '#type' => 'select',
          '#title' => $this->t('目标实体类型'),
          '#description' => $this->t('引用的实体类型。'),
          '#options' => [
            'node' => $this->t('内容节点'),
            'user' => $this->t('用户'),
            'taxonomy_term' => $this->t('分类术语'),
            'file' => $this->t('文件'),
            'media' => $this->t('媒体'),
          ],
          '#default_value' => $settings['target_type'] ?? 'node',
          '#required' => TRUE,
        ];
        
        $form['target_bundles'] = [
          '#type' => 'textarea',
          '#title' => $this->t('目标Bundle限制'),
          '#description' => $this->t('限制可选择的bundle类型，每行一个。留空表示允许所有bundle。'),
          '#default_value' => $this->formatTargetBundles($settings['target_bundles'] ?? []),
          '#rows' => 4,
        ];
        
        $form['multiple'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('允许多选'),
          '#description' => $this->t('允许引用多个实体。'),
          '#default_value' => $settings['multiple'] ?? FALSE,
        ];
        
        $form['auto_create'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('自动创建'),
          '#description' => $this->t('如果引用的实体不存在，允许自动创建。'),
          '#default_value' => $settings['auto_create'] ?? FALSE,
        ];
        
        $form['auto_create_bundle'] = [
          '#type' => 'textfield',
          '#title' => $this->t('自动创建Bundle'),
          '#description' => $this->t('自动创建实体时使用的bundle类型。'),
          '#default_value' => $settings['auto_create_bundle'] ?? '',
          '#states' => [
            'visible' => [
              ':input[name="settings_wrapper[settings][auto_create]"]' => ['checked' => TRUE],
            ],
          ],
        ];
        
        // 排序设置
        $form['sort_field'] = [
          '#type' => 'select',
          '#title' => $this->t('排序字段'),
          '#description' => $this->t('选择用于排序的字段。'),
          '#options' => [
            '' => $this->t('- 无排序 -'),
            'title' => $this->t('标题'),
            'created' => $this->t('创建时间'),
            'changed' => $this->t('修改时间'),
          ],
          '#default_value' => $settings['sort']['field'] ?? '',
        ];
        
        $form['sort_direction'] = [
          '#type' => 'select',
          '#title' => $this->t('排序方向'),
          '#options' => [
            'ASC' => $this->t('升序'),
            'DESC' => $this->t('降序'),
          ],
          '#default_value' => $settings['sort']['direction'] ?? 'ASC',
          '#states' => [
            'visible' => [
              ':input[name="settings_wrapper[settings][sort_field]"]' => ['!value' => ''],
            ],
          ],
        ];
        break;
    }

    return $form;
  }

  /**
   * AJAX回调，更新字段设置表单。
   */
  public function updateFieldSettingsForm(array &$form, FormStateInterface $form_state)
  {
    return $form['settings_wrapper'];
  }

  /**
   * 检查字段名称是否已存在。
   *
   * @param string $name
   *   字段名称。
   * @param array $element
   *   表单元素。
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   表单状态。
   *
   * @return bool
   *   是否已存在。
   */
  public function fieldNameExists($name, array $element, FormStateInterface $form_state)
  {
    $template_id = $form_state->get('template_id');
    $field = $this->templateManager->getFieldByName($template_id, $name);
    return $field !== FALSE;
  }

  /**
   * 获取取消URL。
   *
   * @param int $template_id
   *   模板ID。
   *
   * @return \Drupal\Core\Url
   *   URL对象。
   */
  protected function getCancelUrl($template_id)
  {
    return \Drupal\Core\Url::fromRoute('baas_entity.fields', ['template_id' => $template_id]);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    $template_id = $form_state->get('template_id');
    $name = $form_state->getValue('name');
    $type = $form_state->getValue('type');

    // 验证字段名称唯一性（仅在新建时）
    $field_id = $form_state->get('field_id');
    if (!$field_id) {
      $existing_field = $this->templateManager->getFieldByName($template_id, $name);
      if ($existing_field) {
        $form_state->setErrorByName('name', $this->t('字段名称 "@name" 已存在。', ['@name' => $name]));
      }
    }

    // 验证列表字段的允许值
    if (in_array($type, ['list_string', 'list_integer'])) {
      // 尝试从多个路径获取allowed_values
      $allowed_values_text = '';

      // 优先从settings_wrapper路径获取
      $settings_wrapper = $form_state->getValue('settings_wrapper') ?? [];
      $settings = $settings_wrapper['settings'] ?? [];
      if (!empty($settings['allowed_values'])) {
        $allowed_values_text = $settings['allowed_values'];
      }

      // 如果settings路径为空，尝试直接路径
      if (empty(trim($allowed_values_text ?? ''))) {
        $direct_value = $form_state->getValue('allowed_values');
        if (!empty($direct_value)) {
          $allowed_values_text = $direct_value;
        }
      }

      if (empty(trim($allowed_values_text ?? ''))) {
        $form_state->setErrorByName('settings_wrapper][settings][allowed_values', $this->t('列表字段必须定义允许的值。'));
      } else {
        $parsed_values = $this->parseAllowedValuesFromTextarea($allowed_values_text, $type);
        if (empty($parsed_values)) {
          $form_state->setErrorByName('settings_wrapper][settings][allowed_values', $this->t('允许值格式不正确。'));
        }
      }
    }
  }

  /**
   * 将允许值数组格式化为文本区域显示格式。
   *
   * @param array $allowed_values
   *   允许值数组，格式为 [key => label, ...]
   *
   * @return string
   *   格式化后的文本，每行一个值
   */
  protected function formatAllowedValuesForTextarea(?array $allowed_values): string
  {
    if (empty($allowed_values) || !is_array($allowed_values)) {
      return '';
    }

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
   *
   * @param string $text
   *   文本区域内容
   * @param string $field_type
   *   字段类型（list_string 或 list_integer）
   *
   * @return array
   *   解析后的允许值数组
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
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $template_id = $form_state->get('template_id');
    $field_id = $form_state->get('field_id');
    $values = $form_state->getValues();

    // 添加调试日志
    \Drupal::logger('baas_entity')->info('字段表单提交: @type 类型字段', [
      '@type' => $values['type']
    ]);

    // 调试：记录所有表单提交的值
    \Drupal::logger('baas_entity')->debug('所有表单值: @values', [
      '@values' => json_encode(array_keys($values)),
    ]);

    // 调试：记录表单提交的关键值
    \Drupal::logger('baas_entity')->debug('表单提交 - 字段类型: @type', [
      '@type' => $values['type'] ?? 'unknown',
    ]);

    // 调试：检查settings_wrapper是否存在
    if (isset($values['settings_wrapper'])) {
      \Drupal::logger('baas_entity')->debug('settings_wrapper存在，内容: @content', [
        '@content' => json_encode($values['settings_wrapper']),
      ]);
    } else {
      \Drupal::logger('baas_entity')->debug('settings_wrapper不存在');
    }

    // 获取字段设置
    $settings = [];

    // 首先尝试从settings_wrapper获取
    $settings_wrapper = $values['settings_wrapper'] ?? [];
    if (isset($settings_wrapper['settings']) && is_array($settings_wrapper['settings'])) {
      $settings = $settings_wrapper['settings'];
      \Drupal::logger('baas_entity')->debug('从settings_wrapper获取settings: @settings', [
        '@settings' => json_encode($settings),
      ]);
    } else {
      \Drupal::logger('baas_entity')->debug('settings_wrapper不存在，尝试从根级别获取字段设置');

      // 如果settings_wrapper不存在，直接从根级别获取字段特定的设置
      switch ($values['type']) {
        case 'string':
          if (isset($values['max_length'])) {
            $settings['max_length'] = $values['max_length'];
          }
          break;

        case 'text':
          if (isset($values['rows'])) {
            $settings['rows'] = $values['rows'];
          }
          break;

        case 'integer':
          if (isset($values['min'])) {
            $settings['min'] = $values['min'];
          }
          if (isset($values['max'])) {
            $settings['max'] = $values['max'];
          }
          break;

        case 'decimal':
          if (isset($values['precision'])) {
            $settings['precision'] = $values['precision'];
          }
          if (isset($values['scale'])) {
            $settings['scale'] = $values['scale'];
          }
          break;

        case 'boolean':
          if (isset($values['on_label'])) {
            $settings['on_label'] = $values['on_label'];
          }
          if (isset($values['off_label'])) {
            $settings['off_label'] = $values['off_label'];
          }
          break;

        case 'date':
          if (isset($values['date_type'])) {
            $settings['date_type'] = $values['date_type'];
          }
          break;

        case 'list_string':
        case 'list_integer':
          if (isset($values['allowed_values'])) {
            $settings['allowed_values'] = $values['allowed_values'];
          }
          if (isset($values['multiple'])) {
            $settings['multiple'] = $values['multiple'];
          }
          break;

        case 'url':
          if (isset($values['link_type'])) {
            $settings['link_type'] = $values['link_type'];
          }
          if (isset($values['title'])) {
            $settings['title'] = $values['title'];
          }
          if (isset($values['max_length'])) {
            $settings['max_length'] = $values['max_length'];
          }
          break;

        case 'reference':
          if (isset($values['target_type'])) {
            $settings['target_type'] = $values['target_type'];
          }
          if (isset($values['target_bundles'])) {
            $settings['target_bundles'] = $this->parseTargetBundles($values['target_bundles']);
          }
          if (isset($values['multiple'])) {
            $settings['multiple'] = $values['multiple'];
          }
          if (isset($values['auto_create'])) {
            $settings['auto_create'] = $values['auto_create'];
          }
          if (isset($values['auto_create_bundle'])) {
            $settings['auto_create_bundle'] = $values['auto_create_bundle'];
          }
          if (isset($values['sort_field']) && !empty($values['sort_field'])) {
            $settings['sort']['field'] = $values['sort_field'];
            $settings['sort']['direction'] = $values['sort_direction'] ?? 'ASC';
          }
          break;
      }

      \Drupal::logger('baas_entity')->debug('从根级别获取的settings: @settings', [
        '@settings' => json_encode($settings),
      ]);
    }

    // 处理列表字段的允许值
    if (in_array($values['type'], ['list_string', 'list_integer'])) {
      if (isset($settings['allowed_values'])) {
        $allowed_values_text = $settings['allowed_values'];
        \Drupal::logger('baas_entity')->debug('允许值文本: @text', [
          '@text' => $allowed_values_text,
        ]);

        $parsed_values = $this->parseAllowedValuesFromTextarea($allowed_values_text, $values['type']);
        \Drupal::logger('baas_entity')->debug('解析后的允许值: @parsed', [
          '@parsed' => json_encode($parsed_values),
        ]);

        $settings['allowed_values'] = $parsed_values;
      } else {
        \Drupal::logger('baas_entity')->warning('列表字段缺少allowed_values设置');
      }
    }

    // 调试：记录最终的settings
    \Drupal::logger('baas_entity')->debug('最终settings: @settings', [
      '@settings' => json_encode($settings),
    ]);

    // 特殊处理实体引用字段
    if ($values['type'] === 'reference') {
      \Drupal::logger('baas_entity')->info('处理实体引用字段设置: @settings', [
        '@settings' => json_encode($settings)
      ]);
    }

    try {
      if ($field_id) {
        // 更新现有字段
        $result = $this->templateManager->updateField($field_id, [
          'label' => $values['label'],
          'description' => $values['description'],
          'required' => (int) $values['required'],
          'weight' => (int) $values['weight'],
          'settings' => $settings,
        ]);

        if ($result) {
          $this->messenger()->addStatus($this->t('字段已更新。'));
        } else {
          $this->messenger()->addError($this->t('更新字段失败。'));
        }
      } else {
        // 创建新字段
        $field_id = $this->templateManager->addField(
          $template_id,
          $values['name'],
          $values['label'],
          $values['type'],
          $values['description'],
          (int) $values['required'],
          (int) $values['weight'],
          $settings
        );

        if ($field_id) {
          $this->messenger()->addStatus($this->t('字段已添加。'));
        } else {
          $this->messenger()->addError($this->t('添加字段失败。'));
        }
      }
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('发生错误: @message', ['@message' => $e->getMessage()]));
    }

    // 返回字段列表
    $form_state->setRedirect('baas_entity.fields', ['template_id' => $template_id]);
  }

  /**
   * 获取格式化的允许值。
   *
   * @param array|null $allowed_values
   *   允许值数组或null
   *
   * @return string
   *   格式化后的允许值
   */
  protected function getFormattedAllowedValues($allowed_values)
  {
    if (is_array($allowed_values)) {
      return $this->formatAllowedValuesForTextarea($allowed_values);
    } elseif (is_string($allowed_values)) {
      return $allowed_values;
    } else {
      return '';
    }
  }
  
  /**
   * 格式化目标bundle列表为文本区域显示格式。
   *
   * @param array $target_bundles
   *   目标bundle数组
   *
   * @return string
   *   格式化后的文本，每行一个bundle
   */
  protected function formatTargetBundles(array $target_bundles): string
  {
    if (empty($target_bundles)) {
      return '';
    }
    
    return implode("\n", array_keys($target_bundles));
  }
  
  /**
   * 从文本区域解析目标bundle列表。
   *
   * @param string $text
   *   文本区域内容
   *
   * @return array
   *   解析后的bundle数组
   */
  protected function parseTargetBundles(string $text): array
  {
    if (empty(trim($text))) {
      return [];
    }
    
    $bundles = [];
    $lines = array_filter(array_map('trim', explode("\n", $text)));
    
    foreach ($lines as $line) {
      if (!empty($line)) {
        $bundles[$line] = $line;
      }
    }
    
    return $bundles;
  }
}
