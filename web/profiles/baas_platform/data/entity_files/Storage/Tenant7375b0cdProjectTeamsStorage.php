<?php

namespace Drupal\baas_project\Storage\Dynamic;

use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Language\LanguageInterface;

/**
 * 项目级动态实体存储类: teams.
 *
 * 此文件由BaaS项目系统自动生成。
 * 生成时间: 2025-07-30 13:48:12
 */
class Tenant7375b0cdProjectTeamsStorage extends SqlContentEntityStorage {

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
  const ENTITY_NAME = 'teams';

  /**
   * {@inheritdoc}
   */
  public function loadByProperties(array $values = []) {
    // 确保只加载当前租户和项目的实体
    $values['tenant_id'] = self::TENANT_ID;
    $values['project_id'] = self::PROJECT_ID;

    return parent::loadByProperties($values);
  }

  /**
   * 根据租户和项目过滤查询。
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   查询对象。
   */
  protected function applyTenantProjectFilter($query) {
    $query->condition('tenant_id', self::TENANT_ID);
    $query->condition('project_id', self::PROJECT_ID);
  }

  /**
   * 获取租户专用的实体列表。
   *
   * @param int $limit
   *   限制数量。
   * @param int $offset
   *   偏移量。
   *
   * @return array
   *   实体列表。
   */
  public function loadTenantProjectEntities(int $limit = 50, int $offset = 0): array {
    $query = $this->getQuery()
      ->condition('tenant_id', self::TENANT_ID)
      ->condition('project_id', self::PROJECT_ID)
      ->range($offset, $limit)
      ->sort('created', 'DESC');

    $entity_ids = $query->execute();

    return $entity_ids ? $this->loadMultiple($entity_ids) : [];
  }

  /**
   * 统计租户项目实体数量。
   *
   * @return int
   *   实体数量。
   */
  public function countTenantProjectEntities(): int {
    $query = $this->getQuery()
      ->condition('tenant_id', self::TENANT_ID)
      ->condition('project_id', self::PROJECT_ID)
      ->count();

    return (int) $query->execute();
  }

}
