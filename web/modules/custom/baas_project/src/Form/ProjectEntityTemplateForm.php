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
use Drupal\baas_project\Service\ProjectTableNameGenerator;
use Drupal\baas_project\Service\ProjectEntityTemplateManager;

/**
 * 项目实体模板创建/编辑表单。
 */
class ProjectEntityTemplateForm extends FormBase
{
  use StringTranslationTrait;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly Connection $database,
    protected readonly ProjectEntityGenerator $entityGenerator,
    protected readonly ProjectTableNameGenerator $tableNameGenerator,
    protected readonly ProjectEntityTemplateManager $entityTemplateManager
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
      $container->get('baas_project.table_name_generator'),
      $container->get('baas_project.entity_template_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'baas_project_entity_template_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $tenant_id = NULL, ?string $project_id = NULL, ?string $template_id = NULL): array
  {
    // 存储参数到表单状态
    $form_state->set('tenant_id', $tenant_id);
    $form_state->set('project_id', $project_id);
    $form_state->set('template_id', $template_id);

    // 验证项目权限
    $project = $this->projectManager->getProject($project_id);
    if (!$project || $project['tenant_id'] !== $tenant_id) {
      $this->messenger()->addError($this->t('找不到指定的项目。'));
      return $form;
    }

    // 如果是编辑模式，加载现有模板
    $template = NULL;
    if ($template_id) {
      $template = $this->loadEntityTemplate($template_id);
      if (!$template || $template['project_id'] !== $project_id) {
        $this->messenger()->addError($this->t('找不到指定的实体模板。'));
        return $form;
      }
    }

    $form['#attributes']['class'][] = 'project-entity-template-form';
    $form['#attached']['library'][] = 'baas_project/entity-template-form';

    // 基本信息
    $form['basic_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('基本信息'),
      '#tree' => TRUE,
    ];

    $form['basic_info']['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('实体名称'),
      '#description' => $this->t('实体的显示名称，如"用户"、"文章"等。'),
      '#required' => TRUE,
      '#maxlength' => 128,
      '#default_value' => $template['label'] ?? '',
    ];

    // 计算动态的最大长度
    $max_entity_name_length = $this->entityTemplateManager->calculateMaxEntityNameLength($tenant_id, $project_id);
    
    $form['basic_info']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('机器名'),
      '#description' => $this->t('实体的机器名，用于URL和程序识别。只能包含小写字母、数字和下划线。最多 @max_length 个字符（受Drupal 32字符实体类型ID限制）。', ['@max_length' => $max_entity_name_length]),
      '#required' => TRUE,
      '#maxlength' => $max_entity_name_length,
      '#default_value' => $template['name'] ?? '',
      '#disabled' => !empty($template_id), // 编辑时不允许修改机器名
      '#pattern' => '[a-z0-9_]+',
      '#attributes' => [
        'placeholder' => $this->t('例如：user_profile'),
        'data-max-length' => $max_entity_name_length,
        'data-tenant-id' => $tenant_id,
        'data-project-id' => $project_id,
        'class' => ['entity-name-field'],
      ],
    ];

