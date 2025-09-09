<?php

declare(strict_types=1);

namespace Drupal\baas_project\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_project\Service\ProjectTableNameGenerator;
use Drupal\Core\Database\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\baas_project\Controller\ProjectEntityController;

/**
 * 调试控制器。
 */
class DebugController extends ControllerBase
{

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly ProjectTableNameGenerator $tableNameGenerator,
    protected readonly Connection $database,
    protected readonly ProjectEntityController $entityController
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_project.table_name_generator'),
      $container->get('database'),
      ProjectEntityController::create($container)
    );
  }

  /**
   * 调试表名和数据。
   */
  public function debugTableData(): JsonResponse
  {
    $tenant_id = 'user_14_0ebf86fe';
    $project_id = 'user_14_0ebf86fe_project_687631e77d122';
    $entity_name = 'web1';

    $result = [
      'tenant_id' => $tenant_id,
      'project_id' => $project_id,
      'entity_name' => $entity_name,
    ];

    // 生成表名
    $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);
    $result['generated_table_name'] = $table_name;

    // 检查表是否存在
    $table_exists = $this->database->schema()->tableExists($table_name);
    $result['table_exists'] = $table_exists;

    if ($table_exists) {
      try {
        // 获取记录数
        $count = $this->database->select($table_name, 't')
          ->countQuery()
          ->execute()
          ->fetchField();
        $result['record_count'] = (int) $count;

        // 获取所有记录
        $records = $this->database->select($table_name, 't')
          ->fields('t')
          ->execute()
          ->fetchAll(\PDO::FETCH_ASSOC);
        $result['records'] = $records;

        // 获取表结构
        $fields = $this->database->query("SELECT column_name, data_type, is_nullable FROM information_schema.columns WHERE table_name = :table", [':table' => $table_name])->fetchAll();
        $result['table_structure'] = $fields;

      } catch (\Exception $e) {
        $result['error'] = $e->getMessage();
      }
    }

    // 检查其他可能的表名
    $alternative_table_names = [
      "tenant_{$tenant_id}_project_{$project_id}_{$entity_name}",
      "baas_{$entity_name}",
      $entity_name,
    ];

    $result['alternative_tables'] = [];
    foreach ($alternative_table_names as $alt_name) {
      if ($this->database->schema()->tableExists($alt_name)) {
        $alt_count = $this->database->select($alt_name, 't')->countQuery()->execute()->fetchField();
        $result['alternative_tables'][$alt_name] = (int) $alt_count;
      }
    }

    return new JsonResponse($result);
  }

  /**
   * 测试实体数据列表逻辑。
   */
  public function testEntityDataList(): JsonResponse
  {
    $tenant_id = 'user_14_0ebf86fe';
    $project_id = 'user_14_0ebf86fe_project_687631e77d122';
    $entity_name = 'web1';

    $result = [
      'tenant_id' => $tenant_id,
      'project_id' => $project_id,
      'entity_name' => $entity_name,
    ];

    try {
      // 直接调用控制器的getEntityData方法 (通过反射)
      $reflection = new \ReflectionClass($this->entityController);
      $method = $reflection->getMethod('getEntityData');
      $method->setAccessible(true);
      
      $entity_data = $method->invoke($this->entityController, $tenant_id, $project_id, $entity_name);
      $result['entity_data'] = $entity_data;
      $result['data_count'] = count($entity_data);

      // 测试数据列表构建
      $buildMethod = $reflection->getMethod('buildEntityDataList');
      $buildMethod->setAccessible(true);
      
      $build_result = $buildMethod->invoke($this->entityController, $entity_data, $tenant_id, $project_id, $entity_name, 'owner');
      $result['build_result_type'] = gettype($build_result);
      $result['build_result_keys'] = is_array($build_result) ? array_keys($build_result) : null;
      
      if (isset($build_result['#rows'])) {
        $result['table_rows'] = $build_result['#rows'];
      }

    } catch (\Exception $e) {
      $result['error'] = $e->getMessage();
      $result['trace'] = $e->getTraceAsString();
    }

    return new JsonResponse($result);
  }

  /**
   * 检查用户权限状态。
   */
  public function checkUserPermissions(): JsonResponse
  {
    $current_user = \Drupal::currentUser();
    $user_id = $current_user->id();
    
    $result = [
      'user_id' => $user_id,
      'user_name' => $current_user->getDisplayName(),
      'is_authenticated' => $current_user->isAuthenticated(),
      'is_anonymous' => $current_user->isAnonymous(),
      'permissions' => [],
    ];

    // 检查相关权限
    $permissions_to_check = [
      'access baas project content',
      'view baas project',
      'administer baas project',
      'administer baas entity templates',
      'create baas project content',
      'edit baas project content',
      'delete baas project content',
    ];

    foreach ($permissions_to_check as $permission) {
      $result['permissions'][$permission] = $current_user->hasPermission($permission);
    }

    // 如果用户已登录，检查项目成员身份
    if ($current_user->isAuthenticated()) {
      $project_id = 'user_14_0ebf86fe_project_687631e77d122';
      
      try {
        $project_manager = \Drupal::service('baas_project.manager');
        $user_role = $project_manager->getUserProjectRole($project_id, (int) $user_id);
        $result['project_role'] = $user_role;
        
        // 检查项目是否存在
        $project = $project_manager->getProject($project_id);
        $result['project_exists'] = $project ? true : false;
        if ($project) {
          $result['project_name'] = $project['name'];
          $result['project_tenant_id'] = $project['tenant_id'];
        }
        
      } catch (\Exception $e) {
        $result['project_check_error'] = $e->getMessage();
      }
    }

    return new JsonResponse($result);
  }

  /**
   * 检查实体表结构同步状态。
   */
  public function checkEntityTableSync(): JsonResponse
  {
    $tenant_id = 'user_14_0ebf86fe';
    $project_id = 'user_14_0ebf86fe_project_687631e77d122';
    $entity_name = 'web1';

    $result = [
      'tenant_id' => $tenant_id,
      'project_id' => $project_id,
      'entity_name' => $entity_name,
    ];

    // 生成表名
    $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);
    $result['table_name'] = $table_name;

    // 检查表是否存在
    $table_exists = $this->database->schema()->tableExists($table_name);
    $result['table_exists'] = $table_exists;

    if ($table_exists) {
      // 获取表中的实际字段
      $actual_fields = [];
      try {
        $fields_query = $this->database->query("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = :table", [':table' => $table_name]);
        $actual_fields = $fields_query->fetchAll(\PDO::FETCH_ASSOC);
        $result['actual_fields'] = $actual_fields;
      } catch (\Exception $e) {
        $result['actual_fields_error'] = $e->getMessage();
      }

      // 获取实体模板定义的字段
      $defined_fields = [];
      try {
        $template_query = $this->database->select('baas_entity_template', 'e')
          ->fields('e', ['id'])
          ->condition('tenant_id', $tenant_id)
          ->condition('project_id', $project_id)
          ->condition('name', $entity_name)
          ->execute();
        
        $template = $template_query->fetch(\PDO::FETCH_ASSOC);
        if ($template) {
          $fields_query = $this->database->select('baas_entity_field', 'f')
            ->fields('f', ['name', 'type'])
            ->condition('template_id', $template['id'])
            ->execute();
          $defined_fields = $fields_query->fetchAll(\PDO::FETCH_ASSOC);
        }
        $result['defined_fields'] = $defined_fields;
      } catch (\Exception $e) {
        $result['defined_fields_error'] = $e->getMessage();
      }

      // 比较字段差异
      $actual_field_names = array_column($actual_fields, 'column_name');
      $defined_field_names = array_column($defined_fields, 'name');
      
      // 系统保留字段
      $system_fields = ['id', 'uuid', 'created', 'updated', 'tenant_id', 'project_id'];
      
      // 过滤掉系统字段
      $actual_custom_fields = array_diff($actual_field_names, $system_fields);
      
      $result['orphan_fields'] = array_diff($actual_custom_fields, $defined_field_names);
      $result['missing_fields'] = array_diff($defined_field_names, $actual_field_names);
    }

    return new JsonResponse($result);
  }

  /**
   * 清理孤立的数据库字段。
   */
  public function cleanupOrphanFields(): JsonResponse
  {
    $tenant_id = 'user_14_0ebf86fe';
    $project_id = 'user_14_0ebf86fe_project_687631e77d122';
    $entity_name = 'web1';

    $result = [
      'tenant_id' => $tenant_id,
      'project_id' => $project_id,
      'entity_name' => $entity_name,
      'cleaned_fields' => [],
      'errors' => [],
    ];

    // 生成表名
    $table_name = $this->tableNameGenerator->generateTableName($tenant_id, $project_id, $entity_name);
    $result['table_name'] = $table_name;

    if (!$this->database->schema()->tableExists($table_name)) {
      $result['errors'][] = 'Table does not exist';
      return new JsonResponse($result);
    }

    try {
      // 获取表中的实际字段
      $fields_query = $this->database->query("SELECT column_name FROM information_schema.columns WHERE table_name = :table", [':table' => $table_name]);
      $actual_fields = $fields_query->fetchCol();

      // 获取实体模板定义的字段
      $template_query = $this->database->select('baas_entity_template', 'e')
        ->fields('e', ['id'])
        ->condition('tenant_id', $tenant_id)
        ->condition('project_id', $project_id)
        ->condition('name', $entity_name)
        ->execute();
      
      $template = $template_query->fetch(\PDO::FETCH_ASSOC);
      if (!$template) {
        $result['errors'][] = 'Entity template not found';
        return new JsonResponse($result);
      }

      $fields_query = $this->database->select('baas_entity_field', 'f')
        ->fields('f', ['name'])
        ->condition('template_id', $template['id'])
        ->execute();
      $defined_fields = $fields_query->fetchCol();

      // 系统保留字段
      $system_fields = ['id', 'uuid', 'created', 'updated', 'tenant_id', 'project_id'];
      
      // 找出孤立字段（存在于表中但不在定义中的字段，排除系统字段）
      $actual_custom_fields = array_diff($actual_fields, $system_fields);
      $orphan_fields = array_diff($actual_custom_fields, $defined_fields);

      // 删除孤立字段
      foreach ($orphan_fields as $field_name) {
        try {
          $this->database->schema()->dropField($table_name, $field_name);
          $result['cleaned_fields'][] = $field_name;
        } catch (\Exception $e) {
          $result['errors'][] = "Failed to drop field {$field_name}: " . $e->getMessage();
        }
      }

    } catch (\Exception $e) {
      $result['errors'][] = 'General error: ' . $e->getMessage();
    }

    return new JsonResponse($result);
  }

  /**
   * 测试字段删除功能。
   */
  public function testFieldDeletion(): JsonResponse
  {
    $result = [
      'operation' => 'test_field_deletion',
      'steps' => [],
      'errors' => [],
    ];

    try {
      // 1. 查找 options 字段
      $field_query = $this->database->select('baas_entity_field', 'f')
        ->fields('f')
        ->condition('name', 'options')
        ->execute();
      
      $field = $field_query->fetch(\PDO::FETCH_ASSOC);
      if (!$field) {
        $result['errors'][] = 'options 字段不存在于 baas_entity_field 表中';
        return new JsonResponse($result);
      }

      $result['steps'][] = 'Found field: ' . $field['name'] . ' (ID: ' . $field['id'] . ')';

      // 2. 获取模板信息
      $template_query = $this->database->select('baas_entity_template', 'e')
        ->fields('e')
        ->condition('id', $field['template_id'])
        ->execute();
      
      $template = $template_query->fetch(\PDO::FETCH_ASSOC);
      if (!$template) {
        $result['errors'][] = 'Template not found for field';
        return new JsonResponse($result);
      }

      $result['steps'][] = 'Found template: ' . $template['name'] . ' (tenant: ' . $template['tenant_id'] . ', project: ' . $template['project_id'] . ')';

      // 3. 生成表名
      $table_name = $this->tableNameGenerator->generateTableName($template['tenant_id'], $template['project_id'], $template['name']);
      $result['steps'][] = 'Generated table name: ' . $table_name;

      // 4. 检查表和字段是否存在
      $table_exists = $this->database->schema()->tableExists($table_name);
      $field_exists = $table_exists ? $this->database->schema()->fieldExists($table_name, $field['name']) : false;
      
      $result['steps'][] = 'Table exists: ' . ($table_exists ? 'YES' : 'NO');
      $result['steps'][] = 'Field exists in table: ' . ($field_exists ? 'YES' : 'NO');

      // 5. 尝试删除字段（模拟）
      if ($table_exists && $field_exists) {
        $result['steps'][] = 'Would delete field ' . $field['name'] . ' from table ' . $table_name;
        // 不实际删除，只是模拟
        $result['simulation'] = true;
      } else {
        $result['errors'][] = 'Cannot delete field - table or field does not exist';
      }

    } catch (\Exception $e) {
      $result['errors'][] = 'Exception: ' . $e->getMessage();
    }

    return new JsonResponse($result);
  }

  /**
   * 测试统计功能。
   */
  public function testUsageStats(): JsonResponse
  {
    $result = [
      'test_name' => 'UsageStats Controller Test',
      'timestamp' => time(),
      'status' => 'starting',
    ];

    try {
      // 测试基本服务可用性
      $container = \Drupal::getContainer();
      
      $result['services_tested'] = [];
      
      // 测试数据库服务
      try {
        $database = $container->get('database');
        $tenant_count = $database->select('baas_tenant_config', 't')
          ->condition('status', 1)
          ->countQuery()
          ->execute()
          ->fetchField();
        $result['services_tested']['database'] = 'OK';
        $result['tenant_count'] = (int) $tenant_count;
      } catch (\Exception $e) {
        $result['services_tested']['database'] = 'FAILED: ' . $e->getMessage();
      }

      // 测试UsageStatsController服务依赖
      $services = [
        'usage_tracker' => 'baas_project.usage_tracker',
        'resource_limit_manager' => 'baas_project.resource_limit_manager',
        'project_manager' => 'baas_project.manager',
      ];

      foreach ($services as $name => $service_id) {
        try {
          $service = $container->get($service_id);
          $result['services_tested'][$name] = 'OK - ' . get_class($service);
        } catch (\Exception $e) {
          $result['services_tested'][$name] = 'FAILED: ' . $e->getMessage();
        }
      }

      $result['status'] = 'completed';

    } catch (\Exception $e) {
      $result['status'] = 'error';
      $result['error'] = $e->getMessage();
    }

    return new JsonResponse($result);
  }

}