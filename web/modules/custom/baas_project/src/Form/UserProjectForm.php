<?php

declare(strict_types=1);

namespace Drupal\baas_project\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_tenant\TenantManagerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 用户项目创建/编辑表单。
 */
class UserProjectForm extends FormBase
{

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_project\ProjectManagerInterface $projectManager
   *   项目管理服务。
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenantManager
   *   租户管理服务。
   */
  public function __construct(
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly TenantManagerInterface $tenantManager
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_project.manager'),
      $container->get('baas_tenant.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'user_project_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $tenant_id = null, ?string $project_id = null): array
  {
    // 验证租户访问权限
    if (!$this->checkTenantAccess($tenant_id)) {
      throw new AccessDeniedHttpException();
    }

    $tenant = $this->tenantManager->getTenant($tenant_id);
    if (!$tenant) {
      throw new NotFoundHttpException('租户不存在');
    }

    $form_state->set('tenant_id', $tenant_id);
    $form_state->set('tenant', $tenant);

    // 如果有项目ID，加载现有项目数据
    $project = null;
    if ($project_id) {
      $project = $this->projectManager->getProject($project_id);
      if (!$project || $project['tenant_id'] !== $tenant_id) {
        throw new NotFoundHttpException('项目不存在');
      }
      $form_state->set('project_id', $project_id);
      $form_state->set('project', $project);
    }

    // 租户信息显示
    $form['tenant_info'] = [
      '#type' => 'item',
      '#title' => $this->t('所属租户'),
      '#markup' => '<strong>' . $tenant['name'] . '</strong> (' . $tenant_id . ')',
    ];

    // 项目基本信息
    $form['basic'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('基本信息'),
    ];

    $form['basic']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('项目名称'),
      '#required' => true,
      '#maxlength' => 255,
      '#default_value' => $project['name'] ?? '',
      '#description' => $this->t('项目的显示名称。'),
    ];

    $form['basic']['machine_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('机器名称'),
      '#required' => true,
      '#maxlength' => 64,
      '#pattern' => '[a-z0-9_]+',
      '#default_value' => $project['machine_name'] ?? '',
      '#description' => $this->t('项目的唯一标识符，只能包含小写字母、数字和下划线。'),
    ];

    // 编辑模式时机器名不能修改
    if ($project) {
      $form['basic']['machine_name']['#disabled'] = true;
      $form['basic']['machine_name']['#description'] = $this->t('项目创建后机器名不能修改。');
    }

    $form['basic']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('项目描述'),
      '#rows' => 3,
      '#default_value' => $project['description'] ?? '',
      '#description' => $this->t('描述此项目的用途。'),
    ];

