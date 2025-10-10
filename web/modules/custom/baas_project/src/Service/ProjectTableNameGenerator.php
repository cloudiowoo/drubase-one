<?php

declare(strict_types=1);

namespace Drupal\baas_project\Service;

use Drupal\Core\Database\Connection;

/**
 * 项目实体表名生成器服务。
 * 
 * 负责生成精简且唯一的项目实体表名。
 */
class ProjectTableNameGenerator
{

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database
  ) {}

  /**
   * 生成项目实体表名。
   *
   * 策略：
   * 1. 使用短哈希代替完整的租户ID和项目ID
   * 2. 格式：baas_t{tenant_hash}_p{project_hash}_{entity_name}
   * 3. 如果表名仍然过长，截断entity_name并添加哈希
   * 
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   *
   * @return string
   *   生成的表名。
   */
  public function generateTableName(string $tenant_id, string $project_id, string $entity_name): string
  {
    // 生成组合哈希：tenant_id + project_id 的MD5值取前6位
    $combined_hash = substr(md5($tenant_id . '_' . $project_id), 0, 6);
    
    // 基础表名格式：baas_{6hash}_{entity_name}
    $base_name = "baas_{$combined_hash}_{$entity_name}";
    
    // 检查表名长度（PostgreSQL限制为63个字符）
    if (strlen($base_name) <= 63) {
      return $base_name;
    }
    
    // 如果表名过长，截断实体名称并添加实体哈希
    $entity_hash = substr(md5($entity_name), 0, 4);
    $max_entity_length = 63 - strlen("baas_{$combined_hash}__h{$entity_hash}");
    
    if ($max_entity_length > 0) {
      $truncated_entity = substr($entity_name, 0, $max_entity_length);
      return "baas_{$combined_hash}_{$truncated_entity}_h{$entity_hash}";
    }
    
    // 极端情况：完全基于哈希
    return "baas_{$combined_hash}_h{$entity_hash}";
  }

  /**
   * 生成旧格式的表名（用于兼容性）。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   *
   * @return string
   *   旧格式的表名。
   */
  public function generateLegacyTableName(string $tenant_id, string $project_id, string $entity_name): string
  {
    return "tenant_{$tenant_id}_project_{$project_id}_{$entity_name}";
  }

  /**
   * 生成实体类型ID。
   *
   * 为了保持一致性，实体类型ID也使用相同的缩短策略。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   *
   * @return string
   *   实体类型ID。
   */
  public function generateEntityTypeId(string $tenant_id, string $project_id, string $entity_name): string
  {
    // 生成组合哈希：tenant_id + project_id 的MD5值取前6位
    $combined_hash = substr(md5($tenant_id . '_' . $project_id), 0, 6);
    
    return "baas_{$combined_hash}_{$entity_name}";
  }

  /**
   * 生成短哈希。
   *
   * @param string $input
   *   输入字符串。
   *
   * @return string
   *   4位短哈希。
   */
  protected function generateShortHash(string $input): string
  {
    return substr(md5($input), 0, 4);
  }

  /**
   * 从表名解析出租户ID和项目ID。
   *
   * @param string $table_name
   *   表名。
   *
   * @return array|null
   *   包含tenant_id和project_id的数组，失败返回null。
   */
  public function parseTableName(string $table_name): ?array
  {
    // 尝试解析新的组合哈希格式: baas_{6hash}_{entity_name}
    if (preg_match('/^baas_([a-f0-9]{6})_(.+)$/', $table_name, $matches)) {
      $combined_hash = $matches[1];
      $entity_name = $matches[2];
      
      // 从数据库查找对应的租户ID和项目ID
      return $this->findOriginalIdsByCombinedHash($combined_hash, $entity_name);
    }
    
    // 尝试解析旧的分离哈希格式: baas_t{hash}_p{hash}_{entity_name}
    if (preg_match('/^baas_t([a-f0-9]{4,6})_p([a-f0-9]{4,6})_/', $table_name, $matches)) {
      $tenant_hash = $matches[1];
      $project_hash = $matches[2];
      
      // 从数据库查找对应的租户ID和项目ID
      return $this->findOriginalIds($tenant_hash, $project_hash);
    }
    
    // 尝试解析旧格式
    if (preg_match('/^tenant_(.+)_project_(.+)_([^_]+)$/', $table_name, $matches)) {
      return [
        'tenant_id' => $matches[1],
        'project_id' => $matches[2],
        'entity_name' => $matches[3],
      ];
    }
    
    return null;
  }

  /**
   * 根据组合哈希查找原始ID。
   *
   * @param string $combined_hash
   *   组合哈希（tenant_id + project_id的MD5前6位）。
   * @param string $entity_name
   *   实体名称。
   *
   * @return array|null
   *   包含原始ID的数组，失败返回null。
   */
  protected function findOriginalIdsByCombinedHash(string $combined_hash, string $entity_name): ?array
  {
    if (!$this->database->schema()->tableExists('baas_project_config')) {
      return null;
    }

    // 查找所有项目，然后检查组合哈希匹配
    $projects = $this->database->select('baas_project_config', 'p')
      ->fields('p', ['tenant_id', 'project_id'])
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($projects as $project) {
      $computed_hash = substr(md5($project['tenant_id'] . '_' . $project['project_id']), 0, 6);
      
      if ($computed_hash === $combined_hash) {
        return [
          'tenant_id' => $project['tenant_id'],
          'project_id' => $project['project_id'],
          'entity_name' => $entity_name,
        ];
      }
    }
    
    return null;
  }

  /**
   * 根据哈希查找原始ID。
   *
   * @param string $tenant_hash
   *   租户哈希。
   * @param string $project_hash
   *   项目哈希。
   *
   * @return array|null
   *   包含原始ID的数组，失败返回null。
   */
  protected function findOriginalIds(string $tenant_hash, string $project_hash): ?array
  {
    if (!$this->database->schema()->tableExists('baas_project_config')) {
      return null;
    }

    // 查找所有项目，然后检查哈希匹配
    $projects = $this->database->select('baas_project_config', 'p')
      ->fields('p', ['tenant_id', 'project_id'])
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($projects as $project) {
      $computed_tenant_hash = $this->generateShortHash($project['tenant_id']);
      $computed_project_hash = $this->generateShortHash($project['project_id']);
      
      if ($computed_tenant_hash === $tenant_hash && $computed_project_hash === $project_hash) {
        return [
          'tenant_id' => $project['tenant_id'],
          'project_id' => $project['project_id'],
        ];
      }
    }

    return null;
  }

  /**
   * 获取表名映射缓存键。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return string
   *   缓存键。
   */
  public function getTableNameCacheKey(string $tenant_id, string $project_id): string
  {
    return "baas_project_table_name:{$tenant_id}:{$project_id}";
  }

}