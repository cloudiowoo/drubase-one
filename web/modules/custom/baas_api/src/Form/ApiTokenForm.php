<?php

namespace Drupal\baas_api\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_api\Service\ApiTokenManager;
use Drupal\baas_tenant\TenantManager;

/**
 * 提供API令牌管理表单。
 */
class ApiTokenForm extends FormBase {

  /**
   * API令牌管理器。
   *
   * @var \Drupal\baas_api\Service\ApiTokenManager
   */
  protected $tokenManager;

  /**
   * 租户管理器。
   *
   * @var \Drupal\baas_tenant\TenantManager
   */
  protected $tenantManager;

  /**
   * 日期格式器。
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_api\Service\ApiTokenManager $token_manager
   *   API令牌管理器。
   * @param \Drupal\baas_tenant\TenantManager $tenant_manager
   *   租户管理器。
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   日期格式器。
   */
  public function __construct(
    ApiTokenManager $token_manager,
    TenantManager $tenant_manager,
    DateFormatterInterface $date_formatter
  ) {
    $this->tokenManager = $token_manager;
    $this->tenantManager = $tenant_manager;
    $this->dateFormatter = $date_formatter;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('baas_api.token_manager'),
      $container->get('baas_tenant.manager'),
      $container->get('date.formatter')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'baas_api_token_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $tenant_id = $this->getRequest()->query->get('tenant_id');

    if (empty($tenant_id)) {
      // 没有指定租户ID，展示租户选择列表
      $tenants = $this->tenantManager->listTenants();
      if (empty($tenants)) {
        return [
          '#markup' => $this->t('未找到租户，请先创建租户。'),
        ];
      }

      $tenant_options = [];
      foreach ($tenants as $tenant) {
        $tenant_options[$tenant['tenant_id']] = $tenant['name'] . ' (' . $tenant['tenant_id'] . ')';
      }

      $form['tenant_select'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('选择租户'),
      ];

      $form['tenant_select']['tenant_id'] = [
        '#type' => 'select',
        '#title' => $this->t('租户'),
        '#options' => $tenant_options,
        '#required' => TRUE,
      ];

      $form['tenant_select']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('查看令牌'),
        '#submit' => ['::submitTenantSelect'],
      ];

      return $form;
    }

    // 显示令牌列表和添加表单
    $tenant = $this->tenantManager->getTenant($tenant_id);

    if (!$tenant) {
      $this->messenger()->addError($this->t('无效的租户ID'));
      return $this->redirect('baas_api.tokens');
    }

    $form['tenant_info'] = [
      '#markup' => '<h2>' . $this->t('租户: @name (@id)', [
        '@name' => $tenant['name'],
        '@id' => $tenant['tenant_id'],
      ]) . '</h2>',
    ];

    $form['token_creation'] = [
      '#type' => 'details',
      '#title' => $this->t('创建新令牌'),
      '#open' => TRUE,
    ];

    $form['token_creation']['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('令牌名称'),
      '#description' => $this->t('为令牌指定一个描述性名称'),
      '#required' => TRUE,
    ];

    // 定义API作用域选项
    $scope_options = [
      '*' => $this->t('所有权限（全访问）'),
      'read' => $this->t('读取权限（GET请求）'),
      'write' => $this->t('写入权限（POST, PUT, PATCH请求）'),
      'delete' => $this->t('删除权限（DELETE请求）'),
    ];

    $form['token_creation']['scopes'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('令牌作用域'),
      '#description' => $this->t('选择此令牌可以执行的操作'),
      '#options' => $scope_options,
      '#default_value' => ['*'],
      '#required' => TRUE,
    ];

    $form['token_creation']['expiration'] = [
      '#type' => 'select',
      '#title' => $this->t('过期时间'),
      '#description' => $this->t('选择令牌的有效期'),
      '#options' => [
        '0' => $this->t('永不过期'),
        '86400' => $this->t('1天'),
        '604800' => $this->t('1周'),
        '2592000' => $this->t('1个月'),
        '7776000' => $this->t('3个月'),
        '15552000' => $this->t('6个月'),
        '31536000' => $this->t('1年'),
      ],
      '#default_value' => '2592000',
    ];

