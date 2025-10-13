<?php

namespace Drupal\baas_project\Entity\Dynamic;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * 定义项目级动态实体类: activities.
 *
 * 此文件由BaaS项目系统自动生成。
 * 生成时间: 2025-10-11 22:40:14
 * 表名: baas_856064_activities
 * 实体类型ID: baas_856064_activities
 *
 * 注意：此类不使用@ContentEntityType注解，
 * 实体类型定义通过ProjectEntityRegistry服务动态注册。
 *
 * @ingroup baas_project
 */
class Project7375b0cd6888d012be80cActivities extends ContentEntityBase {

  /**
   * 租户ID。
   */
  const TENANT_ID = '7375b0cd';

  /**
   * 项目ID。
   */
  const PROJECT_ID = '7375b0cd_6888d012be80c';

  /**
   * 实体名称。
   */
  const ENTITY_NAME = 'activities';

  /**
   * {@inheritdoc}
   */
  public function __construct(array $values = [], $entity_type_id = NULL, $bundle = NULL, ?array $translations = NULL) {
    parent::__construct($values, 'baas_856064_activities', NULL, $translations);

    // 确保设置租户ID和项目ID
    if (empty($this->get('tenant_id')->value)) {
      $this->set('tenant_id', self::TENANT_ID);
    }
    if (empty($this->get('project_id')->value)) {
      $this->set('project_id', self::PROJECT_ID);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function preCreate(EntityStorageInterface $storage, array &$values) {
    parent::preCreate($storage, $values);

    // 确保设置租户ID和项目ID
    if (!isset($values['tenant_id'])) {
      $values['tenant_id'] = self::TENANT_ID;
    }
    if (!isset($values['project_id'])) {
      $values['project_id'] = self::PROJECT_ID;
    }

    // 生成UUID
    if (!isset($values['uuid'])) {
      $values['uuid'] = \Drupal::service('uuid')->generate();
    }

    // 设置创建和更新时间
    $current_time = time();
    if (!isset($values['created'])) {
      $values['created'] = $current_time;
    }
    $values['updated'] = $current_time;
  }

  /**
   * 获取租户ID。
   *
   * @return string
   *   租户ID。
   */
  public function getTenantId(): string {
    return $this->get('tenant_id')->value ?? self::TENANT_ID;
  }

  /**
   * 获取项目ID。
   *
   * @return string
   *   项目ID。
   */
  public function getProjectId(): string {
    return $this->get('project_id')->value ?? self::PROJECT_ID;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['tenant_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('租户ID'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::TENANT_ID)
      ->setReadOnly(TRUE)
      ->setSettings([
        'max_length' => 64,
      ]);

    $fields['project_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('项目ID'))
      ->setRequired(TRUE)
      ->setDefaultValue(self::PROJECT_ID)
      ->setReadOnly(TRUE)
      ->setSettings([
        'max_length' => 64,
      ]);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(t('UUID'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE);

    $fields['created'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('创建时间'))
      ->setRequired(TRUE)
      ->setDefaultValueCallback('time')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE);

    $fields['updated'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(t('更新时间'))
      ->setRequired(TRUE)
      ->setDefaultValueCallback('time')
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 21,
      ])
      ->setDisplayConfigurable('view', TRUE);


    $fields['activity_date'] = BaseFieldDefinition::create('datetime')
      ->setLabel(t('activity_date'))
      ->setDescription(t('activity_date'))
      ->setRequired(TRUE)
      ->setSettings([
        'datetime_type' => 'datetime',
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'datetime_default',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'datetime_default',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['activity_time'] = BaseFieldDefinition::create('string')
      ->setLabel(t('activity_time'))
      ->setDescription(t('activity_time'))
      ->setRequired(TRUE)
      ->setTranslatable(FALSE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['creator_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('creator_id'))
      ->setDescription(t('creator_id'))
      ->setRequired(TRUE)
      ->setSettings([
        'target_type' => 'node',
        'handler' => 'default',
        'handler_settings' => [
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 0,
        'settings' => [
          'match_operator' => 'CONTAINS',
          'size' => '60',
          'placeholder' => '',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('description'))
      ->setDescription(t('description'))
      ->setRequired(FALSE)
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['is_creator_demo'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('is_creator_demo'))
      ->setDescription(t('is_creator_demo'))
      ->setRequired(FALSE)
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['is_demo'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('is_demo'))
      ->setDescription(t('is_demo'))
      ->setRequired(FALSE)
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['is_locked'] = BaseFieldDefinition::create('boolean')
      ->setLabel(t('is_locked'))
      ->setDescription(t('is_locked'))
      ->setRequired(FALSE)
      ->setDefaultValue(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['location'] = BaseFieldDefinition::create('text_long')
      ->setLabel(t('location'))
      ->setDescription(t('location'))
      ->setRequired(FALSE)
      ->setTranslatable(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['participants_visibility'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('仅本人'))
      ->setDescription(t('participants_visibility'))
      ->setRequired(TRUE)
      ->setCardinality(1)
      ->setSettings([
        'allowed_values' => [
        'all_visible' => t('全体可见'),
        'team_visible' => t('对内可见'),
        'self_only' => t('仅本人'),
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['players_per_team'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('players_per_team'))
      ->setDescription(t('players_per_team'))
      ->setRequired(TRUE)
      ->setSettings([
        
        
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['sport_type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('其他'))
      ->setDescription(t('sport_type'))
      ->setRequired(TRUE)
      ->setCardinality(1)
      ->setSettings([
        'allowed_values' => [
        'football' => t('足球'),
        'basketball' => t('篮球'),
        'other' => t('其他'),
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('取消'))
      ->setDescription(t('status'))
      ->setRequired(FALSE)
      ->setCardinality(1)
      ->setSettings([
        'allowed_values' => [
        'planned' => t('计划'),
        'active' => t('生效'),
        'compelted' => t('完成'),
        'cancelled' => t('取消'),
        ],
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'list_default',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_select',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['style'] = BaseFieldDefinition::create('string')
      ->setLabel(t('style'))
      ->setDescription(t('style'))
      ->setRequired(FALSE)
      ->setTranslatable(FALSE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['team_count'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('team_count'))
      ->setDescription(t('team_count'))
      ->setRequired(TRUE)
      ->setSettings([
        
        
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'number_integer',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'number',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);
    $fields['title'] = BaseFieldDefinition::create('string')
      ->setLabel(t('title'))
      ->setDescription(t('title'))
      ->setRequired(TRUE)
      ->setTranslatable(FALSE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
