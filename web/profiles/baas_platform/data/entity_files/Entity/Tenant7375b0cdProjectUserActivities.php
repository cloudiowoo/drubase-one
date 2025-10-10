<?php

namespace Drupal\baas_project\Entity\Dynamic;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * 定义项目级动态实体类: user_activities.
 *
 * 此文件由BaaS项目系统自动生成。
 * 生成时间: 2025-07-30 20:24:21
 * 表名: baas_00403b_user_activities
 * 实体类型ID: baas_00403b_user_activities
 *
 * 注意：此类不使用@ContentEntityType注解，
 * 实体类型定义通过ProjectEntityRegistry服务动态注册。
 *
 * @ingroup baas_project
 */
class Tenant7375b0cdProjectUserActivities extends ContentEntityBase {

  /**
   * 租户ID。
   */
  const TENANT_ID = 'tenant_7375b0cd';

  /**
   * 项目ID。
   */
  const PROJECT_ID = 'tenant_7375b0cd_project_6888d012be80c';

  /**
   * 实体名称。
   */
  const ENTITY_NAME = 'user_activities';

  /**
   * {@inheritdoc}
   */
  public function __construct(array $values = [], $entity_type_id = NULL, $bundle = NULL, ?array $translations = NULL) {
    parent::__construct($values, 'baas_00403b_user_activities', NULL, $translations);

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
    $fields['display_name'] = BaseFieldDefinition::create('string')
      ->setLabel(t('display_name'))
      ->setDescription(t('用户在特定活动中的显示名称，可以与用户主名不同'))
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
    $fields['position_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('position_id'))
      ->setDescription(t('position_id'))
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
    $fields['status'] = BaseFieldDefinition::create('list_string')
      ->setLabel(t('不参加'))
      ->setDescription(t('status'))
      ->setRequired(TRUE)
      ->setCardinality(1)
      ->setSettings([
        'allowed_values' => [
        'joined' => t('已加入'),
        'pending' => t('未加入'),
        'left' => t('不参加'),
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
    $fields['team_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('team_id'))
      ->setDescription(t('team_id'))
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
    $fields['user_id'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('user_id'))
      ->setDescription(t('user_id'))
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

    return $fields;
  }

}
