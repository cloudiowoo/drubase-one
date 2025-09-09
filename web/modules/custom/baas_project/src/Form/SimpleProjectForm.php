<?php

declare(strict_types=1);

namespace Drupal\baas_project\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_tenant\TenantManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * 简化的项目创建表单.
 *
 * 重构后的项目创建流程：
 * 1. 自动检测用户的租户空间
 * 2. 简化项目创建字段
 * 3. 自动处理租户关联
 */
class SimpleProjectForm extends FormBase
{
  use StringTranslationTrait;

  /**
   * 项目管理服务.
   *
   * @var \Drupal\baas_project\ProjectManagerInterface
   */
  protected $projectManager;

  /**
   * 租户管理服务.
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected $tenantManager;

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_project\ProjectManagerInterface $projectManager
   *   项目管理服务.
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenantManager
   *   租户管理服务.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   消息服务.
   */
  public function __construct(
    ProjectManagerInterface $projectManager,
    TenantManagerInterface $tenantManager,
    MessengerInterface $messenger
  ) {
    $this->projectManager = $projectManager;
    $this->tenantManager = $tenantManager;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_project.manager'),
      $container->get('baas_tenant.manager'),
      $container->get('messenger')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'baas_project_simple_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $project_id = NULL): array
  {
    $current_user = $this->currentUser();
    $edit_mode = !empty($project_id);

    // 检查用户是否已登录
    if ($current_user->isAnonymous()) {
      $this->messenger()->addError($this->t('请先登录才能创建项目。'));
      $form_state->setRedirect('user.login');
      return [];
    }

    // 获取用户的租户
    $user_tenants = $this->getUserTenants((int) $current_user->id());

    // 如果没有租户，自动创建或引导用户
    if (empty($user_tenants)) {
      // 检查用户是否是项目管理员
      $user_entity = \Drupal::entityTypeManager()->getStorage('user')->load((int) $current_user->id());
      $is_project_manager = baas_tenant_is_user_tenant($user_entity);

      if ($is_project_manager) {
        // 自动创建租户
        $tenant_id = $this->createTenantForUser($user_entity);
        if ($tenant_id) {
          $user_tenants = $this->getUserTenants((int) $current_user->id());
        }
      }

      if (empty($user_tenants)) {
        $this->messenger()->addError($this->t('您还没有租户空间。请联系管理员配置您的项目权限。'));
        $form_state->setRedirect('baas_project.user_list');
        return [];
      }
    }

    // 编辑模式下加载项目数据
    $project = NULL;
    if ($edit_mode) {
      $project = $this->projectManager->getProject($project_id);
      if (!$project) {
        $this->messenger()->addError($this->t('项目不存在。'));
        $form_state->setRedirect('baas_project.user_list');
        return [];
      }
    }

    $form['#attached']['library'][] = 'baas_project/user-projects';

    // 项目基本信息
    $form['basic_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('基本信息'),
      '#collapsible' => FALSE,
    ];

    $form['basic_info']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('项目名称'),
      '#required' => TRUE,
      '#maxlength' => 128,
      '#description' => $this->t('项目的显示名称，用于界面展示。'),
      '#default_value' => $project['name'] ?? '',
    ];

    $form['basic_info']['machine_name'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('机器名'),
      '#maxlength' => 64,
      '#required' => TRUE,
      '#description' => $this->t('项目的机器名，用于API和内部引用。只能包含小写字母、数字和下划线。'),
      '#machine_name' => [
        'exists' => [$this, 'projectMachineNameExists'],
        'source' => ['basic_info', 'name'],
        'replace_pattern' => '[^a-z0-9_]+',
        'replace' => '_',
      ],
      '#default_value' => $project['machine_name'] ?? '',
      '#disabled' => $edit_mode, // 编辑模式下不能修改机器名
    ];

    $form['basic_info']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('项目描述'),
      '#rows' => 3,
      '#description' => $this->t('项目的详细描述（可选）。'),
      '#default_value' => $project['description'] ?? '',
    ];

    // 租户选择（如果有多个租户）
    if (count($user_tenants) > 1) {
      $tenant_options = [];
      foreach ($user_tenants as $tenant) {
        $tenant_options[$tenant['tenant_id']] = $tenant['name'];
      }

      $form['basic_info']['tenant_id'] = [
        '#type' => 'select',
        '#title' => $this->t('所属租户'),
        '#options' => $tenant_options,
        '#required' => TRUE,
        '#description' => $this->t('选择项目所属的租户空间。'),
        '#default_value' => $project['tenant_id'] ?? array_key_first($tenant_options),
        '#disabled' => $edit_mode, // 编辑模式下不能修改租户
      ];
    } else {
      // 只有一个租户，隐藏选择
      $form['basic_info']['tenant_id'] = [
        '#type' => 'value',
        '#value' => $user_tenants[0]['tenant_id'],
      ];

      $form['basic_info']['tenant_info'] = [
        '#type' => 'item',
        '#title' => $this->t('所属租户'),
        '#markup' => $user_tenants[0]['name'],
      ];
    }

    // 项目设置
    $form['settings'] = [
      '#type' => 'details',
      '#title' => $this->t('项目设置'),
      '#open' => FALSE,
    ];

    $settings = $project['settings'] ?? [];

