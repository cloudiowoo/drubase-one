<?php

declare(strict_types=1);

namespace Drupal\baas_realtime\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * BaaS实时设置表单。
 */
class RealtimeSettingsForm extends ConfigFormBase
{

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['baas_realtime.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'baas_realtime_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('baas_realtime.settings');

    $form['websocket'] = [
      '#type' => 'details',
      '#title' => $this->t('WebSocket Settings'),
      '#open' => TRUE,
    ];

    $form['websocket']['websocket_server_url'] = [
      '#type' => 'url',
      '#title' => $this->t('WebSocket Server URL'),
      '#default_value' => $config->get('websocket_server_url'),
      '#description' => $this->t('The URL of the WebSocket server (e.g., ws://localhost:4000)'),
      '#required' => TRUE,
    ];

    $form['websocket']['max_connections_per_project'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Connections Per Project'),
      '#default_value' => $config->get('max_connections_per_project'),
      '#description' => $this->t('Maximum number of WebSocket connections allowed per project'),
      '#min' => 1,
      '#max' => 10000,
      '#required' => TRUE,
    ];

    $form['websocket']['heartbeat_interval'] = [
      '#type' => 'number',
      '#title' => $this->t('Heartbeat Interval (seconds)'),
      '#default_value' => $config->get('heartbeat_interval'),
      '#description' => $this->t('How often clients should send heartbeat messages'),
      '#min' => 10,
      '#max' => 300,
      '#required' => TRUE,
    ];

    $form['websocket']['connection_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Connection Timeout (seconds)'),
      '#default_value' => $config->get('connection_timeout'),
      '#description' => $this->t('How long to wait before considering a connection dead'),
      '#min' => 60,
      '#max' => 3600,
      '#required' => TRUE,
    ];

    $form['messages'] = [
      '#type' => 'details',
      '#title' => $this->t('Message Settings'),
      '#open' => TRUE,
    ];

    $form['messages']['message_history_retention'] = [
      '#type' => 'number',
      '#title' => $this->t('Message History Retention (seconds)'),
      '#default_value' => $config->get('message_history_retention'),
      '#description' => $this->t('How long to keep message history for replay'),
      '#min' => 3600,
      '#max' => 2592000, // 30 days
      '#required' => TRUE,
    ];

    $form['messages']['max_message_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Message Size (bytes)'),
      '#default_value' => $config->get('max_message_size') ?: 65536,
      '#description' => $this->t('Maximum size for WebSocket messages'),
      '#min' => 1024,
      '#max' => 1048576, // 1MB
      '#required' => TRUE,
    ];

    $form['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance Settings'),
      '#open' => FALSE,
    ];

    $form['performance']['enable_message_compression'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Message Compression'),
      '#default_value' => $config->get('enable_message_compression') ?? TRUE,
      '#description' => $this->t('Compress WebSocket messages to reduce bandwidth'),
    ];

    $form['performance']['enable_message_batching'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Message Batching'),
      '#default_value' => $config->get('enable_message_batching') ?? TRUE,
      '#description' => $this->t('Batch multiple messages together for better performance'),
    ];

    $form['performance']['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Batch Size'),
      '#default_value' => $config->get('batch_size') ?: 10,
      '#description' => $this->t('Number of messages to batch together'),
      '#min' => 1,
      '#max' => 100,
      '#states' => [
        'visible' => [
          ':input[name="enable_message_batching"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['security'] = [
      '#type' => 'details',
      '#title' => $this->t('Security Settings'),
      '#open' => FALSE,
    ];

    $form['security']['require_ssl'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require SSL/TLS'),
      '#default_value' => $config->get('require_ssl') ?? FALSE,
      '#description' => $this->t('Require secure WebSocket connections (wss://)'),
    ];

    $form['security']['allowed_origins'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Allowed Origins'),
      '#default_value' => $config->get('allowed_origins') ?: '',
      '#description' => $this->t('One origin per line. Leave empty to allow all origins.'),
      '#rows' => 5,
    ];

    $form['monitoring'] = [
      '#type' => 'details',
      '#title' => $this->t('Monitoring Settings'),
      '#open' => FALSE,
    ];

    $form['monitoring']['enable_connection_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Connection Logging'),
      '#default_value' => $config->get('enable_connection_logging') ?? TRUE,
      '#description' => $this->t('Log WebSocket connection events'),
    ];

    $form['monitoring']['enable_message_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Message Logging'),
      '#default_value' => $config->get('enable_message_logging') ?? FALSE,
      '#description' => $this->t('Log WebSocket messages (may impact performance)'),
    ];

    $form['monitoring']['log_level'] = [
      '#type' => 'select',
      '#title' => $this->t('Log Level'),
      '#default_value' => $config->get('log_level') ?: 'info',
      '#options' => [
        'debug' => $this->t('Debug'),
        'info' => $this->t('Info'),
        'warning' => $this->t('Warning'),
        'error' => $this->t('Error'),
      ],
      '#description' => $this->t('Minimum log level for realtime events'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // 验证WebSocket URL格式
    $websocket_url = $form_state->getValue('websocket_server_url');
    if (!empty($websocket_url) && !in_array(parse_url($websocket_url, PHP_URL_SCHEME), ['ws', 'wss'])) {
      $form_state->setErrorByName('websocket_server_url', 
        $this->t('WebSocket URL must use ws:// or wss:// scheme.'));
    }

    // 验证允许的来源格式
    $allowed_origins = $form_state->getValue('allowed_origins');
    if (!empty($allowed_origins)) {
      $origins = array_filter(explode("\n", $allowed_origins));
      foreach ($origins as $origin) {
        $origin = trim($origin);
        if (!empty($origin) && !filter_var($origin, FILTER_VALIDATE_URL)) {
          $form_state->setErrorByName('allowed_origins', 
            $this->t('Invalid origin format: @origin', ['@origin' => $origin]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('baas_realtime.settings');

    // WebSocket设置
    $config->set('websocket_server_url', $form_state->getValue('websocket_server_url'));
    $config->set('max_connections_per_project', (int) $form_state->getValue('max_connections_per_project'));
    $config->set('heartbeat_interval', (int) $form_state->getValue('heartbeat_interval'));
    $config->set('connection_timeout', (int) $form_state->getValue('connection_timeout'));

    // 消息设置
    $config->set('message_history_retention', (int) $form_state->getValue('message_history_retention'));
    $config->set('max_message_size', (int) $form_state->getValue('max_message_size'));

    // 性能设置
    $config->set('enable_message_compression', (bool) $form_state->getValue('enable_message_compression'));
    $config->set('enable_message_batching', (bool) $form_state->getValue('enable_message_batching'));
    $config->set('batch_size', (int) $form_state->getValue('batch_size'));

    // 安全设置
    $config->set('require_ssl', (bool) $form_state->getValue('require_ssl'));
    $config->set('allowed_origins', trim($form_state->getValue('allowed_origins')));

    // 监控设置
    $config->set('enable_connection_logging', (bool) $form_state->getValue('enable_connection_logging'));
    $config->set('enable_message_logging', (bool) $form_state->getValue('enable_message_logging'));
    $config->set('log_level', $form_state->getValue('log_level'));

    $config->save();

    parent::submitForm($form, $form_state);
  }

}