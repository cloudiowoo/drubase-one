<?php

declare(strict_types=1);

namespace Drupal\baas_tenant\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_tenant\TenantManagerInterface;
use Drupal\baas_auth\Service\ApiKeyManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * 用户创建API密钥表单。
 */
class UserApiKeyCreateForm extends FormBase
{

  /**
   * 租户管理服务。
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected $tenantManager;

  /**
   * API密钥管理服务。
   *
   * @var \Drupal\baas_auth\Service\ApiKeyManagerInterface
   */
  protected $apiKeyManager;

  /**
   * 配置工厂。
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * 构造函数。
   */
  public function __construct(
    TenantManagerInterface $tenant_manager,
    ApiKeyManagerInterface $api_key_manager,
    ConfigFactoryInterface $config_factory
  ) {
    $this->tenantManager = $tenant_manager;
    $this->apiKeyManager = $api_key_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_tenant.manager'),
      $container->get('baas_auth.api_key_manager'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'baas_tenant_user_api_key_create_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $current_user = $this->currentUser();
    $user_id = (int) $current_user->id();
    
    // 获取用户的租户信息
    $user_tenant = $this->getUserTenant($user_id);
    if (!$user_tenant) {
      $this->messenger()->addError($this->t('您还没有租户权限，无法创建API密钥。'));
      return $this->redirect('baas_project.user_list');
    }

    // 页面标题和描述
    $form['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form-header']],
    ];

    $form['header']['title'] = [
      '#markup' => '<h2>' . $this->t('创建新API密钥') . '</h2>',
    ];

    $form['header']['description'] = [
      '#markup' => '<p class="form-description">' . 
        $this->t('API密钥是用于访问BaaS平台API服务的认证凭证。请为您的API密钥指定一个容易识别的名称和适当的权限。') . 
        '</p>',
    ];

    // 租户信息
    $form['tenant_info'] = [
      '#type' => 'item',
      '#title' => $this->t('租户'),
      '#markup' => $this->t('@name (@id)', [
        '@name' => $user_tenant['name'],
        '@id' => $user_tenant['tenant_id'],
      ]),
    ];

    // 基本信息
    $form['basic_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('基本信息'),
      '#tree' => FALSE,
    ];

