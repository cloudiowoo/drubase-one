<?php

namespace Drupal\baas_platform;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Groups项目静态数据导入器
 * 
 * 从发行版打包的静态数据文件导入Groups项目完整结构和数据
 * 保持原始表名、类名、ID等完整性，符合Drupal Entity API规范
 */
class GroupsStaticDataImporter {

  /**
   * The database connection.
   */
  protected Connection $database;

  /**
   * The logger.
   */
  protected $logger;

  /**
   * Entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Groups项目原始配置（保持不变）
   */
  protected array $groupsProjectConfig = [
    'tenant_id' => 'tenant_7375b0cd',
    'project_id' => 'tenant_7375b0cd_project_6888d012be80c',
    'project_name' => 'Groups Sports Activity Management',
    'entities' => ['users', 'activities', 'teams', 'positions', 'user_activities', 'system_config'],
    'table_prefix' => 'baas_00403b_',
  ];

  /**
   * 构造函数
   */
  public function __construct(
    Connection $database, 
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory
  ) {
    $this->database = $database;
    $this->logger = $logger_factory->get('groups_static_import');
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * 导入Groups项目静态数据
   */
  public function importGroupsStaticData(): array {
    $this->logger->info('开始导入Groups项目静态数据');

    $import_results = [
      'success' => TRUE,
      'tenant_created' => FALSE,
      'project_created' => FALSE,
      'entities_imported' => [],
      'data_imported' => [],
      'configs_applied' => [],
      'entity_files_deployed' => [],
      'errors' => [],
    ];

    try {
      // 1. 创建租户配置
      $import_results['tenant_created'] = $this->importTenantConfig();

      // 2. 创建项目配置
      $import_results['project_created'] = $this->importProjectConfig();

      // 3. 导入实体模板和字段定义
      $import_results['entities_imported'] = $this->importEntityTemplates();

      // 4. 应用Drupal配置
      $import_results['configs_applied'] = $this->applyDrupalConfigurations();

      // 5. 部署动态实体文件
      $import_results['entity_files_deployed'] = $this->deployEntityFiles();

      // 6. 导入脱敏数据
      $import_results['data_imported'] = $this->importAnonymizedData();

      // 7. 注册实体类型到Drupal
      $this->registerEntityTypesWithDrupal();

      $this->logger->info('Groups项目静态数据导入完成');

    } catch (\Exception $e) {
      $import_results['success'] = FALSE;
      $import_results['errors'][] = $e->getMessage();
      $this->logger->error('Groups数据导入失败: @error', ['@error' => $e->getMessage()]);
    }

    return $import_results;
  }

  /**
   * 导入租户配置（保持原始ID）
   */
  protected function importTenantConfig(): bool {
    if (!\Drupal::moduleHandler()->moduleExists('baas_tenant')) {
      return FALSE;
    }

    try {
      $tenant_service = \Drupal::service('baas_tenant.manager');
      
      // 使用原始租户ID，不进行重命名
      $tenant_service->createTenant(
        $this->groupsProjectConfig['tenant_id'], // 保持原始: tenant_7375b0cd
        'Groups Sports Demo Tenant',
        [
          'description' => 'Groups运动活动管理演示租户（保持原始结构）',
          'status' => 1,
          'organization_type' => 'sports_club',
          'contact_email' => 'demo@groups-sports.com',
          'is_demo' => TRUE,
        ]
      );

      $this->logger->info('租户配置导入成功: @tenant_id', [
        '@tenant_id' => $this->groupsProjectConfig['tenant_id']
      ]);

      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('租户配置导入失败: @error', ['@error' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * 导入项目配置（保持原始ID）
   */
  protected function importProjectConfig(): bool {
    if (!\Drupal::moduleHandler()->moduleExists('baas_project')) {
      return FALSE;
    }

    try {
      $project_service = \Drupal::service('baas_project.manager');
      
      // 使用原始项目ID，不进行重命名
      $project_service->createProject([
        'project_id' => $this->groupsProjectConfig['project_id'], // 保持原始
        'tenant_id' => $this->groupsProjectConfig['tenant_id'],   // 保持原始
        'name' => $this->groupsProjectConfig['project_name'],
        'machine_name' => 'groups_sports',
        'description' => '运动活动组织和管理平台，基于原始Groups项目结构',
        'status' => 1,
        'is_demo' => TRUE,
      ]);

      $this->logger->info('项目配置导入成功: @project_id', [
        '@project_id' => $this->groupsProjectConfig['project_id']
      ]);

      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('项目配置导入失败: @error', ['@error' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * 导入实体模板（从静态配置文件）
   */
  protected function importEntityTemplates(): array {
    $imported_entities = [];
    
    // 从发行版打包的静态配置文件读取实体定义
    $static_data_path = DRUPAL_ROOT . '/profiles/baas_platform/data/groups_static_data.json';
    
    if (!file_exists($static_data_path)) {
      $this->logger->error('静态数据文件不存在: @path', ['@path' => $static_data_path]);
      return [];
    }

    $static_data = json_decode(file_get_contents($static_data_path), TRUE);
    
    if (!isset($static_data['entities'])) {
      $this->logger->error('静态数据文件中缺少实体定义');
      return [];
    }

    if (!\Drupal::moduleHandler()->moduleExists('baas_entity')) {
      $this->logger->warning('baas_entity模块未启用，跳过实体模板导入');
      return [];
    }

    $template_service = \Drupal::service('baas_entity.template_manager');

    foreach ($static_data['entities'] as $entity_name => $entity_config) {
      try {
        // 保持原始项目ID和结构
        $entity_config['project_id'] = $this->groupsProjectConfig['project_id'];
        $entity_config['is_demo'] = TRUE;
        
        $template_service->createEntityTemplate($entity_config);
        $imported_entities[] = $entity_name;
        
        $this->logger->info('实体模板导入成功: @entity', ['@entity' => $entity_name]);
      } catch (\Exception $e) {
        $this->logger->error('实体模板导入失败 @entity: @error', [
          '@entity' => $entity_name,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    return $imported_entities;
  }

  /**
   * 应用Drupal配置
   */
  protected function applyDrupalConfigurations(): array {
    $applied_configs = [];

    try {
      // 1. 配置BaaS模块设置
      $this->configFactory->getEditable('baas_tenant.settings')
        ->set('demo_tenant_id', $this->groupsProjectConfig['tenant_id'])
        ->save();

      $this->configFactory->getEditable('baas_project.settings')
        ->set('demo_project_id', $this->groupsProjectConfig['project_id'])
        ->save();

      $applied_configs[] = 'baas_tenant.settings';
      $applied_configs[] = 'baas_project.settings';

      // 2. 配置实体类型注册
      foreach ($this->groupsProjectConfig['entities'] as $entity_name) {
        $config_name = "baas_entity.entity_type.{$this->groupsProjectConfig['table_prefix']}{$entity_name}";
        
        $this->configFactory->getEditable($config_name)
          ->set('entity_type_id', $this->groupsProjectConfig['table_prefix'] . $entity_name)
          ->set('project_id', $this->groupsProjectConfig['project_id'])
          ->set('tenant_id', $this->groupsProjectConfig['tenant_id'])
          ->set('is_demo', TRUE)
          ->save();

        $applied_configs[] = $config_name;
      }

      $this->logger->info('Drupal配置应用成功: @count项', ['@count' => count($applied_configs)]);

    } catch (\Exception $e) {
      $this->logger->error('Drupal配置应用失败: @error', ['@error' => $e->getMessage()]);
    }

    return $applied_configs;
  }

  /**
   * 部署动态实体文件（保持原始类名和目录结构）
   */
  protected function deployEntityFiles(): array {
    $deployed_files = [];
    
    // 从发行版打包的实体文件目录读取
    $static_entities_path = DRUPAL_ROOT . '/profiles/baas_platform/data/entity_files';
    
    if (!is_dir($static_entities_path)) {
      $this->logger->warning('静态实体文件目录不存在: @path', ['@path' => $static_entities_path]);
      return [];
    }

    // 目标部署目录（保持原始路径结构）
    $target_base_path = DRUPAL_ROOT . '/sites/default/files/dynamic_entities/' . $this->groupsProjectConfig['tenant_id'] . '/projects/' . $this->groupsProjectConfig['project_id'];
    
    // 创建基础目录
    if (!is_dir($target_base_path)) {
      mkdir($target_base_path, 0755, TRUE);
    }

    $this->logger->info('开始部署实体文件到: @path', ['@path' => $target_base_path]);
    
    // 递归复制整个目录结构（保持原始Entity/Storage目录结构）
    $deployed_files = $this->recursiveCopyDirectory($static_entities_path, $target_base_path);
    
    $this->logger->info('实体文件部署完成，共部署 @count 个文件', ['@count' => count($deployed_files)]);

    // 部署上传文件
    $deployed_uploads = $this->deployUploadFiles();
    
    return array_merge($deployed_files, $deployed_uploads);
  }

  /**
   * 递归复制目录及其结构
   */
  protected function recursiveCopyDirectory(string $source_dir, string $target_dir): array {
    $deployed_files = [];
    
    if (!is_dir($source_dir)) {
      return $deployed_files;
    }
    
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($source_dir, \RecursiveDirectoryIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $item) {
      $source_path = $item->getPathname();
      $relative_path = substr($source_path, strlen($source_dir) + 1);
      $target_path = $target_dir . '/' . $relative_path;
      
      if ($item->isDir()) {
        // 创建目录
        if (!is_dir($target_path)) {
          mkdir($target_path, 0755, TRUE);
          $this->logger->info('创建目录: @dir', ['@dir' => $relative_path]);
        }
      } elseif ($item->isFile() && pathinfo($source_path, PATHINFO_EXTENSION) === 'php') {
        // 复制PHP文件
        $target_dir_path = dirname($target_path);
        if (!is_dir($target_dir_path)) {
          mkdir($target_dir_path, 0755, TRUE);
        }
        
        if (copy($source_path, $target_path)) {
          $deployed_files[] = $relative_path;
          $this->logger->info('实体文件部署成功: @file', ['@file' => $relative_path]);
        } else {
          $this->logger->error('实体文件部署失败: @file', ['@file' => $relative_path]);
        }
      }
    }
    
    return $deployed_files;
  }

  /**
   * 部署项目上传文件
   */
  protected function deployUploadFiles(): array {
    $deployed_files = [];
    
    // 从发行版打包的上传文件目录读取
    $static_uploads_path = DRUPAL_ROOT . '/profiles/baas_platform/data/uploads';
    
    if (!is_dir($static_uploads_path)) {
      $this->logger->info('静态上传文件目录不存在，跳过上传文件部署');
      return [];
    }

    // 目标部署目录（BaaS文件系统结构）
    $target_uploads_path = DRUPAL_ROOT . '/sites/default/files/baas/' . $this->groupsProjectConfig['tenant_id'] . '/' . $this->groupsProjectConfig['project_id'];
    
    if (!is_dir($target_uploads_path)) {
      mkdir($target_uploads_path, 0755, TRUE);
    }

    // 复制所有上传文件
    foreach (glob("$static_uploads_path/*") as $source_file) {
      if (is_file($source_file)) {
        $filename = basename($source_file);
        $target_file = "$target_uploads_path/$filename";
        
        if (copy($source_file, $target_file)) {
          $deployed_files[] = "uploads/$filename";
          $this->logger->info('上传文件部署成功: @file', ['@file' => $filename]);
        } else {
          $this->logger->error('上传文件部署失败: @file', ['@file' => $filename]);
        }
      }
    }

    if (count($deployed_files) > 0) {
      $this->logger->info('总共部署了 @count 个上传文件', ['@count' => count($deployed_files)]);
    }

    return $deployed_files;
  }

  /**
   * 导入脱敏数据（保持原始表名）
   */
  protected function importAnonymizedData(): array {
    $imported_data = [];
    
    // 从静态数据文件读取脱敏数据
    $static_data_path = DRUPAL_ROOT . '/profiles/baas_platform/data/groups_static_data.json';
    $static_data = json_decode(file_get_contents($static_data_path), TRUE);
    
    if (!isset($static_data['sample_data'])) {
      $this->logger->error('静态数据文件中缺少示例数据');
      return [];
    }

    foreach ($static_data['sample_data'] as $entity_name => $sample_records) {
      if (empty($sample_records)) {
        continue;
      }

      $table_name = $this->groupsProjectConfig['table_prefix'] . $entity_name; // 保持原始表名
      
      try {
        // 检查表是否存在，如不存在则创建
        if (!$this->database->schema()->tableExists($table_name)) {
          $this->logger->warning('数据表不存在，跳过: @table', ['@table' => $table_name]);
          continue;
        }

        // 导入数据到原始表名
        $inserted_count = 0;
        foreach ($sample_records as $record) {
          // 确保使用原始租户和项目ID
          $record['tenant_id'] = $this->groupsProjectConfig['tenant_id'];
          $record['project_id'] = $this->groupsProjectConfig['project_id'];
          $record['is_demo'] = TRUE;
          
          $this->database->insert($table_name)->fields($record)->execute();
          $inserted_count++;
        }
        
        $imported_data[$entity_name] = $inserted_count;
        $this->logger->info('数据导入成功: @entity (@count条)', [
          '@entity' => $entity_name,
          '@count' => $inserted_count,
        ]);
        
      } catch (\Exception $e) {
        $this->logger->error('数据导入失败 @entity: @error', [
          '@entity' => $entity_name,
          '@error' => $e->getMessage(),
        ]);
      }
    }

    return $imported_data;
  }

  /**
   * 注册实体类型到Drupal系统
   */
  protected function registerEntityTypesWithDrupal(): void {
    try {
      // 通知Drupal重新发现实体类型
      $this->entityTypeManager->clearCachedDefinitions();
      
      // 重建缓存以确保实体类型可用
      drupal_flush_all_caches();
      
      $this->logger->info('实体类型已注册到Drupal系统');
    } catch (\Exception $e) {
      $this->logger->error('实体类型注册失败: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * 验证导入结果
   */
  public function validateImport(): array {
    $validation_results = [
      'valid' => TRUE,
      'checks' => [],
      'issues' => [],
    ];

    // 检查租户是否存在
    try {
      $tenant_exists = $this->database->select('baas_tenant_config', 't')
        ->condition('tenant_id', $this->groupsProjectConfig['tenant_id'])
        ->countQuery()
        ->execute()
        ->fetchField() > 0;
        
      $validation_results['checks']['tenant_exists'] = $tenant_exists;
      if (!$tenant_exists) {
        $validation_results['valid'] = FALSE;
        $validation_results['issues'][] = '租户配置未找到';
      }
    } catch (\Exception $e) {
      $validation_results['issues'][] = "租户检查失败: {$e->getMessage()}";
    }

    // 检查项目是否存在
    try {
      $project_exists = $this->database->select('baas_project_config', 'p')
        ->condition('project_id', $this->groupsProjectConfig['project_id'])
        ->countQuery()
        ->execute()
        ->fetchField() > 0;
        
      $validation_results['checks']['project_exists'] = $project_exists;
      if (!$project_exists) {
        $validation_results['valid'] = FALSE;
        $validation_results['issues'][] = '项目配置未找到';
      }
    } catch (\Exception $e) {
      $validation_results['issues'][] = "项目检查失败: {$e->getMessage()}";
    }

    // 检查数据表和数据
    foreach ($this->groupsProjectConfig['entities'] as $entity_name) {
      $table_name = $this->groupsProjectConfig['table_prefix'] . $entity_name;
      
      try {
        $table_exists = $this->database->schema()->tableExists($table_name);
        $validation_results['checks'][$entity_name . '_table_exists'] = $table_exists;
        
        if ($table_exists) {
          $record_count = $this->database->select($table_name)
            ->condition('is_demo', TRUE)
            ->countQuery()
            ->execute()
            ->fetchField();
            
          $validation_results['checks'][$entity_name . '_demo_records'] = $record_count;
        }
      } catch (\Exception $e) {
        $validation_results['issues'][] = "数据表检查失败 {$table_name}: {$e->getMessage()}";
      }
    }

    return $validation_results;
  }
}