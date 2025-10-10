<?php

declare(strict_types=1);

namespace Drupal\baas_project\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\baas_project\Service\ProjectTableNameGenerator;
use Drupal\Core\Session\AccountInterface;

/**
 * 项目实体数据创建/编辑表单。
 */
class ProjectEntityDataForm extends FormBase
{
  use StringTranslationTrait;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly Connection $database,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ProjectTableNameGenerator $tableNameGenerator,
    protected readonly AccountInterface $currentUser
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_project.manager'),
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('baas_project.table_name_generator'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'baas_project_entity_data_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $tenant_id = NULL, ?string $project_id = NULL, ?string $entity_name = NULL, ?string $data_id = NULL): array
  {
    // 存储参数到表单状态
    $form_state->set('tenant_id', $tenant_id);
    $form_state->set('project_id', $project_id);
    $form_state->set('entity_name', $entity_name);
    $form_state->set('data_id', $data_id);

    // 验证权限
    if (!$this->validateAccess($tenant_id, $project_id, $entity_name)) {
      $this->messenger()->addError($this->t('您没有权限访问此实体。'));
      return $form;
    }

    // 获取实体模板和字段信息
    $entity_template = $this->loadEntityTemplate($tenant_id, $project_id, $entity_name);
    if (!$entity_template) {
      $this->messenger()->addError($this->t('找不到指定的实体模板。'));
      return $form;
    }

    $entity_fields = $this->loadEntityFields($entity_template['id']);

    // 如果是编辑模式，加载现有数据
    $entity_data = NULL;
    if ($data_id) {
      $entity_data = $this->loadEntityData($tenant_id, $project_id, $entity_name, $data_id);
      if (!$entity_data) {
        $this->messenger()->addError($this->t('找不到指定的数据记录。'));
        return $form;
      }
    }

    $form['#attributes']['class'][] = 'project-entity-data-form';

    $form['entity_info'] = [
      '#markup' => '<div class="entity-info"><h3>' . $this->t('实体：@name', ['@name' => $entity_template['label']]) . '</h3></div>',
    ];

    // 数据字段
    $form['data'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('数据内容'),
      '#tree' => TRUE,
    ];

    // 检查实体表是否有 title 字段
    $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);
    $has_title_field = FALSE;
    
    if ($this->database->schema()->tableExists($table_name)) {
      $has_title_field = $this->database->schema()->fieldExists($table_name, 'title');
    }
    
    // 只有当实体表确实有 title 字段时才添加标题字段表单
    if ($has_title_field) {
      $form['data']['title'] = [
        '#type' => 'textfield',
        '#title' => $this->t('标题'),
        '#description' => $this->t('此记录的标题或名称。'),
        '#required' => TRUE,
        '#maxlength' => 255,
        '#default_value' => $entity_data['title'] ?? '',
      ];
    }

    // 动态生成字段表单
    foreach ($entity_fields as $field) {
      $this->buildFieldForm($form, $form_state, $field, $entity_data);
    }

    // 操作按钮
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $data_id ? $this->t('更新数据') : $this->t('保存数据'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('取消'),
      '#url' => Url::fromRoute('baas_project.entity_data', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
        'entity_name' => $entity_name,
      ]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * 构建字段表单。
   */
  protected function buildFieldForm(array &$form, FormStateInterface $form_state, array $field, ?array $entity_data): void
  {
    $field_name = $field['name'];
    $field_type = $field['type'];
    $field_settings = is_string($field['settings']) ? json_decode($field['settings'], TRUE) : ($field['settings'] ?? []);
    $default_value = $entity_data[$field_name] ?? ($field_settings['default_value'] ?? '');

    // 跳过系统字段
    if (in_array($field_name, ['id', 'uuid', 'created', 'updated', 'tenant_id', 'project_id'])) {
      return;
    }

    $form_element = [
      '#title' => $field['label'],
      '#description' => $field['description'] ?: '',
      '#required' => (bool) $field['required'],
    ];
    
    // 对于非datetime字段，设置通用默认值
    if ($field_type !== 'datetime') {
      $form_element['#default_value'] = $default_value;
    }

    // 根据字段类型构建表单元素
    switch ($field_type) {
      case 'string':
        $form_element['#type'] = 'textfield';
        $form_element['#maxlength'] = $field_settings['max_length'] ?? 255;
        break;

      case 'text':
        $form_element['#type'] = 'textarea';
        $form_element['#rows'] = 5;
        break;

      case 'integer':
        $form_element['#type'] = 'number';
        $form_element['#step'] = 1;
        if (isset($field_settings['min_value']) && $field_settings['min_value'] !== '') {
          $form_element['#min'] = $field_settings['min_value'];
        }
        if (isset($field_settings['max_value']) && $field_settings['max_value'] !== '') {
          $form_element['#max'] = $field_settings['max_value'];
        }
        break;

      case 'decimal':
        $form_element['#type'] = 'number';
        $form_element['#step'] = 0.01;
        break;

      case 'boolean':
        $form_element['#type'] = 'checkbox';
        // 处理布尔值默认值：确保0值也能正确识别
        if (isset($entity_data[$field_name])) {
          // 编辑模式：使用数据库中的值
          $form_element['#default_value'] = (bool) $entity_data[$field_name];
        } else {
          // 新建模式：使用字段设置的默认值
          $form_element['#default_value'] = (bool) $default_value;
        }
        break;

      case 'datetime':
        $form_element['#type'] = 'datetime';
        
        // 处理datetime字段的默认值
        if (isset($entity_data[$field_name]) && !empty($entity_data[$field_name])) {
          // 编辑模式：使用数据库中的值，转换为DrupalDateTime对象
          try {
            $form_element['#default_value'] = new \Drupal\Core\Datetime\DrupalDateTime($entity_data[$field_name]);
          } catch (\Exception $e) {
            // 如果解析失败，使用当前时间作为默认值
            $form_element['#default_value'] = new \Drupal\Core\Datetime\DrupalDateTime();
          }
        } else {
          // 新建模式：检查是否有默认值设置
          $default_value = $field_settings['default_value'] ?? '';
          if (!empty($default_value)) {
            try {
              // 如果默认值是 'now' 或类似字符串，创建当前时间
              if ($default_value === 'now' || $default_value === 'current') {
                $form_element['#default_value'] = new \Drupal\Core\Datetime\DrupalDateTime();
              } else {
                $form_element['#default_value'] = new \Drupal\Core\Datetime\DrupalDateTime($default_value);
              }
            } catch (\Exception $e) {
              // 如果解析失败，使用当前时间
              $form_element['#default_value'] = new \Drupal\Core\Datetime\DrupalDateTime();
            }
          }
          // 如果没有默认值设置，不设置default_value，让用户自己选择
        }
        break;

      case 'email':
        $form_element['#type'] = 'email';
        break;

      case 'url':
        $form_element['#type'] = 'url';
        break;

      case 'list_string':
        $allowed_values = $field_settings['allowed_values'] ?? [];
        $multiple = $field_settings['multiple'] ?? FALSE;
        
        if ($multiple) {
          $form_element['#type'] = 'checkboxes';
          $form_element['#options'] = $allowed_values;
          $form_element['#default_value'] = is_array($default_value) ? $default_value : [];
        } else {
          $form_element['#type'] = 'select';
          $form_element['#options'] = ['' => $this->t('- 请选择 -')] + $allowed_values;
        }
        break;

      case 'list_integer':
        $allowed_values = $field_settings['allowed_values'] ?? [];
        $multiple = $field_settings['multiple'] ?? FALSE;
        
        if ($multiple) {
          $form_element['#type'] = 'checkboxes';
          $form_element['#options'] = $allowed_values;
          $form_element['#default_value'] = is_array($default_value) ? $default_value : [];
        } else {
          $form_element['#type'] = 'select';
          $form_element['#options'] = ['' => $this->t('- 请选择 -')] + $allowed_values;
        }
        break;

      case 'json':
        $form_element['#type'] = 'textarea';
        $form_element['#rows'] = 10;
        $form_element['#description'] .= ' ' . $this->t('请输入有效的JSON格式数据。');
        $form_element['#default_value'] = is_array($default_value) ? json_encode($default_value, JSON_PRETTY_PRINT) : $default_value;
        break;

      case 'reference':
        $target_entity = $field_settings['target_entity'] ?? '';
        $multiple = $field_settings['multiple'] ?? FALSE;
        $required_reference = $field_settings['required_reference'] ?? FALSE;
        
        if ($target_entity) {
          // 获取目标实体的数据选项
          $current_data_id = $form_state->get('data_id');
          $current_entity_name = $form_state->get('entity_name');
          
          // 只有当引用同一个实体类型时才排除当前记录
          $exclude_data_id = null;
          if ($target_entity === $current_entity_name && !empty($current_data_id)) {
            $exclude_data_id = $current_data_id;
          }
          
          $reference_options = $this->getReferenceOptions($form_state->get('tenant_id'), $form_state->get('project_id'), $target_entity, $exclude_data_id);
          
          if ($multiple) {
            $form_element['#type'] = 'checkboxes';
            $form_element['#options'] = $reference_options;
            $form_element['#default_value'] = is_array($default_value) ? $default_value : [];
          } else {
            $form_element['#type'] = 'select';
            $form_element['#options'] = ['' => $this->t('- 请选择 -')] + $reference_options;
          }
          
          if ($required_reference) {
            $form_element['#required'] = TRUE;
          }
          
          $form_element['#description'] .= ' ' . $this->t('引用的实体：@entity', ['@entity' => $target_entity]);
        } else {
          // 如果没有配置目标实体，显示错误信息
          $form_element['#type'] = 'textfield';
          $form_element['#disabled'] = TRUE;
          $form_element['#description'] = $this->t('引用字段配置错误：未设置目标实体。');
        }
        break;

      default:
        $form_element['#type'] = 'textfield';
        $form_element['#maxlength'] = 255;
        break;
    }

    $form['data'][$field_name] = $form_element;
  }

  /**
   * 验证访问权限。
   */
  protected function validateAccess(string $tenant_id, string $project_id, string $entity_name): bool
  {
    $current_user = $this->currentUser;
    $user_id = (int) $current_user->id();

    // 验证项目访问权限
    $project = $this->projectManager->getProject($project_id);
    if (!$project || $project['tenant_id'] !== $tenant_id) {
      return FALSE;
    }

    // 检查用户是否是项目成员或有管理权限
    $user_role = $this->projectManager->getUserProjectRole($project_id, $user_id);
    $has_admin_permission = $current_user->hasPermission('administer baas project') || 
                           $current_user->hasPermission('create baas project content');

    return $user_role || $has_admin_permission;
  }

  /**
   * 加载实体模板。
   */
  protected function loadEntityTemplate(string $tenant_id, string $project_id, string $entity_name): ?array
  {
    $template = $this->database->select('baas_entity_template', 'e')
      ->fields('e')
      ->condition('tenant_id', $tenant_id)
      ->condition('project_id', $project_id)
      ->condition('name', $entity_name)
      ->condition('status', 1)
      ->execute()
      ->fetch(\PDO::FETCH_ASSOC);

    return $template ?: NULL;
  }

  /**
   * 加载实体字段。
   */
  protected function loadEntityFields(string $template_id): array
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

  /**
   * 加载实体数据。
   */
  protected function loadEntityData(string $tenant_id, string $project_id, string $entity_name, string $data_id): ?array
  {
    $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);
    
    if (!$this->database->schema()->tableExists($table_name)) {
      return NULL;
    }

    $data = $this->database->select($table_name, 'd')
      ->fields('d')
      ->condition('id', $data_id)
      ->execute()
      ->fetch(\PDO::FETCH_ASSOC);

    return $data ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $tenant_id = $form_state->get('tenant_id');
    $project_id = $form_state->get('project_id');
    $entity_name = $form_state->get('entity_name');

    // 验证基本权限
    if (!$this->validateAccess($tenant_id, $project_id, $entity_name)) {
      $form_state->setError($form, $this->t('您没有权限执行此操作。'));
      return;
    }

    // 获取表单数据
    $data_values = $form_state->getValue('data');

    // 检查实体表是否有 title 字段，只有存在时才验证
    $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);
    $has_title_field = FALSE;
    
    if ($this->database->schema()->tableExists($table_name)) {
      $has_title_field = $this->database->schema()->fieldExists($table_name, 'title');
    }
    
    // 只有当实体表确实有 title 字段时才验证标题
    if ($has_title_field && empty(trim($data_values['title'] ?? ''))) {
      $form_state->setErrorByName('data][title', $this->t('标题不能为空。'));
    }

    // 验证字段数据
    $entity_template = $this->loadEntityTemplate($tenant_id, $project_id, $entity_name);
    if ($entity_template) {
      $entity_fields = $this->loadEntityFields($entity_template['id']);
      $this->validateFieldData($form, $form_state, $entity_fields, $data_values);
    }
  }

