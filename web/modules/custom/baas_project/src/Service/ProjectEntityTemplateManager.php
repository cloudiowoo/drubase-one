<?php

declare(strict_types=1);

namespace Drupal\baas_project\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\baas_project\Service\ProjectEntityGenerator;
use Drupal\baas_project\Service\ProjectTableNameGenerator;
use Drupal\baas_project\Exception\ProjectException;

/**
 * 项目实体模板管理服务。
 * 
 * 遵循 Drupal Entity 流转规范的实体模板管理。
 */
class ProjectEntityTemplateManager
{

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly CacheBackendInterface $cache,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly AccountInterface $currentUser,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly ProjectEntityGenerator $entityGenerator,
    protected readonly ProjectTableNameGenerator $tableNameGenerator
  ) {}

  /**
   * 创建实体模板。
   *
   * @param array $values
   *   模板数据。
   *
   * @return array
   *   创建结果。
   */
  public function createEntityTemplate(array $values): array
  {
    $result = [
      'success' => FALSE,
      'template_id' => NULL,
      'messages' => [],
      'errors' => [],
    ];

    try {
      // 1. 验证必需字段
      $this->validateTemplateData($values);

      // 2. 触发 presave 事件
      $this->moduleHandler->invokeAll('baas_project_entity_template_presave', [$values, NULL]);

      // 3. 准备数据
      $values['created'] = time();
      $values['updated'] = time();
      $values['status'] = 1;

      // 4. 插入数据库
      $template_id = $this->database->insert('baas_entity_template')
        ->fields($values)
        ->execute();

      if (!$template_id) {
        throw new ProjectException('创建实体模板失败');
      }

      $result['template_id'] = $template_id;

      // 5. 触发 insert 事件
      $this->moduleHandler->invokeAll('baas_project_entity_template_insert', [$template_id, $values]);

      // 6. 创建动态实体表
      $this->createDynamicEntityTable($values['tenant_id'], $values['project_id'], $values['name'], $template_id);

      // 7. 生成动态实体类文件
      $entity_generation_result = $this->entityGenerator->generateProjectEntityFiles($template_id);
      if (!$entity_generation_result) {
        $result['messages'][] = '实体模板已创建，但生成动态实体类文件时出现问题';
      } else {
        $result['messages'][] = '实体模板已创建，动态实体类文件已生成';
      }

      // 8. 触发 postsave 事件
      $this->moduleHandler->invokeAll('baas_project_entity_template_postsave', [$template_id, $values, 'insert']);

      // 9. 清理缓存
      $this->clearEntityTypeCache($values['tenant_id'], $values['project_id'], $values['name']);

      $result['success'] = TRUE;
      \Drupal::logger('baas_project')->notice('创建实体模板成功: @name (ID: @id)', [
        '@name' => $values['name'],
        '@id' => $template_id,
      ]);

    } catch (\Exception $e) {
      $result['errors'][] = $e->getMessage();
      \Drupal::logger('baas_project')->error('创建实体模板失败: @error', ['@error' => $e->getMessage()]);
    }

    return $result;
  }

  /**
   * 更新实体模板。
   *
   * @param string $template_id
   *   模板ID。
   * @param array $values
   *   更新数据。
   *
   * @return array
   *   更新结果。
   */
  public function updateEntityTemplate(string $template_id, array $values): array
  {
    $result = [
      'success' => FALSE,
      'template_id' => $template_id,
      'messages' => [],
      'errors' => [],
    ];

    try {
      // 1. 加载现有模板
      $original_template = $this->loadEntityTemplate($template_id);
      if (!$original_template) {
        throw new ProjectException('实体模板不存在');
      }

      // 2. 验证更新数据
      $this->validateTemplateData($values, $template_id);

      // 3. 触发 presave 事件
      $this->moduleHandler->invokeAll('baas_project_entity_template_presave', [$values, $original_template]);

      // 4. 准备更新数据
      $values['updated'] = time();
      unset($values['created'], $values['id']); // 保护字段

      // 5. 更新数据库
      $affected_rows = $this->database->update('baas_entity_template')
        ->fields($values)
        ->condition('id', $template_id)
        ->execute();

      if ($affected_rows === 0) {
        throw new ProjectException('更新实体模板失败');
      }

      // 6. 触发 update 事件
      $this->moduleHandler->invokeAll('baas_project_entity_template_update', [$template_id, $values, $original_template]);

      // 7. 重新生成动态实体类文件
      $entity_generation_result = $this->entityGenerator->generateProjectEntityFiles($template_id);
      if (!$entity_generation_result) {
        $result['messages'][] = '实体模板已更新，但重新生成动态实体类文件时出现问题';
      } else {
        $result['messages'][] = '实体模板已更新，动态实体类文件已重新生成';
      }

      // 8. 触发 postsave 事件
      $this->moduleHandler->invokeAll('baas_project_entity_template_postsave', [$template_id, $values, 'update']);

      // 9. 清理缓存
      $this->clearEntityTypeCache($original_template['tenant_id'], $original_template['project_id'], $original_template['name']);

      $result['success'] = TRUE;
      \Drupal::logger('baas_project')->notice('更新实体模板成功: @name (ID: @id)', [
        '@name' => $values['name'] ?? $original_template['name'],
        '@id' => $template_id,
      ]);

    } catch (\Exception $e) {
      $result['errors'][] = $e->getMessage();
      \Drupal::logger('baas_project')->error('更新实体模板失败: @error', ['@error' => $e->getMessage()]);
    }

    return $result;
  }

  /**
   * 创建实体字段。
   *
   * @param array $values
   *   字段数据。
   *
   * @return array
   *   创建结果。
   */
  public function createEntityField(array $values): array
  {
    $result = [
      'success' => FALSE,
      'field_id' => NULL,
      'messages' => [],
      'errors' => [],
    ];

    try {
      // 1. 验证字段数据
      $this->validateFieldData($values);

      // 2. 触发 presave 事件
      $this->moduleHandler->invokeAll('baas_project_entity_field_presave', [$values, NULL]);

      // 3. 准备数据
      $values['created'] = time();
      $values['updated'] = time();

      // 4. 插入数据库
      $field_id = $this->database->insert('baas_entity_field')
        ->fields($values)
        ->execute();

      if (!$field_id) {
        throw new ProjectException('创建实体字段失败');
      }

      $result['field_id'] = $field_id;

      // 5. 触发 insert 事件
      $this->moduleHandler->invokeAll('baas_project_entity_field_insert', [$field_id, $values]);

      // 6. 重新生成动态实体类文件
      $entity_generation_result = $this->entityGenerator->generateProjectEntityFiles((string) $values['template_id']);
      if (!$entity_generation_result) {
        $result['messages'][] = '字段已创建，但重新生成动态实体类文件时出现问题';
      } else {
        $result['messages'][] = '字段已创建，动态实体类文件已重新生成';
      }

      // 7. 更新动态实体表结构
      $template = $this->loadEntityTemplate($values['template_id']);
      if ($template) {
        $this->updateDynamicEntityTable($template['tenant_id'], $template['project_id'], $values['template_id'], $values, 'add');
      }

      // 8. 触发 postsave 事件
      $this->moduleHandler->invokeAll('baas_project_entity_field_postsave', [$field_id, $values, 'insert']);

      // 9. 清理缓存
      if ($template) {
        $this->clearEntityTypeCache($template['tenant_id'], $template['project_id'], $template['name']);
      }

      $result['success'] = TRUE;
      \Drupal::logger('baas_project')->notice('创建实体字段成功: @name (ID: @id)', [
        '@name' => $values['name'],
        '@id' => $field_id,
      ]);

    } catch (\Exception $e) {
      $result['errors'][] = $e->getMessage();
      \Drupal::logger('baas_project')->error('创建实体字段失败: @error', ['@error' => $e->getMessage()]);
    }

    return $result;
  }

  /**
   * 更新实体字段。
   *
   * @param string $field_id
   *   字段ID。
   * @param array $values
   *   更新数据。
   *
   * @return array
   *   更新结果。
   */
  public function updateEntityField(string $field_id, array $values): array
  {
    $result = [
      'success' => FALSE,
      'field_id' => $field_id,
      'messages' => [],
      'errors' => [],
    ];

    try {
      // 1. 加载现有字段
      $original_field = $this->loadEntityField($field_id);
      if (!$original_field) {
        throw new ProjectException('实体字段不存在');
      }

      // 2. 验证更新数据
      $this->validateFieldData($values, $field_id);

      // 3. 触发 presave 事件
      $this->moduleHandler->invokeAll('baas_project_entity_field_presave', [$values, $original_field]);

      // 4. 准备更新数据
      $values['updated'] = time();
      unset($values['created'], $values['id']); // 保护字段

      // 5. 更新数据库
      $affected_rows = $this->database->update('baas_entity_field')
        ->fields($values)
        ->condition('id', $field_id)
        ->execute();

      if ($affected_rows === 0) {
        throw new ProjectException('更新实体字段失败');
      }

      // 6. 触发 update 事件
      $this->moduleHandler->invokeAll('baas_project_entity_field_update', [$field_id, $values, $original_field]);

      // 7. 重新生成动态实体类文件
      $entity_generation_result = $this->entityGenerator->generateProjectEntityFiles((string) $original_field['template_id']);
      if (!$entity_generation_result) {
        $result['messages'][] = '字段已更新，但重新生成动态实体类文件时出现问题';
      } else {
        $result['messages'][] = '字段已更新，动态实体类文件已重新生成';
      }

      // 8. 更新动态实体表结构
      $template = $this->loadEntityTemplate($original_field['template_id']);
      if ($template) {
        $this->updateDynamicEntityTable($template['tenant_id'], $template['project_id'], $original_field['template_id'], $values, 'update');
      }

      // 9. 触发 postsave 事件
      $this->moduleHandler->invokeAll('baas_project_entity_field_postsave', [$field_id, $values, 'update']);

      // 10. 清理缓存
      if ($template) {
        $this->clearEntityTypeCache($template['tenant_id'], $template['project_id'], $template['name']);
      }

      $result['success'] = TRUE;
      \Drupal::logger('baas_project')->notice('更新实体字段成功: @name (ID: @id)', [
        '@name' => $values['name'] ?? $original_field['name'],
        '@id' => $field_id,
      ]);

    } catch (\Exception $e) {
      $result['errors'][] = $e->getMessage();
      \Drupal::logger('baas_project')->error('更新实体字段失败: @error', ['@error' => $e->getMessage()]);
    }

    return $result;
  }

  /**
   * 验证模板数据。
   *
   * @param array $values
   *   模板数据。
   * @param string|null $template_id
   *   模板ID（更新时）。
   *
   * @throws ProjectException
   *   验证失败时抛出异常。
   */
  protected function validateTemplateData(array $values, ?string $template_id = NULL): void
  {
    // 验证必需字段
    $required_fields = ['tenant_id', 'project_id', 'name', 'label'];
    foreach ($required_fields as $field) {
      if (!isset($values[$field]) || empty(trim($values[$field]))) {
        throw new ProjectException("字段 {$field} 不能为空");
      }
    }

    // 清理字符串值
    $values['tenant_id'] = trim($values['tenant_id']);
    $values['project_id'] = trim($values['project_id']);
    $values['name'] = trim($values['name']);
    $values['label'] = trim($values['label']);

    // 验证机器名格式
    if (!preg_match('/^[a-z0-9_]+$/', $values['name'])) {
      throw new ProjectException('机器名只能包含小写字母、数字和下划线');
    }

    // 验证机器名长度（考虑Drupal 32字符限制）
    $max_entity_name_length = $this->calculateMaxEntityNameLength($values['tenant_id'], $values['project_id']);
    if (strlen($values['name']) < 2) {
      throw new ProjectException('机器名长度不能少于2个字符');
    }
    if (strlen($values['name']) > $max_entity_name_length) {
      throw new ProjectException("机器名长度不能超过 {$max_entity_name_length} 个字符（受Drupal 32字符实体类型ID限制）");
    }

    // 验证标签长度
    if (strlen($values['label']) < 1 || strlen($values['label']) > 255) {
      throw new ProjectException('标签长度必须在1-255个字符之间');
    }

    // 检查机器名重复
    $query = $this->database->select('baas_entity_template', 'e')
      ->condition('project_id', $values['project_id'])
      ->condition('name', $values['name'])
      ->condition('status', 1);

    if ($template_id) {
      $query->condition('id', $template_id, '!=');
    }

    if ($query->countQuery()->execute()->fetchField()) {
      throw new ProjectException('机器名已存在');
    }
  }

  /**
   * 验证字段数据。
   *
   * @param array $values
   *   字段数据。
   * @param string|null $field_id
   *   字段ID（更新时）。
   *
   * @throws ProjectException
   *   验证失败时抛出异常。
   */
  protected function validateFieldData(array &$values, ?string $field_id = NULL): void
  {
    // 验证必需字段
    $required_fields = ['template_id', 'name', 'label', 'type'];
    foreach ($required_fields as $field) {
      if (empty($values[$field])) {
        throw new ProjectException("字段 {$field} 不能为空");
      }
    }
    
    // 确保description不为空，如果为空则使用label
    if (empty($values['description'])) {
      $values['description'] = $values['label'];
    }

    // 验证机器名格式
    if (!preg_match('/^[a-z0-9_]+$/', $values['name'])) {
      throw new ProjectException('字段机器名只能包含小写字母、数字和下划线');
    }

    // 检查保留字
    $reserved_names = ['id', 'uuid', 'created', 'updated', 'tenant_id', 'project_id'];
    if (in_array($values['name'], $reserved_names)) {
      throw new ProjectException("字段机器名 '{$values['name']}' 是保留字，请使用其他名称");
    }

    // 检查字段名重复
    if ($this->database->schema()->tableExists('baas_entity_field')) {
      $query = $this->database->select('baas_entity_field', 'f')
        ->condition('template_id', $values['template_id'])
        ->condition('name', $values['name']);

      if ($field_id) {
        $query->condition('id', $field_id, '!=');
      }

      if ($query->countQuery()->execute()->fetchField()) {
        throw new ProjectException('字段名已存在');
      }
    }
  }

  /**
   * 清理实体类型缓存。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   */
  protected function clearEntityTypeCache(string $tenant_id, string $project_id, string $entity_name): void
  {
    try {
      // 清理实体类型定义缓存
      $this->entityTypeManager->clearCachedDefinitions();

      // 清理相关缓存标签
      $cache_tags = [
        'entity_types',
        'entity_bundles',
        "baas_project_entity:{$tenant_id}:{$project_id}:{$entity_name}",
      ];

      foreach ($cache_tags as $tag) {
        \Drupal::cache()->invalidate($tag);
      }

      // 清理路由缓存 - 暂时禁用以避免ltrim()错误
      // 路由重建会触发实体类型发现过程，可能导致ltrim()错误
      // \Drupal::service('router.builder')->rebuild();
      
      // 使用更轻量级的缓存清理方法
      \Drupal::cache()->deleteAll();
      \Drupal::cache('entity')->deleteAll();
      \Drupal::cache('render')->deleteAll();

      \Drupal::logger('baas_project')->notice('清理实体类型缓存: @entity', [
        '@entity' => $entity_name,
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('清理缓存失败: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * 创建动态实体数据表。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   * @param string $template_id
   *   模板ID。
   */
  protected function createDynamicEntityTable(string $tenant_id, string $project_id, string $entity_name, string $template_id): void
  {
    try {
      // 获取字段列表
      $fields = $this->getEntityTemplateFields($template_id);
      
      // 创建项目级实体表（使用新的精简表名）
      $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);
      
      if ($this->database->schema()->tableExists($table_name)) {
        \Drupal::logger('baas_project')->notice('实体表已存在: @table', ['@table' => $table_name]);
        return;
      }

      // 创建基本表结构
      $schema = [
        'description' => "存储项目 {$project_id} 中 {$entity_name} 实体的数据",
        'fields' => [
          'id' => [
            'type' => 'serial',
            'not null' => TRUE,
            'description' => '主键',
          ],
          'uuid' => [
            'type' => 'varchar',
            'length' => 128,
            'not null' => TRUE,
            'description' => 'UUID标识',
          ],
          'created' => [
            'type' => 'int',
            'not null' => TRUE,
            'description' => '创建时间',
            'default' => 0,
          ],
          'updated' => [
            'type' => 'int',
            'not null' => TRUE,
            'description' => '修改时间',
            'default' => 0,
          ],
          'tenant_id' => [
            'type' => 'varchar',
            'length' => 64,
            'not null' => TRUE,
            'description' => '租户ID',
            'default' => $tenant_id,
          ],
          'project_id' => [
            'type' => 'varchar',
            'length' => 64,
            'not null' => TRUE,
            'description' => '项目ID',
            'default' => $project_id,
          ],
        ],
        'primary key' => ['id'],
        'unique keys' => [
          'uuid' => ['uuid'],
        ],
        'indexes' => [
          'tenant_id' => ['tenant_id'],
          'project_id' => ['project_id'],
          'created' => ['created'],
        ],
      ];

      // 添加自定义字段到表结构
      foreach ($fields as $field) {
        $field_name = $field['name'];
        $field_type = $field['type'];

        // 跳过已存在的基础字段
        if (in_array($field_name, ['id', 'uuid', 'created', 'updated', 'tenant_id', 'project_id'])) {
          continue;
        }

        // 获取字段数据库定义
        $field_schema = $this->getFieldSchema($field_type, $field);
        if ($field_schema) {
          $schema['fields'][$field_name] = $field_schema;
        }
      }

      // 创建表
      $this->database->schema()->createTable($table_name, $schema);
      
      \Drupal::logger('baas_project')->notice('创建实体数据表: @table', ['@table' => $table_name]);

    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('创建实体数据表失败: @error', ['@error' => $e->getMessage()]);
      throw new ProjectException('创建实体数据表失败: ' . $e->getMessage());
    }
  }

  /**
   * 更新动态实体表结构。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $template_id
   *   模板ID。
   * @param array $field_data
   *   字段数据。
   * @param string $operation
   *   操作类型。
   */
  protected function updateDynamicEntityTable(string $tenant_id, string $project_id, string $template_id, array $field_data, string $operation): void
  {
    try {
      $template = $this->loadEntityTemplate($template_id);
      if (!$template) {
        \Drupal::logger('baas_project')->error('updateDynamicEntityTable: 模板不存在, template_id=@id', ['@id' => $template_id]);
        return;
      }

      $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $template['name']);
      
      \Drupal::logger('baas_project')->info('updateDynamicEntityTable: operation=@op, field=@field, table=@table', [
        '@op' => $operation,
        '@field' => $field_data['name'] ?? 'unknown',
        '@table' => $table_name,
      ]);
      
      if (!$this->database->schema()->tableExists($table_name)) {
        \Drupal::logger('baas_project')->warning('实体表不存在: @table', ['@table' => $table_name]);
        return;
      }

      if ($operation === 'add') {
        // 特殊处理JSON字段类型
        if ($field_data['type'] === 'json') {
          $this->addJsonFieldToTable($table_name, $field_data);
        } elseif ($field_data['type'] === 'boolean') {
          // 特殊处理Boolean字段类型，使用PostgreSQL原生boolean类型
          $this->addBooleanFieldToTable($table_name, $field_data);
        } else {
          // 添加其他类型字段
          $field_schema = $this->getFieldSchema($field_data['type'], $field_data);
          if ($field_schema) {
            $this->database->schema()->addField($table_name, $field_data['name'], $field_schema);
            \Drupal::logger('baas_project')->notice('添加字段到表: @field -> @table', [
              '@field' => $field_data['name'],
              '@table' => $table_name,
            ]);
          }
        }
      } elseif ($operation === 'update') {
        // 更新字段（如果需要）
        if ($field_data['type'] === 'json') {
          // 对于JSON字段，可能需要特殊处理
          \Drupal::logger('baas_project')->notice('JSON字段更新暂时跳过: @field', ['@field' => $field_data['name']]);
        } else {
          $field_schema = $this->getFieldSchema($field_data['type'], $field_data);
          if ($field_schema) {
            $this->database->schema()->changeField($table_name, $field_data['name'], $field_data['name'], $field_schema);
            \Drupal::logger('baas_project')->notice('更新表字段: @field -> @table', [
              '@field' => $field_data['name'],
              '@table' => $table_name,
            ]);
          }
        }
      } elseif ($operation === 'remove') {
        // 删除字段
        \Drupal::logger('baas_project')->info('🔥 updateDynamicEntityTable[remove] - 尝试删除字段: @field from @table', [
          '@field' => $field_data['name'],
          '@table' => $table_name,
        ]);
        
        if ($this->database->schema()->fieldExists($table_name, $field_data['name'])) {
          \Drupal::logger('baas_project')->info('🔥 字段存在，准备删除: @field', ['@field' => $field_data['name']]);
          $this->database->schema()->dropField($table_name, $field_data['name']);
          \Drupal::logger('baas_project')->notice('🔥 ✅ 成功从表中删除字段: @field -> @table', [
            '@field' => $field_data['name'],
            '@table' => $table_name,
          ]);
        } else {
          \Drupal::logger('baas_project')->warning('🔥 ⚠️ 要删除的字段在表中不存在: @field -> @table', [
            '@field' => $field_data['name'],
            '@table' => $table_name,
          ]);
        }
      }

    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('更新实体表结构失败: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * 添加JSON字段到表中。
   *
   * @param string $table_name
   *   表名。
   * @param array $field_data
   *   字段数据。
   */
  protected function addJsonFieldToTable(string $table_name, array $field_data): void
  {
    try {
      $field_name = $field_data['name'];
      $required = !empty($field_data['required']);
      
      // 使用原生SQL添加JSONB字段
      $null_clause = $required ? 'NOT NULL' : '';
      $default_clause = $required ? "DEFAULT '{}'::jsonb" : '';
      
      $query = "ALTER TABLE {{$table_name}} ADD COLUMN {$field_name} JSONB {$null_clause} {$default_clause}";
      $this->database->query($query);
      
      \Drupal::logger('baas_project')->notice('成功添加JSON字段到表: @field -> @table', [
        '@field' => $field_name,
        '@table' => $table_name,
      ]);
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('添加JSON字段失败: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * 添加Boolean字段到表中，使用PostgreSQL原生boolean类型。
   *
   * @param string $table_name
   *   表名。
   * @param array $field_data
   *   字段数据。
   */
  protected function addBooleanFieldToTable(string $table_name, array $field_data): void
  {
    try {
      $field_name = $field_data['name'];
      $settings = is_string($field_data['settings']) ? json_decode($field_data['settings'], TRUE) : ($field_data['settings'] ?? []);
      
      // 从字段设置中获取默认值
      $default_value = 'FALSE';
      if (isset($settings['default_value'])) {
        if ($settings['default_value'] === '1' || $settings['default_value'] === 1 || $settings['default_value'] === true || $settings['default_value'] === 'true') {
          $default_value = 'TRUE';
        }
      }
      
      // 使用原生SQL添加Boolean字段
      $query = "ALTER TABLE {{$table_name}} ADD COLUMN {$field_name} BOOLEAN NOT NULL DEFAULT {$default_value}";
      $this->database->query($query);
      
      \Drupal::logger('baas_project')->notice('成功添加Boolean字段到表: @field -> @table (default: @default)', [
        '@field' => $field_name,
        '@table' => $table_name,
        '@default' => $default_value,
      ]);
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('添加Boolean字段失败: @error', ['@error' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * 获取字段数据库模式定义。
   *
   * @param string $type
   *   字段类型。
   * @param array $field
   *   字段数据。
   *
   * @return array|null
   *   字段模式定义。
   */
  protected function getFieldSchema(string $type, array $field): ?array
  {
    $settings = is_string($field['settings']) ? json_decode($field['settings'], TRUE) : ($field['settings'] ?? []);
    
    switch ($type) {
      case 'string':
        return [
          'type' => 'varchar',
          'length' => $settings['max_length'] ?? 255,
          'not null' => !empty($field['required']),
          'description' => $field['description'] ?? '',
        ];

      case 'text':
        return [
          'type' => 'text',
          'size' => 'big',
          'not null' => !empty($field['required']),
          'description' => $field['description'] ?? '',
        ];

      case 'integer':
        return [
          'type' => 'int',
          'not null' => !empty($field['required']),
          'description' => $field['description'] ?? '',
        ];

      case 'decimal':
        return [
          'type' => 'numeric',
          'precision' => $settings['precision'] ?? 10,
          'scale' => $settings['scale'] ?? 2,
          'not null' => !empty($field['required']),
          'description' => $field['description'] ?? '',
        ];

      case 'boolean':
        // 在 PostgreSQL 中使用原生 boolean 类型
        // 从字段设置中获取默认值
        $default_value = FALSE;
        if (isset($settings['default_value'])) {
          if ($settings['default_value'] === '1' || $settings['default_value'] === 1 || $settings['default_value'] === true || $settings['default_value'] === 'true') {
            $default_value = TRUE;
          }
        }
        
        return [
          'type' => 'varchar',  // Drupal Schema API 需要这个
          'length' => 5,        // 用于存储 'true'/'false'
          'pgsql_type' => 'boolean',  // PostgreSQL 实际类型
          'mysql_type' => 'tinyint',  // MySQL 兼容
          'sqlite_type' => 'boolean', // SQLite 兼容
          'not null' => TRUE,
          'default' => $default_value ? 'true' : 'false',
          'description' => $field['description'] ?? '',
        ];

      case 'datetime':
        return [
          'type' => 'varchar',
          'length' => 20,
          'not null' => !empty($field['required']),
          'description' => $field['description'] ?? '',
        ];

      case 'email':
        return [
          'type' => 'varchar',
          'length' => 254,
          'not null' => !empty($field['required']),
          'description' => $field['description'] ?? '',
        ];

      case 'url':
        return [
          'type' => 'varchar',
          'length' => 2048,
          'not null' => !empty($field['required']),
          'description' => $field['description'] ?? '',
        ];

      case 'json':
        // Use text type with large size for PostgreSQL JSONB compatibility
        return [
          'type' => 'text',
          'size' => 'big',
          'pgsql_type' => 'jsonb',
          'not null' => !empty($field['required']),
          'description' => $field['description'] ?? '',
        ];

      case 'reference':
        // 实体引用字段使用整数类型存储引用的实体ID
        return [
          'type' => 'int',
          'not null' => !empty($field['required']),
          'default' => $field['required'] ? NULL : 0,
          'description' => $field['description'] ?? '',
        ];

      default:
        return [
          'type' => 'varchar',
          'length' => 255,
          'not null' => !empty($field['required']),
          'description' => $field['description'] ?? '',
        ];
    }
  }

  /**
   * 加载实体模板。
   *
   * @param string $template_id
   *   模板ID。
   *
   * @return array|null
   *   模板数据。
   */
  protected function loadEntityTemplate(string $template_id): ?array
  {
    $template = $this->database->select('baas_entity_template', 'e')
      ->fields('e')
      ->condition('id', $template_id)
      ->execute()
      ->fetch(\PDO::FETCH_ASSOC);

    return $template ?: NULL;
  }

  /**
   * 加载实体字段。
   *
   * @param string $field_id
   *   字段ID。
   *
   * @return array|null
   *   字段数据。
   */
  protected function loadEntityField(string $field_id): ?array
  {
    if (!$this->database->schema()->tableExists('baas_entity_field')) {
      return NULL;
    }

    $field = $this->database->select('baas_entity_field', 'f')
      ->fields('f')
      ->condition('id', $field_id)
      ->execute()
      ->fetch(\PDO::FETCH_ASSOC);

    return $field ?: NULL;
  }

  /**
   * 删除实体模板。
   *
   * @param string $template_id
   *   模板ID。
   *
   * @return array
   *   删除结果。
   */
  public function deleteEntityTemplate(string $template_id): array
  {
    $result = [
      'success' => FALSE,
      'template_id' => $template_id,
      'messages' => [],
      'errors' => [],
      'cleaned_files' => [],
      'cleaned_tables' => [],
      'cleaned_records' => [],
    ];

    try {
      // 1. 加载现有模板
      $template = $this->loadEntityTemplate($template_id);
      if (!$template) {
        throw new ProjectException('实体模板不存在');
      }

      // 2. 触发 predelete 事件
      $this->moduleHandler->invokeAll('baas_project_entity_template_predelete', [$template_id, $template]);

      // 3. 开始事务
      $transaction = $this->database->startTransaction();

      try {
        // 4. 删除相关字段
        $field_deletion_result = $this->deleteEntityTemplateFields($template_id);
        $result['cleaned_records'] = array_merge($result['cleaned_records'], $field_deletion_result['cleaned_records']);
        
        // 5. 清理动态实体类文件
        $file_cleanup_result = $this->cleanupEntityFiles($template);
        $result['cleaned_files'] = $file_cleanup_result['cleaned_files'];
        $result['errors'] = array_merge($result['errors'], $file_cleanup_result['errors']);

        // 6. 清理数据表
        $table_cleanup_result = $this->cleanupEntityTable($template);
        $result['cleaned_tables'] = $table_cleanup_result['cleaned_tables'];
        $result['errors'] = array_merge($result['errors'], $table_cleanup_result['errors']);

        // 7. 清理文件路径记录
        $record_cleanup_result = $this->cleanupEntityFileRecords($template);
        $result['cleaned_records'] = array_merge($result['cleaned_records'], $record_cleanup_result['cleaned_records']);

        // 8. 删除实体模板记录
        $affected_rows = $this->database->delete('baas_entity_template')
          ->condition('id', $template_id)
          ->execute();

        if ($affected_rows === 0) {
          throw new ProjectException('删除实体模板记录失败');
        }

        $result['cleaned_records'][] = "baas_entity_template (1 条记录)";

        // 9. 触发 delete 事件
        $this->moduleHandler->invokeAll('baas_project_entity_template_delete', [$template_id, $template]);

        // 10. 清理缓存
        $this->clearEntityTypeCache($template['tenant_id'], $template['project_id'], $template['name']);

        // 11. 触发 postdelete 事件
        $this->moduleHandler->invokeAll('baas_project_entity_template_postdelete', [$template_id, $template]);

        $result['success'] = TRUE;
        $result['messages'][] = '实体模板已成功删除';

        \Drupal::logger('baas_project')->notice('删除实体模板成功: @name (ID: @id)', [
          '@name' => $template['name'],
          '@id' => $template_id,
        ]);

      } catch (\Exception $e) {
        $transaction->rollBack();
        throw $e;
      }

    } catch (\Exception $e) {
      $result['errors'][] = $e->getMessage();
      \Drupal::logger('baas_project')->error('删除实体模板失败: @error', ['@error' => $e->getMessage()]);
    }

    return $result;
  }

  /**
   * 删除实体字段。
   *
   * @param string $field_id
   *   字段ID。
   *
   * @return array
   *   删除结果。
   */
  public function deleteEntityField(string $field_id): array
  {
    $result = [
      'success' => FALSE,
      'field_id' => $field_id,
      'messages' => [],
      'errors' => [],
    ];

    try {
      // 1. 加载现有字段
      $field = $this->loadEntityField($field_id);
      if (!$field) {
        throw new ProjectException('实体字段不存在');
      }

      // 2. 触发 predelete 事件
      $this->moduleHandler->invokeAll('baas_project_entity_field_predelete', [$field_id, $field]);

      // 3. 删除字段记录
      $affected_rows = $this->database->delete('baas_entity_field')
        ->condition('id', $field_id)
        ->execute();

      if ($affected_rows === 0) {
        throw new ProjectException('删除实体字段失败');
      }

      // 4. 触发 delete 事件
      $this->moduleHandler->invokeAll('baas_project_entity_field_delete', [$field_id, $field]);

      // 5. 重新生成动态实体类文件
      $entity_generation_result = $this->entityGenerator->generateProjectEntityFiles((string) $field['template_id']);
      if (!$entity_generation_result) {
        $result['messages'][] = '字段已删除，但重新生成动态实体类文件时出现问题';
      } else {
        $result['messages'][] = '字段已删除，动态实体类文件已重新生成';
      }

      // 6. 更新动态实体表结构（移除字段）
      $template = $this->loadEntityTemplate($field['template_id']);
      if ($template) {
        \Drupal::logger('baas_project')->info('🔥 deleteEntityField: 准备更新表结构 - template_id=@tid, field=@field', [
          '@tid' => $field['template_id'],
          '@field' => $field['name'],
        ]);
        $this->updateDynamicEntityTable($template['tenant_id'], $template['project_id'], $field['template_id'], $field, 'remove');
        \Drupal::logger('baas_project')->info('🔥 deleteEntityField: updateDynamicEntityTable 调用完成');
      } else {
        \Drupal::logger('baas_project')->error('🔥 deleteEntityField: 找不到模板 template_id=@tid', ['@tid' => $field['template_id']]);
      }

      // 7. 触发 postdelete 事件
      $this->moduleHandler->invokeAll('baas_project_entity_field_postdelete', [$field_id, $field]);

      // 8. 清理缓存
      if ($template) {
        $this->clearEntityTypeCache($template['tenant_id'], $template['project_id'], $template['name']);
      }

      $result['success'] = TRUE;
      $result['messages'][] = '实体字段已成功删除';

      \Drupal::logger('baas_project')->notice('删除实体字段成功: @name (ID: @id)', [
        '@name' => $field['name'],
        '@id' => $field_id,
      ]);

    } catch (\Exception $e) {
      $result['errors'][] = $e->getMessage();
      \Drupal::logger('baas_project')->error('删除实体字段失败: @error', ['@error' => $e->getMessage()]);
    }

    return $result;
  }

  /**
   * 删除实体模板的所有字段。
   *
   * @param string $template_id
   *   模板ID。
   *
   * @return array
   *   删除结果。
   */
  protected function deleteEntityTemplateFields(string $template_id): array
  {
    $result = [
      'cleaned_records' => [],
      'errors' => [],
    ];

    try {
      // 获取所有字段
      $fields = $this->getEntityTemplateFields($template_id);
      
      foreach ($fields as $field) {
        // 触发字段删除事件
        $this->moduleHandler->invokeAll('baas_project_entity_field_predelete', [$field['id'], $field]);
        $this->moduleHandler->invokeAll('baas_project_entity_field_delete', [$field['id'], $field]);
        $this->moduleHandler->invokeAll('baas_project_entity_field_postdelete', [$field['id'], $field]);
      }

      // 批量删除字段记录
      if (!empty($fields)) {
        $deleted_count = $this->database->delete('baas_entity_field')
          ->condition('template_id', $template_id)
          ->execute();

        if ($deleted_count > 0) {
          $result['cleaned_records'][] = "baas_entity_field ({$deleted_count} 条记录)";
        }
      }

    } catch (\Exception $e) {
      $result['errors'][] = '删除字段时出错: ' . $e->getMessage();
    }

    return $result;
  }

  /**
   * 清理实体动态类文件。
   *
   * @param array $template
   *   实体模板数据。
   *
   * @return array
   *   清理结果。
   */
  protected function cleanupEntityFiles(array $template): array
  {
    $results = [
      'cleaned_files' => [],
      'errors' => [],
    ];

    $tenant_id = $template['tenant_id'];
    $project_id = $template['project_id'];
    $class_name = $this->getProjectEntityClassName($template);

    // 构建文件路径
    $base_path = 'public://dynamic_entities/' . $tenant_id . '/projects/' . $project_id;
    $files_to_clean = [
      'Entity/' . $class_name . '.php',
      'Storage/' . $class_name . 'Storage.php',
    ];

    foreach ($files_to_clean as $file_path) {
      $full_path = $base_path . '/' . $file_path;
      $real_path = $this->fileSystem->realpath($full_path);

      if ($real_path && file_exists($real_path)) {
        try {
          if (unlink($real_path)) {
            $results['cleaned_files'][] = $file_path;
            \Drupal::logger('baas_project')->notice('删除动态实体文件: @file', ['@file' => $file_path]);
          } else {
            $results['errors'][] = '无法删除文件: ' . $file_path;
          }
        } catch (\Exception $e) {
          $results['errors'][] = '删除文件时出错: ' . $file_path . ' - ' . $e->getMessage();
        }
      }
    }

    // 检查并删除空目录
    $this->cleanupEmptyDirectories($base_path);

    return $results;
  }

  /**
   * 清理实体数据表。
   *
   * @param array $template
   *   实体模板数据。
   *
   * @return array
   *   清理结果。
   */
  protected function cleanupEntityTable(array $template): array
  {
    $results = [
      'cleaned_tables' => [],
      'errors' => [],
    ];

    $tenant_id = $template['tenant_id'];
    $project_id = $template['project_id'];
    $entity_name = $template['name'];

    // 尝试删除新格式的表
    $new_table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);
    if ($this->database->schema()->tableExists($new_table_name)) {
      try {
        $this->database->schema()->dropTable($new_table_name);
        $results['cleaned_tables'][] = $new_table_name;
        \Drupal::logger('baas_project')->notice('删除实体数据表: @table', ['@table' => $new_table_name]);
      } catch (\Exception $e) {
        $results['errors'][] = '无法删除数据表: ' . $new_table_name . ' - ' . $e->getMessage();
      }
    }

    // 尝试删除旧格式的表（兼容性）
    $old_table_name = $this->tableNameGenerator->generateLegacyTableName($tenant_id, $project_id, $entity_name);
    if ($this->database->schema()->tableExists($old_table_name)) {
      try {
        $this->database->schema()->dropTable($old_table_name);
        $results['cleaned_tables'][] = $old_table_name;
        \Drupal::logger('baas_project')->notice('删除旧格式实体数据表: @table', ['@table' => $old_table_name]);
      } catch (\Exception $e) {
        $results['errors'][] = '无法删除旧格式数据表: ' . $old_table_name . ' - ' . $e->getMessage();
      }
    }

    return $results;
  }

  /**
   * 清理实体文件路径记录。
   *
   * @param array $template
   *   实体模板数据。
   *
   * @return array
   *   清理结果。
   */
  protected function cleanupEntityFileRecords(array $template): array
  {
    $results = [
      'cleaned_records' => [],
      'errors' => [],
    ];

    if (!$this->database->schema()->tableExists('baas_entity_class_files')) {
      return $results;
    }

    $entity_type_id = $this->tableNameGenerator->generateEntityTypeId(
      $template['tenant_id'],
      $template['project_id'],
      $template['name']
    );

    try {
      $deleted_count = $this->database->delete('baas_entity_class_files')
        ->condition('entity_type_id', $entity_type_id)
        ->execute();

      if ($deleted_count > 0) {
        $results['cleaned_records'][] = "baas_entity_class_files ({$deleted_count} 条记录)";
        \Drupal::logger('baas_project')->notice('删除实体文件记录: @count 条', ['@count' => $deleted_count]);
      }
    } catch (\Exception $e) {
      $results['errors'][] = '无法删除文件记录: ' . $e->getMessage();
    }

    return $results;
  }

  /**
   * 清理空目录。
   *
   * @param string $base_path
   *   基础路径。
   */
  protected function cleanupEmptyDirectories(string $base_path): void
  {
    $directories = ['Entity', 'Storage'];
    
    foreach ($directories as $dir) {
      $dir_path = $base_path . '/' . $dir;
      $real_path = $this->fileSystem->realpath($dir_path);
      
      if ($real_path && is_dir($real_path)) {
        // 检查目录是否为空
        $files = array_diff(scandir($real_path), ['.', '..']);
        if (empty($files)) {
          try {
            rmdir($real_path);
            \Drupal::logger('baas_project')->notice('删除空目录: @dir', ['@dir' => $dir]);
          } catch (\Exception $e) {
            \Drupal::logger('baas_project')->warning('无法删除空目录: @dir - @error', [
              '@dir' => $dir,
              '@error' => $e->getMessage(),
            ]);
          }
        }
      }
    }

    // 检查项目目录是否为空
    $project_real_path = $this->fileSystem->realpath($base_path);
    if ($project_real_path && is_dir($project_real_path)) {
      $files = array_diff(scandir($project_real_path), ['.', '..']);
      if (empty($files)) {
        try {
          rmdir($project_real_path);
          \Drupal::logger('baas_project')->notice('删除空的项目目录: @dir', ['@dir' => $base_path]);
        } catch (\Exception $e) {
          \Drupal::logger('baas_project')->warning('无法删除空的项目目录: @dir - @error', [
            '@dir' => $base_path,
            '@error' => $e->getMessage(),
          ]);
        }
      }
    }
  }

  /**
   * 生成项目实体类名。
   *
   * @param array $template
   *   实体模板数据。
   *
   * @return string
   *   类名。
   */
  protected function getProjectEntityClassName(array $template): string
  {
    $tenant_parts = explode('_', $template['tenant_id']);
    $tenant_parts = array_map('ucfirst', $tenant_parts);
    $tenant_prefix = implode('', $tenant_parts);

    $entity_parts = explode('_', $template['name']);
    $entity_parts = array_map('ucfirst', $entity_parts);
    $entity_name = implode('', $entity_parts);

    return $tenant_prefix . 'Project' . $entity_name;
  }

  /**
   * 计算实体名称的最大长度。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return int
   *   最大实体名称长度。
   */
  public function calculateMaxEntityNameLength(string $tenant_id, string $project_id): int
  {
    // Drupal实体类型ID最大长度限制
    $drupal_max_length = 32;
    
    // 生成前缀部分（baas_{6位哈希}_）
    $combined_hash = substr(md5($tenant_id . '_' . $project_id), 0, 6);
    $prefix = "baas_{$combined_hash}_";
    $prefix_length = strlen($prefix);
    
    // 计算实体名称最大长度
    $max_entity_name_length = $drupal_max_length - $prefix_length;
    
    // 确保至少有2个字符的最小长度
    return max($max_entity_name_length, 2);
  }

  /**
   * 获取实体模板的字段列表。
   *
   * @param string $template_id
   *   模板ID。
   *
   * @return array
   *   字段列表。
   */
  protected function getEntityTemplateFields(string $template_id): array
  {
    if (!$this->database->schema()->tableExists('baas_entity_field')) {
      return [];
    }

    return $this->database->select('baas_entity_field', 'f')
      ->fields('f')
      ->condition('template_id', $template_id)
      ->orderBy('weight')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

}