<?php

namespace Drupal\baas_entity\Service;

use Drupal\Core\Entity\EntityLastInstalledSchemaRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityDefinitionUpdateManager;
use Drupal\Core\Entity\EntityTypeListenerInterface;
use Drupal\Core\Field\FieldStorageDefinitionListenerInterface;

/**
 * 装饰 EntityDefinitionUpdateManager 以忽略 BaaS 动态实体。
 */
class BaasEntityDefinitionUpdateManager extends EntityDefinitionUpdateManager {

  /**
   * 构造函数。
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityLastInstalledSchemaRepositoryInterface $entity_last_installed_schema_repository,
    EntityFieldManagerInterface $entity_field_manager,
    EntityTypeListenerInterface $entity_type_listener,
    FieldStorageDefinitionListenerInterface $field_storage_definition_listener
  ) {
    parent::__construct($entity_type_manager, $entity_last_installed_schema_repository, $entity_field_manager, $entity_type_listener, $field_storage_definition_listener);
  }

  /**
   * {@inheritdoc}
   */
  public function getChangeList() {
    $change_list = parent::getChangeList();

    // 过滤掉所有 baas_ 开头的动态实体
    foreach ($change_list as $entity_type_id => $change_data) {
      if (strpos($entity_type_id, 'baas_') === 0) {
        unset($change_list[$entity_type_id]);
      }
    }

    return $change_list;
  }

  /**
   * {@inheritdoc}
   */
  public function getChangeSummary() {
    // 获取过滤后的变更列表
    $filtered_change_list = $this->getChangeList();

    // 如果没有变更，返回空数组
    if (empty($filtered_change_list)) {
      return [];
    }

    // 调用父类方法处理剩余的变更
    // 但因为我们已经过滤了 change list，所以不会包含 baas_ 实体
    return parent::getChangeSummary();
  }

  /**
   * {@inheritdoc}
   */
  public function needsUpdates() {
    $change_list = $this->getChangeList();
    return !empty($change_list);
  }

}