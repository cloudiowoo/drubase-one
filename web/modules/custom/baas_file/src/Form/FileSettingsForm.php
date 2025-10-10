<?php

declare(strict_types=1);

namespace Drupal\baas_file\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * 文件管理设置表单.
 */
class FileSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['baas_file.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'baas_file_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('baas_file.settings');

    // 存储限制设置
    $form['storage_limits'] = [
      '#type' => 'details',
      '#title' => $this->t('存储限制设置'),
      '#description' => $this->t('控制文件存储限制功能的开关'),
      '#open' => TRUE,
    ];

    $form['storage_limits']['enable_storage_limits'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用存储限制'),
      '#description' => $this->t('启用后将根据项目/租户配置限制文件存储空间'),
      '#default_value' => $config->get('enable_storage_limits') ?? FALSE,
    ];

    $form['storage_limits']['check_storage_on_upload'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('上传时检查存储空间'),
      '#description' => $this->t('在文件上传前检查剩余存储空间'),
      '#default_value' => $config->get('check_storage_on_upload') ?? TRUE,
      '#states' => [
        'visible' => [
          ':input[name="enable_storage_limits"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['storage_limits']['storage_warning_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('存储警告阈值 (%)'),
      '#description' => $this->t('当存储使用率达到此百分比时发出警告'),
      '#default_value' => $config->get('storage_warning_threshold') ?: 80,
      '#min' => 1,
      '#max' => 100,
      '#states' => [
        'visible' => [
          ':input[name="enable_storage_limits"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['storage_limits']['storage_alert_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('存储告警阈值 (%)'),
      '#description' => $this->t('当存储使用率达到此百分比时发出告警'),
      '#default_value' => $config->get('storage_alert_threshold') ?: 90,
      '#min' => 1,
      '#max' => 100,
      '#states' => [
        'visible' => [
          ':input[name="enable_storage_limits"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // 文件大小限制
    $form['file_size'] = [
      '#type' => 'details',
      '#title' => $this->t('文件大小限制'),
      '#open' => TRUE,
    ];

    $form['file_size']['default_max_file_size'] = [
      '#type' => 'textfield',
      '#title' => $this->t('默认最大文件大小'),
      '#description' => $this->t('默认的单个文件最大大小（例如：10MB, 100MB, 1GB）'),
      '#default_value' => $config->get('default_max_file_size') ?: '100MB',
      '#required' => TRUE,
    ];

    $form['file_size']['enable_file_type_limits'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用文件类型限制'),
      '#description' => $this->t('根据文件类型设置不同的大小限制'),
      '#default_value' => $config->get('enable_file_type_limits') ?? FALSE,
    ];

    // 文件使用追踪
    $form['usage_tracking'] = [
      '#type' => 'details',
      '#title' => $this->t('使用追踪设置'),
      '#open' => TRUE,
    ];

    $form['usage_tracking']['enable_usage_tracking'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用文件使用追踪'),
      '#description' => $this->t('追踪文件的使用情况和访问统计'),
      '#default_value' => $config->get('enable_usage_tracking') ?? TRUE,
    ];

    $form['usage_tracking']['track_file_downloads'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('追踪文件下载'),
      '#description' => $this->t('记录文件下载次数和下载历史'),
      '#default_value' => $config->get('track_file_downloads') ?? TRUE,
      '#states' => [
        'visible' => [
          ':input[name="enable_usage_tracking"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['usage_tracking']['cache_storage_stats'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('缓存存储统计'),
      '#description' => $this->t('缓存项目的存储统计以提高性能'),
      '#default_value' => $config->get('cache_storage_stats') ?? TRUE,
      '#states' => [
        'visible' => [
          ':input[name="enable_usage_tracking"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['usage_tracking']['stats_cache_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('统计缓存时长（秒）'),
      '#description' => $this->t('存储统计缓存的有效时长'),
      '#default_value' => $config->get('stats_cache_duration') ?: 300,
      '#min' => 60,
      '#max' => 3600,
      '#states' => [
        'visible' => [
          ':input[name="cache_storage_stats"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // 清理设置
    $form['cleanup'] = [
      '#type' => 'details',
      '#title' => $this->t('清理设置'),
      '#open' => FALSE,
    ];

    $form['cleanup']['enable_orphan_cleanup'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用孤立文件清理'),
      '#description' => $this->t('自动清理未被引用的文件'),
      '#default_value' => $config->get('enable_orphan_cleanup') ?? FALSE,
    ];

    $form['cleanup']['orphan_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('孤立文件保留天数'),
      '#description' => $this->t('孤立文件在被删除前的保留天数'),
      '#default_value' => $config->get('orphan_retention_days') ?: 30,
      '#min' => 1,
      '#states' => [
        'visible' => [
          ':input[name="enable_orphan_cleanup"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('baas_file.settings');

    // 保存存储限制设置
    $config->set('enable_storage_limits', $form_state->getValue('enable_storage_limits'));
    $config->set('check_storage_on_upload', $form_state->getValue('check_storage_on_upload'));
    $config->set('storage_warning_threshold', $form_state->getValue('storage_warning_threshold'));
    $config->set('storage_alert_threshold', $form_state->getValue('storage_alert_threshold'));

    // 保存文件大小限制
    $config->set('default_max_file_size', $form_state->getValue('default_max_file_size'));
    $config->set('enable_file_type_limits', $form_state->getValue('enable_file_type_limits'));

    // 保存使用追踪设置
    $config->set('enable_usage_tracking', $form_state->getValue('enable_usage_tracking'));
    $config->set('track_file_downloads', $form_state->getValue('track_file_downloads'));
    $config->set('cache_storage_stats', $form_state->getValue('cache_storage_stats'));
    $config->set('stats_cache_duration', $form_state->getValue('stats_cache_duration'));

    // 保存清理设置
    $config->set('enable_orphan_cleanup', $form_state->getValue('enable_orphan_cleanup'));
    $config->set('orphan_retention_days', $form_state->getValue('orphan_retention_days'));

    $config->save();
    parent::submitForm($form, $form_state);
  }

}