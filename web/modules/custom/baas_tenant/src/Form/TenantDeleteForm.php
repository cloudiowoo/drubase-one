<?php

namespace Drupal\baas_tenant\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_tenant\TenantManagerInterface;

/**
 * 租户删除确认表单.
 */
class TenantDeleteForm extends ConfirmFormBase {

  /**
   * 租户管理服务.
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected $tenantManager;

  /**
   * 要删除的租户ID.
   *
   * @var string
   */
  protected $tenantId;

  /**
   * 租户名称.
   *
   * @var string
   */
  protected $tenantName;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('baas_tenant.manager')
    );
  }

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理服务.
   */
  public function __construct(TenantManagerInterface $tenant_manager) {
    $this->tenantManager = $tenant_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'baas_tenant_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $tenant_id = NULL) {
    $this->tenantId = $tenant_id;

    $tenant = $this->tenantManager->getTenant($tenant_id);
    if (!$tenant) {
      $this->messenger()->addError($this->t('租户不存在.'));
      return $this->redirect('baas_tenant.list');
    }

    $this->tenantName = $tenant['name'];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('您确定要删除租户 %name?', ['%name' => $this->tenantName]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('baas_tenant.list');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('此操作将删除租户及其所有相关数据，此操作不可恢复。');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('删除');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($this->tenantManager->deleteTenant($this->tenantId)) {
      $this->messenger()->addStatus($this->t('租户 %name 已成功删除.', [
        '%name' => $this->tenantName,
      ]));
    }
    else {
      $this->messenger()->addError($this->t('删除租户 %name 时出错.', [
        '%name' => $this->tenantName,
      ]));
    }

    $form_state->setRedirect('baas_tenant.list');
  }

}
