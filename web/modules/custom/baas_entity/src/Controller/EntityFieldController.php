<?php

namespace Drupal\baas_entity\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_entity\Service\TemplateManager;
use Drupal\baas_entity\Service\FieldMapper;

/**
 * 实体字段控制器。
 */
class EntityFieldController extends ControllerBase {

  /**
   * 模板管理服务。
   *
   * @var \Drupal\baas_entity\Service\TemplateManager
   */
  protected $templateManager;

  /**
   * 字段映射服务。
   *
   * @var \Drupal\baas_entity\Service\FieldMapper
   */
  protected $fieldMapper;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_entity\Service\TemplateManager $template_manager
   *   模板管理服务。
   * @param \Drupal\baas_entity\Service\FieldMapper $field_mapper
   *   字段映射服务。
   */
  public function __construct(
    TemplateManager $template_manager,
    FieldMapper $field_mapper
  ) {
    $this->templateManager = $template_manager;
    $this->fieldMapper = $field_mapper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('baas_entity.template_manager'),
      $container->get('baas_entity.field_mapper')
    );
  }

  /**
   * 显示实体字段列表。
   *
   * @param int $template_id
   *   模板ID。
   *
   * @return array
   *   渲染数组。
   */
  public function listFields($template_id) {
    // 获取模板
    $template = $this->templateManager->getTemplate($template_id);
    if (!$template) {
      $this->messenger()->addError($this->t('实体模板不存在。'));
      return $this->redirect('baas_entity.list');
    }

    // 获取字段
    $fields = $this->templateManager->getTemplateFields($template_id);

    // 获取字段类型映射
    $field_types = $this->fieldMapper->getSupportedFieldTypes();

    // 创建表头
    $header = [
      'id' => $this->t('ID'),
      'name' => $this->t('名称'),
      'label' => $this->t('标签'),
      'type' => $this->t('类型'),
      'required' => $this->t('必填'),
      'weight' => $this->t('权重'),
      'operations' => $this->t('操作'),
    ];

    $rows = [];

    foreach ($fields as $field) {
      // 创建操作链接
      $operations = [
        'edit' => [
          'title' => $this->t('编辑'),
          'url' => Url::fromRoute('baas_entity.field_edit', ['template_id' => $template_id, 'field_id' => $field->id]),
        ],
        'delete' => [
          'title' => $this->t('删除'),
          'url' => Url::fromRoute('baas_entity.field_delete', ['template_id' => $template_id, 'field_id' => $field->id]),
        ],
      ];

      // 添加行
      $rows[] = [
        'id' => $field->id,
        'name' => $field->name,
        'label' => $field->label,
        'type' => $field_types[$field->type] ?? $field->type,
        'required' => $field->required ? $this->t('是') : $this->t('否'),
        'weight' => $field->weight,
        'operations' => [
          'data' => [
            '#type' => 'dropbutton',
            '#links' => $operations,
          ],
        ],
      ];
    }

    // 创建标题
    $build['title'] = [
      '#type' => 'markup',
      '#markup' => '<h2>' . $this->t('实体模板: @label', ['@label' => $template->label]) . '</h2>',
    ];

    // 添加"添加字段"按钮
    $build['add_field'] = [
      '#type' => 'link',
      '#title' => $this->t('添加字段'),
      '#url' => Url::fromRoute('baas_entity.field_add', ['template_id' => $template_id]),
      '#attributes' => [
        'class' => ['button', 'button--action', 'button--primary'],
      ],
    ];

    // 添加"返回实体模板列表"链接
    $build['back_link'] = [
      '#type' => 'link',
      '#title' => $this->t('返回实体模板列表'),
      '#url' => Url::fromRoute('baas_entity.list'),
      '#attributes' => [
        'class' => ['button'],
      ],
      '#weight' => -10,
    ];

    // 创建表格
    $build['fields_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('没有字段。'),
    ];

    return $build;
  }

}
