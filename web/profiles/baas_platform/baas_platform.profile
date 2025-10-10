<?php

/**
 * @file
 * Enables modules and site configuration for Drubase One profile.
 */

use Drupal\Core\Form\FormStateInterface;

// 在 profile 加载时立即抑制 PHP 8.1+ deprecation warnings
// 这确保了从安装开始到结束的整个过程中都不会显示这些警告
if (!defined('BAAS_ERROR_SUPPRESSION_ACTIVE')) {
  define('BAAS_ERROR_SUPPRESSION_ACTIVE', TRUE);

  // 保存原始设置（仅在首次执行时）
  if (!isset($GLOBALS['baas_profile_error_level'])) {
    $GLOBALS['baas_profile_error_level'] = error_reporting();
    $GLOBALS['baas_profile_display_errors'] = ini_get('display_errors');
  }

  // 设置自定义错误处理器
  set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // 完全忽略 deprecation warnings
    if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
      return TRUE;
    }
    // 其他错误只记录不显示
    if ($errno & (E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE)) {
      error_log("PHP {$errno}: {$errstr} in {$errfile}:{$errline}");
      return TRUE;
    }
    // 严重错误继续传播
    return FALSE;
  });

  // 调整 error_reporting 级别
  error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR);
  ini_set('display_errors', 'Off');
  ini_set('display_startup_errors', 'Off');
}

/**
 * Implements hook_form_FORM_ID_alter() for install_configure_form.
 */
function baas_platform_form_install_configure_form_alter(&$form, FormStateInterface $form_state) {
  // 隐藏字段：示例数据导入设置（默认启用）
  $form['import_demo'] = [
    '#type' => 'hidden',
    '#value' => TRUE,
  ];

  // 隐藏字段：API限流设置（默认启用）
  $form['enable_rate_limiting'] = [
    '#type' => 'hidden',
    '#value' => TRUE,
  ];

  // 隐藏字段：API请求频率设置（默认60/分钟）
  $form['api_requests_per_minute'] = [
    '#type' => 'hidden',
    '#value' => 60,
  ];

  // 添加自定义提交处理器
  $form['#submit'][] = 'baas_platform_install_configure_form_submit';
}

/**
 * Submission handler for the install_configure_form.
 */
function baas_platform_install_configure_form_submit($form, FormStateInterface $form_state) {
  // 保存Groups示例数据导入设置
  \Drupal::state()->set('baas_platform.import_demo_data', $form_state->getValue('import_demo', TRUE));

  // 保存API配置设置
  \Drupal::state()->set('baas_platform.enable_rate_limiting', $form_state->getValue('enable_rate_limiting', TRUE));
  \Drupal::state()->set('baas_platform.api_requests_per_minute', $form_state->getValue('api_requests_per_minute', 60));
}

/**
 * Implements hook_install_tasks().
 */
function baas_platform_install_tasks(&$install_state) {
  return [
    'baas_platform_suppress_errors' => [
      'display_name' => t('Preparing BaaS Platform'),
      'type' => 'normal',
    ],
    'baas_platform_final_setup' => [
      'display_name' => t('Configure BaaS Platform'),
      'type' => 'normal',
    ],
    'baas_platform_restore_errors' => [
      'display_name' => t('Finalizing BaaS Platform'),
      'type' => 'normal',
    ],
  ];
}

/**
 * 抑制错误显示的预处理任务
 */
function baas_platform_suppress_errors(&$install_state) {
  // 确保dblog模块已启用
  if (!\Drupal::moduleHandler()->moduleExists('dblog')) {
    \Drupal::service('module_installer')->install(['dblog'], TRUE);
    \Drupal::logger('baas_platform')->info('Enabled dblog module for installation logging');
  }

  // 保存原始错误设置
  $GLOBALS['baas_original_error_level'] = error_reporting();
  $GLOBALS['baas_original_display_errors'] = ini_get('display_errors');
  $GLOBALS['baas_original_display_startup_errors'] = ini_get('display_startup_errors');
  $GLOBALS['baas_original_log_errors'] = ini_get('log_errors');
  $GLOBALS['baas_original_error_handler'] = set_error_handler('baas_platform_error_handler');

  // 完全禁止错误显示，但保留日志记录
  // E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED = 仅报告非弃用错误
  error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR);
  ini_set('display_errors', 'Off');
  ini_set('display_startup_errors', 'Off');
  ini_set('log_errors', 'On');

  \Drupal::logger('baas_platform')->info('Temporarily suppressed error display for installation. PHP version: @version', [
    '@version' => PHP_VERSION
  ]);
}

