<?php

namespace Drupal\baas_platform_demo_data;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Groups项目动态实体和数据导入助手
 *
 * 参照demo_umami的InstallHelper模式，专门处理BaaS动态实体的创建和数据导入
 * 保持系统用户导入逻辑不变，重点解决动态实体时序问题
 *
 * @internal
 *   This code is only for use by the BaaS Platform Demo Data module.
 */
class InstallHelper implements ContainerInjectionInterface {

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
   * State service.
   */
  protected StateInterface $state;

  /**
   * Module handler.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * 用户名到新UID的映射表
   */
  protected array $userUidMapping = [];

  /**
   * 文件ID映射表（旧fid -> 新fid）
   */
  protected array $fileIdMapping = [];

  /**
   * Groups项目原始配置（保持不变）
   */
  protected array $groupsProjectConfig = [
    'tenant_id' => 'tenant_7375b0cd',
    'project_id' => 'tenant_7375b0cd_project_6888d012be80c',
    'project_name' => 'Groups Sports Activity Management',
    'entities' => ['users', 'activities', 'teams', 'positions', 'user_activities', 'system_config', 'test'],
    'table_prefix' => 'baas_00403b_',
  ];

  /**
   * 构造函数
   */
  public function __construct(
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory,
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    StateInterface $state,
    ModuleHandlerInterface $module_handler
  ) {
    $this->database = $database;
    $this->logger = $logger_factory->get('baas_platform_demo_data');
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->state = $state;
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('logger.factory'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('state'),
      $container->get('module_handler')
    );
  }

  /**
   * 导入完整的Groups项目（推荐方法）
   *
   * 统一处理所有导入步骤，包括系统用户、配置、动态实体等
   * 比分散在两个类中更可靠和一致
   */
  public function importCompleteGroupsProject(): array {
    // 临时禁用所有错误显示，避免动态实体创建过程中的PHP 8.1+ Deprecated警告
    $original_error_level = error_reporting();
    $original_display_errors = ini_get('display_errors');
    $original_log_errors = ini_get('log_errors');

    // 完全禁用错误显示，但保留日志记录
    error_reporting(0);
    ini_set('display_errors', 'Off');
    ini_set('log_errors', 'On');

    $this->logger->info('开始完整导入Groups项目（系统用户+动态实体+业务数据）');

    $import_results = [
      'success' => TRUE,
      'system_users_imported' => [],
      'tenant_created' => FALSE,
      'project_created' => FALSE,
      'entities_imported' => [],
      'entity_tables_created' => [],
      'entity_files_deployed' => [],
      'data_imported' => [],
      'entity_types_registered' => [],
      'user_permissions_fixed' => [],
      'errors' => [],
    ];

    try {
      // 1. 导入系统用户（包含完整的Drupal用户创建和权限）
      $import_results['system_users_imported'] = $this->importSystemUsers();

      // 2. 导入租户配置
      $import_results['tenant_created'] = $this->importTenantConfig();

      // 3. 导入项目配置
      $import_results['project_created'] = $this->importProjectConfig();

      // 4. 导入实体模板定义
      $import_results['entities_imported'] = $this->importEntityTemplates();

      // 5. 创建动态实体数据表（关键步骤）
      $import_results['entity_tables_created'] = $this->createEntityTables();

      // 6. 部署动态实体PHP类文件
      $import_results['entity_files_deployed'] = $this->deployEntityFiles();

      // 7. 注册实体类型到Drupal系统
      $import_results['entity_types_registered'] = $this->registerEntityTypesWithDrupal();

      // 8. 导入上传文件记录（必须在业务数据导入之前，以建立文件ID映射）
      $import_results['files_imported'] = $this->importUploadedFiles();

      // 9. 导入实时功能配置
      $import_results['realtime_config_imported'] = $this->importRealtimeConfig();

      // 10. 导入业务实体数据（使用文件ID映射）
      $import_results['data_imported'] = $this->importBusinessData();

      // 11. 设置用户权限和租户映射
      $import_results['user_permissions_fixed'] = $this->setupUserPermissionsAndMappings();

      // 12. 验证完整导入结果
      $validation = $this->validateCompleteImport();
      if (!$validation['valid']) {
        $import_results['errors'] = array_merge($import_results['errors'], $validation['issues']);
        $this->logger->warning('Groups完整导入验证发现问题: @issues', [
          '@issues' => implode(', ', $validation['issues'])
        ]);
      }

      $this->logger->info('Groups项目完整导入成功');

    } catch (\Exception $e) {
      $import_results['success'] = FALSE;
      $import_results['errors'][] = $e->getMessage();
      $this->logger->error('Groups项目完整导入失败: @error', ['@error' => $e->getMessage()]);
    }
    finally {
      // 恢复原始错误显示设置
      error_reporting($original_error_level);
      ini_set('display_errors', $original_display_errors);
      ini_set('log_errors', $original_log_errors);
    }

    return $import_results;
  }