  /**
   * 验证字段数据。
   */
  protected function validateFieldData(array &$form, FormStateInterface $form_state, array $fields, array $data_values): void
  {
    foreach ($fields as $field) {
      $field_name = $field['name'];
      $field_type = $field['type'];
      $field_value = $data_values[$field_name] ?? NULL;

      // 跳过系统字段
      if (in_array($field_name, ['id', 'uuid', 'created', 'updated', 'tenant_id', 'project_id'])) {
        continue;
      }

      // 必填字段验证
      if ($field['required'] && (is_null($field_value) || $field_value === '')) {
        $form_state->setErrorByName("data][{$field_name}", $this->t('字段 "@field" 是必填的。', ['@field' => $field['label']]));
        continue;
      }

      // 类型特定验证
      switch ($field_type) {
        case 'integer':
          if (!is_null($field_value) && $field_value !== '' && !is_numeric($field_value)) {
            $form_state->setErrorByName("data][{$field_name}", $this->t('字段 "@field" 必须是整数。', ['@field' => $field['label']]));
          }
          break;

        case 'decimal':
          if (!is_null($field_value) && $field_value !== '' && !is_numeric($field_value)) {
            $form_state->setErrorByName("data][{$field_name}", $this->t('字段 "@field" 必须是数字。', ['@field' => $field['label']]));
          }
          break;

        case 'email':
          if (!is_null($field_value) && $field_value !== '' && !filter_var($field_value, FILTER_VALIDATE_EMAIL)) {
            $form_state->setErrorByName("data][{$field_name}", $this->t('字段 "@field" 必须是有效的邮箱地址。', ['@field' => $field['label']]));
          }
          break;

        case 'url':
          if (!is_null($field_value) && $field_value !== '' && !filter_var($field_value, FILTER_VALIDATE_URL)) {
            $form_state->setErrorByName("data][{$field_name}", $this->t('字段 "@field" 必须是有效的URL。', ['@field' => $field['label']]));
          }
          break;

        case 'json':
          if (!is_null($field_value) && $field_value !== '') {
            json_decode($field_value);
            if (json_last_error() !== JSON_ERROR_NONE) {
              $form_state->setErrorByName("data][{$field_name}", $this->t('字段 "@field" 必须包含有效的JSON数据。', ['@field' => $field['label']]));
            }
          }
          break;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $tenant_id = $form_state->get('tenant_id');
    $project_id = $form_state->get('project_id');
    $entity_name = $form_state->get('entity_name');
    $data_id = $form_state->get('data_id');

    try {
      // 获取表单数据
      $data_values = $form_state->getValue('data');

      // 检查实体表是否有 title 字段
      $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);
      $has_title_field = FALSE;
      
      if ($this->database->schema()->tableExists($table_name)) {
        $has_title_field = $this->database->schema()->fieldExists($table_name, 'title');
      }

      // 准备数据
      $entity_data = [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
        'updated' => time(),
      ];
      
      // 只有当实体表确实有 title 字段时才添加 title 数据
      if ($has_title_field) {
        $entity_data['title'] = trim($data_values['title']);
      }

      // 如果是新建，添加创建时间和UUID
      if (!$data_id) {
        $entity_data['created'] = time();
        $entity_data['uuid'] = \Drupal::service('uuid')->generate();
      }

      // 处理字段数据
      $entity_template = $this->loadEntityTemplate($tenant_id, $project_id, $entity_name);
      if ($entity_template) {
        $entity_fields = $this->loadEntityFields($entity_template['id']);
        $this->processFieldData($entity_data, $entity_fields, $data_values);
      }

      // 保存到数据库
      $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);
      
      if ($data_id) {
        // 更新现有数据
        $this->database->update($table_name)
          ->fields($entity_data)
          ->condition('id', $data_id)
          ->execute();
        
        $this->messenger()->addStatus($this->t('数据已成功更新。'));
      } else {
        // 插入新数据
        $new_id = $this->database->insert($table_name)
          ->fields($entity_data)
          ->execute();
        
        $this->messenger()->addStatus($this->t('数据已成功保存。'));
      }

      // 重定向到数据管理页面
      $form_state->setRedirect('baas_project.entity_data', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
        'entity_name' => $entity_name,
      ]);

    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('保存数据时发生错误：@error', ['@error' => $e->getMessage()]));
      \Drupal::logger('baas_project')->error('Error saving entity data: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * 处理字段数据。
   */
  protected function processFieldData(array &$entity_data, array $fields, array $form_data): void
  {
    foreach ($fields as $field) {
      $field_name = $field['name'];
      $field_type = $field['type'];
      $field_value = $form_data[$field_name] ?? NULL;

      // 跳过系统字段
      if (in_array($field_name, ['id', 'uuid', 'created', 'updated', 'tenant_id', 'project_id'])) {
        continue;
      }

      // 处理不同字段类型的数据
      switch ($field_type) {
        case 'integer':
          $entity_data[$field_name] = is_numeric($field_value) ? (int) $field_value : NULL;
          break;

        case 'decimal':
          $entity_data[$field_name] = is_numeric($field_value) ? (float) $field_value : NULL;
          break;

        case 'boolean':
          // 处理布尔值：确保正确转换为数据库可存储的值
          $entity_data[$field_name] = $field_value ? 1 : 0;
          break;

        case 'list_string':
        case 'list_integer':
          $field_settings = is_string($field['settings']) ? json_decode($field['settings'], TRUE) : ($field['settings'] ?? []);
          $multiple = $field_settings['multiple'] ?? FALSE;
          
          if ($multiple && is_array($field_value)) {
            // 多选：过滤掉空值并序列化
            $filtered_values = array_filter($field_value);
            $entity_data[$field_name] = !empty($filtered_values) ? json_encode(array_values($filtered_values)) : NULL;
          } else {
            // 单选：直接保存值
            $entity_data[$field_name] = !empty($field_value) ? $field_value : NULL;
          }
          break;

        case 'json':
          if (!empty($field_value)) {
            // 验证JSON并保存
            $decoded = json_decode($field_value);
            $entity_data[$field_name] = (json_last_error() === JSON_ERROR_NONE) ? $field_value : NULL;
          } else {
            $entity_data[$field_name] = NULL;
          }
          break;

        case 'datetime':
          // 处理日期时间字段：DrupalDateTime对象转换为ISO格式字符串
          if ($field_value instanceof \Drupal\Core\Datetime\DrupalDateTime) {
            $entity_data[$field_name] = $field_value->format('Y-m-d H:i:s');
          } elseif (!empty($field_value) && is_string($field_value)) {
            // 如果已经是字符串，直接使用
            $entity_data[$field_name] = trim($field_value);
          } else {
            // 如果字段值为空，检查是否有默认值设置
            $field_settings = is_string($field['settings']) ? json_decode($field['settings'], TRUE) : ($field['settings'] ?? []);
            $default_value = $field_settings['default_value'] ?? '';
            
            if (!empty($default_value)) {
              if ($default_value === 'now' || $default_value === 'current') {
                // 使用当前时间作为默认值
                $now = new \Drupal\Core\Datetime\DrupalDateTime();
                $entity_data[$field_name] = $now->format('Y-m-d H:i:s');
              } else {
                try {
                  $default_datetime = new \Drupal\Core\Datetime\DrupalDateTime($default_value);
                  $entity_data[$field_name] = $default_datetime->format('Y-m-d H:i:s');
                } catch (\Exception $e) {
                  $entity_data[$field_name] = NULL;
                }
              }
            } else {
              $entity_data[$field_name] = NULL;
            }
          }
          break;

        case 'reference':
          $field_settings = is_string($field['settings']) ? json_decode($field['settings'], TRUE) : ($field['settings'] ?? []);
          $multiple = $field_settings['multiple'] ?? FALSE;
          
          if ($multiple) {
            // 多选引用字段：处理数组数据
            if (is_array($field_value)) {
              $valid_references = array_filter($field_value, function($value) {
                return !empty($value) && is_numeric($value);
              });
              $entity_data[$field_name] = !empty($valid_references) ? json_encode(array_values($valid_references)) : NULL;
            } else {
              $entity_data[$field_name] = NULL;
            }
          } else {
            // 单选引用字段：处理单个值
            $entity_data[$field_name] = (!empty($field_value) && is_numeric($field_value)) ? (int) $field_value : NULL;
          }
          break;

        default:
          $entity_data[$field_name] = !empty($field_value) && is_string($field_value) ? trim($field_value) : NULL;
          break;
      }
    }
  }

  /**
   * 获取引用实体的选项。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $target_entity
   *   目标实体名称。
   * @param string|null $exclude_data_id
   *   要排除的数据ID（防止自引用）。
   *
   * @return array
   *   引用选项数组。
   */
  protected function getReferenceOptions(string $tenant_id, string $project_id, string $target_entity, ?string $exclude_data_id = null): array
  {
    try {
      // 获取目标实体的数据表名
      $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $target_entity);
      
      // 检查表是否存在
      if (!$this->database->schema()->tableExists($table_name)) {
        return [];
      }
      
      // 检查目标表是否有 title 字段
      $has_title_field = $this->database->schema()->fieldExists($table_name, 'title');
      
      // 根据是否有 title 字段来选择显示字段
      $display_fields = ['id'];
      $display_field_name = 'id'; // 默认使用 id 作为显示字段
      
      if ($has_title_field) {
        $display_fields[] = 'title';
        $display_field_name = 'title';
      } else {
        // 尝试找到一个合适的显示字段（username, name, email 等）
        $preferred_fields = ['username', 'name', 'email', 'label'];
        foreach ($preferred_fields as $field) {
          if ($this->database->schema()->fieldExists($table_name, $field)) {
            $display_fields[] = $field;
            $display_field_name = $field;
            break;
          }
        }
      }
      
      // 查询实体数据
      $query = $this->database->select($table_name, 'e')
        ->fields('e', $display_fields)
        ->condition('e.tenant_id', $tenant_id)
        ->condition('e.project_id', $project_id)
        ->range(0, 500); // 限制返回数量，避免性能问题
      
      // 如果有可排序的显示字段，则按该字段排序
      if ($display_field_name !== 'id') {
        $query->orderBy('e.' . $display_field_name, 'ASC');
      } else {
        $query->orderBy('e.id', 'ASC');
      }
      
      // 排除当前数据记录（防止自引用）
      if (!empty($exclude_data_id)) {
        $query->condition('e.id', $exclude_data_id, '!=');
      }
      
      $results = $query->execute();
      $options = [];
      
      foreach ($results as $row) {
        // 根据可用字段构建显示名称
        if ($display_field_name !== 'id' && !empty($row->{$display_field_name})) {
          $options[$row->id] = $row->{$display_field_name};
        } else {
          // 如果没有合适的显示字段，使用 "记录 #ID" 格式
          $options[$row->id] = $this->t('记录 #@id', ['@id' => $row->id]);
        }
      }
      
      return $options;
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('获取引用选项失败: @error', [
        '@error' => $e->getMessage(),
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
        'target_entity' => $target_entity,
        'exclude_data_id' => $exclude_data_id,
      ]);
      return [];
    }
  }

}