    $form['settings']['visibility'] = [
      '#type' => 'radios',
      '#title' => $this->t('项目可见性'),
      '#options' => [
        'private' => $this->t('私有项目 - 仅项目成员可见'),
        'tenant' => $this->t('租户内可见 - 租户内所有用户可见'),
        'public' => $this->t('公开项目 - 所有用户可见'),
      ],
      '#default_value' => $settings['visibility'] ?? 'private',
      '#description' => $this->t('控制项目的可见性范围。'),
    ];

    // 资源限制配置已移除 - 使用系统级限流配置

    // 隐藏字段
    if ($edit_mode) {
      $form['project_id'] = [
        '#type' => 'value',
        '#value' => $project_id,
      ];
    }

    // 提交按钮
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $edit_mode ? $this->t('保存项目') : $this->t('创建项目'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('取消'),
      '#url' => Url::fromRoute('baas_project.user_list'),
      '#attributes' => ['class' => ['button']],
    ];

    // 编辑模式下添加资源限制配置链接
    if ($edit_mode && $this->currentUser()->hasPermission('manage baas project resource limits')) {
      $form['actions']['resource_limits'] = [
        '#type' => 'link',
        '#title' => $this->t('资源限制配置'),
        '#url' => Url::fromRoute('baas_project.resource_limits', [
          'tenant_id' => $project['tenant_id'],
          'project_id' => $project_id,
        ]),
        '#attributes' => [
          'class' => ['button', 'button--secondary'],
          'target' => '_blank',
        ],
      ];
    }

    return $form;
  }

  /**
   * 检查项目机器名是否已存在.
   *
   * @param string $machine_name
   *   机器名.
   * @param array $element
   *   表单元素.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   表单状态.
   *
   * @return bool
   *   是否已存在.
   */
  public function projectMachineNameExists($machine_name, array $element, FormStateInterface $form_state): bool
  {
    $tenant_id = $form_state->getValue('tenant_id');
    if (!$tenant_id) {
      return FALSE;
    }

    $existing_project = $this->projectManager->getProjectByMachineName($tenant_id, $machine_name);

    // 编辑模式下，排除当前项目
    $current_project_id = $form_state->getValue('project_id');
    if ($current_project_id && $existing_project && $existing_project['project_id'] === $current_project_id) {
      return FALSE;
    }

    return (bool) $existing_project;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $machine_name = $form_state->getValue('machine_name');

    // 只验证机器名格式（其他必填验证由 Drupal 内置处理）
    if (!empty($machine_name) && !preg_match('/^[a-z0-9_]+$/', $machine_name)) {
      $form_state->setErrorByName('machine_name', $this->t('机器名只能包含小写字母、数字和下划线。'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $values = $form_state->getValues();
    $edit_mode = !empty($values['project_id']);

    // 准备项目数据（表单字段在顶层，不是嵌套的）
    $project_data = [
      'name' => trim((string) ($values['name'] ?? '')),
      'machine_name' => trim((string) ($values['machine_name'] ?? '')),
      'description' => trim((string) ($values['description'] ?? '')),
      'tenant_id' => $values['tenant_id'] ?? '',
      'settings' => [
        'visibility' => $values['visibility'] ?? 'public',
        // 资源限制已移除
      ],
    ];

    try {
      if ($edit_mode) {
        // 更新项目
        $this->messenger()->addError($this->t('编辑模式暂时禁用。'));
      } else {
        // 创建项目
        $project_id = $this->projectManager->createProject($project_data['tenant_id'], $project_data);
        if ($project_id) {
          $this->messenger()->addStatus($this->t('项目 "@name" 已成功创建。', ['@name' => $project_data['name']]));
        } else {
          $this->messenger()->addError($this->t('创建项目失败。'));
        }
      }
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('操作失败：@error', ['@error' => $e->getMessage()]));
    }

    // 重定向到项目列表
    $form_state->setRedirect('baas_project.user_list');
  }

  /**
   * 获取用户的租户列表.
   *
   * @param int $user_id
   *   用户ID.
   *
   * @return array
   *   租户列表.
   */
  protected function getUserTenants(int $user_id): array
  {
    $database = \Drupal::database();
    $tenants = [];

    if (!$database->schema()->tableExists('baas_user_tenant_mapping')) {
      return [];
    }

    $tenant_mappings = $database->select('baas_user_tenant_mapping', 'm')
      ->fields('m', ['tenant_id'])
      ->condition('user_id', $user_id)
      ->condition('status', 1)
      ->execute()
      ->fetchCol();

    foreach ($tenant_mappings as $tenant_id) {
      $tenant = $this->tenantManager->getTenant($tenant_id);
      if ($tenant) {
        $tenants[] = $tenant;
      }
    }

    return $tenants;
  }

  /**
   * 为用户创建租户.
   *
   * @param \Drupal\user\UserInterface $user
   *   用户对象.
   *
   * @return string|null
   *   创建的租户ID，失败返回NULL.
   */
  protected function createTenantForUser($user): ?string
  {
    try {
      $tenant_data = [
        'name' => $user->getDisplayName() . '的项目空间',
        'settings' => [
          'max_requests' => 10000,
          'organization_type' => 'personal',
          'contact_email' => $user->getEmail(),
          'auto_created' => TRUE,
        ],
      ];

      return $this->tenantManager->createTenant($tenant_data['name'], (int) $user->id(), $tenant_data['settings']);
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('为用户创建租户失败: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }
}