  /**
   * 导入系统用户和权限映射（从GroupsStaticDataImporter移植过来）
   */
  protected function importSystemUsers(): array {
    $imported_users = [];
    $this->userUidMapping = []; // 创建用户名到新UID的映射

    // 从静态数据文件读取系统用户数据
    $static_data_path = DRUPAL_ROOT . '/profiles/baas_platform/data/groups_static_data.json';
    $static_data = json_decode(file_get_contents($static_data_path), TRUE);

    if (!isset($static_data['system_users'])) {
      $this->logger->warning('静态数据文件中缺少系统用户配置');
      return [];
    }

    $system_users = $static_data['system_users'];

    try {
      // 1. 导入 Drupal 用户
      if (isset($system_users['drupal_users'])) {
        foreach ($system_users['drupal_users'] as $user_data) {

          // 检查用户是否已存在
          $existing_user = $this->entityTypeManager
            ->getStorage('user')
            ->loadByProperties(['name' => $user_data['name']]);

          if (empty($existing_user)) {
            // 创建新用户
            $user = $this->entityTypeManager->getStorage('user')->create([
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
            // 记录用户名到新UID的映射
            $this->userUidMapping[$user_data['name']] = $user->id();
            $this->userUidMapping[$user_data['uid']] = $user->id(); // 同时记录旧UID到新UID的映射
            $this->logger->info('系统用户导入成功: @user (旧UID: @old_uid -> 新UID: @new_uid)', [
              '@user' => $user_data['name'],
              '@old_uid' => $user_data['uid'],
              '@new_uid' => $user->id()
            ]);
          } else {
            $existing_user_obj = reset($existing_user);
            // 记录已存在用户的映射
            $this->userUidMapping[$user_data['name']] = $existing_user_obj->id();
            $this->userUidMapping[$user_data['uid']] = $existing_user_obj->id();
            $this->logger->info('系统用户已存在，记录映射: @user (旧UID: @old_uid -> 现有UID: @existing_uid)', [
              '@user' => $user_data['name'],
              '@old_uid' => $user_data['uid'],
              '@existing_uid' => $existing_user_obj->id()
            ]);
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
        $this->logger->info('用户角色关联导入成功: @count 条', ['@count' => count($system_users['user_roles'])]);
      }

      // 3. 导入用户-租户映射
      if (isset($system_users['user_tenant_mapping'])) {
        foreach ($system_users['user_tenant_mapping'] as $mapping_data) {
          // 确保表存在
          if ($this->database->schema()->tableExists('baas_user_tenant_mapping')) {
            // 检查是否已存在
            $existing_mapping = $this->database->select('baas_user_tenant_mapping', 'butm')
              ->condition('user_id', $mapping_data['user_id'])
              ->condition('tenant_id', $mapping_data['tenant_id'])
              ->countQuery()
              ->execute()
              ->fetchField();

            if (!$existing_mapping) {
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
        }

        $imported_users['user_tenant_mapping'] = count($system_users['user_tenant_mapping']);
        $this->logger->info('用户-租户映射导入成功: @count 条', ['@count' => count($system_users['user_tenant_mapping'])]);
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
        $this->logger->info('项目成员关系导入成功: @count 条', ['@count' => count($system_users['project_members'])]);
      }

    } catch (\Exception $e) {
      $this->logger->error('系统用户导入失败: @error', ['@error' => $e->getMessage()]);
      throw $e; // 重新抛出异常，确保导入流程停止
    }

    return $imported_users;
  }

  /**
   * 设置用户权限和租户映射（整合原有逻辑）
   */
  protected function setupUserPermissionsAndMappings(): array {
    $fixed_users = [];
    $user_storage = $this->entityTypeManager->getStorage('user');

    try {
      // 修复admin用户权限（与静态数据中的角色定义一致）
      $admin_users = $user_storage->loadByProperties(['name' => 'admin']);
      $admin_user = !empty($admin_users) ? reset($admin_users) : NULL;
      if ($admin_user) {
        $admin_user->addRole('administrator');
        // 注意：静态数据中admin只有administrator角色
        $admin_user->save();
        $fixed_users['admin'] = $admin_user->id();
        $this->logger->info('修复admin用户权限 (UID: @uid)', ['@uid' => $admin_user->id()]);
      }

      // 修复user1权限（与静态数据中的角色定义一致）
      $user1_users = $user_storage->loadByProperties(['name' => 'user1']);
      $user1 = !empty($user1_users) ? reset($user1_users) : NULL;
      if ($user1) {
        $user1->addRole('project_manager');
        // 注意：静态数据中user1只有project_manager角色
        $user1->save();
        $fixed_users['user1'] = $user1->id();
        $this->logger->info('修复user1权限 (UID: @uid)', ['@uid' => $user1->id()]);
      }

      // 修复user2权限（与静态数据中的角色定义一致）
      $user2_users = $user_storage->loadByProperties(['name' => 'user2']);
      $user2 = !empty($user2_users) ? reset($user2_users) : NULL;
      if ($user2) {
        $user2->addRole('project_editor');
        // 注意：静态数据中user2只有project_editor角色
        $user2->save();
        $fixed_users['user2'] = $user2->id();
        $this->logger->info('修复user2权限 (UID: @uid)', ['@uid' => $user2->id()]);
      }

      // 确保租户映射存在
      if ($this->moduleHandler->moduleExists('baas_auth')) {
        try {
          $tenant_mapping_service = \Drupal::service('baas_auth.user_tenant_mapping');

          // 为user1创建租户所有者映射
          if ($user1) {
            try {
              $tenant_mapping_service->addUserToTenant(
                $user1->id(),
                'tenant_7375b0cd',
                'tenant_admin',
                TRUE  // is_owner
              );
              $this->logger->info('创建租户所有者映射: user1 (UID: @uid)', ['@uid' => $user1->id()]);
            } catch (\Exception $e) {
              $this->logger->warning('租户所有者映射失败 user1: @error', ['@error' => $e->getMessage()]);
            }
          }

          // 为admin创建租户管理员映射
          if ($admin_user) {
            try {
              $tenant_mapping_service->addUserToTenant(
                $admin_user->id(),
                'tenant_7375b0cd',
                'tenant_admin',
                FALSE  // not owner
              );
              $this->logger->info('创建租户管理员映射: admin (UID: @uid)', ['@uid' => $admin_user->id()]);
            } catch (\Exception $e) {
              $this->logger->warning('租户管理员映射失败 admin: @error', ['@error' => $e->getMessage()]);
            }
          }

          // 为user2创建租户用户映射
          if ($user2) {
            try {
              $tenant_mapping_service->addUserToTenant(
                $user2->id(),
                'tenant_7375b0cd',
                'tenant_user',
                FALSE  // not owner
              );
              $this->logger->info('创建租户用户映射: user2 (UID: @uid)', ['@uid' => $user2->id()]);
            } catch (\Exception $e) {
              $this->logger->warning('租户用户映射失败 user2: @error', ['@error' => $e->getMessage()]);
            }
          }
        } catch (\Exception $e) {
          $this->logger->error('租户映射服务不可用: @error', ['@error' => $e->getMessage()]);
        }
      }

      // 设置项目成员关系
      if ($this->moduleHandler->moduleExists('baas_project')) {
        $project_member_service = \Drupal::service('baas_project.member_manager');

        try {
          // user1作为项目所有者
          if ($user1) {
            $project_member_service->addMember('tenant_7375b0cd_project_6888d012be80c', $user1->id(), 'owner', [
              'permissions' => ['manage_entities', 'manage_data', 'manage_members', 'manage_project'],
            ]);
          }

          // user2作为项目编辑者
          if ($user2) {
            $project_member_service->addMember('tenant_7375b0cd_project_6888d012be80c', $user2->id(), 'editor', [
              'permissions' => ['manage_entities', 'manage_data'],
            ]);
          }

        } catch (\Exception $e) {
          $this->logger->warning('项目成员关系设置失败: @error', [
            '@error' => $e->getMessage(),
          ]);
        }
      }

      $this->logger->info('用户权限和映射设置完成');

    } catch (\Exception $e) {
      $this->logger->error('用户权限设置失败: @error', ['@error' => $e->getMessage()]);
    }

    return $fixed_users;
  }

  /**
   * 转换记录数据以匹配数据库字段类型和结构
   */
  protected function transformRecordData(string $entity_name, array $record): array {
    // 转换布尔值为整数（PostgreSQL smallint）
    $boolean_fields = ['is_demo', 'is_locked', 'is_temporary', 'is_creator_demo'];
    foreach ($boolean_fields as $field) {
      if (isset($record[$field])) {
        // 转换 false/true 为 0/1，空字符串为 0
        if ($record[$field] === '' || $record[$field] === false || $record[$field] === 'false') {
          $record[$field] = 0;
        } elseif ($record[$field] === true || $record[$field] === 'true') {
          $record[$field] = 1;
        } else {
          $record[$field] = (int) $record[$field];
        }
      }
    }

    // 所有实体都需要 title 字段（必需），优先使用现有的title，或者从其他字段推导
    if (!isset($record['title']) || $record['title'] === null) {
      switch ($entity_name) {
        case 'users':
          $record['title'] = $record['username'] ?? 'User';
          break;
        case 'teams':
        case 'positions':
          $record['title'] = $record['name'] ?? 'Item';
          break;
        case 'activities':
          $record['title'] = $record['title'] ?? 'Activity';
          break;
        case 'user_activities':
          $record['title'] = $record['display_name'] ?? 'User Activity';
          break;
        case 'system_config':
          $record['title'] = $record['key'] ?? 'Config';
          break;
        default:
          $record['title'] = 'Item';
          break;
      }
    }

    // 实体特定的字段映射和转换
    switch ($entity_name) {
      case 'users':
        // 确保所有字符串字段不为空
        $string_fields = ['role', 'provider', 'wx_open_id', 'wx_session_key'];
        foreach ($string_fields as $field) {
          if (isset($record[$field]) && $record[$field] === null) {
            $record[$field] = '';
          }
        }
        break;

      case 'positions':
        // 位置表的字符串字段处理
        if (isset($record['custom_user_name']) && $record['custom_user_name'] === null) {
          $record['custom_user_name'] = '';
        }
        break;
    }

    return $record;
  }

  /**
   * 应用文件ID映射，将实体数据中的旧fid替换为新fid
   */
  protected function applyFileIdMapping(string $entity_name, array $record): array {
    // 如果没有文件ID映射，直接返回
    if (empty($this->fileIdMapping)) {
      return $record;
    }

    // 定义每个实体类型中包含文件引用的字段
    $file_fields_by_entity = [
      'users' => ['avatar'],      // 用户头像
      'teams' => ['logo'],         // 队伍标志
      'activities' => [],          // 活动暂无文件字段
      'positions' => [],           // 位置暂无文件字段
      'user_activities' => [],     // 用户活动暂无文件字段
    ];

    // 检查当前实体类型是否有文件字段
    if (!isset($file_fields_by_entity[$entity_name])) {
      return $record;
    }

    $file_fields = $file_fields_by_entity[$entity_name];

    // 遍历文件字段，应用ID映射
    foreach ($file_fields as $field_name) {
      if (isset($record[$field_name]) && !empty($record[$field_name])) {
        $old_fid = $record[$field_name];

        // 如果是字符串类型的fid，转换为整数
        if (is_string($old_fid)) {
          $old_fid = (int) $old_fid;
        }

        // 如果映射表中存在此fid，则替换
        if (isset($this->fileIdMapping[$old_fid])) {
          $new_fid = $this->fileIdMapping[$old_fid];
          $record[$field_name] = $new_fid;

          $this->logger->info('文件ID映射应用: @entity.@field 旧FID @old_fid -> 新FID @new_fid', [
            '@entity' => $entity_name,
            '@field' => $field_name,
            '@old_fid' => $old_fid,
            '@new_fid' => $new_fid
          ]);
        } else {
          $this->logger->warning('文件ID映射未找到: @entity.@field FID @fid', [
            '@entity' => $entity_name,
            '@field' => $field_name,
            '@fid' => $old_fid
          ]);
        }
      }
    }

    return $record;
  }

  /**
   * 验证完整导入结果
   */
  protected function validateCompleteImport(): array {
    $validation_results = [
      'valid' => TRUE,
      'checks' => [],
      'issues' => [],
    ];

    // 检查系统用户
    try {
      $system_users = ['admin', 'user1', 'user2'];
      foreach ($system_users as $username) {
        $users = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $username]);
        $user_exists = !empty($users);
        $validation_results['checks'][$username . '_exists'] = $user_exists;

        if (!$user_exists) {
          $validation_results['valid'] = FALSE;
          $validation_results['issues'][] = "系统用户不存在: {$username}";
        }
      }
    } catch (\Exception $e) {
      $validation_results['issues'][] = "系统用户检查失败: {$e->getMessage()}";
    }

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
      // 使用项目表名生成器来获取正确的表名
      $table_name_generator = \Drupal::service('baas_project.table_name_generator');
      $table_name = $table_name_generator->generateTableName(
        $this->groupsProjectConfig['tenant_id'],
        $this->groupsProjectConfig['project_id'],
        $entity_name
      );

      try {
        $table_exists = $this->database->schema()->tableExists($table_name);
        $validation_results['checks'][$entity_name . '_table_exists'] = $table_exists;

        if ($table_exists) {
          $record_count = $this->database->select($table_name)
            ->condition('tenant_id', $this->groupsProjectConfig['tenant_id'])
            ->condition('project_id', $this->groupsProjectConfig['project_id'])
            ->countQuery()
            ->execute()
            ->fetchField();

          $validation_results['checks'][$entity_name . '_demo_records'] = $record_count;
        } else {
          $validation_results['valid'] = FALSE;
          $validation_results['issues'][] = "数据表不存在: {$table_name}";
        }
      } catch (\Exception $e) {
        $validation_results['issues'][] = "数据表检查失败 {$table_name}: {$e->getMessage()}";
      }
    }

    return $validation_results;
  }

  /**
   * 专门导入动态实体（不包括系统用户和基础配置）
   *
   * 与原有GroupsStaticDataImporter配合使用，只处理动态实体表创建和数据导入
   * @deprecated 推荐使用 importCompleteGroupsProject() 方法
   */
  public function importDynamicEntitiesOnly(): array {
    $this->logger->info('开始导入动态实体和业务数据（跳过系统用户和基础配置）');

    $import_results = [
      'success' => TRUE,
      'entity_tables_created' => [],
      'entity_files_deployed' => [],
      'data_imported' => [],
      'entity_types_registered' => [],
      'errors' => [],
    ];

    try {
      // 1. 创建动态实体数据表（关键步骤）
      $import_results['entity_tables_created'] = $this->createEntityTables();

      // 2. 部署动态实体PHP类文件
      $import_results['entity_files_deployed'] = $this->deployEntityFiles();

      // 3. 注册实体类型到Drupal系统
      $import_results['entity_types_registered'] = $this->registerEntityTypesWithDrupal();

      // 4. 导入业务实体数据
      $import_results['data_imported'] = $this->importBusinessData();

      // 5. 验证导入结果
      $validation = $this->validateDynamicEntitiesImport();
      if (!$validation['valid']) {
        $import_results['errors'] = array_merge($import_results['errors'], $validation['issues']);
        $this->logger->warning('动态实体导入验证发现问题: @issues', [
          '@issues' => implode(', ', $validation['issues'])
        ]);
      }

      $this->logger->info('动态实体导入完成');

    } catch (\Exception $e) {
      $import_results['success'] = FALSE;
      $import_results['errors'][] = $e->getMessage();
      $this->logger->error('动态实体导入失败: @error', ['@error' => $e->getMessage()]);
    }

    return $import_results;
  }

  /**
   * 导入Groups项目完整数据（保持系统用户导入逻辑不变）
   *
   * 重点处理动态实体创建和业务数据导入的时序问题
   */
  public function importGroupsContent(): array {
    $this->logger->info('开始导入Groups项目内容（动态实体和业务数据）');

    $import_results = [
      'success' => TRUE,
      'tenant_created' => FALSE,
      'project_created' => FALSE,
      'entities_imported' => [],
      'entity_tables_created' => [],
      'entity_files_deployed' => [],
      'data_imported' => [],
      'entity_types_registered' => [],
      'errors' => [],
    ];

    try {
      // 1. 检查并导入租户配置
      $import_results['tenant_created'] = $this->importTenantConfig();

      // 2. 检查并导入项目配置
      $import_results['project_created'] = $this->importProjectConfig();

      // 3. 导入实体模板定义
      $import_results['entities_imported'] = $this->importEntityTemplates();

      // 4. 创建动态实体数据表（关键步骤）
      $import_results['entity_tables_created'] = $this->createEntityTables();

      // 5. 部署动态实体PHP类文件
      $import_results['entity_files_deployed'] = $this->deployEntityFiles();

      // 6. 注册实体类型到Drupal系统
      $import_results['entity_types_registered'] = $this->registerEntityTypesWithDrupal();

      // 7. 导入业务实体数据
      $import_results['data_imported'] = $this->importBusinessData();

      // 8. 验证导入结果
      $validation = $this->validateImport();
      if (!$validation['valid']) {
        $import_results['errors'] = array_merge($import_results['errors'], $validation['issues']);
        $this->logger->warning('Groups数据导入验证发现问题: @issues', [
          '@issues' => implode(', ', $validation['issues'])
        ]);
      }

      $this->logger->info('Groups项目内容导入完成');

    } catch (\Exception $e) {
      $import_results['success'] = FALSE;
      $import_results['errors'][] = $e->getMessage();
      $this->logger->error('Groups项目内容导入失败: @error', ['@error' => $e->getMessage()]);
    }

    return $import_results;
  }

  /**
   * 导入租户配置（如果不存在）
   */
  protected function importTenantConfig(): bool {
    if (!$this->moduleHandler->moduleExists('baas_tenant')) {
      $this->logger->warning('baas_tenant模块未启用，跳过租户配置导入');
      return FALSE;
    }

    try {
      // 检查租户是否已存在
      $existing_tenant = $this->database->select('baas_tenant_config', 'btc')
        ->condition('tenant_id', $this->groupsProjectConfig['tenant_id'])
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($existing_tenant) {
        $this->logger->info('租户配置已存在: @tenant_id', [
          '@tenant_id' => $this->groupsProjectConfig['tenant_id']
        ]);
        return TRUE;
      }

      // 从静态数据文件读取租户配置
      $static_data_path = DRUPAL_ROOT . '/profiles/baas_platform/data/groups_static_data.json';
      if (!file_exists($static_data_path)) {
        throw new \Exception('静态数据文件不存在: ' . $static_data_path);
      }

      $static_data = json_decode(file_get_contents($static_data_path), TRUE);

      if (!isset($static_data['baas_config']['tenant_config'])) {
        throw new \Exception('静态数据文件中缺少租户配置');
      }

      foreach ($static_data['baas_config']['tenant_config'] as $tenant_data) {
        if ($tenant_data['tenant_id'] === $this->groupsProjectConfig['tenant_id']) {
          // 查找租户所有者（使用新的UID映射）
          $owner_uid = $this->userUidMapping['admin'] ?? 2; // 默认使用 admin 的新UID
          if (isset($static_data['system_users']['user_tenant_mapping'])) {
            foreach ($static_data['system_users']['user_tenant_mapping'] as $mapping) {
              if ($mapping['tenant_id'] === $tenant_data['tenant_id'] && $mapping['role'] === 'tenant_owner') {
                // 使用映射表获取新的UID
                $owner_uid = $this->userUidMapping[$mapping['user_id']] ?? $mapping['user_id'];
                break;
              }
            }
          }

          // 插入租户配置
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

          $this->logger->info('租户配置导入成功: @tenant_id', [
            '@tenant_id' => $tenant_data['tenant_id']
          ]);
          return TRUE;
        }
      }

      throw new \Exception('未找到目标租户配置: ' . $this->groupsProjectConfig['tenant_id']);

    } catch (\Exception $e) {
      $this->logger->error('租户配置导入失败: @error', ['@error' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * 导入项目配置（如果不存在）
   */
  protected function importProjectConfig(): bool {
    if (!$this->moduleHandler->moduleExists('baas_project')) {
      $this->logger->warning('baas_project模块未启用，跳过项目配置导入');
      return FALSE;
    }

    try {
      // 检查项目是否已存在
      $existing_project = $this->database->select('baas_project_config', 'bpc')
        ->condition('project_id', $this->groupsProjectConfig['project_id'])
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($existing_project) {
        $this->logger->info('项目配置已存在: @project_id', [
          '@project_id' => $this->groupsProjectConfig['project_id']
        ]);
        return TRUE;
      }

      // 从静态数据文件读取项目配置
      $static_data_path = DRUPAL_ROOT . '/profiles/baas_platform/data/groups_static_data.json';
      $static_data = json_decode(file_get_contents($static_data_path), TRUE);

      if (!isset($static_data['baas_config']['project_config'])) {
        throw new \Exception('静态数据文件中缺少项目配置');
      }

      foreach ($static_data['baas_config']['project_config'] as $project_data) {
        if ($project_data['project_id'] === $this->groupsProjectConfig['project_id']) {
          // 查找项目所有者（使用新的UID映射）
          $owner_uid = $this->userUidMapping['admin'] ?? 2; // 默认使用 admin 的新UID
          if (isset($static_data['system_users']['project_members'])) {
            foreach ($static_data['system_users']['project_members'] as $member) {
              if ($member['project_id'] === $project_data['project_id'] && $member['role'] === 'owner') {
                // 使用映射表获取新的UID
                $owner_uid = $this->userUidMapping[$member['user_id']] ?? $member['user_id'];
                break;
              }
            }
          }

          // 生成机器名
          $machine_name = strtolower(str_replace([' ', '-'], '_', $project_data['name']));

          // 插入项目配置
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

          $this->logger->info('项目配置导入成功: @project_id', [
            '@project_id' => $project_data['project_id']
          ]);
          return TRUE;
        }
      }

      throw new \Exception('未找到目标项目配置: ' . $this->groupsProjectConfig['project_id']);

    } catch (\Exception $e) {
      $this->logger->error('项目配置导入失败: @error', ['@error' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * 导入实体模板定义
   */
  protected function importEntityTemplates(): array {
    $imported_entities = [];

    if (!$this->moduleHandler->moduleExists('baas_entity')) {
      $this->logger->warning('baas_entity模块未启用，跳过实体模板导入');
      return [];
    }

    try {
      // 从静态数据文件读取实体模板
      $static_data_path = DRUPAL_ROOT . '/profiles/baas_platform/data/groups_static_data.json';
      $static_data = json_decode(file_get_contents($static_data_path), TRUE);

      if (!isset($static_data['baas_config']['entity_templates'])) {
        throw new \Exception('静态数据文件中缺少实体模板配置');
      }

      // 创建原始template_id到新template_id的映射表
      $template_id_mapping = [];

      // 1. 导入实体模板并建立ID映射
      foreach ($static_data['baas_config']['entity_templates'] as $template_data) {
        // 检查是否已存在
        $existing = $this->database->select('baas_entity_template', 'bet')
          ->condition('tenant_id', $template_data['tenant_id'])
          ->condition('project_id', $template_data['project_id'])
          ->condition('name', $template_data['name'])
          ->countQuery()
          ->execute()
          ->fetchField();

        if (!$existing) {
          $new_template_id = $this->database->insert('baas_entity_template')
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

          // 记录原始ID到新ID的映射
          $template_id_mapping[$template_data['id']] = $new_template_id;
          $imported_entities[] = $template_data['name'];
          $this->logger->info('实体模板导入成功: @entity (原始ID: @old_id -> 新ID: @new_id)', [
            '@entity' => $template_data['name'],
            '@old_id' => $template_data['id'],
            '@new_id' => $new_template_id
          ]);
        } else {
          // 获取已存在模板的ID
          $existing_template_id = $this->database->select('baas_entity_template', 'bet')
            ->fields('bet', ['id'])
            ->condition('tenant_id', $template_data['tenant_id'])
            ->condition('project_id', $template_data['project_id'])
            ->condition('name', $template_data['name'])
            ->execute()
            ->fetchField();

          $template_id_mapping[$template_data['id']] = $existing_template_id;
          $this->logger->info('实体模板已存在: @entity (原始ID: @old_id -> 现有ID: @existing_id)', [
            '@entity' => $template_data['name'],
            '@old_id' => $template_data['id'],
            '@existing_id' => $existing_template_id
          ]);
        }
      }

      // 2. 导入实体字段定义（使用template_id映射）
      if (isset($static_data['baas_config']['entity_fields'])) {
        foreach ($static_data['baas_config']['entity_fields'] as $field_data) {
          // 获取映射后的template_id
          $original_template_id = $field_data['template_id'];
          if (!isset($template_id_mapping[$original_template_id])) {
            $this->logger->warning('字段 @field 的template_id @template_id 无法映射，跳过', [
              '@field' => $field_data['name'],
              '@template_id' => $original_template_id
            ]);
            continue;
          }

          $new_template_id = $template_id_mapping[$original_template_id];

          // 检查是否已存在
          $existing_field = $this->database->select('baas_entity_field', 'bef')
            ->condition('template_id', $new_template_id)
            ->condition('name', $field_data['name'])
            ->countQuery()
            ->execute()
            ->fetchField();

          if (!$existing_field) {
            $this->database->insert('baas_entity_field')
              ->fields([
                'template_id' => $new_template_id,
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

            $this->logger->info('字段导入成功: @field (原始template_id: @old_id -> 新template_id: @new_id)', [
              '@field' => $field_data['name'],
              '@old_id' => $original_template_id,
              '@new_id' => $new_template_id
            ]);
          }
        }
      }

      // 3. 注册实体类文件信息
      if (isset($static_data['baas_config']['entity_class_files'])) {
        // 检查表是否存在
        if ($this->database->schema()->tableExists('baas_entity_class_files')) {
          foreach ($static_data['baas_config']['entity_class_files'] as $class_data) {
            // 检查是否已存在
            $existing_class = $this->database->select('baas_entity_class_files', 'becf')
              ->condition('entity_type_id', $class_data['entity_type_id'])
              ->condition('class_type', $class_data['class_type'])
              ->countQuery()
              ->execute()
              ->fetchField();

            if (!$existing_class) {
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
          }
        }
      }

    } catch (\Exception $e) {
      $this->logger->error('实体模板导入失败: @error', ['@error' => $e->getMessage()]);
    }

    return $imported_entities;
  }

  /**
   * 创建动态实体数据表（关键步骤）
   */
  protected function createEntityTables(): array {
    $created_tables = [];

    if (!$this->moduleHandler->moduleExists('baas_entity')) {
      return [];
    }

    try {
      // 检查是否存在预导出的完整DDL文件
      $ddl_file = DRUPAL_ROOT . '/profiles/baas_platform/data/baas_00403b_schema.sql';

      if (file_exists($ddl_file)) {
        $this->logger->info('检测到预导出DDL文件，使用DDL创建表结构');

        // 读取DDL内容
        $ddl_content = file_get_contents($ddl_file);

        if ($ddl_content) {
          try {
            // 使用PDO直接执行完整DDL（支持多条语句）
            $pdo = $this->database->getConnectionOptions();
            $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s',
              $pdo['host'],
              $pdo['port'] ?? 5432,
              $pdo['database']
            );
            $pdo_connection = new \PDO($dsn, $pdo['username'], $pdo['password']);
            $pdo_connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            // 执行完整DDL
            $pdo_connection->exec($ddl_content);

            $this->logger->info('DDL执行成功');

            // 验证所有表是否创建成功
            $table_name_generator = \Drupal::service('baas_project.table_name_generator');
            foreach ($this->groupsProjectConfig['entities'] as $entity_name) {
              $table_name = $table_name_generator->generateTableName(
                $this->groupsProjectConfig['tenant_id'],
                $this->groupsProjectConfig['project_id'],
                $entity_name
              );

              if ($this->database->schema()->tableExists($table_name)) {
                $created_tables[] = $table_name;
                $this->logger->info('从DDL创建表成功: @table', ['@table' => $table_name]);
              } else {
                $this->logger->warning('从DDL创建表失败: @table', ['@table' => $table_name]);
              }
            }

            $this->logger->info('使用完整DDL创建了 @count 个表', ['@count' => count($created_tables)]);
            return $created_tables;

          } catch (\Exception $e) {
            $this->logger->error('DDL执行失败，降级到EntityGenerator: @error', ['@error' => $e->getMessage()]);
            // 继续执行下面的EntityGenerator逻辑
          }
        }
      } else {
        $this->logger->info('未找到DDL文件，使用EntityGenerator创建表结构');
      }

      // 降级方案：使用EntityGenerator创建表（保持向后兼容）
      $entity_generator = \Drupal::service('baas_entity.entity_generator');

      foreach ($this->groupsProjectConfig['entities'] as $entity_name) {
        // 使用项目表名生成器来获取正确的表名
        $table_name_generator = \Drupal::service('baas_project.table_name_generator');
        $table_name = $table_name_generator->generateTableName(
          $this->groupsProjectConfig['tenant_id'],
          $this->groupsProjectConfig['project_id'],
          $entity_name
        );

        // 检查表是否已存在
        if ($this->database->schema()->tableExists($table_name)) {
          $this->logger->info('数据表已存在: @table', ['@table' => $table_name]);
          continue;
        }

        // 获取实体模板
        $template = $this->database->select('baas_entity_template', 'bet')
          ->fields('bet')
          ->condition('tenant_id', $this->groupsProjectConfig['tenant_id'])
          ->condition('project_id', $this->groupsProjectConfig['project_id'])
          ->condition('name', $entity_name)
          ->execute()
          ->fetchObject();

        if (!$template) {
          $this->logger->warning('未找到实体模板: @entity', ['@entity' => $entity_name]);
          continue;
        }

        // 获取实体字段
        $fields = $this->database->select('baas_entity_field', 'bef')
          ->fields('bef')
          ->condition('template_id', $template->id)
          ->execute()
          ->fetchAll();

        // 使用EntityGenerator创建实体类型和表
        $result = $entity_generator->createEntityType($template, $fields);

        if ($result) {
          $created_tables[] = $table_name;
          $this->logger->info('动态实体表创建成功: @table', ['@table' => $table_name]);
        } else {
          $this->logger->error('动态实体表创建失败: @table', ['@table' => $table_name]);
        }
      }

    } catch (\Exception $e) {
      $this->logger->error('动态实体表创建过程失败: @error', ['@error' => $e->getMessage()]);
    }

    return $created_tables;
  }

  /**
   * 部署动态实体PHP类文件
   */
  protected function deployEntityFiles(): array {
    $deployed_files = [];

    // 从发行版打包的实体文件目录读取
    $static_entities_path = DRUPAL_ROOT . '/profiles/baas_platform/data/entity_files';

    if (!is_dir($static_entities_path)) {
      $this->logger->warning('静态实体文件目录不存在: @path', ['@path' => $static_entities_path]);
      return [];
    }

    // 目标部署目录
    $target_base_path = DRUPAL_ROOT . '/sites/default/files/dynamic_entities/' . $this->groupsProjectConfig['tenant_id'] . '/projects/' . $this->groupsProjectConfig['project_id'];

    // 创建基础目录
    if (!is_dir($target_base_path)) {
      mkdir($target_base_path, 0755, TRUE);
    }

    $this->logger->info('开始部署实体文件到: @path', ['@path' => $target_base_path]);

    // 递归复制整个目录结构
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

    return $deployed_files;
  }

  /**
   * 注册实体类型到Drupal系统
   */
  protected function registerEntityTypesWithDrupal(): array {
    $registered_types = [];

    try {
      // 获取EntityRegistry服务
      if (\Drupal::hasService('baas_entity.entity_registry')) {
        $entity_registry = \Drupal::service('baas_entity.entity_registry');

        // 获取所有已注册的实体类型
        $registered_entity_types = $entity_registry->getRegisteredEntityTypes();

        foreach ($this->groupsProjectConfig['entities'] as $entity_name) {
          // 使用项目表名生成器来获取正确的实体类型ID
          $table_name_generator = \Drupal::service('baas_project.table_name_generator');
          $entity_type_id = $table_name_generator->generateEntityTypeId(
            $this->groupsProjectConfig['tenant_id'],
            $this->groupsProjectConfig['project_id'],
            $entity_name
          );

          if (isset($registered_entity_types[$entity_type_id])) {
            $registered_types[] = $entity_type_id;
            $this->logger->info('实体类型已注册: @type', ['@type' => $entity_type_id]);
          } else {
            $this->logger->warning('实体类型未能注册: @type', ['@type' => $entity_type_id]);
          }
        }
      }

      // 清理实体类型定义缓存
      $this->entityTypeManager->clearCachedDefinitions();

      // 重建所有缓存以确保实体类型可用
      drupal_flush_all_caches();

      $this->logger->info('实体类型已注册到Drupal系统: @count 个', [
        '@count' => count($registered_types)
      ]);

    } catch (\Exception $e) {
      $this->logger->error('实体类型注册失败: @error', ['@error' => $e->getMessage()]);
    }

    return $registered_types;
  }

  /**
   * 导入业务实体数据
   */
  protected function importBusinessData(): array {
    $imported_data = [];

    // 从静态数据文件读取业务数据
    $static_data_path = DRUPAL_ROOT . '/profiles/baas_platform/data/groups_static_data.json';
    $static_data = json_decode(file_get_contents($static_data_path), TRUE);

    if (!isset($static_data['entities'])) {
      $this->logger->warning('静态数据文件中缺少业务实体数据');
      return [];
    }

    foreach ($static_data['entities'] as $entity_name => $sample_records) {
      if (empty($sample_records)) {
        $this->logger->info('实体 @entity 没有示例数据，跳过数据导入', ['@entity' => $entity_name]);
        continue;
      }

      // 使用项目表名生成器来获取正确的表名
      $table_name_generator = \Drupal::service('baas_project.table_name_generator');
      $table_name = $table_name_generator->generateTableName(
        $this->groupsProjectConfig['tenant_id'],
        $this->groupsProjectConfig['project_id'],
        $entity_name
      );

      try {
        // 检查表是否存在
        if (!$this->database->schema()->tableExists($table_name)) {
          $this->logger->warning('数据表不存在，跳过数据导入: @table', ['@table' => $table_name]);
          continue;
        }

        // 检查表中是否已有演示数据（包括原始数据中is_demo为false的情况）
        $existing_count = $this->database->select($table_name)
          ->condition('tenant_id', $this->groupsProjectConfig['tenant_id'])
          ->countQuery()
          ->execute()
          ->fetchField();

        if ($existing_count > 0) {
          $this->logger->info('表中已有演示数据，跳过: @table (@count 条)', [
            '@table' => $table_name,
            '@count' => $existing_count
          ]);
          continue;
        }

        // 导入数据
        $inserted_count = 0;
        $this->logger->info('开始向表 @table 导入 @count 条记录', [
          '@table' => $table_name,
          '@count' => count($sample_records)
        ]);

        foreach ($sample_records as $record_index => $record) {
          try {
            // 确保使用原始租户ID，保持原始is_demo值
            $record['tenant_id'] = $this->groupsProjectConfig['tenant_id'];
            // 保持原始的is_demo值，如果没有则设为true
            if (!isset($record['is_demo'])) {
              $record['is_demo'] = TRUE;
            }

            // 数据类型转换和字段映射
            $record = $this->transformRecordData($entity_name, $record);

            // 应用文件ID映射（将旧fid替换为新fid）
            $record = $this->applyFileIdMapping($entity_name, $record);

            // 获取表结构信息用于调试
            $table_fields = [];
            try {
              $query = $this->database->query("SELECT column_name FROM information_schema.columns WHERE table_name = :table_name", [
                ':table_name' => $table_name
              ]);
              foreach ($query as $row) {
                $table_fields[] = $row->column_name;
              }
            } catch (\Exception $e) {
              // 如果无法获取字段信息，使用record的所有字段
              $table_fields = array_keys($record);
            }

            // 过滤掉表中不存在的字段
            $filtered_record = array_intersect_key($record, array_flip($table_fields));

            // 记录字段信息用于调试
            if ($inserted_count === 0) {
              $this->logger->info('表 @table 字段: @fields', [
                '@table' => $table_name,
                '@fields' => implode(', ', $table_fields)
              ]);
              $this->logger->info('数据记录字段: @fields', [
                '@fields' => implode(', ', array_keys($record))
              ]);
            }

            $this->database->insert($table_name)->fields($filtered_record)->execute();
            $inserted_count++;
          } catch (\Exception $insert_error) {
            $this->logger->error('数据记录插入失败: @entity 记录 @index, 错误: @error', [
              '@entity' => $entity_name,
              '@index' => $record_index,
              '@error' => $insert_error->getMessage()
            ]);
            // 继续处理下一条记录
          }
        }

        $imported_data[$entity_name] = $inserted_count;
        $this->logger->info('业务数据导入成功: @entity (@count 条记录)', [
          '@entity' => $entity_name,
          '@count' => $inserted_count
        ]);

      } catch (\Exception $e) {
        $this->logger->error('业务数据导入失败: @entity, 错误: @error', [
          '@entity' => $entity_name,
          '@error' => $e->getMessage()
        ]);
      }
    }

    return $imported_data;
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
      // 使用项目表名生成器来获取正确的表名
      $table_name_generator = \Drupal::service('baas_project.table_name_generator');
      $table_name = $table_name_generator->generateTableName(
        $this->groupsProjectConfig['tenant_id'],
        $this->groupsProjectConfig['project_id'],
        $entity_name
      );

      try {
        $table_exists = $this->database->schema()->tableExists($table_name);
        $validation_results['checks'][$entity_name . '_table_exists'] = $table_exists;

        if ($table_exists) {
          $record_count = $this->database->select($table_name)
            ->condition('tenant_id', $this->groupsProjectConfig['tenant_id'])
            ->condition('project_id', $this->groupsProjectConfig['project_id'])
            ->countQuery()
            ->execute()
            ->fetchField();

          $validation_results['checks'][$entity_name . '_demo_records'] = $record_count;
        } else {
          $validation_results['valid'] = FALSE;
          $validation_results['issues'][] = "数据表不存在: {$table_name}";
        }
      } catch (\Exception $e) {
        $validation_results['issues'][] = "数据表检查失败 {$table_name}: {$e->getMessage()}";
      }
    }

    return $validation_results;
  }

  /**
   * 验证动态实体导入结果（不检查租户和项目配置）
   */
  protected function validateDynamicEntitiesImport(): array {
    $validation_results = [
      'valid' => TRUE,
      'checks' => [],
      'issues' => [],
    ];

    // 检查数据表和数据
    foreach ($this->groupsProjectConfig['entities'] as $entity_name) {
      // 使用项目表名生成器来获取正确的表名
      $table_name_generator = \Drupal::service('baas_project.table_name_generator');
      $table_name = $table_name_generator->generateTableName(
        $this->groupsProjectConfig['tenant_id'],
        $this->groupsProjectConfig['project_id'],
        $entity_name
      );

      try {
        $table_exists = $this->database->schema()->tableExists($table_name);
        $validation_results['checks'][$entity_name . '_table_exists'] = $table_exists;

        if ($table_exists) {
          $record_count = $this->database->select($table_name)
            ->condition('tenant_id', $this->groupsProjectConfig['tenant_id'])
            ->condition('project_id', $this->groupsProjectConfig['project_id'])
            ->countQuery()
            ->execute()
            ->fetchField();

          $validation_results['checks'][$entity_name . '_demo_records'] = $record_count;
        } else {
          $validation_results['valid'] = FALSE;
          $validation_results['issues'][] = "数据表不存在: {$table_name}";
        }
      } catch (\Exception $e) {
        $validation_results['issues'][] = "数据表检查失败 {$table_name}: {$e->getMessage()}";
      }
    }

    return $validation_results;
  }

  /**
   * 删除所有导入的内容（用于模块卸载）
   */
  public function deleteImportedContent(): void {
    try {
      // 删除业务数据
      foreach ($this->groupsProjectConfig['entities'] as $entity_name) {
        // 使用项目表名生成器来获取正确的表名
      $table_name_generator = \Drupal::service('baas_project.table_name_generator');
      $table_name = $table_name_generator->generateTableName(
        $this->groupsProjectConfig['tenant_id'],
        $this->groupsProjectConfig['project_id'],
        $entity_name
      );

        if ($this->database->schema()->tableExists($table_name)) {
          $this->database->delete($table_name)
            ->condition('tenant_id', $this->groupsProjectConfig['tenant_id'])
            ->condition('project_id', $this->groupsProjectConfig['project_id'])
            ->execute();
        }
      }

      // 删除实体模板和字段定义
      if ($this->database->schema()->tableExists('baas_entity_template')) {
        $this->database->delete('baas_entity_template')
          ->condition('tenant_id', $this->groupsProjectConfig['tenant_id'])
          ->condition('project_id', $this->groupsProjectConfig['project_id'])
          ->execute();
      }

      // 删除项目配置
      if ($this->database->schema()->tableExists('baas_project_config')) {
        $this->database->delete('baas_project_config')
          ->condition('project_id', $this->groupsProjectConfig['project_id'])
          ->execute();
      }

      // 删除租户配置
      if ($this->database->schema()->tableExists('baas_tenant_config')) {
        $this->database->delete('baas_tenant_config')
          ->condition('tenant_id', $this->groupsProjectConfig['tenant_id'])
          ->execute();
      }

      $this->logger->info('已删除所有导入的Groups项目内容');

    } catch (\Exception $e) {
      $this->logger->error('删除导入内容失败: @error', ['@error' => $e->getMessage()]);
    }
  }

  /**
   * Import uploaded files from static data.
   *
   * @return int
   *   Number of files imported.
   */
  protected function importUploadedFiles(): int {
    $imported_count = 0;

    try {
      // 从静态数据文件读取
      $static_data_path = DRUPAL_ROOT . '/profiles/baas_platform/data/groups_static_data.json';
      if (!file_exists($static_data_path)) {
        $this->logger->warning('静态数据文件不存在: @path', ['@path' => $static_data_path]);
        return 0;
      }

      $static_data = json_decode(file_get_contents($static_data_path), TRUE);

      // 检查是否有上传文件数据
      if (!isset($static_data['baas_config']['uploaded_files'])) {
        $this->logger->info('静态数据中没有上传文件信息，跳过');
        return 0;
      }

      $uploaded_files = $static_data['baas_config']['uploaded_files'];
      if (empty($uploaded_files)) {
        $this->logger->info('没有需要导入的上传文件');
        return 0;
      }

      $this->logger->info('开始导入 @count 个上传文件记录', ['@count' => count($uploaded_files)]);
      $this->logger->info('当前用户UID映射: @mapping', ['@mapping' => json_encode($this->userUidMapping)]);

      foreach ($uploaded_files as $file_data) {
        try {
          // 检查文件是否已存在
          $existing = $this->database->select('file_managed', 'f')
            ->fields('f', ['fid'])
            ->condition('uri', $file_data['uri'])
            ->execute()
            ->fetchField();

          if ($existing) {
            $this->logger->info('文件已存在，跳过: @uri', ['@uri' => $file_data['uri']]);
            continue;
          }

          // 映射UID（旧UID -> 新UID）
          $old_uid = $file_data['uid'];
          $new_uid = $this->userUidMapping[$old_uid] ?? $old_uid;

          $this->logger->info('文件 @file UID映射: 旧UID @old_uid -> 新UID @new_uid', [
            '@file' => $file_data['filename'],
            '@old_uid' => $old_uid,
            '@new_uid' => $new_uid
          ]);

          // 生成UUID（必需字段）
          $uuid_service = \Drupal::service('uuid');
          $uuid = $uuid_service->generate();

          // 插入文件记录（使用原始fid可能导致冲突，让数据库自动生成）
          $new_fid = $this->database->insert('file_managed')
            ->fields([
              'uuid' => $uuid,
              'langcode' => 'zh-hans',  // 设置语言代码（必需字段）
              'uid' => $new_uid,
              'filename' => $file_data['filename'],
              'uri' => $file_data['uri'],
              'filemime' => $file_data['filemime'],
              'filesize' => $file_data['filesize'],
              'status' => $file_data['status'] ?? 1,
              'created' => $file_data['created'],
              'changed' => $file_data['changed'] ?? $file_data['created'],
            ])
            ->execute();

          // 建立文件ID映射（旧fid -> 新fid）
          $old_fid = $file_data['fid'];
          $this->fileIdMapping[$old_fid] = $new_fid;

          $imported_count++;
          $this->logger->info('导入文件成功: @filename (旧FID: @old_fid -> 新FID: @new_fid, UID: @uid)', [
            '@filename' => $file_data['filename'],
            '@old_fid' => $old_fid,
            '@new_fid' => $new_fid,
            '@uid' => $new_uid
          ]);

        } catch (\Exception $e) {
          $this->logger->warning('导入文件失败: @file, 错误: @error', [
            '@file' => $file_data['filename'] ?? 'unknown',
            '@error' => $e->getMessage()
          ]);
        }
      }

      // 导入文件使用统计
      if (isset($static_data['baas_config']['project_file_usage'])) {
        $file_usage = $static_data['baas_config']['project_file_usage'];
        if (!empty($file_usage) && is_array($file_usage)) {
          foreach ($file_usage as $usage_data) {
            $this->database->merge('baas_project_file_usage')
              ->keys(['project_id' => $usage_data['project_id']])
              ->fields([
                'file_count' => $usage_data['file_count'],
                'total_size' => $usage_data['total_size'],
                'image_count' => $usage_data['image_count'] ?? 0,
                'document_count' => $usage_data['document_count'] ?? 0,
                'last_updated' => $usage_data['last_updated'] ?? time(),
              ])
              ->execute();

            $this->logger->info('导入文件使用统计: @project_id', ['@project_id' => $usage_data['project_id']]);
          }
        }
      }

      $this->logger->info('成功导入 @count 个上传文件记录', ['@count' => $imported_count]);

    } catch (\Exception $e) {
      $this->logger->error('导入上传文件失败: @error', ['@error' => $e->getMessage()]);
    }

    return $imported_count;
  }

  /**
   * Import realtime configuration from static data.
   *
   * @return int
   *   Number of realtime configs imported.
   */
  protected function importRealtimeConfig(): int {
    $imported_count = 0;

    try {
      // 从静态数据文件读取
      $static_data_path = DRUPAL_ROOT . '/profiles/baas_platform/data/groups_static_data.json';
      if (!file_exists($static_data_path)) {
        $this->logger->warning('静态数据文件不存在: @path', ['@path' => $static_data_path]);
        return 0;
      }

      $static_data = json_decode(file_get_contents($static_data_path), TRUE);

      // 检查是否有实时配置数据
      if (!isset($static_data['baas_config']['realtime_config'])) {
        $this->logger->info('静态数据中没有实时功能配置，跳过');
        return 0;
      }

      $realtime_configs = $static_data['baas_config']['realtime_config'];
      if (empty($realtime_configs)) {
        $this->logger->info('没有需要导入的实时功能配置');
        return 0;
      }

      $this->logger->info('开始导入 @count 个实时功能配置', ['@count' => count($realtime_configs)]);

      foreach ($realtime_configs as $config_data) {
        try {
          // 使用merge确保不会重复插入
          $this->database->merge('baas_realtime_project_config')
            ->keys(['project_id' => $config_data['project_id']])
            ->fields([
              'tenant_id' => $config_data['tenant_id'],
              'enabled' => $config_data['enabled'] ?? 1,
              'enabled_entities' => $config_data['enabled_entities'],
              'settings' => $config_data['settings'] ?? '[]',
              'created_by' => $this->userUidMapping[$config_data['created_by']] ?? $config_data['created_by'],
              'updated_by' => $this->userUidMapping[$config_data['updated_by']] ?? $config_data['updated_by'],
              'created_at' => $config_data['created_at'],
              'updated_at' => $config_data['updated_at'] ?? $config_data['created_at'],
            ])
            ->execute();

          $imported_count++;
          $this->logger->info('导入实时配置: @project_id', ['@project_id' => $config_data['project_id']]);

        } catch (\Exception $e) {
          $this->logger->warning('导入实时配置失败: @project_id, 错误: @error', [
            '@project_id' => $config_data['project_id'] ?? 'unknown',
            '@error' => $e->getMessage()
          ]);
        }
      }

      $this->logger->info('成功导入 @count 个实时功能配置', ['@count' => $imported_count]);

    } catch (\Exception $e) {
      $this->logger->error('导入实时配置失败: @error', ['@error' => $e->getMessage()]);
    }

    return $imported_count;
  }

}
