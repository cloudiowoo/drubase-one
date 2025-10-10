<?php

namespace Drupal\baas_tenant\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\baas_tenant\TenantManagerInterface;
use Drupal\baas_tenant\Service\TenantPermissionChecker;
use Drupal\baas_auth\Service\UserTenantMappingInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * 租户成员管理表单.
 */
class TenantMemberForm extends FormBase
{
  use StringTranslationTrait;

  /**
   * 租户管理服务.
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected $tenantManager;

  /**
   * 租户权限检查服务.
   *
   * @var \Drupal\baas_tenant\Service\TenantPermissionChecker
   */
  protected $permissionChecker;

  /**
   * 用户-租户映射服务.
   *
   * @var \Drupal\baas_auth\Service\UserTenantMappingInterface
   */
  protected $userTenantMapping;

  /**
   * 数据库连接.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * 请求堆栈服务.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * 当前用户.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * 实体类型管理器.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('baas_tenant.manager'),
      $container->get('baas_tenant.permission_checker'),
      $container->get('baas_auth.user_tenant_mapping'),
      $container->get('database'),
      $container->get('request_stack'),
      $container->get('current_user'),
      $container->get('messenger'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * 构造函数.
   */
  public function __construct(
    TenantManagerInterface $tenant_manager,
    TenantPermissionChecker $permission_checker,
    UserTenantMappingInterface $user_tenant_mapping,
    Connection $database,
    RequestStack $request_stack,
    AccountInterface $current_user,
    MessengerInterface $messenger,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->tenantManager = $tenant_manager;
    $this->permissionChecker = $permission_checker;
    $this->userTenantMapping = $user_tenant_mapping;
    $this->database = $database;
    $this->requestStack = $request_stack;
    $this->currentUser = $current_user;
    $this->messenger = $messenger;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'baas_tenant_member_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $tenant_id = NULL)
  {
    // 验证租户存在
    $tenant = $this->tenantManager->getTenant($tenant_id);
    if (!$tenant) {
      $this->messenger->addError($this->t('租户不存在'));
      return $this->redirect('baas_tenant.admin_list');
    }

    // 检查权限
    if (!$this->permissionChecker->canManageTenantMembers($this->currentUser, $tenant_id)) {
      $this->messenger->addError($this->t('您没有权限管理此租户的成员'));
      return $this->redirect('baas_tenant.view', ['tenant_id' => $tenant_id]);
    }

    $form['#title'] = $this->t('管理租户 @name 的成员', ['@name' => $tenant['name']]);

    $form['tenant_id'] = [
      '#type' => 'hidden',
      '#value' => $tenant_id,
    ];

    $request = $this->requestStack->getCurrentRequest();
    $user_id = $request->query->get('user_id');
    $action = $request->query->get('action');

    if ($action === 'remove' && $user_id) {
      return $this->buildRemoveForm($form, $form_state, $tenant_id, $user_id);
    } elseif ($user_id) {
      return $this->buildEditForm($form, $form_state, $tenant_id, $user_id);
    } else {
      return $this->buildAddForm($form, $form_state, $tenant_id);
    }
  }

  /**
   * 构建添加成员表单.
   */
  protected function buildAddForm(array $form, FormStateInterface $form_state, $tenant_id)
  {
    $form['add_member'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('添加新成员'),
    ];

    $form['add_member']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('用户名或邮箱'),
      '#description' => $this->t('输入要添加的用户的用户名或邮箱地址'),
      '#required' => TRUE,
    ];

    $form['add_member']['role'] = [
      '#type' => 'select',
      '#title' => $this->t('角色'),
      '#options' => $this->getRoleOptions(),
      '#default_value' => 'tenant_user',
      '#required' => TRUE,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('添加成员'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('取消'),
      '#url' => Url::fromRoute('baas_tenant.members', ['tenant_id' => $tenant_id]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * 构建编辑成员表单.
   */
  protected function buildEditForm(array $form, FormStateInterface $form_state, $tenant_id, $user_id)
  {
    // 通过实体API获取用户信息
    $user_storage = $this->entityTypeManager->getStorage('user');
    /** @var \Drupal\user\UserInterface $user_entity */
    $user_entity = $user_storage->load($user_id);

    if (!$user_entity || !$user_entity->isActive()) {
      $this->messenger->addError($this->t('用户不存在'));
      return $this->redirect('baas_tenant.members', ['tenant_id' => $tenant_id]);
    }

    $user = [
      'uid' => $user_entity->id(),
      'name' => $user_entity->getAccountName(),
      'mail' => $user_entity->getEmail(),
    ];

    // 获取当前角色
    $current_role = $this->userTenantMapping->getUserRole($user_id, $tenant_id);
    $is_owner = $this->userTenantMapping->isUserTenantOwner($user_id, $tenant_id);

    $form['edit_member'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('编辑成员角色'),
    ];

    $form['edit_member']['user_info'] = [
      '#markup' => '<p><strong>' . $this->t('用户: @name (@email)', [
        '@name' => $user['name'],
        '@email' => $user['mail'],
      ]) . '</strong></p>',
    ];

    $form['edit_member']['user_id'] = [
      '#type' => 'hidden',
      '#value' => $user_id,
    ];

    if ($is_owner) {
      $form['edit_member']['role_info'] = [
        '#markup' => '<p>' . $this->t('此用户是租户所有者，角色不能修改。') . '</p>',
      ];
    } else {
      $form['edit_member']['role'] = [
        '#type' => 'select',
        '#title' => $this->t('角色'),
        '#options' => $this->getRoleOptions(),
        '#default_value' => $current_role,
        '#required' => TRUE,
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
    ];

    if (!$is_owner) {
      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('保存修改'),
        '#button_type' => 'primary',
      ];
    }

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('取消'),
      '#url' => Url::fromRoute('baas_tenant.members', ['tenant_id' => $tenant_id]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * 构建移除成员表单.
   */
  protected function buildRemoveForm(array $form, FormStateInterface $form_state, $tenant_id, $user_id)
  {
    // 获取用户信息
    $user = $this->database->select('users_field_data', 'u')
      ->fields('u', ['uid', 'name', 'mail'])
      ->condition('uid', $user_id)
      ->condition('status', 1)
      ->execute()
      ->fetchAssoc();

    if (!$user) {
      $this->messenger->addError($this->t('用户不存在'));
      return $this->redirect('baas_tenant.members', ['tenant_id' => $tenant_id]);
    }

    // 检查是否为所有者
    $is_owner = $this->userTenantMapping->isUserTenantOwner($user_id, $tenant_id);
    if ($is_owner) {
      $this->messenger->addError($this->t('不能移除租户所有者'));
      return $this->redirect('baas_tenant.members', ['tenant_id' => $tenant_id]);
    }

    $form['remove_member'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('移除成员'),
    ];

    $form['remove_member']['confirmation'] = [
      '#markup' => '<p>' . $this->t('确定要将用户 @name (@email) 从此租户中移除吗？此操作不可撤销。', [
        '@name' => $user['name'],
        '@email' => $user['mail'],
      ]) . '</p>',
    ];

    $form['remove_member']['user_id'] = [
      '#type' => 'hidden',
      '#value' => $user_id,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('确认移除'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('取消'),
      '#url' => Url::fromRoute('baas_tenant.members', ['tenant_id' => $tenant_id]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    $tenant_id = $form_state->getValue('tenant_id');
    $request = $this->requestStack->getCurrentRequest();
    $action = $request->query->get('action');
    $user_id = $request->query->get('user_id');

    if ($action === 'remove' || $user_id) {
      // 编辑或移除操作，不需要额外验证
      return;
    }

    // 添加成员验证
    $username_or_email = $form_state->getValue(['add_member', 'username']);

    // 查找用户
    $query = $this->database->select('users_field_data', 'u')
      ->fields('u', ['uid', 'name', 'mail'])
      ->condition('status', 1);

    $query->condition(
      $query->orConditionGroup()
        ->condition('name', $username_or_email)
        ->condition('mail', $username_or_email)
    );

    $user = $query->execute()->fetchAssoc();

    if (!$user) {
      $form_state->setErrorByName('username', $this->t('找不到用户名或邮箱为 "@input" 的用户', [
        '@input' => $username_or_email,
      ]));
      return;
    }

    // 检查用户是否已经是租户成员
    if ($this->userTenantMapping->isUserInTenant($user['uid'], $tenant_id)) {
      $form_state->setErrorByName('username', $this->t('用户 @name 已经是此租户的成员', [
        '@name' => $user['name'],
      ]));
      return;
    }

    // 存储找到的用户ID用于提交处理
    $form_state->setValue('found_user_id', $user['uid']);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $tenant_id = $form_state->getValue('tenant_id');
    $request = $this->requestStack->getCurrentRequest();
    $action = $request->query->get('action');
    $user_id = $request->query->get('user_id');

    try {
      if ($action === 'remove' && $user_id) {
        // 移除成员
        $result = $this->userTenantMapping->removeUserFromTenant($user_id, $tenant_id);
        if ($result) {
          $this->messenger->addStatus($this->t('成员已成功移除'));
        } else {
          $this->messenger->addError($this->t('移除成员失败'));
        }
      } elseif ($user_id) {
        // 编辑角色
        $new_role = $form_state->getValue(['edit_member', 'role']);
        $result = $this->userTenantMapping->updateUserRole($user_id, $tenant_id, $new_role);
        if ($result) {
          $this->messenger->addStatus($this->t('成员角色已更新'));
        } else {
          $this->messenger->addError($this->t('更新成员角色失败'));
        }
      } else {
        // 添加成员
        $found_user_id = $form_state->getValue('found_user_id');
        $role = $form_state->getValue(['add_member', 'role']);

        $result = $this->userTenantMapping->addUserToTenant($found_user_id, $tenant_id, $role, FALSE);
        if ($result) {
          $this->messenger->addStatus($this->t('成员已成功添加'));
        } else {
          $this->messenger->addError($this->t('添加成员失败'));
        }
      }
    } catch (\Exception $e) {
      $this->messenger->addError($this->t('操作失败: @message', ['@message' => $e->getMessage()]));
    }

    // 重定向到成员列表页面
    $form_state->setRedirect('baas_tenant.members', ['tenant_id' => $tenant_id]);
  }

  /**
   * 获取角色选项.
   *
   * @return array
   *   角色选项数组.
   */
  protected function getRoleOptions()
  {
    return [
      'tenant_user' => $this->t('用户'),
      'tenant_manager' => $this->t('管理者'),
      'tenant_admin' => $this->t('管理员'),
    ];
  }
}
