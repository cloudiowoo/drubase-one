<?php

namespace Drupal\baas_tenant\Form;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_tenant\TenantManagerInterface;

/**
 * 租户编辑表单.
 */
class TenantForm extends FormBase
{

  /**
   * 租户管理服务.
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected $tenantManager;

  /**
   * 当前租户ID.
   *
   * @var string
   */
  protected $tenantId;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('baas_tenant.manager')
    );
  }

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理服务.
   */
  public function __construct(TenantManagerInterface $tenant_manager)
  {
    $this->tenantManager = $tenant_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId()
  {
    return 'baas_tenant_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $tenant_id = NULL)
  {
    // 如果提供了租户ID，则加载现有租户数据.
    if ($tenant_id) {
      $tenant = $this->tenantManager->getTenant($tenant_id);
      if (!$tenant) {
        $this->messenger()->addError($this->t('租户不存在'));
        throw new NotFoundHttpException('租户不存在');
      }
      $form_state->set('tenant_id', $tenant_id);
      $form_state->set('tenant', $tenant);
    }

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('租户名称'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $tenant['name'] ?? '',
      '#description' => $this->t('企业或组织的正式名称'),
    ];

    // 显示当前所有者信息（只读）.
    if ($tenant_id) {
      $current_owner_uid = $tenant['owner_uid'] ?? 1;
      $current_owner = \Drupal::entityTypeManager()->getStorage('user')->load($current_owner_uid);
      $form['owner_info'] = [
        '#type' => 'item',
        '#title' => $this->t('租户所有者'),
        '#markup' => $current_owner ? $current_owner->name->value . ' (' . $current_owner->mail->value . ')' : $this->t('未知用户'),
        '#description' => $this->t('租户所有者通过用户提权系统自动分配，不可手动修改。'),
      ];
    } else {
      // 创建新租户时的说明.
      $form['owner_note'] = [
        '#type' => 'item',
        '#title' => $this->t('租户所有者'),
        '#markup' => '<div class="messages messages--warning">' . $this->t('租户所有者将自动设置为当前管理员用户。如需为其他用户创建租户，请使用用户提权功能。') . '</div>',
      ];
    }

    // 企业信息字段.
    $form['enterprise'] = [
      '#type' => 'details',
      '#title' => $this->t('企业信息'),
      '#open' => TRUE,
    ];

    $form['enterprise']['organization_type'] = [
      '#type' => 'select',
      '#title' => $this->t('组织类型'),
      '#options' => [
        'company' => $this->t('公司'),
        'department' => $this->t('部门'),
        'team' => $this->t('团队'),
        'project' => $this->t('项目组'),
        'other' => $this->t('其他'),
      ],
      '#default_value' => $tenant['organization_type'] ?? 'company',
      '#required' => TRUE,
    ];

    $form['enterprise']['contact_email'] = [
      '#type' => 'email',
      '#title' => $this->t('联系邮箱'),
      '#description' => $this->t('租户的官方联系邮箱，如不填写将使用所有者邮箱'),
      '#default_value' => $tenant['contact_email'] ?? '',
    ];

    if ($tenant_id) {
      $form['tenant_id'] = [
        '#type' => 'textfield',
        '#title' => $this->t('租户ID'),
        '#disabled' => TRUE,
        '#default_value' => $tenant_id,
      ];
    }

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用'),
      '#default_value' => $tenant['status'] ?? 1,
    ];

    // 获取当前设置.
    $settings = $tenant['settings'] ?? [];

    // 注释：资源限制功能已移除，统一使用系统级API限流配置
    // 系统级限流配置位于: /admin/config/baas/api/settings

    // 添加域名配置部分.
    $form['domains'] = [
      '#type' => 'details',
      '#title' => $this->t('域名配置'),
      '#open' => TRUE,
      '#description' => $this->t('配置租户的域名访问方式，包括专属域名和子域名'),
    ];

    $form['domains']['domain'] = [
      '#type' => 'textfield',
      '#title' => $this->t('专属域名'),
      '#description' => $this->t('租户的专属域名，例如：tenant-name.com'),
      '#default_value' => $settings['domain'] ?? '',
    ];

    $form['domains']['wildcard_domain'] = [
      '#type' => 'textfield',
      '#title' => $this->t('通配符域名'),
      '#description' => $this->t('支持子域名的主域名，例如：example.com将允许tenant.example.com访问'),
      '#default_value' => $settings['wildcard_domain'] ?? '',
    ];

    $default_subdomains = '';
    if (!empty($settings['allowed_subdomains']) && is_array($settings['allowed_subdomains'])) {
      $default_subdomains = implode("\n", $settings['allowed_subdomains']);
    }

    $form['domains']['allowed_subdomains'] = [
      '#type' => 'textarea',
      '#title' => $this->t('允许的子域名'),
      '#description' => $this->t('每行一个子域名，不包含主域名部分。例如：tenant1, tenant2'),
      '#default_value' => $default_subdomains,
      '#states' => [
        'visible' => [
          ':input[name="wildcard_domain"]' => ['filled' => TRUE],
        ],
      ],
    ];

    $form['domains']['note'] = [
      '#markup' => '<div class="messages messages--warning">' . $this->t('注意：域名配置需要相应的DNS设置才能生效。') . '</div>',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $tenant_id ? $this->t('更新租户') : $this->t('创建租户'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state)
  {
    // 验证租户名称长度.
    $name = $form_state->getValue('name');
    if (strlen($name) > 255) {
      $form_state->setErrorByName('name', $this->t('租户名称不能超过255个字符。'));
    }

    // 租户所有者验证已移除，将自动设置为当前管理员用户.
    // 验证联系邮箱格式（如果提供）.
    $contact_email = $form_state->getValue(['enterprise', 'contact_email']);
    if (!empty($contact_email) && !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
      $form_state->setErrorByName('enterprise][contact_email', $this->t('请输入有效的邮箱地址。'));
    }

    // 检查邮箱是否已被其他租户使用.
    if (!empty($contact_email)) {
      $tenant_id = $form_state->get('tenant_id');
      $existing_tenant = $this->checkEmailUsage($contact_email, $tenant_id);
      if ($existing_tenant) {
        $form_state->setErrorByName('enterprise][contact_email', $this->t('此邮箱已被租户 "@tenant" 使用。', ['@tenant' => $existing_tenant]));
      }
    }
  }

  /**
   * 检查邮箱是否被其他租户使用.
   *
   * @param string $email
   *   要检查的邮箱.
   * @param string|null $exclude_tenant_id
   *   要排除的租户ID（用于编辑时排除自己）.
   *
   * @return string|null
   *   如果被使用，返回使用该邮箱的租户名称，否则返回NULL.
   */
  protected function checkEmailUsage(string $email, ?string $exclude_tenant_id = NULL): ?string
  {
    $database = \Drupal::database();

    $query = $database->select('baas_tenant_config', 't')
      ->fields('t', ['name'])
      ->condition('contact_email', $email);

    if ($exclude_tenant_id) {
      $query->condition('tenant_id', $exclude_tenant_id, '!=');
    }

    $existing_name = $query->execute()->fetchField();

    return $existing_name ? (string) $existing_name : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $values = $form_state->getValues();
    $tenant_id = $form_state->get('tenant_id');

    // 记录调试信息（简化版本）.
    \Drupal::logger('baas_tenant')->info('开始处理租户表单提交，租户名称: @name', ['@name' => $values['name'] ?? 'N/A']);

    // 准备设置数据.
    $settings = [];

    // 添加企业信息到设置.
    if (!empty($values['organization_type'])) {
      $settings['organization_type'] = $values['organization_type'];
    }

    if (!empty($values['contact_email'])) {
      $settings['contact_email'] = $values['contact_email'];
    }

    // 处理域名配置.
    if (!empty($values['domain'])) {
      $settings['domain'] = $values['domain'];
    }

    if (!empty($values['wildcard_domain'])) {
      $settings['wildcard_domain'] = $values['wildcard_domain'];

      // 处理允许的子域名.
      if (!empty($values['allowed_subdomains'])) {
        $subdomains = preg_split('/\r\n|\r|\n/', $values['allowed_subdomains']);
        $subdomains = array_map('trim', $subdomains);
        $subdomains = array_filter($subdomains);
        $settings['allowed_subdomains'] = $subdomains;
      }
    }

    if ($tenant_id) {
      // 更新现有租户.
      \Drupal::logger('baas_tenant')->info('正在更新租户 @tenant_id', ['@tenant_id' => $tenant_id]);

      $update_data = [
        'name' => $values['name'],
        'status' => (int) $values['status'],
        'settings' => $settings,
      ];

      // 所有者不可通过表单修改，由用户提权系统管理.
      $result = $this->tenantManager->updateTenant($tenant_id, $update_data);

      if ($result) {
        $this->messenger()->addStatus($this->t('租户已更新'));
      } else {
        $this->messenger()->addError($this->t('更新租户失败'));
      }
    } else {
      // 创建新租户.
      \Drupal::logger('baas_tenant')->info('正在创建新租户，名称: @name', ['@name' => $values['name']]);

      try {
        // 自动设置当前管理员用户为租户所有者.
        $current_user = \Drupal::currentUser();
        $owner_uid = (int) $current_user->id();
        $tenant_id = $this->tenantManager->createTenant($values['name'], $owner_uid, $settings);
        \Drupal::logger('baas_tenant')->info('租户创建结果: @tenant_id', ['@tenant_id' => $tenant_id ?: 'NULL']);

        if ($tenant_id) {
          $this->messenger()->addStatus($this->t('租户已创建'));

          // 设置租户状态（如果不是启用状态）.
          if (!$values['status']) {
            \Drupal::logger('baas_tenant')->info('正在设置租户状态为禁用');
            $updateResult = $this->tenantManager->updateTenant($tenant_id, ['status' => 0]);
            if (!$updateResult) {
              $this->messenger()->addWarning($this->t('租户已创建，但设置状态时出现问题'));
            }
          }
        } else {
          \Drupal::logger('baas_tenant')->error('租户创建失败：createTenant返回了空值');
          $this->messenger()->addError($this->t('创建租户失败'));
        }
      } catch (\Exception $e) {
        \Drupal::logger('baas_tenant')->error('租户创建过程中发生异常: @error', ['@error' => $e->getMessage()]);
        $this->messenger()->addError($this->t('创建租户失败：@error', ['@error' => $e->getMessage()]));
      }
    }

    // 根据用户权限决定重定向路径.
    $current_user = $this->currentUser();
    if ($current_user->hasPermission('administer baas tenants')) {
      // 管理员重定向到管理员列表页.
      $form_state->setRedirect('baas_tenant.tenant_list');
    } else {
      // 重定向到管理员列表页（用户级租户管理已移除）.
      $form_state->setRedirect('baas_tenant.admin_list');
    }
  }
}