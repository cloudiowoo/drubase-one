<?php

namespace Drupal\baas_platform\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Demo data import form for Drubase One.
 */
class DemoDataImportForm extends FormBase {

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * Constructs a new DemoDataImportForm object.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   */
  public function __construct(MessengerInterface $messenger, StateInterface $state) {
    $this->messenger = $messenger;
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('messenger'),
      $container->get('state')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'baas_platform_demo_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // 检查是否已经导入过演示数据
    $demo_imported = $this->state->get('baas_platform.demo_data_imported', FALSE);
    
    if ($demo_imported) {
      $form['already_imported'] = [
        '#markup' => '<div class="messages messages--warning">' . 
                     $this->t('Demonstration data has already been imported. Re-importing will create duplicate data.') . 
                     '</div>',
      ];
    }

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Import demonstration data to explore Drubase One features and capabilities.') . '</p>',
    ];
    
    $form['data_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Select data to import'),
      '#options' => [
        'tenants' => $this->t('Demo Tenants (Acme Corp, Startup Inc)'),
        'projects' => $this->t('Demo Projects (E-Commerce, Blog CMS, Mobile App)'),
        'entities' => $this->t('Demo Entities (Products, Orders, Blog Posts)'),
        'users' => $this->t('Demo Users (tenant_admin, project_admin, developer)'),
      ],
      '#default_value' => ['tenants', 'projects', 'entities', 'users'],
      '#description' => $this->t('Select which types of demonstration data to import.'),
    ];

    $form['credentials_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Demo User Credentials'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $form['credentials_info']['info'] = [
      '#markup' => '
        <p>' . $this->t('The following demo users will be created with password: <strong>demo123</strong>') . '</p>
        <ul>
          <li><strong>tenant_admin</strong> - Tenant Administrator role</li>
          <li><strong>project_admin</strong> - Project Administrator role</li>
          <li><strong>developer</strong> - API Developer role</li>
        </ul>
        <p><em>' . $this->t('Change these passwords after import for security.') . '</em></p>
      ',
    ];
    
    $form['force_import'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force re-import (skip duplicate checks)'),
      '#description' => $this->t('Check this to re-import data even if it already exists.'),
      '#default_value' => FALSE,
      '#states' => [
        'visible' => [
          ':input[name="already_imported"]' => ['value' => TRUE],
        ],
      ],
    ];
    
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Import Demo Data'),
        '#button_type' => 'primary',
      ],
    ];
    
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $selected = array_filter($form_state->getValue('data_types'));
    
    if (empty($selected)) {
      $form_state->setErrorByName('data_types', $this->t('Please select at least one type of data to import.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $selected = array_filter($form_state->getValue('data_types'));
    $force_import = $form_state->getValue('force_import');
    
    // 设置批处理操作
    $batch = [
      'title' => $this->t('Importing demonstration data...'),
      'operations' => [],
      'finished' => '\Drupal\baas_platform\Form\DemoDataImportForm::batchFinished',
      'init_message' => $this->t('Starting demo data import...'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message' => $this->t('Demo data import has encountered an error.'),
    ];
    
    // 添加批处理操作
    foreach ($selected as $type) {
      $batch['operations'][] = [
        '\Drupal\baas_platform\Form\DemoDataImportForm::batchImport',
        [$type, $force_import]
      ];
    }
    
    batch_set($batch);
  }

  /**
   * Batch operation callback.
   *
   * @param string $type
   *   The type of data to import.
   * @param bool $force_import
   *   Whether to force import even if data exists.
   * @param array $context
   *   The batch context.
   */
  public static function batchImport($type, $force_import, array &$context) {
    if (!isset($context['sandbox']['progress'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current'] = 0;
      $context['sandbox']['max'] = 1;
    }

    try {
      switch ($type) {
        case 'tenants':
          baas_platform_create_demo_tenants();
          $context['message'] = t('Importing demo tenants...');
          break;
          
        case 'projects':
          baas_platform_create_demo_projects();
          $context['message'] = t('Importing demo projects...');
          break;
          
        case 'entities':
          baas_platform_create_demo_entities();
          $context['message'] = t('Importing demo entities...');
          break;
          
        case 'users':
          baas_platform_create_demo_users();
          $context['message'] = t('Importing demo users...');
          break;
      }
      
      $context['results'][] = $type;
      $context['sandbox']['progress']++;
      
    } catch (\Exception $e) {
      $context['results']['errors'][] = t('Error importing @type: @error', [
        '@type' => $type,
        '@error' => $e->getMessage()
      ]);
    }

    $context['finished'] = 1;
  }

  /**
   * Batch finished callback.
   *
   * @param bool $success
   *   Whether the batch completed successfully.
   * @param array $results
   *   The results array.
   * @param array $operations
   *   The operations array.
   */
  public static function batchFinished($success, array $results, array $operations) {
    $messenger = \Drupal::messenger();
    
    if ($success) {
      $imported_types = array_filter($results, function($item) {
        return !is_array($item);
      });
      
      \Drupal::state()->set('baas_platform.demo_data_imported', TRUE);
      
      $messenger->addMessage(t('Demo data import completed successfully. Imported: @types', [
        '@types' => implode(', ', $imported_types)
      ]));
      
      if (isset($results['errors'])) {
        foreach ($results['errors'] as $error) {
          $messenger->addError($error);
        }
      }
    } else {
      $messenger->addError(t('Demo data import failed. Please check the logs for details.'));
    }
  }
}