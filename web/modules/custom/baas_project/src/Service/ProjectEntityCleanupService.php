<?php

declare(strict_types=1);

namespace Drupal\baas_project\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\baas_project\Service\ProjectTableNameGenerator;

/**
 * 项目实体清理服务。
 * 
 * 负责清理删除的项目实体相关的文件和数据。
 */
class ProjectEntityCleanupService
{

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly ProjectTableNameGenerator $tableNameGenerator
  ) {}

  /**
   * 清理项目实体模板相关的所有资源。
   *
   * @param string $template_id
   *   实体模板ID。
   *
   * @return array
   *   清理结果数组。
   */
  public function cleanupProjectEntityTemplate(string $template_id): array
  {
    $results = [
      'success' => FALSE,
      'cleaned_files' => [],
      'cleaned_tables' => [],
      'cleaned_records' => [],
      'errors' => [],
    ];

    try {
      // 1. 加载实体模板信息
      $template = $this->loadEntityTemplate($template_id);
      if (!$template) {
        $results['errors'][] = '实体模板不存在';
        return $results;
      }

      // 2. 清理动态实体类文件
      $file_cleanup_result = $this->cleanupEntityFiles($template);
      $results['cleaned_files'] = $file_cleanup_result['cleaned_files'];
      $results['errors'] = array_merge($results['errors'], $file_cleanup_result['errors']);

      // 3. 清理数据表
      $table_cleanup_result = $this->cleanupEntityTable($template);
      $results['cleaned_tables'] = $table_cleanup_result['cleaned_tables'];
      $results['errors'] = array_merge($results['errors'], $table_cleanup_result['errors']);

      // 4. 清理文件路径记录
      $record_cleanup_result = $this->cleanupEntityFileRecords($template);
      $results['cleaned_records'] = $record_cleanup_result['cleaned_records'];
      $results['errors'] = array_merge($results['errors'], $record_cleanup_result['errors']);

      // 5. 清理字段定义
      $field_cleanup_result = $this->cleanupEntityFields($template_id);
      $results['cleaned_records'] = array_merge($results['cleaned_records'], $field_cleanup_result['cleaned_records']);
      $results['errors'] = array_merge($results['errors'], $field_cleanup_result['errors']);

      // 6. 清理实体模板记录
      $template_cleanup_result = $this->cleanupEntityTemplateRecord($template_id);
      $results['cleaned_records'] = array_merge($results['cleaned_records'], $template_cleanup_result['cleaned_records']);
      $results['errors'] = array_merge($results['errors'], $template_cleanup_result['errors']);

      $results['success'] = empty($results['errors']);

    } catch (\Exception $e) {
      $results['errors'][] = '清理过程中发生异常: ' . $e->getMessage();
      \Drupal::logger('baas_project')->error('Entity cleanup failed: @error', ['@error' => $e->getMessage()]);
    }

    return $results;
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
   * 清理实体字段定义。
   *
   * @param string $template_id
   *   实体模板ID。
   *
   * @return array
   *   清理结果。
   */
  protected function cleanupEntityFields(string $template_id): array
  {
    $results = [
      'cleaned_records' => [],
      'errors' => [],
    ];

    if (!$this->database->schema()->tableExists('baas_entity_field')) {
      return $results;
    }

    try {
      $deleted_count = $this->database->delete('baas_entity_field')
        ->condition('template_id', $template_id)
        ->execute();

      if ($deleted_count > 0) {
        $results['cleaned_records'][] = "baas_entity_field ({$deleted_count} 条记录)";
        \Drupal::logger('baas_project')->notice('删除实体字段定义: @count 条', ['@count' => $deleted_count]);
      }
    } catch (\Exception $e) {
      $results['errors'][] = '无法删除字段定义: ' . $e->getMessage();
    }

    return $results;
  }

  /**
   * 清理实体模板记录。
   *
   * @param string $template_id
   *   实体模板ID。
   *
   * @return array
   *   清理结果。
   */
  protected function cleanupEntityTemplateRecord(string $template_id): array
  {
    $results = [
      'cleaned_records' => [],
      'errors' => [],
    ];

    try {
      $deleted_count = $this->database->delete('baas_entity_template')
        ->condition('id', $template_id)
        ->execute();

      if ($deleted_count > 0) {
        $results['cleaned_records'][] = "baas_entity_template (1 条记录)";
        \Drupal::logger('baas_project')->notice('删除实体模板记录: @template_id', ['@template_id' => $template_id]);
      }
    } catch (\Exception $e) {
      $results['errors'][] = '无法删除实体模板记录: ' . $e->getMessage();
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
   * 清理所有Drupal缓存。
   */
  public function clearAllCaches(): void
  {
    try {
      // 清理实体类型定义缓存
      \Drupal::entityTypeManager()->clearCachedDefinitions();
      
      // 清理路由缓存
      \Drupal::service('router.builder')->rebuild();
      
      // 清理所有缓存
      drupal_flush_all_caches();
      
      \Drupal::logger('baas_project')->notice('已清理所有Drupal缓存');
    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('清理缓存时出错: @error', ['@error' => $e->getMessage()]);
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
   * 预览将要清理的资源（不执行实际清理）。
   *
   * @param string $template_id
   *   实体模板ID。
   *
   * @return array
   *   预览结果数组。
   */
  public function previewCleanupResources(string $template_id): array
  {
    $preview = [
      'field_count' => 0,
      'table_names' => [],
      'file_paths' => [],
      'record_counts' => [],
    ];

    try {
      // 加载实体模板信息
      $template = $this->loadEntityTemplate($template_id);
      if (!$template) {
        return $preview;
      }

      // 统计字段数量
      if ($this->database->schema()->tableExists('baas_entity_field')) {
        $preview['field_count'] = $this->database->select('baas_entity_field', 'f')
          ->condition('template_id', $template_id)
          ->countQuery()
          ->execute()
          ->fetchField();
      }

      // 检查数据表
      $tenant_id = $template['tenant_id'];
      $project_id = $template['project_id'];
      $entity_name = $template['name'];

      // 检查新格式的表
      $new_table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);
      if ($this->database->schema()->tableExists($new_table_name)) {
        $preview['table_names'][] = $new_table_name;
      }

      // 检查旧格式的表
      $old_table_name = $this->tableNameGenerator->generateLegacyTableName($tenant_id, $project_id, $entity_name);
      if ($this->database->schema()->tableExists($old_table_name)) {
        $preview['table_names'][] = $old_table_name;
      }

      // 检查动态实体文件
      $class_name = $this->getProjectEntityClassName($template);
      $base_path = 'public://dynamic_entities/' . $tenant_id . '/projects/' . $project_id;
      $files_to_check = [
        'Entity/' . $class_name . '.php',
        'Storage/' . $class_name . 'Storage.php',
      ];

      foreach ($files_to_check as $file_path) {
        $full_path = $base_path . '/' . $file_path;
        $real_path = $this->fileSystem->realpath($full_path);
        if ($real_path && file_exists($real_path)) {
          $preview['file_paths'][] = $file_path;
        }
      }

      // 检查文件记录
      if ($this->database->schema()->tableExists('baas_entity_class_files')) {
        $entity_type_id = $this->tableNameGenerator->generateEntityTypeId($tenant_id, $project_id, $entity_name);
        $file_record_count = $this->database->select('baas_entity_class_files', 'f')
          ->condition('entity_type_id', $entity_type_id)
          ->countQuery()
          ->execute()
          ->fetchField();
        
        if ($file_record_count > 0) {
          $preview['record_counts'][] = "baas_entity_class_files ({$file_record_count} 条记录)";
        }
      }

      $preview['record_counts'][] = "baas_entity_template (1 条记录)";

    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('Preview cleanup failed: @error', ['@error' => $e->getMessage()]);
    }

    return $preview;
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

}