/**
 * 自定义错误处理器 - 拦截并抑制 PHP 8.1+ deprecation warnings
 */
function baas_platform_error_handler($errno, $errstr, $errfile, $errline) {
  // 只记录到日志，不显示任何错误
  if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
    // Deprecation warnings - 完全忽略，不记录
    return TRUE;
  }

  // 其他错误 - 记录到日志但不显示
  if ($errno === E_WARNING || $errno === E_USER_WARNING || $errno === E_NOTICE || $errno === E_USER_NOTICE) {
    error_log("PHP {$errno}: {$errstr} in {$errfile} on line {$errline}");
    return TRUE;
  }

  // 严重错误 - 让 PHP 默认处理
  return FALSE;
}

/**
 * 恢复错误显示的后处理任务
 */
function baas_platform_restore_errors(&$install_state) {
  // 恢复错误显示设置
  if (isset($GLOBALS['baas_original_error_level'])) {
    error_reporting($GLOBALS['baas_original_error_level']);
    ini_set('display_errors', $GLOBALS['baas_original_display_errors']);
    ini_set('display_startup_errors', $GLOBALS['baas_original_display_startup_errors']);
    ini_set('log_errors', $GLOBALS['baas_original_log_errors']);

    // 恢复原始错误处理器
    if (isset($GLOBALS['baas_original_error_handler'])) {
      if ($GLOBALS['baas_original_error_handler'] === NULL) {
        restore_error_handler();
      } else {
        set_error_handler($GLOBALS['baas_original_error_handler']);
      }
      unset($GLOBALS['baas_original_error_handler']);
    }

    unset($GLOBALS['baas_original_error_level']);
    unset($GLOBALS['baas_original_display_errors']);
    unset($GLOBALS['baas_original_display_startup_errors']);
    unset($GLOBALS['baas_original_log_errors']);

    \Drupal::logger('baas_platform')->info('Restored original error display settings');
  }
}

/**
 * Final setup task for BaaS Platform.
 */
function baas_platform_final_setup(&$install_state) {
  \Drupal::logger('baas_platform')->info('Starting BaaS Platform final setup and demo data import');

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

    // 执行完整的demo数据导入
    try {
      $install_helper = \Drupal::classResolver('\Drupal\baas_platform_demo_data\InstallHelper');
      $import_results = $install_helper->importCompleteGroupsProject();

      if ($import_results['success']) {
        // 标记demo数据已导入
        \Drupal::state()->set('baas_platform.demo_data_imported', TRUE);
        \Drupal::state()->set('baas_platform.demo_project_type', 'groups_sports_original');
        \Drupal::state()->set('baas_platform.demo_tenant_id', 'tenant_7375b0cd');
        \Drupal::state()->set('baas_platform.demo_project_id', 'tenant_7375b0cd_project_6888d012be80c');

        \Drupal::logger('baas_platform')->info('Groups demo project imported successfully during installation');
      } else {
        $error_message = implode('; ', $import_results['errors']);
        \Drupal::logger('baas_platform')->error('Groups demo project import failed: @errors', ['@errors' => $error_message]);
      }
    } catch (\Exception $e) {
      \Drupal::logger('baas_platform')->error('Groups demo project import failed with exception: @error', ['@error' => $e->getMessage()]);
    }
  }

  // 清理临时状态
  \Drupal::state()->delete('baas_platform.enable_rate_limiting');
  \Drupal::state()->delete('baas_platform.api_requests_per_minute');
  \Drupal::state()->delete('baas_platform.import_demo_data');

  \Drupal::logger('baas_platform')->info('BaaS Platform final setup completed successfully');
}

/**
 * Implements hook_preprocess_install_page().
 */
function baas_platform_preprocess_install_page(&$variables) {
  // 自定义安装页面样式
  $variables['#attached']['library'][] = 'baas_platform/install';
}