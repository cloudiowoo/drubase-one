<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\baas_functions\Service\ProjectFunctionManager;
use Drupal\baas_auth\Service\UnifiedPermissionChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Project Function creation and edit form.
 */
class ProjectFunctionForm extends FormBase {

  public function __construct(
    protected readonly ProjectFunctionManager $functionManager,
    protected readonly UnifiedPermissionChecker $permissionChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_functions.manager'),
      $container->get('baas_auth.unified_permission_checker'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'baas_functions_project_function_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $tenant_id = NULL, ?string $project_id = NULL, ?string $function_id = NULL): array {
    $current_user = $this->currentUser();
    
    // Check permission
    if (!$this->permissionChecker->canAccessProject((int) $current_user->id(), $project_id)) {
      $this->messenger()->addError($this->t('Access denied to this project.'));
      return new RedirectResponse('/user/functions');
    }

    // Store parameters for submit handler
    $form_state->set('tenant_id', $tenant_id);
    $form_state->set('project_id', $project_id);
    $form_state->set('function_id', $function_id);

    $is_edit = !empty($function_id);
    $function_data = [];

    // Load existing function data for editing
    if ($is_edit) {
      try {
        $function_data = $this->functionManager->getFunctionById($function_id);
        $form_state->set('function_data', $function_data);
      } catch (\Exception $e) {
        $this->messenger()->addError($this->t('Function not found: @error', ['@error' => $e->getMessage()]));
        return new RedirectResponse("/tenant/{$tenant_id}/project/{$project_id}/functions");
      }
    }

    $form['function_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('基本信息'),
      '#collapsible' => FALSE,
    ];

    $form['function_info']['function_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('函数名称'),
      '#description' => $this->t('函数的唯一标识符，只能包含字母、数字和下划线'),
      '#required' => TRUE,
      '#maxlength' => 64,
      '#pattern' => '^[a-zA-Z][a-zA-Z0-9_]*$',
      '#default_value' => $function_data['function_name'] ?? '',
      '#disabled' => $is_edit, // Function name cannot be changed after creation
    ];

    $form['function_info']['display_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('显示名称'),
      '#description' => $this->t('函数的友好显示名称'),
      '#maxlength' => 128,
      '#default_value' => $function_data['display_name'] ?? '',
    ];

    $form['function_info']['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('描述'),
      '#description' => $this->t('函数的功能描述'),
      '#rows' => 3,
      '#default_value' => $function_data['description'] ?? '',
    ];

    $form['function_code'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('函数代码'),
      '#collapsible' => FALSE,
    ];

    $form['function_code']['code'] = [
      '#type' => 'textarea',
      '#title' => $this->t('JavaScript 代码'),
      '#description' => $this->t('使用 JavaScript 编写的函数代码。函数必须 export default 一个异步函数。'),
      '#required' => TRUE,
      '#rows' => 15,
      '#attributes' => [
        'style' => 'font-family: monospace; font-size: 14px;',
        'placeholder' => $this->getCodeTemplate(),
      ],
      '#default_value' => $function_data['code'] ?? $this->getCodeTemplate(),
    ];

    $form['function_config'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('配置选项'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $form['function_config']['timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('超时时间 (毫秒)'),
      '#description' => $this->t('函数执行的最大时间限制'),
      '#min' => 100,
      '#max' => 300000,
      '#step' => 100,
      '#default_value' => $function_data['config']['timeout'] ?? 30000,
    ];

    $form['function_config']['memory_limit'] = [
      '#type' => 'select',
      '#title' => $this->t('内存限制'),
      '#description' => $this->t('函数执行时的内存限制'),
      '#options' => [
        '128' => '128 MB',
        '256' => '256 MB',
        '512' => '512 MB',
        '1024' => '1 GB',
      ],
      '#default_value' => $function_data['config']['memory_limit'] ?? '128',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $is_edit ? $this->t('更新函数') : $this->t('创建函数'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('取消'),
      '#url' => Url::fromRoute('baas_functions.project_manager', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
      ]),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $function_name = $form_state->getValue('function_name');
    $project_id = $form_state->get('project_id');
    $function_id = $form_state->get('function_id');
    $is_edit = !empty($function_id);

    // Validate function name format
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $function_name)) {
      $form_state->setErrorByName('function_name', $this->t('Function name must start with a letter and contain only letters, numbers, and underscores.'));
    }

    // Check for name uniqueness (only for new functions)
    if (!$is_edit && $this->functionManager->functionExists($project_id, $function_name)) {
      $form_state->setErrorByName('function_name', $this->t('A function with this name already exists in this project.'));
    }

    // Validate JavaScript code
    $code = $form_state->getValue('code');
    if (empty(trim($code))) {
      $form_state->setErrorByName('code', $this->t('Function code is required.'));
    } elseif (strpos($code, 'export default') === FALSE) {
      $form_state->setErrorByName('code', $this->t('Function code must export a default function.'));
    }

    // Validate timeout
    $timeout = $form_state->getValue('timeout');
    if ($timeout < 100 || $timeout > 300000) {
      $form_state->setErrorByName('timeout', $this->t('Timeout must be between 100ms and 300000ms.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $current_user = $this->currentUser();
    $tenant_id = $form_state->get('tenant_id');
    $project_id = $form_state->get('project_id');
    $function_id = $form_state->get('function_id');
    $is_edit = !empty($function_id);

    $function_data = [
      'function_name' => $form_state->getValue('function_name'),
      'display_name' => $form_state->getValue('display_name') ?: $form_state->getValue('function_name'),
      'description' => $form_state->getValue('description'),
      'code' => $form_state->getValue('code'),
      'config' => [
        'timeout' => (int) $form_state->getValue('timeout'),
        'memory_limit' => (int) $form_state->getValue('memory_limit'),
      ],
    ];

    try {
      if ($is_edit) {
        // Update existing function
        $this->functionManager->updateFunction($function_id, $function_data, (int) $current_user->id());
        $this->messenger()->addStatus($this->t('Function "@name" has been updated successfully.', [
          '@name' => $function_data['function_name'],
        ]));
      } else {
        // Create new function
        $created_function = $this->functionManager->createFunction($project_id, $function_data, (int) $current_user->id());
        $this->messenger()->addStatus($this->t('Function "@name" has been created successfully.', [
          '@name' => $function_data['function_name'],
        ]));
      }

      // Redirect to project functions list
      $form_state->setRedirect('baas_functions.project_manager', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
      ]);
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to save function: @error', [
        '@error' => $e->getMessage(),
      ]));
    }
  }

  /**
   * Get default function code template.
   *
   * @return string
   *   The code template.
   */
  protected function getCodeTemplate(): string {
    return <<<'EOF'
// Edge Function Template
// 接收请求并返回响应的异步函数

export default async function(req, ctx) {
  try {
    // 获取请求数据
    const method = req.method;
    const body = req.body;
    const headers = req.headers;
    
    // 处理业务逻辑
    const result = {
      message: 'Hello from Edge Function!',
      timestamp: new Date().toISOString(),
      method: method,
      received_data: body
    };
    
    // 返回成功响应
    return ctx.success(result, {
      'Content-Type': 'application/json',
      'X-Custom-Header': 'Edge-Function-Response'
    });
    
  } catch (error) {
    // 返回错误响应
    return ctx.error('Internal function error: ' + error.message, 500);
  }
}
EOF;
  }

}