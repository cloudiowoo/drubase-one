<?php

namespace Drupal\baas_entity\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_entity\Service\TemplateManager;
use Drupal\baas_tenant\TenantManagerInterface;

/**
 * 实体模板表单。
 */
class EntityTemplateForm extends FormBase {

  /**
   * 模板管理服务。
   *
   * @var \Drupal\baas_entity\Service\TemplateManager
   */
  protected $templateManager;

  /**
   * 租户管理服务。
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected $tenantManager;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_entity\Service\TemplateManager $template_manager
   *   模板管理服务。
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理服务。
   */
  public function __construct(
    TemplateManager $template_manager,
    TenantManagerInterface $tenant_manager
  ) {
    $this->templateManager = $template_manager;
    $this->tenantManager = $tenant_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('baas_entity.template_manager'),
      $container->get('baas_tenant.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'baas_entity_template_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $template_id = NULL) {
    // 存储模板ID
    $form_state->set('template_id', $template_id);

    // 如果提供了模板ID，获取模板详情
    $template = NULL;
    if ($template_id) {
      $template = $this->templateManager->getTemplate($template_id);
      if (!$template) {
        $this->messenger()->addError($this->t('实体模板不存在。'));
        return $this->redirect('baas_entity.list');
      }
    }

    // 获取所有租户作为选项
    $tenant_options = [];
    $tenants = $this->tenantManager->listTenants();
    foreach ($tenants as $tenant) {
      $tenant_options[$tenant['tenant_id']] = $tenant['name'];
    }

    // 租户选择
    $form['tenant_id'] = [
      '#type' => 'select',
      '#title' => $this->t('租户'),
      '#options' => $tenant_options,
      '#required' => TRUE,
      '#default_value' => $template ? $template->tenant_id : NULL,
      '#disabled' => $template ? TRUE : FALSE,
    ];

    // 实体标签
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('实体标签'),
      '#description' => $this->t('实体的显示名称。'),
      '#required' => TRUE,
      '#default_value' => $template ? $template->label : '',
    ];

    // 实体名称
    $form['name'] = [
      '#type' => 'machine_name',
      '#title' => $this->t('实体名称'),
      '#description' => $this->t('实体的机器名称，只能包含小写字母、数字和下划线。'),
      '#required' => TRUE,
      '#default_value' => $template ? $template->name : '',
      '#disabled' => $template ? TRUE : FALSE,
      '#machine_name' => [
        'exists' => [$this, 'entityNameExists'],
        'source' => ['label'],
      ],
    ];

    // 实体描述
    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('实体描述'),
      '#description' => $this->t('实体的详细描述。'),
      '#default_value' => $template ? $template->description : '',
    ];

    // 状态
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用'),
      '#description' => $this->t('启用此实体模板。'),
      '#default_value' => $template ? $template->status : TRUE,
    ];

    // 高级设置
    $form['settings'] = [
      '#type' => 'details',
      '#title' => $this->t('高级设置'),
      '#open' => FALSE,
    ];

    $settings = $template ? $template->settings : [];

    // 添加高级设置字段
    $form['settings']['translatable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('可翻译'),
      '#description' => $this->t('启用此实体的多语言支持。'),
      '#default_value' => isset($settings['translatable']) ? $settings['translatable'] : TRUE,
    ];

    $form['settings']['revisionable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('可修订'),
      '#description' => $this->t('启用此实体的修订历史。'),
      '#default_value' => isset($settings['revisionable']) ? $settings['revisionable'] : FALSE,
    ];

    $form['settings']['publishable'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('可发布'),
      '#description' => $this->t('启用此实体的发布状态控制。'),
      '#default_value' => isset($settings['publishable']) ? $settings['publishable'] : TRUE,
    ];

    // 提交按钮
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $template ? $this->t('更新实体模板') : $this->t('创建实体模板'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('取消'),
      '#url' => $this->getCancelUrl(),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * 检查实体名称是否已存在。
   *
   * @param string $name
   *   实体名称。
   * @param array $element
   *   表单元素。
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   表单状态。
   *
   * @return bool
   *   是否已存在。
   */
  public function entityNameExists($name, array $element, FormStateInterface $form_state) {
    $tenant_id = $form_state->getValue('tenant_id');
    if (!$tenant_id) {
      return FALSE;
    }

    $template = $this->templateManager->getTemplateByName($tenant_id, $name);
    return $template !== FALSE;
  }

  /**
   * 获取取消URL。
   *
   * @return \Drupal\Core\Url
   *   URL对象。
   */
  protected function getCancelUrl() {
    return \Drupal\Core\Url::fromRoute('baas_entity.list');
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $tenant_id = $form_state->getValue('tenant_id');
    $name = $form_state->getValue('name');

    // 检查租户是否存在
    $tenant = $this->tenantManager->getTenant($tenant_id);
    if (!$tenant) {
      $form_state->setErrorByName('tenant_id', $this->t('所选租户不存在。'));
    }

    // 如果是添加新模板，检查名称是否已存在
    $template_id = $form_state->get('template_id');
    if (!$template_id) {
      if ($this->templateManager->getTemplateByName($tenant_id, $name)) {
        $form_state->setErrorByName('name', $this->t('该租户下已存在同名的实体模板。'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $template_id = $form_state->get('template_id');
    $values = $form_state->getValues();

    $settings = [
      'translatable' => $values['translatable'] ?? FALSE,
      'revisionable' => $values['revisionable'] ?? FALSE,
      'publishable' => $values['publishable'] ?? FALSE,
    ];

    try {
      if ($template_id) {
        // 更新现有模板
        $result = $this->templateManager->updateTemplate($template_id, [
          'label' => $values['label'],
          'description' => $values['description'],
          'status' => $values['status'],
          'settings' => $settings,
        ]);

        if ($result) {
          $this->messenger()->addStatus($this->t('实体模板已更新。'));
        }
        else {
          $this->messenger()->addError($this->t('更新实体模板失败。'));
        }
      }
      else {
        // 创建新模板
        $template_id = $this->templateManager->createTemplate(
          $values['tenant_id'],
          $values['name'],
          $values['label'],
          $values['description'],
          $settings
        );

        if ($template_id) {
          $this->messenger()->addStatus($this->t('实体模板已创建。'));

          // 转到字段列表页面
          $form_state->setRedirect('baas_entity.fields', ['template_id' => $template_id]);
          return;
        }
        else {
          $this->messenger()->addError($this->t('创建实体模板失败。'));
        }
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('发生错误: @message', ['@message' => $e->getMessage()]));
    }

    // 返回实体模板列表
    $form_state->setRedirect('baas_entity.list');
  }

}
