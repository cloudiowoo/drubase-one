<?php

namespace Drupal\baas_project\Entity\Dynamic;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * 定义项目级动态实体类: positions.
 *
 * 此文件由BaaS项目系统自动生成。
 * 生成时间: 2025-10-11 22:40:14
 * 表名: baas_856064_positions
 * 实体类型ID: baas_856064_positions
 *
 * 注意：此类不使用@ContentEntityType注解，
 * 实体类型定义通过ProjectEntityRegistry服务动态注册。
 *
 * @ingroup baas_project
 */
class Project7375b0cd6888d012be80cPositions extends ContentEntityBase {

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
  const ENTITY_NAME = 'positions';

  /**
   * {@inheritdoc}
   */
  public function __construct(array $values = [], $entity_type_id = NULL, $bundle = NULL, ?array $translations = NULL) {
    parent::__construct($values, 'baas_856064_positions', NULL, $translations);

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


    $fields['activity_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('activity_id'))
      ->setDescription(t('activity_id'))
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
    $fields['custom_user_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('custom_user_name'))
      ->setDescription(t('自定义用户名称，用于创建者指定匿名用户（非系统用户）占用座位'))
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
    $fields['name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('name'))
      ->setDescription(t('name'))
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
    $fields['team_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('team_id'))
      ->setDescription(t('team_id'))
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
    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('user_id'))
      ->setDescription(t('user_id'))
      ->setRequired(FALSE)
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

    return $fields;
  }

}
