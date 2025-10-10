<?php

namespace Drupal\baas_tenant\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_tenant\TenantManagerInterface;
use Drupal\baas_auth\Service\ApiKeyManagerInterface;

/**
 * 租户API密钥管理表单.
 */
class TenantApiKeyForm extends FormBase {

  /**
   * 租户管理服务.
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected $tenantManager;

  /**
   * API密钥管理服务.
   *
   * @var \Drupal\baas_auth\Service\ApiKeyManagerInterface
   */
  protected $apiKeyManager;

  /**
   * 当前租户ID.
   *
   * @var string
   */
  protected $tenantId;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('baas_tenant.manager'),
      $container->get('baas_auth.api_key_manager')
    );
  }

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理服务.
   * @param \Drupal\baas_auth\Service\ApiKeyManagerInterface $api_key_manager
   *   API密钥管理服务.
   */
  public function __construct(TenantManagerInterface $tenant_manager, ApiKeyManagerInterface $api_key_manager) {
    $this->tenantManager = $tenant_manager;
    $this->apiKeyManager = $api_key_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'baas_tenant_api_key_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $tenant_id = NULL) {
    $this->tenantId = $tenant_id;
    $tenant = NULL;

    if ($tenant_id) {
      $tenant = $this->tenantManager->getTenant($tenant_id);
      if (!$tenant) {
        $this->messenger()->addError($this->t('租户不存在.'));
        return $this->redirect('baas_tenant.list');
      }
    } else {
      $this->messenger()->addError($this->t('未指定租户ID.'));
      return $this->redirect('baas_tenant.list');
    }

    // 处理删除操作
    $request = \Drupal::request();
    if ($delete_id = $request->query->get('delete')) {
      $result = $this->apiKeyManager->deleteApiKey((int) $delete_id);
      if ($result) {
        $this->messenger()->addStatus($this->t('API密钥已删除.'));
      } else {
        $this->messenger()->addError($this->t('删除API密钥失败.'));
      }
      // 重定向到同一页面以刷新列表
      return $this->redirect('baas_tenant.api_key', ['tenant_id' => $tenant_id]);
    }

    // 显示租户信息
    $form['tenant_info'] = [
      '#type' => 'item',
      '#title' => $this->t('租户信息'),
      '#markup' => $this->t('租户ID: @id, 名称: @name', [
        '@id' => $tenant['tenant_id'],
        '@name' => $tenant['name'],
      ]),
    ];

    // 获取租户的所有API密钥
    $api_keys = $this->apiKeyManager->listApiKeys($tenant_id);

    if (!empty($api_keys)) {
      $form['api_keys'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('名称'),
          $this->t('API密钥'),
          $this->t('权限'),
          $this->t('状态'),
          $this->t('创建时间'),
          $this->t('操作'),
        ],
        '#empty' => $this->t('暂无API密钥'),
      ];

      foreach ($api_keys as $key) {
        $form['api_keys'][$key['id']] = [
          'name' => ['#markup' => $key['name']],
          'api_key' => ['#markup' => '<code>' . substr($key['api_key'], 0, 20) . '...</code>'],
          'permissions' => ['#markup' => implode(', ', $key['permissions'])],
          'status' => ['#markup' => $key['status'] ? $this->t('启用') : $this->t('禁用')],
          'created' => ['#markup' => date('Y-m-d H:i:s', $key['created'])],
          'operations' => [
            '#type' => 'operations',
            '#links' => [
              'delete' => [
                'title' => $this->t('删除'),
                'url' => Url::fromRoute('baas_tenant.api_key', ['tenant_id' => $tenant_id]),
                'query' => ['delete' => $key['id']],
                'attributes' => [
                  'onclick' => 'return confirm("' . $this->t('确定要删除这个API密钥吗？') . '");',
                ],
              ],
            ],
          ],
        ];
      }
    }

    // 创建新API密钥的表单
    $form['new_key'] = [
      '#type' => 'details',
      '#title' => $this->t('创建新API密钥'),
      '#open' => empty($api_keys),
    ];

    $form['new_key']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('密钥名称'),
      '#description' => $this->t('为API密钥指定一个容易识别的名称'),
      '#required' => FALSE,
    ];

    $form['new_key']['permissions'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('权限'),
      '#options' => [
        'read' => $this->t('读取'),
        'write' => $this->t('写入'),
        'delete' => $this->t('删除'),
      ],
      '#default_value' => ['read'],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['create'] = [
      '#type' => 'submit',
      '#value' => $this->t('创建API密钥'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('返回'),
      '#url' => Url::fromRoute('baas_tenant.view', ['tenant_id' => $tenant_id]),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // 创建新API密钥
    $name = $form_state->getValue('name') ?: 'API密钥 ' . date('Y-m-d H:i:s');
    $permissions = array_filter($form_state->getValue('permissions'));
    
    // 需要租户所有者的用户ID
    $tenant = $this->tenantManager->getTenant($this->tenantId);
    $owner_uid = $tenant['owner_uid'] ?? 1; // 默认为用户1

    $api_key_data = $this->apiKeyManager->createApiKey(
      $this->tenantId,
      $owner_uid,
      $name,
      array_keys($permissions),
      null // 永不过期
    );

    if ($api_key_data) {
      $this->messenger()->addStatus($this->t('已创建新的API密钥: @key', ['@key' => $api_key_data['api_key']]));
    } else {
      $this->messenger()->addError($this->t('创建API密钥失败.'));
    }

    $form_state->setRedirect('baas_tenant.api_key', ['tenant_id' => $this->tenantId]);
  }


}
