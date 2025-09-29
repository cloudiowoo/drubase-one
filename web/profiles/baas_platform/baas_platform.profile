<?php

/**
 * @file
 * Enables modules and site configuration for Drubase One profile.
 */

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_FORM_ID_alter() for install_configure_form.
 */
function baas_platform_form_install_configure_form_alter(&$form, FormStateInterface $form_state) {
  // 添加示例数据选择
  $form['demo_data'] = [
    '#type' => 'fieldset',
    '#title' => t('Demonstration Data'),
    '#weight' => 15,
    '#collapsible' => FALSE,
    '#collapsed' => FALSE,
  ];
  
  $form['demo_data']['import_demo'] = [
    '#type' => 'checkbox',
    '#title' => t('Import Groups Sports demonstration project'),
    '#description' => t('Imports the Groups Sports Activity Management demo project with anonymized sample data. This includes users, activities, teams, and participation records.'),
    '#default_value' => TRUE,
  ];

  $form['demo_data']['demo_info'] = [
    '#type' => 'item',
    '#title' => t('Demo Project Information'),
    '#markup' => t('<div class="demo-info-box"><strong>Groups Sports Activity Management</strong><br>
      • <strong>Frontend App</strong>: <code>apps/groups</code> - React Native Web application for activity management<br>
      • <strong>Entities</strong>: Users, Activities, Teams, Positions, User Activities, System Config<br>
      • <strong>Features</strong>: Activity creation, team management, position assignment, participation tracking<br>
      • <strong>Data Privacy</strong>: All personal data is anonymized (demo emails, phone numbers, no passwords)</div>'),
    '#states' => [
      'visible' => [
        ':input[name="import_demo"]' => ['checked' => TRUE],
      ],
    ],
  ];
  
  // 添加API配置选项
  $form['api_settings'] = [
    '#type' => 'fieldset',
    '#title' => t('API Configuration'),
    '#weight' => 16,
    '#collapsible' => TRUE,
    '#collapsed' => TRUE,
  ];
  
  $form['api_settings']['enable_rate_limiting'] = [
    '#type' => 'checkbox',
    '#title' => t('Enable API rate limiting'),
    '#description' => t('Enables rate limiting for API requests to protect against abuse.'),
    '#default_value' => TRUE,
  ];
  
  $form['api_settings']['api_requests_per_minute'] = [
    '#type' => 'number',
    '#title' => t('API requests per minute (authenticated users)'),
    '#default_value' => 60,
    '#min' => 10,
    '#max' => 1000,
    '#states' => [
      'visible' => [
        ':input[name="enable_rate_limiting"]' => ['checked' => TRUE],
      ],
    ],
  ];
  
  // 添加自定义提交处理器
  $form['#submit'][] = 'baas_platform_install_configure_form_submit';
}

/**
 * Submission handler for the install_configure_form.
 */
function baas_platform_install_configure_form_submit($form, FormStateInterface $form_state) {
  // 保存Groups示例数据导入设置
  \Drupal::state()->set('baas_platform.import_demo_data', $form_state->getValue('import_demo'));
  
  // 保存API配置设置
  \Drupal::state()->set('baas_platform.enable_rate_limiting', $form_state->getValue('enable_rate_limiting'));
  \Drupal::state()->set('baas_platform.api_requests_per_minute', $form_state->getValue('api_requests_per_minute', 60));
}

/**
 * Implements hook_install_tasks().
 */
function baas_platform_install_tasks(&$install_state) {
  return [
    'baas_platform_final_setup' => [
      'display_name' => t('Configure BaaS Platform'),
      'type' => 'normal',
    ],
  ];
}

/**
 * Final setup task for BaaS Platform.
 */
function baas_platform_final_setup(&$install_state) {
  // 应用用户选择的API配置
  $enable_rate_limiting = \Drupal::state()->get('baas_platform.enable_rate_limiting', TRUE);
  $requests_per_minute = \Drupal::state()->get('baas_platform.api_requests_per_minute', 60);

  if (\Drupal::moduleHandler()->moduleExists('baas_api')) {
    \Drupal::configFactory()->getEditable('baas_api.settings')
      ->set('enable_rate_limiting', $enable_rate_limiting)
      ->set('rate_limits.user.requests', $requests_per_minute)
      ->set('rate_limits.user.window', 60)
      ->set('rate_limits.ip.requests', intval($requests_per_minute / 2))
      ->set('rate_limits.ip.window', 60)
      ->save();
  }

  // 处理demo数据导入
  $import_demo = \Drupal::state()->get('baas_platform.import_demo_data', TRUE);
  if ($import_demo) {
    // 启用demo数据模块
    if (!\Drupal::moduleHandler()->moduleExists('baas_platform_demo_data')) {
      \Drupal::service('module_installer')->install(['baas_platform_demo_data']);
      \Drupal::logger('baas_platform')->info('Enabled baas_platform_demo_data module during installation');
    }
  }

  // 清理临时状态
  \Drupal::state()->delete('baas_platform.enable_rate_limiting');
  \Drupal::state()->delete('baas_platform.api_requests_per_minute');
  \Drupal::state()->delete('baas_platform.import_demo_data');
}

/**
 * Implements hook_preprocess_install_page().
 */
function baas_platform_preprocess_install_page(&$variables) {
  // 自定义安装页面样式
  $variables['#attached']['library'][] = 'baas_platform/install';
}