    $form['basic']['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用项目'),
      '#default_value' => $project['status'] ?? 1,
      '#description' => $this->t('禁用的项目将无法访问。'),
    ];

    // 项目设置
    $settings = $project['settings'] ?? [];
    
    $form['settings'] = [
      '#type' => 'details',
      '#title' => $this->t('项目设置'),
      '#open' => true,
    ];

    $form['settings']['visibility'] = [
      '#type' => 'select',
      '#title' => $this->t('项目可见性'),
      '#options' => [
        'private' => $this->t('私有项目 - 只有项目成员可以访问'),
        'tenant' => $this->t('租户内可见 - 租户内所有用户可以查看'),
        'public' => $this->t('公开项目 - 所有人可以查看（只读）'),
      ],
      '#default_value' => $settings['visibility'] ?? 'private',
      '#description' => $this->t('控制谁可以查看和访问此项目。'),
    ];

    $form['settings']['auto_backup'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用自动备份'),
      '#default_value' => $settings['auto_backup'] ?? false,
      '#description' => $this->t('定期自动备份项目数据。'),
    ];

    $form['settings']['notifications'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用通知'),
      '#default_value' => $settings['notifications'] ?? true,
      '#description' => $this->t('当项目有重要事件时发送通知。'),
    ];

    // 注释：资源限制功能暂时移除，统一使用系统级限流配置
    // 未来可能根据需求重新设计项目级配额管理

    // 操作按钮
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $project ? $this->t('更新项目') : $this->t('创建项目'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('取消'),
      '#url' => Url::fromRoute('baas_project.user_list'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $tenant_id = $form_state->get('tenant_id');
    $project_id = $form_state->get('project_id');
    $machine_name = $form_state->getValue('machine_name');

    // 验证机器名格式
    if (!preg_match('/^[a-z0-9_]+$/', $machine_name)) {
      $form_state->setErrorByName('machine_name', $this->t('机器名只能包含小写字母、数字和下划线。'));
    }

    // 检查机器名唯一性（在租户内）
    if (!$project_id) { // 只在创建时检查
      $existing = $this->projectManager->getProjectByMachineName($tenant_id, $machine_name);
      if ($existing) {
        $form_state->setErrorByName('machine_name', $this->t('此机器名已被使用，请选择其他名称。'));
      }
    }

    // 资源限制验证已移除
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $tenant_id = $form_state->get('tenant_id');
    $project_id = $form_state->get('project_id');
    $current_user = $this->currentUser();

    // 准备项目数据
    $values = $form_state->getValues();
    
    $settings = [
      'visibility' => $values['visibility'],
      'auto_backup' => (bool) $values['auto_backup'],
      'notifications' => (bool) $values['notifications'],
    ];

    // 资源限制设置已移除

    try {
      if ($project_id) {
        // 更新现有项目
        $success = $this->projectManager->updateProject($project_id, [
          'name' => $values['name'],
          'description' => $values['description'],
          'status' => (int) $values['status'],
          'settings' => $settings,
        ]);

        if ($success) {
          $this->messenger()->addStatus($this->t('项目已成功更新。'));
        } else {
          $this->messenger()->addError($this->t('更新项目时发生错误。'));
        }
      } else {
        // 创建新项目
        $new_project_id = $this->projectManager->createProject(
          $tenant_id,
          $values['machine_name'],
          $values['name'],
          $current_user->id(),
          [
            'description' => $values['description'],
            'status' => (int) $values['status'],
            'settings' => $settings,
          ]
        );

        if ($new_project_id) {
          $this->messenger()->addStatus($this->t('项目已成功创建。'));
          $project_id = $new_project_id;
        } else {
          $this->messenger()->addError($this->t('创建项目时发生错误。'));
          return;
        }
      }

      // 重定向到项目列表
      $form_state->setRedirect('baas_project.user_list');

    } catch (\Exception $e) {
      $this->getLogger('baas_project')->error('项目表单处理错误: @error', ['@error' => $e->getMessage()]);
      $this->messenger()->addError($this->t('处理项目时发生错误，请稍后重试。'));
    }
  }

  /**
   * 检查用户对租户的访问权限。
   *
   * @param string $tenant_id
   *   租户ID。
   *
   * @return bool
   *   是否有权限。
   */
  protected function checkTenantAccess(string $tenant_id): bool
  {
    $current_user = $this->currentUser();
    
    // 管理员有全部权限
    if ($current_user->hasPermission('administer baas tenants')) {
      return true;
    }

    $database = \Drupal::database();
    
    // 检查用户是否属于此租户
    $mapping = $database->select('baas_user_tenant_mapping', 'm')
      ->condition('user_id', (int) $current_user->id())
      ->condition('tenant_id', $tenant_id)
      ->condition('status', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($mapping) {
      return true;
    }
    
    // 如果不是租户成员，检查是否为租户下任何项目的成员
    if ($database->schema()->tableExists('baas_project_members') && 
        $database->schema()->tableExists('baas_project_config')) {
      
      $query = $database->select('baas_project_members', 'm');
      $query->leftJoin('baas_project_config', 'p', 'm.project_id = p.project_id');
      $query->condition('m.user_id', (int) $current_user->id())
        ->condition('p.tenant_id', $tenant_id)
        ->condition('m.status', 1)
        ->condition('p.status', 1);
      
      $project_member_count = $query->countQuery()
        ->execute()
        ->fetchField();
        
      return (bool) $project_member_count;
    }

    return false;
  }
}