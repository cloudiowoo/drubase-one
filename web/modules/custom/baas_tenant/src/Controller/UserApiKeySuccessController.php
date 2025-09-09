<?php

declare(strict_types=1);

namespace Drupal\baas_tenant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * API密钥创建成功页面控制器。
 */
class UserApiKeySuccessController extends ControllerBase
{

  /**
   * 显示API密钥创建成功页面。
   */
  public function success(): array
  {
    $session = \Drupal::request()->getSession();
    $new_api_key = $session->get('new_api_key');
    
    // 检查是否有新创建的密钥信息
    if (!$new_api_key) {
      $this->messenger()->addWarning($this->t('没有找到新创建的API密钥信息。'));
      return $this->redirect('baas_tenant.user_api_keys');
    }

    // 立即清除会话中的密钥信息（安全考虑）
    $session->remove('new_api_key');

    $build = [];

    // 成功消息
    $build['success'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['alert', 'alert-success', 'text-center', 'mb-4']],
    ];

    $build['success']['icon'] = [
      '#markup' => '<div class="mb-3"><i class="fas fa-check-circle fa-3x text-success"></i></div>',
    ];

    $build['success']['title'] = [
      '#markup' => '<h2 class="mb-3">' . $this->t('API密钥创建成功！') . '</h2>',
    ];

    $build['success']['message'] = [
      '#markup' => '<p class="lead">' . $this->t('您的新API密钥已经创建完成。请立即复制并妥善保存，密钥信息只会显示这一次。') . '</p>',
    ];

    // 密钥信息卡片
    $build['key_info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card', 'mb-4']],
    ];

    $build['key_info']['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card-header']],
      '#markup' => '<h5 class="mb-0"><i class="fas fa-key"></i> ' . $this->t('API密钥信息') . '</h5>',
    ];

    $build['key_info']['body'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['card-body']],
    ];

    $build['key_info']['body']['name'] = [
      '#type' => 'item',
      '#title' => $this->t('密钥名称'),
      '#markup' => '<strong>' . $this->t($new_api_key['name']) . '</strong>',
    ];

    $build['key_info']['body']['created'] = [
      '#type' => 'item',
      '#title' => $this->t('创建时间'),
      '#markup' => $new_api_key['created'],
    ];

    $build['key_info']['body']['key'] = [
      '#type' => 'item',
      '#title' => $this->t('API密钥'),
      '#markup' => '<div class="api-key-display">
        <code id="api-key-value" class="d-block p-3 bg-light border rounded">' . $new_api_key['key'] . '</code>
        <button type="button" id="copy-api-key" class="btn btn-primary mt-2">
          <i class="fas fa-copy"></i> ' . $this->t('复制密钥') . '
        </button>
      </div>',
    ];

    // 安全提醒
    $build['security_warning'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['alert', 'alert-warning']],
    ];

    $build['security_warning']['content'] = [
      '#markup' => '<h5><i class="fas fa-exclamation-triangle"></i> ' . $this->t('重要安全提醒') . '</h5>
      <ul class="mb-0">
        <li>' . $this->t('这是您唯一一次看到完整的API密钥，请立即复制并保存到安全的地方') . '</li>
        <li>' . $this->t('不要在客户端代码、配置文件或版本控制系统中暴露API密钥') . '</li>
        <li>' . $this->t('建议将API密钥存储在环境变量或安全的密钥管理系统中') . '</li>
        <li>' . $this->t('如果密钥泄露或丢失，请立即删除此密钥并创建新的') . '</li>
      </ul>',
    ];

    // 使用示例
    $build['usage_example'] = [
      '#type' => 'details',
      '#title' => $this->t('使用示例'),
      '#open' => FALSE,
    ];

    $build['usage_example']['content'] = [
      '#markup' => '<h6>' . $this->t('HTTP请求示例') . '</h6>
      <pre class="bg-light p-3 rounded"><code>curl -X GET "' . \Drupal::request()->getSchemeAndHttpHost() . '/api/v1/projects" \
  -H "Authorization: Bearer ' . $new_api_key['key'] . '" \
  -H "Content-Type: application/json"</code></pre>
      
      <h6>' . $this->t('JavaScript示例') . '</h6>
      <pre class="bg-light p-3 rounded"><code>fetch("' . \Drupal::request()->getSchemeAndHttpHost() . '/api/v1/projects", {
  method: "GET",
  headers: {
    "Authorization": "Bearer ' . $new_api_key['key'] . '",
    "Content-Type": "application/json"
  }
})
.then(response => response.json())
.then(data => console.log(data));</code></pre>',
    ];

    // 操作按钮
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['text-center', 'mt-4']],
    ];

    $build['actions']['manage'] = [
      '#type' => 'link',
      '#title' => $this->t('管理API密钥'),
      '#url' => Url::fromRoute('baas_tenant.user_api_keys'),
      '#attributes' => ['class' => ['btn', 'btn-primary', 'btn-lg', 'me-3']],
    ];

    $build['actions']['projects'] = [
      '#type' => 'link',
      '#title' => $this->t('返回项目管理'),
      '#url' => Url::fromRoute('baas_project.user_list'),
      '#attributes' => ['class' => ['btn', 'btn-secondary', 'btn-lg']],
    ];

    // 添加JavaScript用于复制功能
    $build['#attached']['library'][] = 'baas_tenant/api-key-success';

    return $build;
  }
}