<?php

declare(strict_types=1);

namespace Drupal\baas_tenant\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_auth\Service\ApiKeyManagerInterface;

/**
 * 用户删除API密钥确认表单。
 */
class UserApiKeyDeleteForm extends ConfirmFormBase
{

  /**
   * API密钥管理服务。
   *
   * @var \Drupal\baas_auth\Service\ApiKeyManagerInterface
   */
  protected $apiKeyManager;

  /**
   * 要删除的API密钥ID。
   *
   * @var int
   */
  protected $keyId;

  /**
   * API密钥信息。
   *
   * @var array|null
   */
  protected $apiKey;

  /**
   * 构造函数。
   */
  public function __construct(ApiKeyManagerInterface $api_key_manager)
  {
    $this->apiKeyManager = $api_key_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_auth.api_key_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'baas_tenant_user_api_key_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?int $key_id = NULL): array
  {
    if (!$key_id) {
      $this->messenger()->addError($this->t('未指定要删除的API密钥。'));
      return $this->redirect('baas_tenant.user_api_keys');
    }

    $this->keyId = $key_id;
    $this->apiKey = $this->apiKeyManager->getApiKey($key_id);

    if (!$this->apiKey) {
      $this->messenger()->addError($this->t('找不到指定的API密钥。'));
      return $this->redirect('baas_tenant.user_api_keys');
    }

    // 验证API密钥是否属于当前用户
    $current_user_id = (int) $this->currentUser()->id();
    if ($this->apiKey['user_id'] != $current_user_id) {
      $this->messenger()->addError($this->t('您没有权限删除此API密钥。'));
      return $this->redirect('baas_tenant.user_api_keys');
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion()
  {
    return $this->t('确定要删除API密钥 "@name" 吗？', [
      '@name' => $this->apiKey['name'] ?? $this->t('未知密钥'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription()
  {
    $created_date = $this->apiKey['created'] ? date('Y-m-d H:i:s', (int) $this->apiKey['created']) : $this->t('未知');
    $last_used = $this->apiKey['last_used'] ? date('Y-m-d H:i:s', (int) $this->apiKey['last_used']) : $this->t('从未使用');
    
    return $this->t('此操作将永久删除API密钥，无法恢复。<br><br>
      <strong>密钥信息：</strong><br>
      • 名称：@name<br>
      • 创建时间：@created<br>
      • 最后使用：@last_used<br>
      • 权限：@permissions<br><br>
      <strong class="text-danger">警告：删除后使用此密钥的应用程序将无法继续访问API服务。</strong>', [
      '@name' => $this->apiKey['name'],
      '@created' => $created_date,
      '@last_used' => $last_used,
      '@permissions' => !empty($this->apiKey['permissions']) && is_array($this->apiKey['permissions']) ? implode(', ', $this->apiKey['permissions']) : $this->t('无特殊权限'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText()
  {
    return $this->t('确定删除');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText()
  {
    return $this->t('取消');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl()
  {
    return new Url('baas_tenant.user_api_keys');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    try {
      $result = $this->apiKeyManager->deleteApiKey($this->keyId);
      
      if ($result) {
        $this->messenger()->addStatus($this->t('API密钥 "@name" 已成功删除。', [
          '@name' => $this->apiKey['name'],
        ]));
        
        $this->getLogger('baas_tenant')->info('用户 @user 删除了API密钥 @key_id (@name)', [
          '@user' => $this->currentUser()->getAccountName(),
          '@key_id' => $this->keyId,
          '@name' => $this->apiKey['name'],
        ]);
      } else {
        $this->messenger()->addError($this->t('删除API密钥失败，请重试。'));
      }
    } catch (\Exception $e) {
      $this->getLogger('baas_tenant')->error('删除API密钥失败: @error', ['@error' => $e->getMessage()]);
      $this->messenger()->addError($this->t('删除API密钥时发生错误，请重试。'));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }
}