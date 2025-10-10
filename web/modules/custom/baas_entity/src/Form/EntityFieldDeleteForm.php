<?php

namespace Drupal\baas_entity\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_entity\Service\FieldMapper;
use Drupal\baas_entity\Service\TemplateManager;

/**
 * 提供删除实体字段的确认表单。
 */
class EntityFieldDeleteForm extends ConfirmFormBase {

  /**
   * 字段映射服务。
   *
   * @var \Drupal\baas_entity\Service\FieldMapper
   */
  protected $fieldMapper;

  /**
   * 模板管理服务。
   *
   * @var \Drupal\baas_entity\Service\TemplateManager
   */
  protected $templateManager;

  /**
   * 字段ID。
   *
   * @var int
   */
  protected $fieldId;

  /**
   * 模板ID。
   *
   * @var int
   */
  protected $templateId;

  /**
   * 字段详情。
   *
   * @var object
   */
  protected $field;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_entity\Service\FieldMapper $field_mapper
   *   字段映射服务。
   * @param \Drupal\baas_entity\Service\TemplateManager $template_manager
   *   模板管理服务。
   */
  public function __construct(
    FieldMapper $field_mapper,
    TemplateManager $template_manager
  ) {
    $this->fieldMapper = $field_mapper;
    $this->templateManager = $template_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('baas_entity.field_mapper'),
      $container->get('baas_entity.template_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'baas_entity_field_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $template_id = NULL, $field_id = NULL) {
    $this->templateId = $template_id;
    $this->fieldId = $field_id;

    // 获取字段信息
    $this->field = $this->templateManager->getField($field_id);
    if (!$this->field) {
      $this->messenger()->addError($this->t('找不到指定的字段。'));
      return $this->redirect('baas_entity.fields', ['template_id' => $template_id]);
    }

    // 获取模板信息
    $template = $this->templateManager->getTemplate($template_id);
    if (!$template) {
      $this->messenger()->addError($this->t('找不到指定的实体模板。'));
      return $this->redirect('baas_entity.list');
    }

    $form = parent::buildForm($form, $form_state);

    // 添加警告信息
    $form['warning'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning">' . $this->t('警告：此操作将删除字段的所有数据，且无法恢复。') . '</div>',
      '#weight' => -10,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('确定要删除字段 %field 吗？', ['%field' => $this->field->label]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('baas_entity.fields', ['template_id' => $this->templateId]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('此操作将永久删除字段 %field 及其所有数据。此操作不可撤销。', [
      '%field' => $this->field->label,
    ]);
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
    try {
      // 删除字段
      $result = $this->templateManager->deleteField($this->fieldId);

      if ($result) {
        $this->messenger()->addStatus($this->t('字段 %field 已成功删除。', [
          '%field' => $this->field->label,
        ]));
      }
      else {
        $this->messenger()->addError($this->t('删除字段 %field 失败。', [
          '%field' => $this->field->label,
        ]));
      }
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('删除字段时发生错误: @message', [
        '@message' => $e->getMessage(),
      ]));
    }

    // 重定向到字段列表页面
    $form_state->setRedirect('baas_entity.fields', ['template_id' => $this->templateId]);
  }

}
