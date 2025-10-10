<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\baas_functions\Service\EnvironmentVariableManager;
use Drupal\baas_project\ProjectManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * 环境变量删除确认表单
 */
class EnvironmentVariableDeleteForm extends ConfirmFormBase {

  protected ?array $env_var = NULL;
  protected string $tenant_id = '';
  protected string $project_id = '';
  protected string $var_name = '';

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
    return 'baas_functions_environment_variable_delete_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->requestStack->getCurrentRequest();
    $this->tenant_id = $request->get('tenant_id');
    $this->project_id = $request->get('project_id');
    $this->var_name = $request->get('var_name');

    // 验证项目存在
    $project = $this->projectManager->getProject($this->project_id);
    if (!$project || $project['tenant_id'] !== $this->tenant_id) {
      $this->messenger()->addError('项目不存在或无权访问。');
      return $form;
    }

    // 加载环境变量
    try {
      $this->env_var = $this->environmentVariableManager->getEnvVar($this->project_id, $this->var_name, FALSE);
      if (!$this->env_var) {
        $this->messenger()->addError('环境变量不存在。');
        return $form;
      }
    } catch (\Exception $e) {
      $this->messenger()->addError('加载环境变量失败：' . $e->getMessage());
      return $form;
    }

    return parent::buildForm($form, $form_state);
  }

  public function getQuestion(): string {
    return sprintf('确定要删除环境变量 "%s" 吗？', $this->var_name);
  }

  public function getDescription(): string {
    return '
      <div class="delete-confirmation-details">
        <p><strong>警告：此操作不可逆！</strong></p>
        <p>删除环境变量后：</p>
        <ul>
          <li>所有使用此环境变量的函数可能会出现错误</li>
          <li>变量值将被永久删除，无法恢复</li>
          <li>建议在删除前确认没有函数依赖此变量</li>
        </ul>
        <p><strong>变量信息：</strong></p>
        <ul>
          <li><strong>变量名：</strong>' . $this->var_name . '</li>
          <li><strong>描述：</strong>' . ($this->env_var['description'] ?: '无描述') . '</li>
          <li><strong>创建时间：</strong>' . date('Y-m-d H:i:s', (int) $this->env_var['created_at']) . '</li>
        </ul>
      </div>
    ';
  }

  public function getConfirmText(): string {
    return '确定删除';
  }

  public function getCancelText(): string {
    return '取消';
  }

  public function getCancelUrl(): Url {
    return new Url('baas_functions.project_env_vars', [
      'tenant_id' => $this->tenant_id,
      'project_id' => $this->project_id,
    ]);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $this->environmentVariableManager->deleteEnvVar($this->project_id, $this->var_name);
      
      $this->messenger()->addStatus(sprintf('环境变量 "%s" 已成功删除。', $this->var_name));
      
      $this->getLogger('baas_functions')->info('Environment variable deleted', [
        'project_id' => $this->project_id,
        'var_name' => $this->var_name,
        'user_id' => $this->currentUser()->id(),
      ]);

    } catch (\Exception $e) {
      $this->messenger()->addError('删除失败：' . $e->getMessage());
      $this->getLogger('baas_functions')->error('Environment variable deletion failed', [
        'project_id' => $this->project_id,
        'var_name' => $this->var_name,
        'error' => $e->getMessage(),
      ]);
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }
}