    $form['token_creation']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('生成令牌'),
      '#submit' => ['::submitGenerateToken'],
    ];

    // 显示现有令牌
    $tokens = $this->tokenManager->getTokens($tenant_id);

    $form['existing_tokens'] = [
      '#type' => 'details',
      '#title' => $this->t('现有令牌 (@count)', ['@count' => count($tokens)]),
      '#open' => TRUE,
    ];

    if (empty($tokens)) {
      $form['existing_tokens']['empty'] = [
        '#markup' => $this->t('没有找到令牌'),
      ];
    }
    else {
      $header = [
        'name' => $this->t('名称'),
        'scopes' => $this->t('作用域'),
        'created' => $this->t('创建时间'),
        'expires' => $this->t('过期时间'),
        'last_used' => $this->t('最后使用'),
        'status' => $this->t('状态'),
        'operations' => $this->t('操作'),
      ];

      $rows = [];
      foreach ($tokens as $token) {
        $row = [];
        $row['name'] = $token['name'];

        $scopes = json_decode($token['scopes'], TRUE);
        $row['scopes'] = implode(', ', $scopes);

        $row['created'] = $this->dateFormatter->format($token['created'], 'short');

        if ($token['expires'] > 0) {
          $row['expires'] = $this->dateFormatter->format($token['expires'], 'short');
          if ($token['expires'] < time()) {
            $row['expires'] .= ' (' . $this->t('已过期') . ')';
          }
        }
        else {
          $row['expires'] = $this->t('永不过期');
        }

        if ($token['last_used'] > 0) {
          $row['last_used'] = $this->dateFormatter->format($token['last_used'], 'short');
        }
        else {
          $row['last_used'] = $this->t('从未使用');
        }

        $row['status'] = $token['status'] ? $this->t('活跃') : $this->t('已撤销');

        $operations = [];
        if ($token['status']) {
          $operations[] = [
            'title' => $this->t('撤销'),
            'url' => $this->buildRevocationUrl($tenant_id, $token['token_hash']),
          ];
        }

        $row['operations'] = [
          'data' => [
            '#type' => 'operations',
            '#links' => $operations,
          ],
        ];

        $rows[] = $row;
      }

      $form['existing_tokens']['table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('没有找到令牌'),
      ];
    }

    $form['back'] = [
      '#type' => 'link',
      '#title' => $this->t('返回租户列表'),
      '#url' => $this->buildBackUrl(),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * 提交租户选择表单。
   */
  public function submitTenantSelect(array &$form, FormStateInterface $form_state) {
    $tenant_id = $form_state->getValue('tenant_id');
    $form_state->setRedirect('baas_api.tokens', [
      'tenant_id' => $tenant_id,
    ]);
  }

  /**
   * 提交生成令牌表单。
   */
  public function submitGenerateToken(array &$form, FormStateInterface $form_state) {
    $tenant_id = $this->getRequest()->query->get('tenant_id');
    $name = $form_state->getValue('name');
    $expiration = (int) $form_state->getValue('expiration');
    $scopes = array_filter($form_state->getValue('scopes'));
    $scopes = array_keys($scopes);

    // 如果选择了所有权限，只使用通配符
    if (in_array('*', $scopes)) {
      $scopes = ['*'];
    }

    // 计算过期时间戳
    $expires = 0;
    if ($expiration > 0) {
      $expires = time() + $expiration;
    }

    // 生成令牌
    $token_data = $this->tokenManager->generateToken($tenant_id, $scopes, $expires, $name);

    if ($token_data) {
      $this->messenger()->addStatus($this->t('成功创建API令牌'));

      // 显示令牌值（仅显示一次）
      $message = $this->t('令牌值（请立即复制，它不会再次显示）: @token', [
        '@token' => $token_data['token'],
      ]);
      $this->messenger()->addWarning($message);
    }
    else {
      $this->messenger()->addError($this->t('创建API令牌时出错'));
    }

    // 重定向回令牌列表
    $form_state->setRedirect('baas_api.tokens', [
      'tenant_id' => $tenant_id,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // 主要表单处理逻辑已分离到submitTenantSelect和submitGenerateToken
  }

  /**
   * 构建返回URL。
   *
   * @return \Drupal\Core\Url
   *   返回URL。
   */
  protected function buildBackUrl() {
    return \Drupal\Core\Url::fromRoute('baas_api.tokens');
  }

  /**
   * 构建撤销URL。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $token_hash
   *   令牌哈希。
   *
   * @return \Drupal\Core\Url
   *   撤销URL。
   */
  protected function buildRevocationUrl($tenant_id, $token_hash) {
    return \Drupal\Core\Url::fromRoute('baas_api.token_revoke', [
      'tenant_id' => $tenant_id,
      'token_hash' => $token_hash,
    ]);
  }
}