    $form['basic_info']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('密钥名称'),
      '#description' => $this->t('为您的API密钥指定一个容易识别的名称，例如"移动应用密钥"、"测试环境密钥"等。'),
      '#required' => TRUE,
      '#maxlength' => 255,
      '#default_value' => $this->t('API密钥 @date', ['@date' => date('Y-m-d')]),
    ];

    $form['basic_info']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('描述（可选）'),
      '#description' => $this->t('描述这个API密钥的用途，例如用于哪个应用程序或服务。'),
      '#rows' => 3,
    ];

    // 权限设置
    $form['permissions'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('权限设置'),
      '#description' => $this->t('选择此API密钥可以执行的操作。为了安全，建议只授予必要的权限。'),
      '#tree' => FALSE,
    ];

    $form['permissions']['api_permissions'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('API权限'),
      '#options' => [
        'read' => $this->t('读取权限 - 查看项目、实体和数据'),
        'write' => $this->t('写入权限 - 创建和更新数据'),
        'delete' => $this->t('删除权限 - 删除实体数据'),
        'manage' => $this->t('管理权限 - 管理项目和实体结构'),
      ],
      '#default_value' => ['read'],
      '#required' => TRUE,
    ];

    // 安全设置
    $form['security'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('安全设置'),
      '#tree' => FALSE,
    ];

    // 从JWT配置获取默认API密钥过期时间
    $jwt_config = $this->configFactory->get('baas_auth.jwt');
    $settings_config = $this->configFactory->get('baas_auth.settings');
    $default_ttl = $jwt_config->get('api_key_default_ttl') ?? $settings_config->get('jwt.api_key_default_ttl') ?? 2592000; // 默认30天

    $now = time();
    $default_expires_at = $default_ttl > 0 ? ($now + $default_ttl) : '';

    $form['security']['expires_at'] = [
      '#type' => 'select',
      '#title' => $this->t('过期时间'),
      '#description' => $this->t('设置API密钥的过期时间。过期后需要重新创建密钥。默认时长由系统管理员配置。'),
      '#options' => [
        '' => $this->t('永不过期'),
        $now + 86400 => $this->t('1天后过期'),
        $now + 604800 => $this->t('7天后过期'),
        $now + 2592000 => $this->t('30天后过期'),
        $now + 7776000 => $this->t('90天后过期'),
        $now + 31536000 => $this->t('1年后过期'),
      ],
      '#default_value' => $default_expires_at,
    ];

    // 安全提醒
    $form['security']['warning'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['alert', 'alert-warning']],
    ];

    $form['security']['warning']['message'] = [
      '#markup' => '<strong>' . $this->t('安全提醒：') . '</strong><br>' .
        $this->t('• API密钥创建后将只显示一次，请立即复制并妥善保存<br>') .
        $this->t('• 不要在客户端代码中硬编码API密钥<br>') .
        $this->t('• 如果密钥泄露，请立即删除并重新创建'),
    ];

    // 操作按钮
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('创建API密钥'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('取消'),
      '#url' => Url::fromRoute('baas_tenant.user_api_keys'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $name = trim($form_state->getValue('name'));
    if (empty($name)) {
      $form_state->setErrorByName('name', $this->t('密钥名称不能为空。'));
    }

    $permissions = array_filter($form_state->getValue('api_permissions'));
    if (empty($permissions)) {
      $form_state->setErrorByName('api_permissions', $this->t('请至少选择一个权限。'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $current_user = $this->currentUser();
    $user_id = (int) $current_user->id();
    
    // 获取用户的租户信息
    $user_tenant = $this->getUserTenant($user_id);
    if (!$user_tenant) {
      $this->messenger()->addError($this->t('无法获取租户信息。'));
      return;
    }

    $tenant_id = $user_tenant['tenant_id'];
    $name = trim($form_state->getValue('name'));
    $description = trim($form_state->getValue('description'));
    $permissions = array_keys(array_filter($form_state->getValue('api_permissions')));
    $expires_at = $form_state->getValue('expires_at');

    // 将描述信息包含在名称中（如果有）
    if (!empty($description)) {
      $name .= ' - ' . $description;
    }

    try {
      $api_key_data = $this->apiKeyManager->createApiKey(
        $tenant_id,
        $user_id,
        $name,
        $permissions,
        $expires_at ?: NULL
      );

      if ($api_key_data) {
        // 显示成功创建的密钥（只显示一次）
        $this->messenger()->addStatus($this->t('API密钥创建成功！'));
        
        // 存储新创建的密钥到会话中，以便在下一页显示
        $session = \Drupal::request()->getSession();
        $session->set('new_api_key', [
          'name' => $api_key_data['name'],
          'key' => $api_key_data['api_key'],
          'created' => date('Y-m-d H:i:s'),
        ]);

        $form_state->setRedirect('baas_tenant.user_api_key_success');
      } else {
        $this->messenger()->addError($this->t('创建API密钥失败，请重试。'));
      }
    } catch (\Exception $e) {
      $this->getLogger('baas_tenant')->error('创建API密钥失败: @error', ['@error' => $e->getMessage()]);
      $this->messenger()->addError($this->t('创建API密钥时发生错误，请重试。'));
    }
  }

  /**
   * 获取用户的租户信息。
   */
  protected function getUserTenant(int $user_id): ?array
  {
    try {
      // 从baas_tenant模块的函数获取用户租户信息
      if (function_exists('baas_tenant_get_user_tenant')) {
        return baas_tenant_get_user_tenant($user_id);
      }

      // 如果函数不存在，尝试直接查询
      $database = \Drupal::database();
      $query = $database->select('baas_tenant_config', 't')
        ->fields('t')
        ->condition('owner_uid', $user_id)
        ->condition('status', 1)
        ->orderBy('created', 'DESC')
        ->range(0, 1);

      $result = $query->execute()->fetchAssoc();
      return $result ?: NULL;
    } catch (\Exception $e) {
      $this->getLogger('baas_tenant')->error('获取用户租户信息失败: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }
}