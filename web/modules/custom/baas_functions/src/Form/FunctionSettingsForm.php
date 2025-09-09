<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\baas_functions\Service\FunctionExecutor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * BaaS Functions Settings Form.
 *
 * Configuration form for BaaS Functions service settings.
 */
class FunctionSettingsForm extends ConfigFormBase {

  protected readonly FunctionExecutor $functionExecutor;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    FunctionExecutor $function_executor,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
    $this->functionExecutor = $function_executor;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('baas_functions.executor'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['baas_functions.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'baas_functions_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('baas_functions.settings');

    $form['nodejs_service'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Node.js Service Configuration'),
      '#collapsible' => FALSE,
    ];

    $form['nodejs_service']['nodejs_service_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Node.js Service URL'),
      '#default_value' => $config->get('nodejs_service_url'),
      '#description' => $this->t('URL of the Node.js Functions service. Example: http://172.31.1.40:3001'),
      '#required' => TRUE,
    ];

    $form['execution_limits'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Execution Limits'),
      '#collapsible' => FALSE,
    ];

    $form['execution_limits']['default_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Default Timeout (seconds)'),
      '#default_value' => $config->get('default_timeout'),
      '#description' => $this->t('Default timeout for function execution in seconds.'),
      '#min' => 1,
      '#max' => 600,
      '#required' => TRUE,
    ];

    $form['execution_limits']['max_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Timeout (seconds)'),
      '#default_value' => $config->get('max_timeout'),
      '#description' => $this->t('Maximum allowed timeout for function execution in seconds.'),
      '#min' => 1,
      '#max' => 600,
      '#required' => TRUE,
    ];

    $form['rate_limiting'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Rate Limiting'),
      '#collapsible' => FALSE,
    ];

    $form['rate_limiting']['rate_limit_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Rate Limiting'),
      '#default_value' => $config->get('rate_limit_enabled'),
      '#description' => $this->t('Enable rate limiting for function execution.'),
    ];

    $form['rate_limiting']['rate_limit_window'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate Limit Window (seconds)'),
      '#default_value' => $config->get('rate_limit_window'),
      '#description' => $this->t('Time window for rate limiting in seconds.'),
      '#min' => 1,
      '#max' => 3600,
      '#states' => [
        'visible' => [
          ':input[name="rate_limit_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['rate_limiting']['rate_limit_max_requests'] = [
      '#type' => 'number',
      '#title' => $this->t('Max Requests per Window'),
      '#default_value' => $config->get('rate_limit_max_requests'),
      '#description' => $this->t('Maximum number of requests allowed per rate limit window.'),
      '#min' => 1,
      '#max' => 10000,
      '#states' => [
        'visible' => [
          ':input[name="rate_limit_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['security'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Security'),
      '#collapsible' => FALSE,
    ];

    $form['security']['security_checks_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Security Checks'),
      '#default_value' => $config->get('security_checks_enabled'),
      '#description' => $this->t('Enable additional security checks for function code.'),
    ];

    $form['logging'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Logging'),
      '#collapsible' => FALSE,
    ];

    $form['logging']['logging_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Logging'),
      '#default_value' => $config->get('logging_enabled'),
      '#description' => $this->t('Enable detailed logging for function execution.'),
    ];

    // Service Status
    $form['service_status'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Service Status'),
      '#collapsible' => FALSE,
    ];

    try {
      $status = $this->functionExecutor->checkServiceStatus();
      $status_class = $status['status'] === 'online' ? 'color: green;' : 'color: red;';
      
      $form['service_status']['status_display'] = [
        '#markup' => '<p><strong>' . $this->t('Service Status:') . '</strong> <span style="' . $status_class . '">' . ucfirst($status['status']) . '</span></p>',
      ];

      if ($status['status'] === 'online') {
        $form['service_status']['details'] = [
          '#markup' => '<ul>' .
            '<li><strong>' . $this->t('Service URL:') . '</strong> ' . $status['service_url'] . '</li>' .
            '<li><strong>' . $this->t('Response Time:') . '</strong> ' . ($status['response_time_ms'] ?? 0) . 'ms</li>' .
            '<li><strong>' . $this->t('Version:') . '</strong> ' . ($status['version'] ?? 'unknown') . '</li>' .
            '<li><strong>' . $this->t('Uptime:') . '</strong> ' . ($status['uptime'] ?? 0) . 's</li>' .
            '</ul>',
        ];
      } else {
        $form['service_status']['error'] = [
          '#markup' => '<p style="color: red;"><strong>' . $this->t('Error:') . '</strong> ' . ($status['error'] ?? 'Unknown error') . '</p>',
        ];
      }
    }
    catch (\Exception $e) {
      $form['service_status']['error'] = [
        '#markup' => '<p style="color: red;"><strong>' . $this->t('Error:') . '</strong> ' . $e->getMessage() . '</p>',
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $nodejs_service_url = $form_state->getValue('nodejs_service_url');
    $default_timeout = $form_state->getValue('default_timeout');
    $max_timeout = $form_state->getValue('max_timeout');

    // Validate service URL format
    if (!filter_var($nodejs_service_url, FILTER_VALIDATE_URL)) {
      $form_state->setErrorByName('nodejs_service_url', $this->t('Please enter a valid URL.'));
    }

    // Validate timeout logic
    if ($default_timeout > $max_timeout) {
      $form_state->setErrorByName('default_timeout', $this->t('Default timeout cannot be greater than maximum timeout.'));
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('baas_functions.settings')
      ->set('nodejs_service_url', $form_state->getValue('nodejs_service_url'))
      ->set('default_timeout', (int) $form_state->getValue('default_timeout'))
      ->set('max_timeout', (int) $form_state->getValue('max_timeout'))
      ->set('rate_limit_enabled', (bool) $form_state->getValue('rate_limit_enabled'))
      ->set('rate_limit_window', (int) $form_state->getValue('rate_limit_window'))
      ->set('rate_limit_max_requests', (int) $form_state->getValue('rate_limit_max_requests'))
      ->set('security_checks_enabled', (bool) $form_state->getValue('security_checks_enabled'))
      ->set('logging_enabled', (bool) $form_state->getValue('logging_enabled'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}