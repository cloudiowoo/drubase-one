<?php

declare(strict_types=1);

namespace Drupal\baas_api\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * API设置表单.
 */
class ApiSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['baas_api.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'baas_api_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('baas_api.settings');

    // API文档设置
    $form['docs'] = [
      '#type' => 'details',
      '#title' => $this->t('API文档设置'),
      '#open' => TRUE,
    ];

    $form['docs']['api_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API标题'),
      '#description' => $this->t('API文档标题'),
      '#default_value' => $config->get('api_title') ?: 'BaaS Platform API',
      '#required' => TRUE,
    ];

    $form['docs']['api_description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('API描述'),
      '#description' => $this->t('API文档描述'),
      '#default_value' => $config->get('api_description') ?: '基于Drupal 11的BaaS平台API',
      '#rows' => 3,
    ];

    $form['docs']['api_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API版本'),
      '#description' => $this->t('API版本号'),
      '#default_value' => $config->get('api_version') ?: 'v1',
      '#required' => TRUE,
    ];

    $form['docs']['api_server_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API服务器URL'),
      '#description' => $this->t('API服务器的基础URL'),
      '#default_value' => $config->get('api_server_url') ?: '/api/v1',
      '#required' => TRUE,
    ];

    // 联系信息
    $form['docs']['contact_info'] = [
      '#type' => 'details',
      '#title' => $this->t('联系信息'),
      '#open' => FALSE,
    ];

    $form['docs']['contact_info']['api_contact_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('联系人姓名'),
      '#default_value' => $config->get('api_contact_name') ?: '',
    ];

    $form['docs']['contact_info']['api_contact_email'] = [
      '#type' => 'email',
      '#title' => $this->t('联系人邮箱'),
      '#default_value' => $config->get('api_contact_email') ?: '',
    ];

    $form['docs']['contact_info']['api_contact_url'] = [
      '#type' => 'url',
      '#title' => $this->t('联系URL'),
      '#default_value' => $config->get('api_contact_url') ?: '',
    ];

    // API速率限制设置
    $form['rate_limiting'] = [
      '#type' => 'details',
      '#title' => $this->t('API速率限制'),
      '#description' => $this->t('配置API请求的速率限制，防止滥用和过载。'),
      '#open' => TRUE,
    ];

    $form['rate_limiting']['enable_rate_limiting'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用API速率限制'),
      '#description' => $this->t('开启后将对API请求进行速率限制'),
      '#default_value' => $config->get('enable_rate_limiting') ?? FALSE,
    ];

    // 详细限流配置
    $form['rate_limit_details'] = [
      '#type' => 'details',
      '#title' => $this->t('限流配置'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="enable_rate_limiting"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // 用户级别限流
    $form['rate_limit_details']['user_limits'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('用户级别限流'),
    ];

    $form['rate_limit_details']['user_limits']['user_requests_per_minute'] = [
      '#type' => 'number',
      '#title' => $this->t('每分钟请求数'),
      '#default_value' => $config->get('rate_limits.user.requests') ?: 60,
      '#min' => 1,
      '#max' => 1000,
      '#description' => $this->t('每个用户每分钟允许的最大请求数'),
    ];

    $form['rate_limit_details']['user_limits']['user_burst'] = [
      '#type' => 'number',
      '#title' => $this->t('突发请求数'),
      '#default_value' => $config->get('rate_limits.user.burst') ?: 10,
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('允许的突发请求数量'),
    ];

    // IP级别限流
    $form['rate_limit_details']['ip_limits'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('IP级别限流'),
    ];

    $form['rate_limit_details']['ip_limits']['ip_requests_per_minute'] = [
      '#type' => 'number',
      '#title' => $this->t('每分钟请求数'),
      '#default_value' => $config->get('rate_limits.ip.requests') ?: 30,
      '#min' => 1,
      '#max' => 500,
      '#description' => $this->t('每个IP每分钟允许的最大请求数'),
    ];

    $form['rate_limit_details']['ip_limits']['ip_burst'] = [
      '#type' => 'number',
      '#title' => $this->t('突发请求数'),
      '#default_value' => $config->get('rate_limits.ip.burst') ?: 5,
      '#min' => 1,
      '#max' => 50,
      '#description' => $this->t('允许的突发请求数量'),
    ];

    // 租户级别限流
    $form['rate_limit_details']['tenant_limits'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('租户级别限流'),
    ];

    $form['rate_limit_details']['tenant_limits']['tenant_requests_per_minute'] = [
      '#type' => 'number',
      '#title' => $this->t('每分钟请求数'),
      '#default_value' => $config->get('rate_limits.tenant.requests') ?: 1000,
      '#min' => 10,
      '#max' => 10000,
      '#description' => $this->t('每个租户每分钟允许的最大请求数'),
    ];

    $form['rate_limit_details']['tenant_limits']['tenant_burst'] = [
      '#type' => 'number',
      '#title' => $this->t('突发请求数'),
      '#default_value' => $config->get('rate_limits.tenant.burst') ?: 100,
      '#min' => 10,
      '#max' => 1000,
      '#description' => $this->t('允许的突发请求数量'),
    ];

    // 日志与统计设置
    $form['logging'] = [
      '#type' => 'details',
      '#title' => $this->t('日志与统计设置'),
      '#open' => TRUE,
    ];

    $form['logging']['enable_request_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用请求日志'),
      '#description' => $this->t('记录API请求日志'),
      '#default_value' => $config->get('enable_request_logging') ?? TRUE,
    ];

    $form['logging']['request_log_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('请求日志保留天数'),
      '#default_value' => $config->get('request_log_retention_days') ?: 30,
      '#min' => 1,
      '#required' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="enable_request_logging"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['logging']['stats_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('统计数据保留天数'),
      '#default_value' => $config->get('stats_retention_days') ?: 365,
      '#min' => 1,
      '#required' => TRUE,
    ];

    // CORS设置
    $form['cors'] = [
      '#type' => 'details',
      '#title' => $this->t('CORS设置'),
      '#open' => TRUE,
    ];

    $form['cors']['enable_cors'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用CORS'),
      '#description' => $this->t('允许跨域资源共享'),
      '#default_value' => $config->get('enable_cors') ?? TRUE,
    ];

    $form['cors']['allowed_origins'] = [
      '#type' => 'textarea',
      '#title' => $this->t('允许的来源'),
      '#description' => $this->t('每行一个来源，使用*表示允许所有来源'),
      '#default_value' => $config->get('allowed_origins') ?: '*',
      '#states' => [
        'visible' => [
          ':input[name="enable_cors"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['cors']['allowed_methods'] = [
      '#type' => 'textfield',
      '#title' => $this->t('允许的方法'),
      '#description' => $this->t('使用逗号分隔，例如：GET, POST, PUT, DELETE'),
      '#default_value' => $config->get('allowed_methods') ?: 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
      '#states' => [
        'visible' => [
          ':input[name="enable_cors"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('baas_api.settings');

    // 保存API文档设置
    $config->set('api_title', $form_state->getValue('api_title'));
    $config->set('api_description', $form_state->getValue('api_description'));
    $config->set('api_version', $form_state->getValue('api_version'));
    $config->set('api_server_url', $form_state->getValue('api_server_url'));
    $config->set('api_contact_name', $form_state->getValue('api_contact_name'));
    $config->set('api_contact_email', $form_state->getValue('api_contact_email'));
    $config->set('api_contact_url', $form_state->getValue('api_contact_url'));

    // 保存速率限制设置
    $config->set('enable_rate_limiting', $form_state->getValue('enable_rate_limiting'));
    
    // 保存详细限流配置
    if ($form_state->getValue('enable_rate_limiting')) {
      $rate_limits = [
        'user' => [
          'requests' => (int) $form_state->getValue('user_requests_per_minute'),
          'window' => 60, // 固定为60秒
          'burst' => (int) $form_state->getValue('user_burst'),
        ],
        'ip' => [
          'requests' => (int) $form_state->getValue('ip_requests_per_minute'),
          'window' => 60,
          'burst' => (int) $form_state->getValue('ip_burst'),
        ],
        'tenant' => [
          'requests' => (int) $form_state->getValue('tenant_requests_per_minute'),
          'window' => 60,
          'burst' => (int) $form_state->getValue('tenant_burst'),
        ],
      ];
      $config->set('rate_limits', $rate_limits);
    }

    // 保存日志与统计设置
    $config->set('enable_request_logging', $form_state->getValue('enable_request_logging'));
    $config->set('request_log_retention_days', $form_state->getValue('request_log_retention_days'));
    $config->set('stats_retention_days', $form_state->getValue('stats_retention_days'));

    // 保存CORS设置
    $config->set('enable_cors', $form_state->getValue('enable_cors'));
    $config->set('allowed_origins', $form_state->getValue('allowed_origins'));
    $config->set('allowed_methods', $form_state->getValue('allowed_methods'));

    $config->save();
    parent::submitForm($form, $form_state);
  }

}
