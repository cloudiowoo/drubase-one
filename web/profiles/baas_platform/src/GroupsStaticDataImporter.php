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

      // 6. 导入实体数据
      $import_results['data_imported'] = $this->importAnonymizedData();

      // 7. 导入系统用户和权限映射
      $import_results['system_users_imported'] = $this->importSystemUsers();

      // 8. 注册实体类型到Drupal
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
      // 从静态数据文件读取租户配置
      $static_data_path = DRUPAL_ROOT . '/profiles/baas_platform/data/groups_static_data.json';
      $static_data = json_decode(file_get_contents($static_data_path), TRUE);

      if (isset($static_data['baas_config']['tenant_config'])) {
        foreach ($static_data['baas_config']['tenant_config'] as $tenant_data) {
          // 检查租户是否已存在
          $existing_tenant = $this->database->select('baas_tenant_config', 'btc')
            ->condition('tenant_id', $tenant_data['tenant_id'])
            ->countQuery()
            ->execute()
            ->fetchField();

          if (!$existing_tenant) {
            // 查找关联的系统用户作为 owner_uid
            $owner_uid = 2; // 默认使用 admin 用户作为 owner
            if (isset($static_data['system_users']['user_tenant_mapping'])) {
              foreach ($static_data['system_users']['user_tenant_mapping'] as $mapping) {
                if ($mapping['tenant_id'] === $tenant_data['tenant_id'] && $mapping['role'] === 'tenant_owner') {
                  $owner_uid = $mapping['user_id'];
                  break;
                }
              }
            }

            // 直接插入到数据库
            $this->database->insert('baas_tenant_config')
              ->fields([
                'tenant_id' => $tenant_data['tenant_id'],
                'name' => $tenant_data['name'],
                'owner_uid' => $owner_uid,
                'organization_type' => $tenant_data['organization_type'],
                'contact_email' => $tenant_data['contact_email'],
                'settings' => $tenant_data['settings'],
                'status' => $tenant_data['status'],
                'created' => $tenant_data['created'],
                'updated' => $tenant_data['updated'],
              ])
              ->execute();

            $this->logger->info('租户配置导入成功: @tenant_id', ['@tenant_id' => $tenant_data['tenant_id']]);
          } else {
            $this->logger->info('租户配置已存在，跳过: @tenant_id', ['@tenant_id' => $tenant_data['tenant_id']]);
          }
        }
      }

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
      // 从静态数据文件读取项目配置
      $static_data_path = DRUPAL_ROOT . '/profiles/baas_platform/data/groups_static_data.json';
      $static_data = json_decode(file_get_contents($static_data_path), TRUE);

      if (isset($static_data['baas_config']['project_config'])) {
        foreach ($static_data['baas_config']['project_config'] as $project_data) {
          // 检查项目是否已存在
          $existing_project = $this->database->select('baas_project_config', 'bpc')
            ->condition('project_id', $project_data['project_id'])
            ->countQuery()
            ->execute()
            ->fetchField();

          if (!$existing_project) {
            // 查找关联的项目成员作为 owner_uid
            $owner_uid = 2; // 默认使用 admin 用户作为 owner
            if (isset($static_data['system_users']['project_members'])) {
              foreach ($static_data['system_users']['project_members'] as $member) {
                if ($member['project_id'] === $project_data['project_id'] && $member['role'] === 'owner') {
                  $owner_uid = $member['user_id'];
                  break;
                }
              }
            }

            // 生成 machine_name (从项目名称生成)
            $machine_name = strtolower(str_replace([' ', '-'], '_', $project_data['name']));

            // 直接插入到数据库
            $this->database->insert('baas_project_config')
              ->fields([
                'project_id' => $project_data['project_id'],
                'tenant_id' => $project_data['tenant_id'],
                'name' => $project_data['name'],
                'machine_name' => $machine_name,
                'description' => $project_data['description'],
                'settings' => $project_data['settings'],
                'status' => $project_data['status'],
                'owner_uid' => $owner_uid,
                'created' => $project_data['created'],
                'updated' => $project_data['updated'],
              ])
              ->execute();

            $this->logger->info('项目配置导入成功: @project_id', ['@project_id' => $project_data['project_id']]);
          } else {
            $this->logger->info('项目配置已存在，跳过: @project_id', ['@project_id' => $project_data['project_id']]);
          }
        }
      }

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

    if (!isset($static_data['baas_config']['entity_templates'])) {
      $this->logger->error('静态数据文件中缺少实体模板配置');
      return [];
    }

    if (!\Drupal::moduleHandler()->moduleExists('baas_entity')) {
      $this->logger->warning('baas_entity模块未启用，跳过实体模板导入');
      return [];
    }

    // 直接导入实体模板到数据库表
    try {
      // 1. 导入实体模板
      foreach ($static_data['baas_config']['entity_templates'] as $template_data) {
        // 检查是否已存在相同的实体模板
        $existing = $this->database->select('baas_entity_template', 'bet')
          ->condition('tenant_id', $template_data['tenant_id'])
          ->condition('name', $template_data['name'])
          ->countQuery()
          ->execute()
          ->fetchField();

        if (!$existing) {
          $this->database->insert('baas_entity_template')
            ->fields([
              'tenant_id' => $template_data['tenant_id'],
              'project_id' => $template_data['project_id'],
              'name' => $template_data['name'],
              'label' => $template_data['label'],
              'description' => $template_data['description'],
              'status' => $template_data['status'],
              'settings' => $template_data['settings'],
              'created' => $template_data['created'],
              'updated' => $template_data['updated'],
            ])
            ->execute();

          $imported_entities[] = $template_data['name'];
          $this->logger->info('实体模板导入成功: @entity', ['@entity' => $template_data['name']]);
        } else {
          $this->logger->info('实体模板已存在，跳过: @entity', ['@entity' => $template_data['name']]);
        }
      }

      // 2. 导入实体字段
      if (isset($static_data['baas_config']['entity_fields'])) {
        foreach ($static_data['baas_config']['entity_fields'] as $field_data) {
          // 检查是否已存在相同的字段
          $existing_field = $this->database->select('baas_entity_field', 'bef')
            ->condition('template_id', $field_data['template_id'])
            ->condition('name', $field_data['name'])
            ->countQuery()
            ->execute()
            ->fetchField();

          if (!$existing_field) {
            $this->database->insert('baas_entity_field')
              ->fields([
                'template_id' => $field_data['template_id'],
                'name' => $field_data['name'],
                'label' => $field_data['label'],
                'type' => $field_data['type'],
                'required' => $field_data['required'],
                'settings' => $field_data['settings'],
                'weight' => $field_data['weight'],
                'description' => $field_data['description'],
                'created' => $field_data['created'],
                'updated' => $field_data['updated'],
              ])
              ->execute();
          }
        }

        $this->logger->info('实体字段导入成功: @count 个', ['@count' => count($static_data['baas_config']['entity_fields'])]);
      }

      // 3. 导入实体类文件注册信息
      if (isset($static_data['baas_config']['entity_class_files'])) {
        // 检查表是否存在
        if ($this->database->schema()->tableExists('baas_entity_class_files')) {
          foreach ($static_data['baas_config']['entity_class_files'] as $class_data) {
            $this->database->insert('baas_entity_class_files')
              ->fields([
                'entity_type_id' => $class_data['entity_type_id'],
                'tenant_id' => $class_data['tenant_id'],
                'class_name' => $class_data['class_name'],
                'class_type' => $class_data['class_type'],
                'file_path' => $class_data['file_path'],
              ])
              ->execute();
          }

          $this->logger->info('实体类文件注册成功: @count 个', ['@count' => count($static_data['baas_config']['entity_class_files'])]);
        } else {
          $this->logger->warning('baas_entity_class_files 表不存在，跳过实体类文件注册');
        }
      }

    } catch (\Exception $e) {
      $this->logger->error('实体模板导入失败: @error', ['@error' => $e->getMessage()]);
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
    
    if (!isset($static_data['entities'])) {
      return [];
    }

    foreach ($static_data['entities'] as $entity_name => $sample_records) {
      if (empty($sample_records)) {
        continue;
      }

      $table_name = $this->groupsProjectConfig['table_prefix'] . $entity_name; // 保持原始表名
      
      try {
        // 检查表是否存在，如不存在则跳过
        if (!$this->database->schema()->tableExists($table_name)) {
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
        
      } catch (\Exception $e) {
        // 静默处理错误，继续下一个实体的导入
      }
    }

    return $imported_data;
  }

  /**
   * 导入系统用户和权限映射
   */
  protected function importSystemUsers(): array {
    $imported_users = [];

    // 从静态数据文件读取系统用户数据
    $static_data_path = DRUPAL_ROOT . '/profiles/baas_platform/data/groups_static_data.json';
    $static_data = json_decode(file_get_contents($static_data_path), TRUE);

    if (!isset($static_data['system_users'])) {
      return [];
    }

    $system_users = $static_data['system_users'];

    try {
      // 1. 导入 Drupal 用户
      if (isset($system_users['drupal_users'])) {
        foreach ($system_users['drupal_users'] as $user_data) {

          // 检查用户是否已存在
          $existing_user = \Drupal::entityTypeManager()
            ->getStorage('user')
            ->loadByProperties(['name' => $user_data['name']]);

          if (empty($existing_user)) {
            // 创建新用户
            $user = \Drupal\user\Entity\User::create([
              'name' => $user_data['name'],
              'mail' => $user_data['mail'],
              'status' => $user_data['status'],
              'timezone' => $user_data['timezone'],
              'created' => $user_data['created'],
              'changed' => $user_data['changed'],
              'access' => $user_data['access'],
              'login' => $user_data['login'],
              'init' => $user_data['init'],
            ]);
            $user->save();

            // 直接更新密码哈希到数据库（绕过Drupal的密码验证）
            $this->database->update('users_field_data')
              ->fields(['pass' => $user_data['pass']])
              ->condition('uid', $user->id())
              ->execute();

            $imported_users['drupal_users'][] = $user_data['name'];
          }
        }
      }

      // 2. 导入用户角色关联
      if (isset($system_users['user_roles'])) {
        foreach ($system_users['user_roles'] as $role_data) {
          // 检查是否已存在相同的角色关联
          $existing_role = $this->database->select('user__roles', 'ur')
            ->condition('entity_id', $role_data['entity_id'])
            ->condition('deleted', $role_data['deleted'])
            ->condition('delta', $role_data['delta'])
            ->condition('langcode', $role_data['langcode'])
            ->condition('roles_target_id', $role_data['roles_target_id'])
            ->countQuery()
            ->execute()
            ->fetchField();

          if (!$existing_role) {
            // 直接插入到 user__roles 表
            $this->database->insert('user__roles')
              ->fields([
                'bundle' => $role_data['bundle'],
                'deleted' => $role_data['deleted'],
                'entity_id' => $role_data['entity_id'],
                'revision_id' => $role_data['revision_id'],
                'langcode' => $role_data['langcode'],
                'delta' => $role_data['delta'],
                'roles_target_id' => $role_data['roles_target_id'],
              ])
              ->execute();
          }
        }

        $imported_users['user_roles'] = count($system_users['user_roles']);
      }

      // 3. 导入用户-租户映射
      if (isset($system_users['user_tenant_mapping'])) {
        foreach ($system_users['user_tenant_mapping'] as $mapping_data) {
          // 确保表存在
          if ($this->database->schema()->tableExists('baas_user_tenant_mapping')) {
            $current_time = time();
            $this->database->insert('baas_user_tenant_mapping')
              ->fields([
                'user_id' => $mapping_data['user_id'],
                'tenant_id' => $mapping_data['tenant_id'],
                'role' => $mapping_data['role'],
                'status' => $mapping_data['status'],
                'created' => $current_time,
                'updated' => $current_time,
              ])
              ->execute();
          }
        }

        $imported_users['user_tenant_mapping'] = count($system_users['user_tenant_mapping']);
      }

      // 4. 导入项目成员关系
      if (isset($system_users['project_members'])) {
        foreach ($system_users['project_members'] as $member_data) {
          // 确保表存在
          if ($this->database->schema()->tableExists('baas_project_members')) {
            // 检查项目成员关系是否已存在
            $existing_member = $this->database->select('baas_project_members', 'bpm')
              ->condition('project_id', $member_data['project_id'])
              ->condition('user_id', $member_data['user_id'])
              ->countQuery()
              ->execute()
              ->fetchField();

            if (!$existing_member) {
              $current_time = time();

              $this->database->insert('baas_project_members')
                ->fields([
                  'project_id' => $member_data['project_id'],
                  'user_id' => $member_data['user_id'],
                  'role' => $member_data['role'],
                  'permissions' => $member_data['permissions'],
                  'status' => $member_data['status'],
                  'joined_at' => $current_time,
                  'updated_at' => $current_time,
                ])
                ->execute();
            }
          }
        }

        $imported_users['project_members'] = count($system_users['project_members']);
      }

    } catch (\Exception $e) {
      // 静默处理错误
    }

    return $imported_users;
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