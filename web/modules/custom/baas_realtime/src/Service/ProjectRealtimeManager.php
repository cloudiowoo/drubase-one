<?php

declare(strict_types=1);

namespace Drupal\baas_realtime\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_project\Service\ProjectEntityTemplateManager;

/**
 * 项目实时功能管理服务。
 */
class ProjectRealtimeManager
{

  /**
   * Logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly UnifiedPermissionCheckerInterface $permissionChecker,
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly ProjectEntityTemplateManager $entityTemplateManager,
    protected readonly RealtimeManagerInterface $realtimeManager,
  ) {
    $this->logger = $loggerFactory->get('baas_realtime');
  }

  /**
   * 获取项目实时配置。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return array
   *   配置数组。
   */
  public function getProjectRealtimeConfig(string $project_id, string $tenant_id): array {
    try {
      $result = $this->database->select('baas_realtime_project_config', 'c')
        ->fields('c')
        ->condition('project_id', $project_id)
        ->condition('tenant_id', $tenant_id)
        ->execute()
        ->fetchAssoc();

      if ($result) {
        $result['enabled_entities'] = json_decode($result['enabled_entities'] ?? '[]', TRUE);
        $result['settings'] = json_decode($result['settings'] ?? '{}', TRUE);
        return $result;
      }

      // 返回默认配置
      return [
        'project_id' => $project_id,
        'tenant_id' => $tenant_id,
        'enabled' => 0,
        'enabled_entities' => [],
        'settings' => [],
        'created_at' => time(),
        'updated_at' => time(),
      ];

    } catch (\Exception $e) {
      $this->logger->error('Failed to get project realtime config: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * 保存项目实时配置。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $tenant_id
   *   租户ID。
   * @param array $config
   *   配置数据。
   * @param int $user_id
   *   操作用户ID。
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE。
   */
  public function saveProjectRealtimeConfig(string $project_id, string $tenant_id, array $config, int $user_id): bool {
    try {
      // 检查权限
      if (!$this->permissionChecker->checkProjectPermission($user_id, $project_id, 'manage realtime')) {
        $this->logger->warning('User lacks permission to manage realtime', [
          'user_id' => $user_id,
          'project_id' => $project_id,
        ]);
        return FALSE;
      }

      // 验证配置数据
      $enabled = (int) ($config['enabled'] ?? 0);
      $enabled_entities = $config['enabled_entities'] ?? [];
      $settings = $config['settings'] ?? [];

      // 验证实体表名
      if ($enabled && !empty($enabled_entities)) {
        // 从表名中提取实体名称进行验证
        $entity_names = [];
        $combined_hash = substr(md5($tenant_id . '_' . $project_id), 0, 6);
        $table_prefix = "baas_{$combined_hash}_";
        
        foreach ($enabled_entities as $table_name) {
          if (strpos($table_name, $table_prefix) === 0) {
            $entity_names[] = substr($table_name, strlen($table_prefix));
          }
        }
        
        $valid_entities = $this->validateProjectEntities($project_id, $tenant_id, $entity_names);
        if (empty($valid_entities)) {
          $this->logger->warning('No valid entities found for realtime', [
            'project_id' => $project_id,
            'entities' => $entity_names,
          ]);
          return FALSE;
        }
        $enabled_entities = $valid_entities;
      }

      // 检查是否已存在配置
      $existing = $this->database->select('baas_realtime_project_config', 'c')
        ->fields('c', ['id'])
        ->condition('project_id', $project_id)
        ->condition('tenant_id', $tenant_id)
        ->execute()
        ->fetchField();

      $data = [
        'enabled' => $enabled,
        'enabled_entities' => json_encode($enabled_entities),
        'settings' => json_encode($settings),
        'updated_by' => $user_id,
        'updated_at' => time(),
      ];

      if ($existing) {
        // 更新现有配置
        $updated = $this->database->update('baas_realtime_project_config')
          ->fields($data)
          ->condition('id', $existing)
          ->execute();

        $success = $updated > 0;
      } else {
        // 创建新配置
        $data['project_id'] = $project_id;
        $data['tenant_id'] = $tenant_id;
        $data['created_by'] = $user_id;
        $data['created_at'] = time();

        $id = $this->database->insert('baas_realtime_project_config')
          ->fields($data)
          ->execute();

        $success = $id > 0;
      }

      if ($success) {
        // 应用配置更改
        $this->applyRealtimeConfiguration($project_id, $tenant_id, $enabled_entities, (bool) $enabled);

        $this->logger->info('Project realtime config saved', [
          'project_id' => $project_id,
          'enabled' => $enabled,
          'entities_count' => count($enabled_entities),
          'user_id' => $user_id,
        ]);
      }

      return $success;

    } catch (\Exception $e) {
      $this->logger->error('Failed to save project realtime config: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 验证项目实体。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $tenant_id
   *   租户ID。
   * @param array $entity_names
   *   实体名称列表。
   *
   * @return array
   *   有效的实体表名列表。
   */
  protected function validateProjectEntities(string $project_id, string $tenant_id, array $entity_names): array {
    try {
      $valid_entities = [];
      
      // 直接从数据库获取项目的实体模板
      $query = $this->database->select('baas_entity_template', 'et')
        ->fields('et', ['id', 'name', 'label', 'description'])
        ->condition('tenant_id', $tenant_id)
        ->condition('project_id', $project_id)
        ->condition('status', 1)
        ->orderBy('name');
      
      $templates = $query->execute()->fetchAll();
      
      foreach ($entity_names as $entity_name) {
        // 检查实体是否存在
        $found = FALSE;
        foreach ($templates as $template) {
          if ($template->name === $entity_name) {
            // 构建表名
            $combined_hash = substr(md5($tenant_id . '_' . $project_id), 0, 6);
            $table_name = "baas_{$combined_hash}_{$entity_name}";
            
            // 检查表是否存在
            if ($this->database->schema()->tableExists($table_name)) {
              $valid_entities[] = $table_name;
              $found = TRUE;
              break;
            }
          }
        }
        
        if (!$found) {
          $this->logger->warning('Entity not found for realtime: @entity', [
            '@entity' => $entity_name,
            'project_id' => $project_id,
          ]);
        }
      }

      return $valid_entities;

    } catch (\Exception $e) {
      $this->logger->error('Failed to validate project entities: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * 应用实时配置。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $tenant_id
   *   租户ID。
   * @param array $enabled_entities
   *   启用的实体表名。
   * @param bool $enabled
   *   是否启用。
   */
  protected function applyRealtimeConfiguration(string $project_id, string $tenant_id, array $enabled_entities, bool $enabled): void {
    try {
      if ($enabled && !empty($enabled_entities)) {
        // 为启用的表添加触发器
        foreach ($enabled_entities as $table_name) {
          $this->realtimeManager->addTableTrigger($table_name);
        }

        // 移除未启用表的触发器
        $this->removeUnusedTriggers($project_id, $tenant_id, $enabled_entities);
      } else {
        // 移除所有项目表的触发器
        $this->removeAllProjectTriggers($project_id, $tenant_id);
      }

    } catch (\Exception $e) {
      $this->logger->error('Failed to apply realtime configuration: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 移除未使用的触发器。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $tenant_id
   *   租户ID。
   * @param array $enabled_entities
   *   启用的实体表名。
   */
  protected function removeUnusedTriggers(string $project_id, string $tenant_id, array $enabled_entities): void {
    try {
      // 获取项目的所有表
      $combined_hash = substr(md5($tenant_id . '_' . $project_id), 0, 6);
      $table_prefix = "baas_{$combined_hash}_";
      
      $all_tables = $this->database->schema()->findTables($table_prefix . '%');
      
      foreach ($all_tables as $table_name) {
        if (!in_array($table_name, $enabled_entities)) {
          $this->realtimeManager->removeTableTrigger($table_name);
        }
      }

    } catch (\Exception $e) {
      $this->logger->error('Failed to remove unused triggers: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 移除所有项目触发器。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $tenant_id
   *   租户ID。
   */
  protected function removeAllProjectTriggers(string $project_id, string $tenant_id): void {
    try {
      // 获取项目的所有表
      $combined_hash = substr(md5($tenant_id . '_' . $project_id), 0, 6);
      $table_prefix = "baas_{$combined_hash}_";
      
      $all_tables = $this->database->schema()->findTables($table_prefix . '%');
      
      foreach ($all_tables as $table_name) {
        $this->realtimeManager->removeTableTrigger($table_name);
      }

    } catch (\Exception $e) {
      $this->logger->error('Failed to remove all project triggers: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 获取项目的可用实体列表。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return array
   *   实体列表。
   */
  public function getAvailableEntities(string $project_id, string $tenant_id): array {
    try {
      $entities = [];
      
      // 直接从数据库获取项目的实体模板
      $query = $this->database->select('baas_entity_template', 'et')
        ->fields('et', ['id', 'name', 'label', 'description'])
        ->condition('tenant_id', $tenant_id)
        ->condition('project_id', $project_id)
        ->condition('status', 1)
        ->orderBy('name');
      
      $templates = $query->execute()->fetchAll();
      
      foreach ($templates as $template) {
        $combined_hash = substr(md5($tenant_id . '_' . $project_id), 0, 6);
        $table_name = "baas_{$combined_hash}_{$template->name}";
        
        // 检查表是否存在
        if ($this->database->schema()->tableExists($table_name)) {
          $entities[] = [
            'id' => $template->id,
            'name' => $template->name,
            'label' => $template->label ?: $template->name,
            'display_name' => $template->label ?: $template->name,
            'description' => $template->description ?: '',
            'table_name' => $table_name,
            'enabled' => $this->isEntityRealtimeEnabled($project_id, $tenant_id, $template->name),
            'has_trigger' => $this->isEntityRealtimeEnabled($project_id, $tenant_id, $template->name),
          ];
        }
      }

      return $entities;

    } catch (\Exception $e) {
      $this->logger->error('Failed to get available entities: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * 检查表是否有实时触发器。
   *
   * @param string $table_name
   *   表名。
   *
   * @return bool
   *   有触发器返回TRUE，否则返回FALSE。
   */
  protected function hasRealtimeTrigger(string $table_name): bool {
    try {
      $trigger_name = "realtime_trigger_{$table_name}";
      
      $result = $this->database->query(
        "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_name = :trigger_name",
        [':trigger_name' => $trigger_name]
      )->fetchField();

      return $result > 0;

    } catch (\Exception $e) {
      $this->logger->error('Failed to check trigger existence: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 切换实体的实时状态。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $tenant_id
   *   租户ID。
   * @param string $entity_name
   *   实体名称。
   * @param bool $enabled
   *   是否启用。
   * @param int $user_id
   *   操作用户ID。
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE。
   */
  public function toggleEntityRealtime(string $project_id, string $tenant_id, string $entity_name, bool $enabled, int $user_id): bool {
    try {
      // 获取当前配置
      $config = $this->getProjectRealtimeConfig($project_id, $tenant_id);
      $enabled_entities = $config['enabled_entities'] ?? [];

      // 构建表名
      $combined_hash = substr(md5($tenant_id . '_' . $project_id), 0, 6);
      $table_name = "baas_{$combined_hash}_{$entity_name}";

      if ($enabled) {
        // 添加到启用列表
        if (!in_array($table_name, $enabled_entities)) {
          $enabled_entities[] = $table_name;
        }
      } else {
        // 从启用列表移除
        $enabled_entities = array_filter($enabled_entities, function($item) use ($table_name) {
          return $item !== $table_name;
        });
      }

      // 保存配置
      $config['enabled'] = !empty($enabled_entities) ? 1 : 0;
      $config['enabled_entities'] = array_values($enabled_entities);

      return $this->saveProjectRealtimeConfig($project_id, $tenant_id, $config, $user_id);

    } catch (\Exception $e) {
      $this->logger->error('Failed to toggle entity realtime: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 检查实体是否启用了实时功能。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $tenant_id
   *   租户ID。
   * @param string $entity_name
   *   实体名称。
   *
   * @return bool
   *   是否启用。
   */
  protected function isEntityRealtimeEnabled(string $project_id, string $tenant_id, string $entity_name): bool {
    try {
      $config = $this->getProjectRealtimeConfig($project_id, $tenant_id);
      
      if (!$config['enabled']) {
        return FALSE;
      }
      
      $combined_hash = substr(md5($tenant_id . '_' . $project_id), 0, 6);
      $table_name = "baas_{$combined_hash}_{$entity_name}";
      
      return in_array($table_name, $config['enabled_entities'] ?? []);
    } catch (\Exception $e) {
      $this->logger->error('Failed to check entity realtime status: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}