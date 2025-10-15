<?php

declare(strict_types=1);

namespace Drupal\baas_project\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\baas_project\Service\ProjectTableNameGenerator;

/**
 * 项目实体注册服务。
 * 
 * 负责向Drupal注册项目级的动态实体类型。
 */
class ProjectEntityRegistry
{
  use StringTranslationTrait;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly ProjectTableNameGenerator $tableNameGenerator
  ) {}

  /**
   * 注册项目级动态实体类型。
   *
   * @param array &$entity_types
   *   实体类型数组。
   */
  public function registerProjectEntityTypes(array &$entity_types): void
  {
    try {
      $registered_types = $this->loadRegisteredProjectEntityTypes();
      
      foreach ($registered_types as $type_info) {
      $entity_type_id = $type_info['entity_type_id'] ?? '';
      $tenant_id = $type_info['tenant_id'] ?? '';
      $project_id = $type_info['project_id'] ?? '';
      $entity_name = $type_info['entity_name'] ?? '';
      $entity_label = $type_info['entity_label'] ?? '';
      $class_name = $type_info['class_name'] ?? '';
      
      // 验证数据完整性 - 先检查必需字段
      if (empty(trim($entity_type_id)) || empty(trim($tenant_id)) || empty(trim($project_id)) || empty(trim($entity_name)) || empty(trim($entity_label)) || empty(trim($class_name))) {
        \Drupal::logger('baas_project')->warning('项目实体数据不完整: @data', [
          '@data' => json_encode($type_info),
        ]);
        continue;
      }
      
      // 检查实体类文件是否存在
      if (!$this->entityClassExists($type_info)) {
        \Drupal::logger('baas_project')->warning('项目实体类文件不存在: @class', [
          '@class' => $class_name,
        ]);
        continue;
      }

      // 生成精简表名和实体类型ID
      $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);
      $entity_type_id = $this->tableNameGenerator->generateEntityTypeId($tenant_id, $project_id, $entity_name);
      
      // 验证生成的值
      if (empty($table_name) || empty($entity_type_id)) {
        \Drupal::logger('baas_project')->warning('生成的表名或实体类型ID为空: table=@table, type=@type', [
          '@table' => $table_name,
          '@type' => $entity_type_id,
        ]);
        continue;
      }
      
      // 创建实体类型定义
      try {
        // 确保所有字符串值都不为空
        $entity_type_id = trim($entity_type_id) ?: 'unknown';
        $entity_label = trim($entity_label) ?: 'Unknown Entity';
        $table_name = trim($table_name) ?: 'unknown_table';
        $class_name = trim($class_name) ?: 'UnknownClass';
        $tenant_id = trim($tenant_id) ?: 'unknown_tenant';
        $project_id = trim($project_id) ?: 'unknown_project';
        $entity_name = trim($entity_name) ?: 'unknown_entity';
        
        // 创建标签 - 确保不为null
        $translated_label = $this->t('@label', ['@label' => $entity_label]);
        if (empty($translated_label)) {
          $translated_label = $entity_label ?: 'Unknown Entity';
        }
        
        // 创建安全的实体类型定义
        $definition = [
          'id' => $entity_type_id,
          'label' => $translated_label,
          'base_table' => $table_name,
          'class' => "Drupal\\baas_project\\Entity\\Dynamic\\{$class_name}",
          'storage_class' => "Drupal\\baas_project\\Storage\\Dynamic\\{$class_name}Storage",
          'admin_permission' => 'manage baas project content',
          'translatable' => FALSE,
          'persistent_cache' => TRUE,
          'entity_keys' => [
            'id' => 'id',
            'uuid' => 'uuid',
            'label' => 'title',
          ],
          'handlers' => [
            'storage' => "Drupal\\baas_project\\Storage\\Dynamic\\{$class_name}Storage",
            'access' => "Drupal\\baas_project\\Access\\ProjectEntityAccessControlHandler",
          ],
          'links' => [
            'canonical' => "/user/tenants/{$tenant_id}/projects/{$project_id}/entities/{$entity_name}/{{$entity_type_id}}",
            'collection' => "/user/tenants/{$tenant_id}/projects/{$project_id}/entities/{$entity_name}/data",
            'add-form' => "/user/tenants/{$tenant_id}/projects/{$project_id}/entities/{$entity_name}/data/add",
            'edit-form' => "/user/tenants/{$tenant_id}/projects/{$project_id}/entities/{$entity_name}/{{$entity_type_id}}/edit",
            'delete-form' => "/user/tenants/{$tenant_id}/projects/{$project_id}/entities/{$entity_name}/{{$entity_type_id}}/delete",
          ],
        ];
        
        // 记录调试信息（在sanitize之前）
        \Drupal::logger('baas_project')->debug('Before sanitize - class: @class, storage_class: @storage', [
          '@class' => $definition['class'] ?? 'NULL',
          '@storage' => $definition['storage_class'] ?? 'NULL',
        ]);

        // 验证定义中的所有字符串值，确保不为null
        $definition = $this->sanitizeEntityTypeDefinition($definition);

        // 记录调试信息（在sanitize之后）
        \Drupal::logger('baas_project')->debug('After sanitize - id=@id, label=@label, table=@table, class=@class', [
          '@id' => $definition['id'],
          '@label' => $definition['label'],
          '@table' => $definition['base_table'],
          '@class' => $definition['class'] ?? 'NULL',
        ]);
        
        $entity_type = new ContentEntityType($definition);
      } catch (\Exception $e) {
        \Drupal::logger('baas_project')->error('创建实体类型定义失败: @error', [
          '@error' => $e->getMessage(),
        ]);
        continue;
      }

      $entity_types[$entity_type_id] = $entity_type;
      
      \Drupal::logger('baas_project')->debug('注册项目实体类型: @type', [
        '@type' => $entity_type_id,
      ]);
    }
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('Error in registerProjectEntityTypes: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 从数据库加载已注册的项目实体类型。
   *
   * @return array
   *   实体类型信息数组。
   */
  protected function loadRegisteredProjectEntityTypes(): array
  {
    if (!$this->database->schema()->tableExists('baas_entity_template')) {
      return [];
    }

    // 查询所有项目级的实体模板
    $query = $this->database->select('baas_entity_template', 'et');
    $query->fields('et', ['id', 'tenant_id', 'project_id', 'name', 'label']);
    $query->condition('et.project_id', '', '!=');
    $query->condition('et.project_id', NULL, 'IS NOT NULL');
    $query->condition('et.status', 1);
    
    $templates = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $registered_types = [];
    foreach ($templates as $template) {
      // 确保所有必需字段都不为空
      if (empty($template['tenant_id']) || empty($template['project_id']) || empty($template['name']) || empty($template['label'])) {
        \Drupal::logger('baas_project')->warning('跳过不完整的模板数据: @data', [
          '@data' => json_encode($template),
        ]);
        continue;
      }
      
      $entity_type_id = $this->tableNameGenerator->generateEntityTypeId($template['tenant_id'], $template['project_id'], $template['name']);
      $class_name = $this->getProjectEntityClassName($template);
      
      // 确保生成的值不为空
      if (empty($entity_type_id) || empty($class_name)) {
        \Drupal::logger('baas_project')->warning('生成的实体类型ID或类名为空: type_id=@type_id, class=@class', [
          '@type_id' => $entity_type_id,
          '@class' => $class_name,
        ]);
        continue;
      }
      
      $registered_types[] = [
        'entity_type_id' => $entity_type_id,
        'tenant_id' => $template['tenant_id'],
        'project_id' => $template['project_id'],
        'entity_name' => $template['name'],
        'entity_label' => $template['label'],
        'class_name' => $class_name,
        'template_id' => $template['id'],
      ];
    }

    return $registered_types;
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
    // 验证输入数据
    $tenant_id = trim($template['tenant_id'] ?? '');
    $project_id = trim($template['project_id'] ?? '');
    $entity_name = trim($template['name'] ?? '');

    if (empty($tenant_id) || empty($project_id) || empty($entity_name)) {
      \Drupal::logger('baas_project')->warning('生成类名时缺少必要数据: tenant_id=@tenant_id, project_id=@project_id, name=@name', [
        '@tenant_id' => $tenant_id,
        '@project_id' => $project_id,
        '@name' => $entity_name,
      ]);
      return 'UnknownProjectEntity';
    }

    // 1. 转换为短格式（如果输入是长格式）
    $tenant_id = str_replace('tenant_', '', $tenant_id);
    $project_id = preg_replace('/^tenant_(.+?)_project_/', '$1_', $project_id);

    // 2. 处理 tenant_id：移除下划线
    $tenant_clean = str_replace('_', '', $tenant_id);

    // 3. 处理 project_id：提取 UUID 部分，移除下划线
    $project_parts = explode('_', $project_id);
    $project_uuid = $project_parts[1] ?? str_replace('_', '', $project_id);

    // 4. 处理实体名称：转换为驼峰命名
    $entity_parts = explode('_', $entity_name);
    $entity_parts = array_map('ucfirst', $entity_parts);
    $entity_name_formatted = implode('', $entity_parts);

    // 5. 组合最终类名
    // 格式: Project{tenant_id}{project_uuid}{EntityName}
    $class_name = 'Project' . $tenant_clean . $project_uuid . $entity_name_formatted;

    return $class_name;
  }

  /**
   * 检查实体类文件是否存在。
   *
   * @param array $type_info
   *   实体类型信息。
   *
   * @return bool
   *   存在返回TRUE，否则返回FALSE。
   */
  protected function entityClassExists(array $type_info): bool
  {
    $tenant_id = trim($type_info['tenant_id'] ?? '');
    $project_id = trim($type_info['project_id'] ?? '');
    $class_name = trim($type_info['class_name'] ?? '');

    // 验证必需参数
    if (empty($tenant_id) || empty($project_id) || empty($class_name)) {
      \Drupal::logger('baas_project')->warning('检查实体类文件时缺少必要参数: tenant_id=@tenant_id, project_id=@project_id, class_name=@class_name', [
        '@tenant_id' => $tenant_id,
        '@project_id' => $project_id, 
        '@class_name' => $class_name,
      ]);
      return FALSE;
    }

    // 检查实体类文件
    $entity_file_path = \Drupal::service('file_system')->realpath(
      'public://dynamic_entities/' . $tenant_id . '/projects/' . $project_id . '/Entity/' . $class_name . '.php'
    );

    if (!$entity_file_path || !file_exists($entity_file_path)) {
      return FALSE;
    }

    // 检查存储类文件
    $storage_file_path = \Drupal::service('file_system')->realpath(
      'public://dynamic_entities/' . $tenant_id . '/projects/' . $project_id . '/Storage/' . $class_name . 'Storage.php'
    );

    if (!$storage_file_path || !file_exists($storage_file_path)) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * 检查实体类型是否已注册。
   *
   * @param string $entity_type_id
   *   实体类型ID。
   *
   * @return bool
   *   已注册返回TRUE，否则返回FALSE。
   */
  public function isProjectEntityTypeRegistered(string $entity_type_id): bool
  {
    $registered_types = $this->loadRegisteredProjectEntityTypes();
    
    foreach ($registered_types as $type_info) {
      if ($type_info['entity_type_id'] === $entity_type_id) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * 清理实体类型定义，确保所有字符串值不为null。
   *
   * @param array $definition
   *   实体类型定义数组。
   *
   * @return array
   *   清理后的定义数组。
   */
  protected function sanitizeEntityTypeDefinition(array $definition): array
  {
    // 递归处理所有字符串值
    $sanitized = [];
    foreach ($definition as $key => $value) {
      if (is_string($value)) {
        // 确保字符串不为null或空
        $trimmed = trim($value ?? '');
        $sanitized[$key] = $trimmed !== '' ? $trimmed : 'default_value';
      } elseif (is_array($value)) {
        $sanitized[$key] = $this->sanitizeEntityTypeDefinition($value);
      } elseif ($value === null) {
        // 将null值转换为默认字符串
        $sanitized[$key] = 'default_value';
      } else {
        $sanitized[$key] = $value;
      }
    }
    
    // 确保关键字段不为空
    $critical_fields = ['id', 'label', 'base_table', 'class', 'storage_class'];
    foreach ($critical_fields as $field) {
      if (isset($sanitized[$field]) && (empty($sanitized[$field]) || $sanitized[$field] === 'default_value')) {
        $sanitized[$field] = 'project_entity_' . $field;
      }
    }
    
    return $sanitized;
  }

}