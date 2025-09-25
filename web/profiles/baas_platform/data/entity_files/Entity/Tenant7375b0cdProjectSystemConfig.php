<?php

namespace Drupal\baas_project\Entity\Dynamic;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * 定义项目级动态实体类: system_config.
 *
 * 此文件由BaaS项目系统自动生成。
 * 生成时间: 2025-07-30 10:13:53
 * 表名: baas_00403b_system_config
 * 实体类型ID: baas_00403b_system_config
 *
 * 注意：此类不使用@ContentEntityType注解，
 * 实体类型定义通过ProjectEntityRegistry服务动态注册。
 *
 * @ingroup baas_project
 */
class Tenant7375b0cdProjectSystemConfig extends ContentEntityBase {

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
  const ENTITY_NAME = 'system_config';

  /**
   * {@inheritdoc}
   */
  public function __construct(array $values = [], $entity_type_id = NULL, $bundle = NULL, ?array $translations = NULL) {
    parent::__construct($values, 'baas_00403b_system_config', NULL, $translations);

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


    $fields['key'] = BaseFieldDefinition::create('string')
      ->setLabel(t('key'))
      ->setDescription(t('key'))
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
    $fields['value'] = BaseFieldDefinition::create('map')
      ->setLabel(t('value'))
      ->setDescription(t('value'))
      ->setRequired(TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'map_default',
        'weight' => 0,
      ])
      ->setDisplayOptions('form', [
        'type' => 'map_default',
        'weight' => 0,
        'settings' => [
          'placeholder' => 'Enter valid JSON data',
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayConfigurable('view', TRUE);

    return $fields;
  }

}
