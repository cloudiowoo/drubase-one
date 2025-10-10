<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\baas_functions\Service\EnvironmentVariableManager;
use Drupal\baas_project\ProjectManager;
use Drupal\baas_auth\Service\UnifiedPermissionChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * 环境变量创建/编辑表单
 */
class EnvironmentVariableForm extends FormBase {

  protected ?array $env_var = NULL;

  public function __construct(
    protected readonly EnvironmentVariableManager $environmentVariableManager,
    protected readonly ProjectManager $projectManager,
    protected readonly UnifiedPermissionChecker $permissionChecker,
    RequestStack $requestStack,
  ) {
    $this->requestStack = $requestStack;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_functions.env_manager'),
      $container->get('baas_project.manager'),
      $container->get('baas_auth.unified_permission_checker'),
      $container->get('request_stack'),
    );
  }

  public function getFormId(): string {
    return 'baas_functions_environment_variable_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->requestStack->getCurrentRequest();
    $tenant_id = $request->get('tenant_id');
    $project_id = $request->get('project_id');
    
    // 通过路由名称判断是否为编辑模式，而不是依赖URL参数
    $route_name = $request->attributes->get('_route');
    $is_edit_route = ($route_name === 'baas_functions.project_env_var_edit');
    $var_name = $is_edit_route ? $request->get('var_name') : null;

    // 验证项目存在
    $project = $this->projectManager->getProject($project_id);
    if (!$project || $project['tenant_id'] !== $tenant_id) {
      $this->messenger()->addError('项目不存在或无权访问。');
      return $form;
    }

    // 编辑模式：只有在编辑路由时才加载现有环境变量
    if ($is_edit_route && !empty($var_name)) {
      try {
        $this->env_var = $this->environmentVariableManager->getEnvVar($project_id, $var_name, TRUE);
        if (!$this->env_var) {
          $this->messenger()->addError('环境变量不存在。');
          return $form;
        }
      } catch (\Exception $e) {
        $this->messenger()->addError('加载环境变量失败：' . $e->getMessage());
        return $form;
      }
    }

    $is_edit = !empty($this->env_var);

    // 存储表单参数
    $form_state->set('tenant_id', $tenant_id);
    $form_state->set('project_id', $project_id);
    $form_state->set('var_name', $var_name);
    $form_state->set('is_edit', $is_edit);

    // 表单标题
    $form['title'] = [
      '#markup' => '<h2>' . ($is_edit ? '编辑环境变量' : '创建环境变量') . '</h2>',
    ];

    // 项目信息
    $form['project_info'] = [
      '#type' => 'item',
      '#title' => '项目',
      '#markup' => sprintf('<strong>%s</strong> (%s)', $project['name'], $project_id),
    ];

    // 环境变量名称
    $form['var_name'] = [
      '#type' => 'textfield',
      '#title' => '变量名',
      '#required' => TRUE,
      '#maxlength' => 100,
      '#default_value' => $is_edit ? $this->env_var['var_name'] : '',
      '#disabled' => $is_edit, // 编辑时不允许修改变量名
      '#description' => '环境变量名称，只能包含字母、数字和下划线，建议使用大写字母。',
      '#pattern' => '^[A-Z][A-Z0-9_]*$',
    ];

    // 环境变量值
    $form['value'] = [
      '#type' => 'textarea',
      '#title' => '变量值',
      '#required' => !$is_edit, // 编辑时可以不填写，保持原值
      '#rows' => 3,
      '#default_value' => '', // 出于安全考虑，不显示现有值
      '#description' => $is_edit ? 
        '留空表示保持原有值不变。出于安全考虑，不会显示当前值。' : 
        '环境变量的值，支持多行文本。',
    ];

    // 描述
    $form['description'] = [
      '#type' => 'textfield',
      '#title' => '描述',
      '#maxlength' => 255,
      '#default_value' => $is_edit ? $this->env_var['description'] : '',
      '#description' => '可选的描述信息，用于说明此环境变量的用途。',
    ];

    // 安全提示
    $form['security_notice'] = [
      '#type' => 'item',
      '#markup' => '
        <div class="messages messages--warning">
          <strong>安全提示：</strong>
          <ul>
            <li>环境变量值将被加密存储</li>
            <li>请勿在变量值中包含敏感信息的明文备注</li>
            <li>只有项目成员才能访问这些环境变量</li>
            <li>环境变量仅在函数执行时可见</li>
          </ul>
        </div>
      ',
    ];

    // 表单操作按钮
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $is_edit ? '更新环境变量' : '创建环境变量',
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => '取消',
      '#url' => Url::fromRoute('baas_functions.project_env_vars', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
      ]),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $var_name = $form_state->getValue('var_name');
    $value = $form_state->getValue('value');
    $project_id = $form_state->get('project_id');
    $is_edit = $form_state->get('is_edit');

    // 如果从form_state获取不到，尝试从request获取
    if (empty($project_id)) {
      $request = $this->requestStack->getCurrentRequest();
      $project_id = $request->get('project_id');
      if ($project_id) {
        $form_state->set('project_id', $project_id);
      }
    }

    // 确保有必要的参数
    if (empty($project_id)) {
      $form_state->setErrorByName('', '项目信息丢失，请重新访问此页面。');
      return;
    }

    // 验证变量名格式
    if (empty($var_name) || !preg_match('/^[A-Z][A-Z0-9_]*$/', $var_name)) {
      $form_state->setErrorByName('var_name', '变量名必须以大写字母开头，只能包含大写字母、数字和下划线。');
    }

    // 创建模式下检查变量名是否已存在
    if (!$is_edit && !empty($var_name)) {
      try {
        $existing = $this->environmentVariableManager->getEnvVar($project_id, $var_name, FALSE);
        if ($existing) {
          $form_state->setErrorByName('var_name', '该变量名已存在，请使用不同的名称。');
        }
      } catch (\Exception $e) {
        // 如果查询失败，可能是变量不存在，继续执行
      }
    }

    // 创建模式下必须提供值
    if (!$is_edit && (empty($value) || empty(trim((string) $value)))) {
      $form_state->setErrorByName('value', '请输入环境变量的值。');
    }

    // 验证值的长度（假设数据库字段为TEXT类型）
    if (!empty($value) && strlen((string) $value) > 65535) {
      $form_state->setErrorByName('value', '环境变量值过长，请控制在65535个字符以内。');
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $tenant_id = $form_state->get('tenant_id');
    $project_id = $form_state->get('project_id');
    $is_edit = $form_state->get('is_edit');
    
    // 如果从form_state获取不到，尝试从request获取
    if (empty($project_id) || empty($tenant_id)) {
      $request = $this->requestStack->getCurrentRequest();
      if (empty($tenant_id)) {
        $tenant_id = $request->get('tenant_id');
      }
      if (empty($project_id)) {
        $project_id = $request->get('project_id');
      }
    }
    
    $var_name = $form_state->getValue('var_name');
    $value = $form_state->getValue('value');
    $description = $form_state->getValue('description');
    $current_user_id = (int) $this->currentUser()->id();

    try {
      if ($is_edit) {
        // 更新环境变量
        $update_data = [
          'description' => $description,
        ];
        
        // 只有提供了新值才更新
        if (!empty($value) && !empty(trim((string) $value))) {
          $update_data['value'] = $value;
        }

        $this->environmentVariableManager->updateEnvVar($project_id, $var_name, $update_data);
        $this->messenger()->addStatus('环境变量更新成功。');

      } else {
        // 创建环境变量
        $this->environmentVariableManager->createEnvVar(
          $project_id,
          $var_name,
          $value,
          $description,
          $current_user_id
        );
        $this->messenger()->addStatus('环境变量创建成功。');
      }

      // 重定向到环境变量列表页面
      $form_state->setRedirect('baas_functions.project_env_vars', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
      ]);

    } catch (\Exception $e) {
      $this->messenger()->addError('操作失败：' . $e->getMessage());
      $this->getLogger('baas_functions')->error('Environment variable operation failed', [
        'project_id' => $project_id,
        'var_name' => $var_name,
        'is_edit' => $is_edit,
        'error' => $e->getMessage(),
      ]);
    }
  }
}