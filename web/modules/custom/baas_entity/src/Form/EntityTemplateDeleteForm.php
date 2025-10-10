<?php

namespace Drupal\baas_entity\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_entity\Service\TemplateManager;
use Drupal\baas_entity\Service\EntityGenerator;

/**
 * 提供删除实体模板的确认表单。
 */
class EntityTemplateDeleteForm extends ConfirmFormBase {

  /**
   * 模板管理服务。
   *
   * @var \Drupal\baas_entity\Service\TemplateManager
   */
  protected $templateManager;

  /**
   * 实体生成器服务。
   *
   * @var \Drupal\baas_entity\Service\EntityGenerator
   */
  protected $entityGenerator;

  /**
   * 模板ID。
   *
   * @var int
   */
  protected $templateId;

  /**
   * 模板详情。
   *
   * @var object
   */
  protected $template;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_entity\Service\TemplateManager $template_manager
   *   模板管理服务。
   * @param \Drupal\baas_entity\Service\EntityGenerator $entity_generator
   *   实体生成器服务。
   */
  public function __construct(
    TemplateManager $template_manager,
    EntityGenerator $entity_generator
  ) {
    $this->templateManager = $template_manager;
    $this->entityGenerator = $entity_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('baas_entity.template_manager'),
      $container->get('baas_entity.entity_generator')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'baas_entity_template_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $template_id = NULL) {
    $this->templateId = $template_id;

    // 获取模板信息
    $this->template = $this->templateManager->getTemplate($template_id);
    if (!$this->template) {
      $this->messenger()->addError($this->t('找不到指定的实体模板。'));
      return $this->redirect('baas_entity.list');
    }

    // 获取字段数量作为参考
    $fields = $this->templateManager->getTemplateFields($template_id);
    $field_count = count($fields);

    $form = parent::buildForm($form, $form_state);

    // 添加警告信息
    $warning_message = $this->t('警告：此操作将删除模板及其所有字段定义。');
    if ($field_count > 0) {
      $warning_message .= ' ' . $this->t('此模板当前有 @count 个字段定义，删除模板将同时删除这些字段。', [
        '@count' => $field_count,
      ]);
    }

    $form['warning'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--warning">' . $warning_message . '</div>',
      '#weight' => -10,
    ];

    // 添加确认复选框
    $form['confirm_delete'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('我明白此操作的后果，并确认要删除此模板及其所有数据。'),
      '#required' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('确定要删除实体模板 %template 吗？', [
      '%template' => $this->template->label,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('baas_entity.list');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('此操作将永久删除实体模板 %template 及其所有字段和数据。此操作不可撤销。', [
      '%template' => $this->template->label,
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
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    if (!$form_state->getValue('confirm_delete')) {
      $form_state->setErrorByName('confirm_delete', $this->t('请确认您了解删除操作的后果。'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    try {
      // 构建实体类型ID
      $entity_type_id = $this->template->tenant_id . '_' . $this->template->name;

      // 记录操作信息
      $this->logger('baas_entity')->notice('正在删除实体模板 @label (ID: @id)', [
        '@label' => $this->template->label,
        '@id' => $this->templateId,
      ]);

      // 1. 删除实体类型定义（文件和数据表）
      $delete_entity_result = $this->entityGenerator->deleteEntityType($this->template);
      if ($delete_entity_result) {
        $this->logger('baas_entity')->notice('成功删除实体类型: @type', [
          '@type' => $entity_type_id,
        ]);
      } else {
        $this->logger('baas_entity')->error('删除实体类型失败: @type', [
          '@type' => $entity_type_id,
        ]);
      }

      // 2. 从baas_entity_class_files表中删除记录
      try {
        $deleted_records = \Drupal::database()->delete('baas_entity_class_files')
          ->condition('entity_type_id', $entity_type_id)
          ->execute();
        $this->logger('baas_entity')->notice('已从类文件记录表中删除 @count 条记录', [
          '@count' => $deleted_records,
        ]);
      } catch (\Exception $e) {
        $this->logger('baas_entity')->error('删除类文件记录失败: @error', [
          '@error' => $e->getMessage(),
        ]);
      }

      // 3. 删除模板（包括其所有字段）
      $result = $this->templateManager->deleteTemplate($this->templateId);

      if ($result) {
        $this->messenger()->addStatus($this->t('实体模板 %template 及其所有相关数据已成功删除。', [
          '%template' => $this->template->label,
        ]));
      }
      else {
        $this->messenger()->addError($this->t('删除实体模板 %template 失败。', [
          '%template' => $this->template->label,
        ]));
      }

      // 4. 清除缓存以确保实体类型定义被移除
      drupal_flush_all_caches();
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('删除模板时发生错误: @message', [
        '@message' => $e->getMessage(),
      ]));
    }

    // 重定向到模板列表页面
    $form_state->setRedirect('baas_entity.list');
  }

}
