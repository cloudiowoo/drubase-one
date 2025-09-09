<?php

declare(strict_types=1);

namespace Drupal\baas_api\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\baas_tenant\TenantManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * API统计过滤表单.
 */
class ApiStatsFilterForm extends FormBase {

  /**
   * 租户管理器.
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected TenantManagerInterface $tenantManager;

  /**
   * 请求栈.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理器.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   请求栈.
   */
  public function __construct(
    TenantManagerInterface $tenant_manager,
    RequestStack $request_stack
  ) {
    $this->tenantManager = $tenant_manager;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('baas_tenant.manager'),
      $container->get('request_stack')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'baas_api_stats_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $request = $this->requestStack->getCurrentRequest();

    // 日期范围
    $form['date_range'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('日期范围'),
      '#collapsible' => FALSE,
    ];

    $form['date_range']['start_date'] = [
      '#type' => 'date',
      '#title' => $this->t('开始日期'),
      '#default_value' => date('Y-m-d', (int) $request->query->get('start_date', strtotime('-30 days'))),
    ];

    $form['date_range']['end_date'] = [
      '#type' => 'date',
      '#title' => $this->t('结束日期'),
      '#default_value' => date('Y-m-d', (int) $request->query->get('end_date', time())),
    ];

    // 获取租户列表
    $tenants = [];
    $tenant_list = $this->tenantManager->listTenants();
    foreach ($tenant_list as $tenant) {
      if (isset($tenant['tenant_id']) && isset($tenant['name'])) {
        $tenants[$tenant['tenant_id']] = $tenant['name'];
      }
    }

    // 租户过滤
    $form['tenant_id'] = [
      '#type' => 'select',
      '#title' => $this->t('租户'),
      '#options' => ['' => $this->t('- 所有租户 -')] + $tenants,
      '#default_value' => $request->query->get('tenant_id', ''),
    ];

    // HTTP方法过滤
    $form['method'] = [
      '#type' => 'select',
      '#title' => $this->t('HTTP方法'),
      '#options' => [
        '' => $this->t('- 所有方法 -'),
        'GET' => 'GET',
        'POST' => 'POST',
        'PUT' => 'PUT',
        'PATCH' => 'PATCH',
        'DELETE' => 'DELETE',
      ],
      '#default_value' => $request->query->get('method', ''),
    ];

    // 端点过滤
    $form['endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API端点'),
      '#default_value' => $request->query->get('endpoint', ''),
      '#placeholder' => '/api/v1/...',
    ];

    // 状态码过滤
    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('状态码'),
      '#options' => [
        '' => $this->t('- 所有状态码 -'),
        '200' => '200 - OK',
        '201' => '201 - Created',
        '204' => '204 - No Content',
        '400' => '400 - Bad Request',
        '401' => '401 - Unauthorized',
        '403' => '403 - Forbidden',
        '404' => '404 - Not Found',
        '500' => '500 - Server Error',
      ],
      '#default_value' => $request->query->get('status', ''),
    ];

    // 操作按钮
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('应用过滤'),
    ];

    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('重置'),
      '#url' => Url::fromRoute('baas_api.stats'),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // 将表单值转换为查询参数
    $query = [];

    // 日期范围
    $start_date = $form_state->getValue('start_date');
    if ($start_date) {
      $query['start_date'] = strtotime($start_date);
    }

    $end_date = $form_state->getValue('end_date');
    if ($end_date) {
      $query['end_date'] = strtotime($end_date) + 86399; // 结束日期的最后一秒
    }

    // 其他过滤条件
    $filters = ['tenant_id', 'method', 'endpoint', 'status'];
    foreach ($filters as $filter) {
      $value = $form_state->getValue($filter);
      if ($value !== '') {
        $query[$filter] = $value;
      }
    }

    // 重定向到带有查询参数的统计页面
    $form_state->setRedirect('baas_api.stats', [], ['query' => $query]);
  }

}
