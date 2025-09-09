<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\baas_functions\Service\EnvironmentVariableManager;
use Drupal\baas_project\ProjectManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * 环境变量批量导入表单
 */
class EnvironmentVariableImportForm extends FormBase {

  public function __construct(
    protected readonly EnvironmentVariableManager $environmentVariableManager,
    protected readonly ProjectManager $projectManager,
    RequestStack $requestStack,
  ) {
    $this->requestStack = $requestStack;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_functions.env_manager'),
      $container->get('baas_project.manager'),
      $container->get('request_stack'),
    );
  }

  public function getFormId(): string {
    return 'baas_functions_environment_variable_import_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->requestStack->getCurrentRequest();
    $tenant_id = $request->get('tenant_id');
    $project_id = $request->get('project_id');

    // 验证项目存在
    $project = $this->projectManager->getProject($project_id);
    if (!$project || $project['tenant_id'] !== $tenant_id) {
      $this->messenger()->addError('项目不存在或无权访问。');
      return $form;
    }

    // 存储表单参数
    $form_state->set('tenant_id', $tenant_id);
    $form_state->set('project_id', $project_id);

    // 表单标题
    $form['title'] = [
      '#markup' => '<h2>批量导入环境变量</h2>',
    ];

    // 项目信息
    $form['project_info'] = [
      '#type' => 'item',
      '#title' => '项目',
      '#markup' => sprintf('<strong>%s</strong> (%s)', $project['name'], $project_id),
    ];

    // 导入格式说明
    $form['format_help'] = [
      '#type' => 'details',
      '#title' => '导入格式说明',
      '#open' => TRUE,
    ];

    $form['format_help']['content'] = [
      '#markup' => '
        <div class="import-format-help">
          <h4>支持的导入格式：</h4>
          
          <h5>1. KEY=VALUE 格式（推荐）：</h5>
          <pre><code>API_KEY=your_api_key_here
DATABASE_URL=postgresql://user:pass@host:5432/db
DEBUG_MODE=true
MAX_CONNECTIONS=100</code></pre>
          
          <h5>2. JSON 格式：</h5>
          <pre><code>{
  "API_KEY": "your_api_key_here",
  "DATABASE_URL": "postgresql://user:pass@host:5432/db", 
  "DEBUG_MODE": "true",
  "MAX_CONNECTIONS": "100"
}</code></pre>
          
          <h4>导入规则：</h4>
          <ul>
            <li>变量名必须以大写字母开头，只能包含大写字母、数字和下划线</li>
            <li>空行和以 # 开头的行将被忽略</li>
            <li>重复的变量名将根据下方选项处理</li>
            <li>每个变量值最大长度为 65535 个字符</li>
          </ul>
        </div>
      ',
    ];

    // 导入内容
    $form['import_content'] = [
      '#type' => 'textarea',
      '#title' => '环境变量内容',
      '#required' => TRUE,
      '#rows' => 15,
      '#description' => '请按照上述格式输入环境变量。支持 KEY=VALUE 或 JSON 格式。',
      '#placeholder' => "# 示例：微信小程序配置\nWX_APPID=wx1234567890abcdef\nWX_APP_SECRET=your_secret_here\n\n# API 配置\nAPI_BASE_URL=https://api.example.com\nAPI_TIMEOUT=30000",
    ];

    // 冲突处理选项
    $form['conflict_resolution'] = [
      '#type' => 'radios',
      '#title' => '变量名冲突处理',
      '#required' => TRUE,
      '#default_value' => 'skip',
      '#options' => [
        'skip' => '跳过已存在的变量（保持原值不变）',
        'overwrite' => '覆盖已存在的变量（更新为新值）',
        'error' => '遇到冲突时停止导入并报错',
      ],
      '#description' => '当导入的变量名已存在时的处理方式。',
    ];

    // 添加描述选项
    $form['add_descriptions'] = [
      '#type' => 'checkbox',
      '#title' => '自动添加导入时间描述',
      '#default_value' => TRUE,
      '#description' => '为每个导入的变量自动添加导入时间作为描述信息。',
    ];

    // 预览模式
    $form['preview_mode'] = [
      '#type' => 'checkbox',
      '#title' => '预览模式（不实际导入）',
      '#default_value' => FALSE,
      '#description' => '选中此项将只解析和验证导入内容，不会实际创建环境变量。',
    ];

    // 表单操作按钮
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => '开始导入',
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
    $content = trim($form_state->getValue('import_content'));
    
    if (empty($content)) {
      $form_state->setErrorByName('import_content', '请输入要导入的环境变量内容。');
      return;
    }

    // 解析导入内容
    try {
      $env_vars = $this->parseImportContent($content);
      $form_state->set('parsed_env_vars', $env_vars);
    } catch (\Exception $e) {
      $form_state->setErrorByName('import_content', '解析失败：' . $e->getMessage());
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $tenant_id = $form_state->get('tenant_id');
    $project_id = $form_state->get('project_id');
    $env_vars = $form_state->get('parsed_env_vars');
    $conflict_resolution = $form_state->getValue('conflict_resolution');
    $add_descriptions = $form_state->getValue('add_descriptions');
    $preview_mode = $form_state->getValue('preview_mode');
    $current_user_id = $this->currentUser()->id();

    if ($preview_mode) {
      // 预览模式：显示解析结果
      $this->displayPreview($env_vars);
      return;
    }

    // 准备导入数据
    $import_data = [];
    foreach ($env_vars as $var_name => $value) {
      $description = '';
      if ($add_descriptions) {
        $description = '批量导入 - ' . date('Y-m-d H:i:s');
      }
      $import_data[$var_name] = $value;
    }

    try {
      // 执行批量导入
      $overwrite = ($conflict_resolution === 'overwrite');
      $result = $this->environmentVariableManager->importEnvVars(
        $project_id,
        $import_data,
        $current_user_id,
        $overwrite
      );

      // 处理导入结果
      $success_count = count($result['success']);
      $skip_count = count($result['skipped']);
      $error_count = count($result['errors']);

      $messages = [];
      if ($success_count > 0) {
        $messages[] = sprintf('成功导入 %d 个环境变量', $success_count);
      }
      if ($skip_count > 0) {
        $messages[] = sprintf('跳过 %d 个已存在的变量', $skip_count);
      }
      if ($error_count > 0) {
        $messages[] = sprintf('%d 个变量导入失败', $error_count);
        foreach ($result['errors'] as $var_name => $error) {
          $this->messenger()->addWarning(sprintf('变量 "%s" 导入失败：%s', $var_name, $error));
        }
      }

      if (!empty($messages)) {
        $this->messenger()->addStatus('导入完成：' . implode('，', $messages) . '。');
      }

      // 记录日志
      $this->getLogger('baas_functions')->info('Environment variables imported', [
        'project_id' => $project_id,
        'user_id' => $current_user_id,
        'success_count' => $success_count,
        'skip_count' => $skip_count,
        'error_count' => $error_count,
      ]);

      // 重定向到环境变量列表页面
      $form_state->setRedirect('baas_functions.project_env_vars', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
      ]);

    } catch (\Exception $e) {
      $this->messenger()->addError('导入失败：' . $e->getMessage());
      $this->getLogger('baas_functions')->error('Environment variables import failed', [
        'project_id' => $project_id,
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 解析导入内容
   */
  protected function parseImportContent(string $content): array {
    $env_vars = [];

    // 尝试JSON格式
    if (str_starts_with(trim($content), '{')) {
      $json_data = json_decode($content, TRUE);
      if (json_last_error() === JSON_ERROR_NONE && is_array($json_data)) {
        foreach ($json_data as $key => $value) {
          if (!is_string($key) || !is_scalar($value)) {
            throw new \Exception('JSON格式错误：键值必须为字符串');
          }
          $env_vars[$key] = (string) $value;
        }
        return $this->validateEnvVars($env_vars);
      }
    }

    // KEY=VALUE 格式
    $lines = explode("\n", $content);
    foreach ($lines as $line_number => $line) {
      $line = trim($line);
      
      // 跳过空行和注释行
      if (empty($line) || str_starts_with($line, '#')) {
        continue;
      }

      // 解析 KEY=VALUE
      if (!str_contains($line, '=')) {
        throw new \Exception(sprintf('第 %d 行格式错误：缺少等号', $line_number + 1));
      }

      [$key, $value] = explode('=', $line, 2);
      $key = trim($key);
      $value = trim($value);

      if (empty($key)) {
        throw new \Exception(sprintf('第 %d 行格式错误：变量名不能为空', $line_number + 1));
      }

      $env_vars[$key] = $value;
    }

    return $this->validateEnvVars($env_vars);
  }

  /**
   * 验证环境变量
   */
  protected function validateEnvVars(array $env_vars): array {
    foreach ($env_vars as $key => $value) {
      // 验证变量名格式
      if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
        throw new \Exception(sprintf('变量名 "%s" 格式错误：必须以大写字母开头，只能包含大写字母、数字和下划线', $key));
      }

      // 验证值长度
      if (strlen($value) > 65535) {
        throw new \Exception(sprintf('变量 "%s" 的值过长：超过 65535 个字符', $key));
      }
    }

    return $env_vars;
  }

  /**
   * 显示预览结果
   */
  protected function displayPreview(array $env_vars): void {
    $count = count($env_vars);
    $this->messenger()->addStatus(sprintf('预览结果：解析到 %d 个有效的环境变量', $count));

    if ($count > 0) {
      $preview_items = [];
      foreach ($env_vars as $key => $value) {
        $preview_items[] = sprintf('<strong>%s</strong> = %s', $key, $this->truncateValue($value));
      }

      $this->messenger()->addStatus([
        '#markup' => '<div class="env-vars-preview"><h4>预览内容：</h4><ul><li>' . 
                     implode('</li><li>', $preview_items) . 
                     '</li></ul></div>',
      ]);
    }
  }

  /**
   * 截断长值用于预览
   */
  protected function truncateValue(string $value): string {
    if (strlen($value) > 50) {
      return substr($value, 0, 47) . '...';
    }
    return $value;
  }
}