    $form['basic_info']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('描述'),
      '#description' => $this->t('对此实体的描述说明。'),
      '#rows' => 3,
      '#default_value' => $template['description'] ?? '',
    ];

    // 实体设置
    $form['settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('实体设置'),
      '#tree' => TRUE,
    ];

    $current_settings = $template['settings'] ?? [];
    if (is_string($current_settings)) {
      $current_settings = json_decode($current_settings, TRUE) ?: [];
    }

    $form['settings']['enable_revisions'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用修订版本'),
      '#description' => $this->t('启用后，可以跟踪实体的历史版本。'),
      '#default_value' => $current_settings['enable_revisions'] ?? FALSE,
    ];

    $form['settings']['enable_moderation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用内容审核'),
      '#description' => $this->t('启用后，内容需要审核才能发布。'),
      '#default_value' => $current_settings['enable_moderation'] ?? FALSE,
    ];

    $form['settings']['enable_translation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用多语言'),
      '#description' => $this->t('启用后，实体支持多语言内容。'),
      '#default_value' => $current_settings['enable_translation'] ?? FALSE,
    ];

    $form['settings']['cache_duration'] = [
      '#type' => 'select',
      '#title' => $this->t('缓存时长'),
      '#description' => $this->t('实体数据的缓存时长设置。'),
      '#options' => [
        0 => $this->t('不缓存'),
        300 => $this->t('5分钟'),
        900 => $this->t('15分钟'),
        1800 => $this->t('30分钟'),
        3600 => $this->t('1小时'),
        7200 => $this->t('2小时'),
        86400 => $this->t('1天'),
      ],
      '#default_value' => $current_settings['cache_duration'] ?? 1800,
    ];

    // 字段配置预览
    if ($template_id) {
      $form['fields_preview'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('字段配置'),
        '#description' => $this->t('保存实体后，您可以在字段管理页面添加和配置字段。'),
      ];

      $fields = $this->getEntityTemplateFields($template_id);
      if (!empty($fields)) {
        $form['fields_preview']['table'] = [
          '#type' => 'table',
          '#header' => [
            $this->t('字段名'),
            $this->t('字段类型'),
            $this->t('必填'),
            $this->t('操作'),
          ],
          '#empty' => $this->t('还没有添加字段。'),
        ];

        foreach ($fields as $field) {
          $form['fields_preview']['table'][$field['id']] = [
            'name' => ['#markup' => $field['name']],
            'type' => ['#markup' => $this->getFieldTypeLabel($field['type'])],
            'required' => ['#markup' => $field['required'] ? $this->t('是') : $this->t('否')],
            'operations' => [
              '#type' => 'operations',
              '#links' => [
                'edit' => [
                  'title' => $this->t('编辑'),
                  'url' => Url::fromRoute('baas_project.entity_field_edit', [
                    'tenant_id' => $tenant_id,
                    'project_id' => $project_id,
                    'template_id' => $template_id,
                    'field_id' => $field['id'],
                  ]),
                ],
                'delete' => [
                  'title' => $this->t('删除'),
                  'url' => Url::fromRoute('baas_project.entity_field_delete', [
                    'tenant_id' => $tenant_id,
                    'project_id' => $project_id,
                    'template_id' => $template_id,
                    'field_id' => $field['id'],
                  ]),
                ],
              ],
            ],
          ];
        }

        $form['fields_preview']['add_field'] = [
          '#type' => 'link',
          '#title' => $this->t('添加字段'),
          '#url' => Url::fromRoute('baas_project.entity_field_add', [
            'tenant_id' => $tenant_id,
            'project_id' => $project_id,
            'template_id' => $template_id,
          ]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ];
      } else {
        $form['fields_preview']['empty'] = [
          '#markup' => '<p>' . $this->t('还没有添加字段。') . '</p>',
        ];
        $form['fields_preview']['add_first_field'] = [
          '#type' => 'link',
          '#title' => $this->t('添加第一个字段'),
          '#url' => Url::fromRoute('baas_project.entity_field_add', [
            'tenant_id' => $tenant_id,
            'project_id' => $project_id,
            'template_id' => $template_id,
          ]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ];
      }
    }

    // 操作按钮
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $template_id ? $this->t('更新实体') : $this->t('创建实体'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('取消'),
      '#url' => Url::fromRoute('baas_project.entities', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
      ]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * 验证机器名是否已存在。
   */
  public function machineNameExists($value, $element, FormStateInterface $form_state): bool
  {
    $project_id = $form_state->get('project_id');
    $template_id = $form_state->get('template_id');

    $query = $this->database->select('baas_entity_template', 'e')
      ->condition('project_id', $project_id)
      ->condition('name', $value)
      ->condition('status', 1);

    // 编辑时排除当前模板
    if ($template_id) {
      $query->condition('id', $template_id, '!=');
    }

    return (bool) $query->countQuery()->execute()->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    // Get machine name - check both possible paths
    $machine_name = $form_state->getValue(['basic_info', 'name']);
    if (empty($machine_name)) {
      $machine_name = $form_state->getValue('name');
    }
    
    // 检查机器名是否为空
    if (empty($machine_name)) {
      $form_state->setErrorByName('name', $this->t('机器名不能为空。'));
      return;
    }
    
    // 验证机器名格式
    if (!preg_match('/^[a-z0-9_]+$/', $machine_name)) {
      $form_state->setErrorByName('name', $this->t('机器名只能包含小写字母、数字和下划线。'));
    }

    // 验证机器名长度
    if (strlen($machine_name) < 2) {
      $form_state->setErrorByName('name', $this->t('机器名至少需要2个字符。'));
    }
    
    // 使用动态计算的最大长度进行验证
    $tenant_id = $form_state->get('tenant_id');
    $project_id = $form_state->get('project_id');
    if (!empty($tenant_id) && !empty($project_id)) {
      $max_length = $this->entityTemplateManager->calculateMaxEntityNameLength($tenant_id, $project_id);
      if (strlen($machine_name) > $max_length) {
        $form_state->setErrorByName('name', $this->t('机器名长度不能超过 @max_length 个字符（受Drupal 32字符实体类型ID限制）', ['@max_length' => $max_length]));
      }
    }

    // 检查保留字
    $reserved_names = ['id', 'type', 'bundle', 'created', 'updated', 'uid', 'status'];
    if (in_array($machine_name, $reserved_names)) {
      $form_state->setErrorByName('name', $this->t('机器名 "@name" 是保留字，请使用其他名称。', ['@name' => $machine_name]));
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

    $values = [
      'project_id' => $project_id,
      'tenant_id' => $tenant_id,
      'name' => $form_state->getValue(['basic_info', 'name']),
      'label' => $form_state->getValue(['basic_info', 'label']),
      'description' => $form_state->getValue(['basic_info', 'description']),
      'settings' => json_encode($form_state->getValue('settings')),
    ];

    try {
      if ($template_id) {
        // 使用Entity管理服务更新模板
        $result = $this->entityTemplateManager->updateEntityTemplate($template_id, $values);
      } else {
        // 使用Entity管理服务创建模板
        $result = $this->entityTemplateManager->createEntityTemplate($values);
        $template_id = $result['template_id'];
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

      // 重定向到实体管理页面
      $form_state->setRedirect('baas_project.entities', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
      ]);

    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('保存实体模板时发生错误：@error', ['@error' => $e->getMessage()]));
      \Drupal::logger('baas_project')->error('Error saving entity template: @error', ['@error' => $e->getMessage()]);
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
   * 获取实体模板的字段列表。
   */
  protected function getEntityTemplateFields(string $template_id): array
  {
    if (!$this->database->schema()->tableExists('baas_entity_field')) {
      return [];
    }

    return $this->database->select('baas_entity_field', 'f')
      ->fields('f')
      ->condition('template_id', $template_id)
      ->orderBy('weight')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * 获取字段类型标签。
   */
  protected function getFieldTypeLabel(string $type): string
  {
    $labels = [
      'string' => $this->t('文本'),
      'text' => $this->t('长文本'),
      'integer' => $this->t('整数'),
      'decimal' => $this->t('小数'),
      'boolean' => $this->t('布尔值'),
      'datetime' => $this->t('日期时间'),
      'email' => $this->t('邮箱'),
      'url' => $this->t('URL'),
      'json' => $this->t('JSON'),
      'reference' => $this->t('引用'),
    ];

    return (string) ($labels[$type] ?? $type);
  }

  /**
   * 创建动态实体数据表。
   */
  protected function createDynamicEntityTable(string $tenant_id, string $project_id, string $entity_name, $template_id): void
  {
    try {
      // 获取字段列表
      $fields = $this->getEntityTemplateFields($template_id);
      
      // 创建项目级实体表（使用新的精简表名）
      $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);
      
      if ($this->database->schema()->tableExists($table_name)) {
        \Drupal::logger('baas_project')->notice('实体表已存在: @table', ['@table' => $table_name]);
        return;
      }

      // 创建基本表结构
      $schema = [
        'description' => "存储项目 {$project_id} 中 {$entity_name} 实体的数据",
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
            'default' => $tenant_id,
          ],
          'project_id' => [
            'type' => 'varchar',
            'length' => 64,
            'not null' => TRUE,
            'description' => '项目ID',
            'default' => $project_id,
          ],
        ],
        'primary key' => ['id'],
        'unique keys' => [
          'uuid' => ['uuid'],
        ],
        'indexes' => [
          'tenant_id' => ['tenant_id'],
          'project_id' => ['project_id'],
          'created' => ['created'],
        ],
      ];

      // 添加自定义字段到表结构
      foreach ($fields as $field) {
        $field_name = $field['name'];
        $field_type = $field['type'];

        // 跳过已存在的基础字段
        if (in_array($field_name, ['id', 'uuid', 'created', 'updated', 'tenant_id', 'project_id'])) {
          continue;
        }

        // 获取字段数据库定义
        $field_schema = $this->getFieldSchema($field_type, $field);
        if ($field_schema) {
          $schema['fields'][$field_name] = $field_schema;
        }
      }

      // 创建表
      $this->database->schema()->createTable($table_name, $schema);
      
      \Drupal::logger('baas_project')->notice('创建实体数据表: @table', ['@table' => $table_name]);

    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('创建实体数据表失败: @error', ['@error' => $e->getMessage()]);
      $this->messenger()->addWarning($this->t('实体模板已创建，但创建数据表时出现问题：@error', ['@error' => $e->getMessage()]));
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
          'description' => $field['label'] ?? $field['name'],
        ];
      case 'text':
        return [
          'type' => 'text',
          'size' => 'big',
          'not null' => FALSE,
          'description' => $field['label'] ?? $field['name'],
        ];
      case 'integer':
        return [
          'type' => 'int',
          'not null' => !empty($field['required']),
          'default' => 0,
          'description' => $field['label'] ?? $field['name'],
        ];
      case 'decimal':
        return [
          'type' => 'float',
          'not null' => !empty($field['required']),
          'default' => 0,
          'description' => $field['label'] ?? $field['name'],
        ];
      case 'boolean':
        return [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 0,
          'description' => $field['label'] ?? $field['name'],
        ];
      case 'datetime':
        return [
          'type' => 'varchar',
          'length' => 20,
          'not null' => !empty($field['required']),
          'default' => NULL,
          'description' => $field['label'] ?? $field['name'],
        ];
      case 'email':
        return [
          'type' => 'varchar',
          'length' => 255,
          'not null' => !empty($field['required']),
          'default' => '',
          'description' => $field['label'] ?? $field['name'],
        ];
      case 'url':
        return [
          'type' => 'varchar',
          'length' => 2048,
          'not null' => !empty($field['required']),
          'default' => '',
          'description' => $field['label'] ?? $field['name'],
        ];
      case 'json':
        return [
          'type' => 'jsonb',
          'not null' => FALSE,
          'default' => NULL,
          'description' => $field['label'] ?? $field['name'],
        ];
      case 'reference':
        return [
          'type' => 'int',
          'not null' => !empty($field['required']),
          'default' => 0,
          'description' => $field['label'] ?? $field['name'],
        ];
      default:
        return [
          'type' => 'varchar',
          'length' => 255,
          'not null' => !empty($field['required']),
          'default' => '',
          'description' => $field['label'] ?? $field['name'],
        ];
    }
  }
}