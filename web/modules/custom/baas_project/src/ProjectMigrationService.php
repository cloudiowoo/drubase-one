<?php

declare(strict_types=1);

namespace Drupal\baas_project;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
// use Drupal\baas_entity\TemplateManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\baas_project\Event\ProjectEvent;
use Drupal\baas_project\Exception\ProjectException;

/**
 * 项目数据迁移服务。
 *
 * 负责将现有数据迁移到项目级管理架构。
 */
class ProjectMigrationService
{

  protected readonly LoggerChannelInterface $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly ProjectManagerInterface $projectManager,
    // protected readonly TemplateManagerInterface $templateManager,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly EventDispatcherInterface $eventDispatcher,
  ) {
    $this->logger = $loggerFactory->get('baas_project_migration');
  }

  /**
   * 检查是否已经执行过迁移。
   *
   * @return bool
   *   如果已迁移返回TRUE，否则返回FALSE。
   */
  public function isMigrated(): bool
  {
    try {
      // 检查是否存在迁移创建的默认项目
      $default_projects = $this->database->select('baas_project_config', 'p')
        ->condition('machine_name', 'default')
        ->condition('project_id', '%_project_default', 'LIKE')
        ->countQuery()
        ->execute()
        ->fetchField();

      return $default_projects > 0;
    } catch (\Exception $e) {
      $this->logger->error('Failed to check migration status: @error', ['@error' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * 执行数据迁移。
   *
   * @return array
   *   迁移结果统计。
   */
  public function executeMigration(): array
  {
    return $this->executeFullMigration();
  }

  /**
   * 执行完整的数据迁移。
   *
   * @return array
   *   迁移结果统计。
   */
  public function executeFullMigration(): array
  {
    $this->logger->info('Starting full project migration...');

    $results = [
      'tenants_processed' => 0,
      'projects_created' => 0,
      'templates_migrated' => 0,
      'fields_migrated' => 0,
      'errors' => [],
      'start_time' => time(),
    ];

    try {
      // 开始事务
      $transaction = $this->database->startTransaction();

      try {
        // 步骤1：为每个租户创建默认项目
        $results = array_merge($results, $this->createDefaultProjects());

        // 步骤2：迁移实体模板到项目
        $results = array_merge($results, $this->migrateEntityTemplates());

        // 步骤3：迁移实体字段到项目
        $results = array_merge($results, $this->migrateEntityFields());

        // 步骤4：更新租户配置
        $results = array_merge($results, $this->updateTenantConfigurations());

        // 步骤5：验证迁移结果
        $validation_results = $this->validateMigration();
        $results['validation'] = $validation_results;

        if (!$validation_results['success']) {
          throw new ProjectException('Migration validation failed: ' . implode(', ', $validation_results['errors']));
        }

        $results['end_time'] = time();
        $results['duration'] = $results['end_time'] - $results['start_time'];
        $results['success'] = true;

        $this->logger->info('Full project migration completed successfully', $results);

        return $results;
      } catch (\Exception $e) {
        $transaction->rollBack();
        throw $e;
      }
    } catch (\Exception $e) {
      $results['success'] = false;
      $results['error'] = $e->getMessage();
      $results['end_time'] = time();

      $this->logger->error('Full project migration failed: @error', ['@error' => $e->getMessage()]);

      return $results;
    }
  }

  /**
   * 为每个租户创建默认项目。
   *
   * @return array
   *   创建结果统计。
   */
  public function createDefaultProjects(): array
  {
    $this->logger->info('Creating default projects for tenants...');

    $results = [
      'tenants_processed' => 0,
      'projects_created' => 0,
      'errors' => [],
    ];

    try {
      // 获取所有租户
      $tenants = $this->database->select('baas_tenant_config', 't')
        ->fields('t', ['tenant_id', 'name'])
        ->condition('status', 1)
        ->execute()
        ->fetchAll();

      foreach ($tenants as $tenant) {
        try {
          $results['tenants_processed']++;

          // 检查是否已有默认项目
          $default_project_id = $tenant->tenant_id . '_project_default';

          if ($this->projectManager->projectExists($default_project_id)) {
            $this->logger->info('Default project already exists for tenant: @tenant_id', [
              '@tenant_id' => $tenant->tenant_id,
            ]);
            continue;
          }

          // 获取租户所有者
          $owner_uid = $this->getTenantOwner($tenant->tenant_id);
          if (!$owner_uid) {
            $owner_uid = 1; // 默认为管理员
          }

          // 创建默认项目
          $project_data = [
            'name' => 'Default Project',
            'machine_name' => 'default',
            'description' => 'Default project for migrated entities and data',
            'status' => 1,
            'settings' => [
              'is_default' => true,
              'created_by_migration' => true,
              'migration_date' => date('Y-m-d H:i:s'),
            ],
            'owner_uid' => $owner_uid,
          ];

          // 手动插入项目记录（绕过权限检查）
          $this->database->insert('baas_project_config')
            ->fields([
              'project_id' => $default_project_id,
              'tenant_id' => $tenant->tenant_id,
              'name' => $project_data['name'],
              'machine_name' => $project_data['machine_name'],
              'description' => $project_data['description'],
              'status' => $project_data['status'],
              'settings' => json_encode($project_data['settings']),
              'owner_uid' => $project_data['owner_uid'],
              'created' => time(),
              'updated' => time(),
            ])
            ->execute();

          // 添加所有者为项目成员
          $this->database->insert('baas_project_members')
            ->fields([
              'project_id' => $default_project_id,
              'user_id' => $project_data['owner_uid'],
              'role' => 'owner',
              'status' => 1,
              'joined_at' => time(),
              'updated_at' => time(),
            ])
            ->execute();

          $results['projects_created']++;

          $this->logger->info('Created default project for tenant: @tenant_id', [
            '@tenant_id' => $tenant->tenant_id,
          ]);

          // 触发项目创建事件
          $event = new ProjectEvent($default_project_id, array_merge($project_data, [
            'tenant_id' => $tenant->tenant_id,
            'migration' => true,
          ]));
          $this->eventDispatcher->dispatch($event, ProjectEvent::PROJECT_CREATED);
        } catch (\Exception $e) {
          $results['errors'][] = "Failed to create default project for tenant {$tenant->tenant_id}: {$e->getMessage()}";
          $this->logger->error('Failed to create default project for tenant @tenant_id: @error', [
            '@tenant_id' => $tenant->tenant_id,
            '@error' => $e->getMessage(),
          ]);
        }
      }

      return $results;
    } catch (\Exception $e) {
      $results['errors'][] = "Failed to create default projects: {$e->getMessage()}";
      $this->logger->error('Failed to create default projects: @error', ['@error' => $e->getMessage()]);
      return $results;
    }
  }

  /**
   * 迁移实体模板到项目。
   *
   * @return array
   *   迁移结果统计。
   */
  public function migrateEntityTemplates(): array
  {
    $this->logger->info('Migrating entity templates to projects...');

    $results = [
      'templates_migrated' => 0,
      'errors' => [],
    ];

    try {
      // 获取所有未分配项目的实体模板
      $templates = $this->database->select('baas_entity_template', 't')
        ->fields('t', ['id', 'tenant_id', 'name'])
        ->condition('project_id', NULL, 'IS NULL')
        ->execute()
        ->fetchAll();

      foreach ($templates as $template) {
        try {
          // 确定目标项目ID
          $default_project_id = $template->tenant_id . '_project_default';

          // 检查默认项目是否存在
          if (!$this->projectManager->projectExists($default_project_id)) {
            throw new ProjectException("Default project not found for tenant: {$template->tenant_id}");
          }

          // 更新模板的project_id
          $affected_rows = $this->database->update('baas_entity_template')
            ->fields(['project_id' => $default_project_id])
            ->condition('id', $template->id)
            ->execute();

          if ($affected_rows > 0) {
            $results['templates_migrated']++;

            $this->logger->info('Migrated template @template_name (ID: @template_id) to project @project_id', [
              '@template_name' => $template->name,
              '@template_id' => $template->id,
              '@project_id' => $default_project_id,
            ]);
          }
        } catch (\Exception $e) {
          $results['errors'][] = "Failed to migrate template {$template->name} (ID: {$template->id}): {$e->getMessage()}";
          $this->logger->error('Failed to migrate template @template_name: @error', [
            '@template_name' => $template->name,
            '@error' => $e->getMessage(),
          ]);
        }
      }

      return $results;
    } catch (\Exception $e) {
      $results['errors'][] = "Failed to migrate entity templates: {$e->getMessage()}";
      $this->logger->error('Failed to migrate entity templates: @error', ['@error' => $e->getMessage()]);
      return $results;
    }
  }

  /**
   * 迁移实体字段到项目。
   *
   * @return array
   *   迁移结果统计。
   */
  public function migrateEntityFields(): array
  {
    $this->logger->info('Migrating entity fields to projects...');

    $results = [
      'fields_migrated' => 0,
      'errors' => [],
    ];

    try {
      // 通过JOIN查询更新字段的project_id
      $query = "
        UPDATE {baas_entity_field} f
        INNER JOIN {baas_entity_template} t ON f.template_id = t.id
        SET f.project_id = t.project_id
        WHERE f.project_id IS NULL AND t.project_id IS NOT NULL
      ";

      $affected_rows = $this->database->query($query)->rowCount();
      $results['fields_migrated'] = $affected_rows;

      $this->logger->info('Migrated @count entity fields to projects', [
        '@count' => $affected_rows,
      ]);

      return $results;
    } catch (\Exception $e) {
      $results['errors'][] = "Failed to migrate entity fields: {$e->getMessage()}";
      $this->logger->error('Failed to migrate entity fields: @error', ['@error' => $e->getMessage()]);
      return $results;
    }
  }

  /**
   * 更新租户配置以支持项目管理。
   *
   * @return array
   *   更新结果统计。
   */
  public function updateTenantConfigurations(): array
  {
    $this->logger->info('Updating tenant configurations for project support...');

    $results = [
      'tenants_updated' => 0,
      'errors' => [],
    ];

    try {
      // 获取所有租户
      $tenants = $this->database->select('baas_tenant_config', 't')
        ->fields('t', ['tenant_id', 'settings'])
        ->execute()
        ->fetchAll();

      foreach ($tenants as $tenant) {
        try {
          // 解析现有设置
          $settings = json_decode($tenant->settings ?? '{}', true);

          // 添加项目管理相关设置
          $settings['project_management'] = [
            'enabled' => true,
            'default_project_created' => true,
            'migration_completed' => true,
            'migration_date' => date('Y-m-d H:i:s'),
          ];

          // 更新租户设置
          $this->database->update('baas_tenant_config')
            ->fields([
              'settings' => json_encode($settings),
              'updated' => time(),
            ])
            ->condition('tenant_id', $tenant->tenant_id)
            ->execute();

          $results['tenants_updated']++;

          $this->logger->info('Updated tenant configuration: @tenant_id', [
            '@tenant_id' => $tenant->tenant_id,
          ]);
        } catch (\Exception $e) {
          $results['errors'][] = "Failed to update tenant configuration {$tenant->tenant_id}: {$e->getMessage()}";
          $this->logger->error('Failed to update tenant configuration @tenant_id: @error', [
            '@tenant_id' => $tenant->tenant_id,
            '@error' => $e->getMessage(),
          ]);
        }
      }

      return $results;
    } catch (\Exception $e) {
      $results['errors'][] = "Failed to update tenant configurations: {$e->getMessage()}";
      $this->logger->error('Failed to update tenant configurations: @error', ['@error' => $e->getMessage()]);
      return $results;
    }
  }

  /**
   * 验证迁移结果。
   *
   * @return array
   *   验证结果。
   */
  public function validateMigration(): array
  {
    $this->logger->info('Validating migration results...');

    $results = [
      'success' => true,
      'errors' => [],
      'warnings' => [],
      'statistics' => [],
    ];

    try {
      // 检查1：所有租户都有默认项目
      $tenants_without_default_project = $this->database->query("
        SELECT t.tenant_id
        FROM {baas_tenant_config} t
        LEFT JOIN {baas_project_config} p ON CONCAT(t.tenant_id, '_project_default') = p.project_id
        WHERE t.status = 1 AND p.project_id IS NULL
      ")->fetchCol();

      if (!empty($tenants_without_default_project)) {
        $results['errors'][] = 'Tenants without default projects: ' . implode(', ', $tenants_without_default_project);
        $results['success'] = false;
      }

      // 检查2：所有实体模板都分配了项目
      $templates_without_project = $this->database->select('baas_entity_template', 't')
        ->condition('project_id', NULL, 'IS NULL')
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($templates_without_project > 0) {
        $results['errors'][] = "Found {$templates_without_project} entity templates without project assignment";
        $results['success'] = false;
      }

      // 检查3：所有实体字段都分配了项目
      $fields_without_project = $this->database->select('baas_entity_field', 'f')
        ->condition('project_id', NULL, 'IS NULL')
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($fields_without_project > 0) {
        $results['errors'][] = "Found {$fields_without_project} entity fields without project assignment";
        $results['success'] = false;
      }

      // 统计信息
      $results['statistics'] = [
        'total_projects' => $this->database->select('baas_project_config', 'p')->countQuery()->execute()->fetchField(),
        'total_templates' => $this->database->select('baas_entity_template', 't')->countQuery()->execute()->fetchField(),
        'total_fields' => $this->database->select('baas_entity_field', 'f')->countQuery()->execute()->fetchField(),
        'templates_with_project' => $this->database->select('baas_entity_template', 't')
          ->condition('project_id', NULL, 'IS NOT NULL')
          ->countQuery()
          ->execute()
          ->fetchField(),
        'fields_with_project' => $this->database->select('baas_entity_field', 'f')
          ->condition('project_id', NULL, 'IS NOT NULL')
          ->countQuery()
          ->execute()
          ->fetchField(),
      ];

      if ($results['success']) {
        $this->logger->info('Migration validation passed', $results['statistics']);
      } else {
        $this->logger->error('Migration validation failed', $results);
      }

      return $results;
    } catch (\Exception $e) {
      $results['success'] = false;
      $results['errors'][] = "Validation failed: {$e->getMessage()}";
      $this->logger->error('Migration validation error: @error', ['@error' => $e->getMessage()]);
      return $results;
    }
  }

  /**
   * 回滚迁移（仅用于开发和测试）。
   *
   * @return array
   *   回滚结果。
   */
  public function rollbackMigration(): array
  {
    $this->logger->warning('Starting migration rollback...');

    $results = [
      'success' => false,
      'projects_deleted' => 0,
      'templates_reset' => 0,
      'fields_reset' => 0,
      'errors' => [],
    ];

    try {
      // 开始事务
      $transaction = $this->database->startTransaction();

      try {
        // 重置实体字段的project_id
        $fields_reset = $this->database->update('baas_entity_field')
          ->fields(['project_id' => NULL])
          ->execute();
        $results['fields_reset'] = $fields_reset;

        // 重置实体模板的project_id
        $templates_reset = $this->database->update('baas_entity_template')
          ->fields(['project_id' => NULL])
          ->execute();
        $results['templates_reset'] = $templates_reset;

        // 删除迁移创建的默认项目
        $projects_deleted = $this->database->delete('baas_project_config')
          ->condition('machine_name', 'default')
          ->condition('project_id', '%_project_default', 'LIKE')
          ->execute();
        $results['projects_deleted'] = $projects_deleted;

        // 删除项目成员记录
        $this->database->delete('baas_project_members')
          ->condition('project_id', '%_project_default', 'LIKE')
          ->execute();

        $results['success'] = true;

        $this->logger->info('Migration rollback completed', $results);

        return $results;
      } catch (\Exception $e) {
        $transaction->rollBack();
        throw $e;
      }
    } catch (\Exception $e) {
      $results['errors'][] = $e->getMessage();
      $this->logger->error('Migration rollback failed: @error', ['@error' => $e->getMessage()]);
      return $results;
    }
  }

  /**
   * 获取租户所有者。
   *
   * @param string $tenant_id
   *   租户ID。
   *
   * @return int|false
   *   所有者用户ID，未找到时返回FALSE。
   */
  protected function getTenantOwner(string $tenant_id): int|false
  {
    try {
      $owner_uid = $this->database->select('baas_user_tenant_mapping', 'm')
        ->fields('m', ['user_id'])
        ->condition('tenant_id', $tenant_id)
        ->condition('is_owner', 1)
        ->condition('status', 1)
        ->execute()
        ->fetchField();

      return $owner_uid ?: false;
    } catch (\Exception $e) {
      $this->logger->error('Failed to get tenant owner for @tenant_id: @error', [
        '@tenant_id' => $tenant_id,
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }
}
