<?php

namespace Drupal\baas_entity\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\baas_tenant\TenantManagerInterface;

/**
 * 实体注册服务，负责注册动态实体类型。
 */
class EntityRegistry {

  /**
   * 实体类型管理器服务。
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * 数据库连接服务。
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * 租户管理服务。
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected $tenantManager;

  /**
   * 模块处理器服务。
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * 已注册的动态实体类型。
   *
   * @var array
   */
  protected $registeredEntityTypes = [];

  /**
   * 加载锁，防止递归加载。
   *
   * @var bool
   */
  protected $loadLock = FALSE;

  /**
   * 构造函数。
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   实体类型管理器。
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接。
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理服务。
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   模块处理器服务。
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    TenantManagerInterface $tenant_manager,
    ModuleHandlerInterface $module_handler
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->tenantManager = $tenant_manager;
    $this->moduleHandler = $module_handler;

    // 初始加载已注册的实体类型
    $this->loadRegisteredEntityTypes();
  }

  /**
   * 加载已注册的实体类型。
   */
  protected function loadRegisteredEntityTypes() {
    // 检查表是否存在，如果不存在则直接返回
    if (!$this->database->schema()->tableExists('baas_entity_template')) {
      return;
    }

    // 获取所有模板
    $templates = $this->database->select('baas_entity_template', 't')
      ->fields('t', ['id', 'tenant_id', 'name', 'label', 'status', 'project_id'])
      ->condition('status', 1)
      ->execute()
      ->fetchAll();

    foreach ($templates as $template) {
      $entity_type_id = $template->tenant_id . '_' . $template->name;
      // 减少日志噪音 - 只记录调试级别
      \Drupal::logger('baas_entity_registry')->debug('注册实体类型: @type', [
        '@type' => $entity_type_id,
      ]);

      // 优先从文件记录表查询类文件位置
      if ($this->database->schema()->tableExists('baas_entity_class_files')) {
        $file_info = $this->database->select('baas_entity_class_files', 'f')
          ->fields('f', ['class_name', 'file_path'])
          ->condition('entity_type_id', $entity_type_id)
          ->condition('class_type', 'Entity')
          ->execute()
          ->fetchAssoc();

        if ($file_info) {
          $class_name = $file_info['class_name'];
          $file_path = 'public://' . $file_info['file_path'];
          $real_path = \Drupal::service('file_system')->realpath($file_path);

          if (file_exists($real_path)) {
            $this->registeredEntityTypes[$entity_type_id] = [
              'class' => "Drupal\\baas_entity\\Entity\\Dynamic\\{$class_name}",
              'tenant_id' => $template->tenant_id,
              'entity_name' => $template->name,
              'label' => $template->label,
              'file_path' => $file_path,
            ];
            \Drupal::logger('baas_entity_registry')->notice('从记录表注册实体类型: @type, 类: @class, 文件: @path', [
              '@type' => $entity_type_id,
              '@class' => $class_name,
              '@path' => $real_path,
            ]);
            continue;
          }
        }
      }

      // 尝试直接从文件系统查找实体类文件
      // 构建符合PHP命名规范的类名
      $name_parts = explode('_', $template->name);
      $name_parts = array_map('ucfirst', $name_parts);
      $class_base = implode('', $name_parts);

      $tenant_parts = explode('_', $template->tenant_id);
      $tenant_parts = array_map('ucfirst', $tenant_parts);
      $tenant_prefix = implode('', $tenant_parts);

      $class_name = $tenant_prefix . $class_base;

      // 尝试从公共文件目录加载
      $file_path = 'public://dynamic_entities/' . $template->tenant_id . '/Entity/' . $class_name . '.php';
      $real_path = \Drupal::service('file_system')->realpath($file_path);

      if (file_exists($real_path)) {
        $this->registeredEntityTypes[$entity_type_id] = [
          'class' => "Drupal\\baas_entity\\Entity\\Dynamic\\{$class_name}",
          'tenant_id' => $template->tenant_id,
          'entity_name' => $template->name,
          'label' => $template->label,
          'file_path' => $file_path,
        ];
        \Drupal::logger('baas_entity_registry')->notice('从文件系统注册实体类型: @type, 类: @class, 文件: @path', [
          '@type' => $entity_type_id,
          '@class' => $class_name,
          '@path' => $real_path,
        ]);
        continue;
      }

      // 尝试从旧路径加载
      $module_path = \Drupal::service('extension.list.module')->getPath('baas_entity');
      $old_path = $module_path . '/src/Entity/Dynamic/' . $class_name . '.php';

      if (file_exists($old_path)) {
        $this->registeredEntityTypes[$entity_type_id] = [
          'class' => "Drupal\\baas_entity\\Entity\\Dynamic\\{$class_name}",
          'tenant_id' => $template->tenant_id,
          'entity_name' => $template->name,
          'label' => $template->label,
          'file_path' => $old_path,
        ];
        \Drupal::logger('baas_entity_registry')->notice('从旧路径注册实体类型: @type, 类: @class, 文件: @path', [
          '@type' => $entity_type_id,
          '@class' => $class_name,
          '@path' => $old_path,
        ]);
        continue;
      }

      // 如果文件不存在但数据库中有模板记录，检查是否有project_id
      // 有project_id的实体模板已经迁移到项目管理系统，不需要在这里注册
      if (!empty($template->project_id)) {
        \Drupal::logger('baas_entity_registry')->debug('跳过项目实体类型注册: @type (已由项目管理系统管理)', [
          '@type' => $entity_type_id,
        ]);
        continue;
      }
      
      // 只注册没有project_id的旧实体类型
      $this->registeredEntityTypes[$entity_type_id] = [
        'class' => "Drupal\\baas_entity\\Entity\\Dynamic\\{$class_name}",
        'tenant_id' => $template->tenant_id,
        'entity_name' => $template->name,
        'label' => $template->label,
      ];
      \Drupal::logger('baas_entity_registry')->notice('注册实体类型(无文件): @type, 类: @class', [
        '@type' => $entity_type_id,
        '@class' => $class_name,
      ]);
    }
  }

  /**
   * 注册动态实体类型。
   *
   * @param string $entity_type_id
   *   实体类型ID。
   * @param string $class_name
   *   类名。
   * @param string $tenant_id
   *   租户ID。
   * @param string $entity_name
   *   实体名称。
   * @param string $label
   *   显示名称。
   *
   * @return bool
   *   是否成功。
   */
  public function registerEntityType($entity_type_id, $class_name, $tenant_id, $entity_name, $label) {
    $full_class_name = "Drupal\\baas_entity\\Entity\\Dynamic\\{$class_name}";
    $file_path = NULL;

    // 首先尝试从文件记录表获取文件路径
    if ($this->database->schema()->tableExists('baas_entity_class_files')) {
      $file_record = $this->database->select('baas_entity_class_files', 'f')
        ->fields('f', ['file_path'])
        ->condition('entity_type_id', $entity_type_id)
        ->condition('class_type', 'Entity')
        ->condition('class_name', $class_name)
        ->execute()
        ->fetchField();

      if ($file_record) {
        $file_path = 'public://' . $file_record;
        $real_path = \Drupal::service('file_system')->realpath($file_path);

        if (file_exists($real_path)) {
          // 文件存在，添加到注册表
          $this->registeredEntityTypes[$entity_type_id] = [
            'class' => $full_class_name,
            'tenant_id' => $tenant_id,
            'entity_name' => $entity_name,
            'label' => $label,
            'file_path' => $file_path,
          ];
          \Drupal::logger('baas_entity_registry')->notice('从记录表注册实体类型: @type, 类: @class, 文件: @path', [
            '@type' => $entity_type_id,
            '@class' => $class_name,
            '@path' => $real_path,
          ]);
          return TRUE;
        }
      }
    }

    // 如果文件记录表中没有找到，或者文件不存在，则尝试从公共文件系统中查找
    $file_path = 'public://dynamic_entities/' . $tenant_id . '/Entity/' . $class_name . '.php';
    $real_path = \Drupal::service('file_system')->realpath($file_path);

    if (file_exists($real_path)) {
      // 文件存在，添加到注册表
      $this->registeredEntityTypes[$entity_type_id] = [
        'class' => $full_class_name,
        'tenant_id' => $tenant_id,
        'entity_name' => $entity_name,
        'label' => $label,
        'file_path' => $file_path,
      ];
      \Drupal::logger('baas_entity_registry')->notice('从文件系统注册实体类型: @type, 类: @class, 文件: @path', [
        '@type' => $entity_type_id,
        '@class' => $class_name,
        '@path' => $real_path,
      ]);
      return TRUE;
    }

    // 如果公共文件系统中没有找到，则尝试从模块目录中查找
    $module_path = \Drupal::service('extension.list.module')->getPath('baas_entity');
    $old_path = $module_path . '/src/Entity/Dynamic/' . $class_name . '.php';

    if (file_exists($old_path)) {
      // 文件存在，添加到注册表
      $this->registeredEntityTypes[$entity_type_id] = [
        'class' => $full_class_name,
        'tenant_id' => $tenant_id,
        'entity_name' => $entity_name,
        'label' => $label,
        'file_path' => $old_path,
      ];
      \Drupal::logger('baas_entity_registry')->notice('从旧路径注册实体类型: @type, 类: @class, 文件: @path', [
        '@type' => $entity_type_id,
        '@class' => $class_name,
        '@path' => $old_path,
      ]);
      return TRUE;
    }

    // 如果都没有找到，则添加一个记录但返回失败
    $this->registeredEntityTypes[$entity_type_id] = [
      'class' => $full_class_name,
      'tenant_id' => $tenant_id,
      'entity_name' => $entity_name,
      'label' => $label,
    ];
    \Drupal::logger('baas_entity_registry')->error('未能找到实体类型文件: @type, 类: @class', [
      '@type' => $entity_type_id,
      '@class' => $class_name,
    ]);
    return FALSE;
  }

  /**
   * 注销动态实体类型。
   *
   * @param string $entity_type_id
   *   实体类型ID。
   *
   * @return bool
   *   是否成功。
   */
  public function unregisterEntityType($entity_type_id) {
    if (isset($this->registeredEntityTypes[$entity_type_id])) {
      unset($this->registeredEntityTypes[$entity_type_id]);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * 获取所有已注册的实体类型。
   *
   * @return array
   *   所有已注册的实体类型。
   */
  public function getRegisteredEntityTypes() {
    // 检查是否已经在加载过程中，防止递归调用
    if ($this->loadLock) {
      \Drupal::logger('baas_entity_registry')->notice('检测到递归加载请求，返回当前已加载的实体类型');
      return $this->registeredEntityTypes;
    }

    // 设置加载锁
    $this->loadLock = TRUE;

    try {
      // 如果尚未加载，则加载已注册的实体类型
      if (empty($this->registeredEntityTypes)) {
        $this->loadRegisteredEntityTypes();
      }

      \Drupal::logger('baas_entity_registry')->debug('获取已注册的实体类型: @count', [
        '@count' => count($this->registeredEntityTypes),
      ]);

      if (!empty($this->registeredEntityTypes)) {
        \Drupal::logger('baas_entity_registry')->debug('已注册的实体类型: @types', [
          '@types' => implode(', ', array_keys($this->registeredEntityTypes)),
        ]);
      }
    }
    finally {
      // 无论成功失败，都释放加载锁
      $this->loadLock = FALSE;
    }

    return $this->registeredEntityTypes;
  }

  /**
   * 检查实体类型是否已注册。
   *
   * @param string $entity_type_id
   *   实体类型ID。
   *
   * @return bool
   *   是否已注册。
   */
  public function isEntityTypeRegistered($entity_type_id) {
    // 如果处于加载锁中，只进行简单检查
    if ($this->loadLock) {
      $result = isset($this->registeredEntityTypes[$entity_type_id]);
      \Drupal::logger('baas_entity_registry')->notice('检查实体类型 @type 是否注册(锁中): @result', [
        '@type' => $entity_type_id,
        '@result' => $result ? '是' : '否',
      ]);
      return $result;
    }

    // 设置临时锁，防止递归
    $this->loadLock = TRUE;

    try {
      // 如果缓存为空，先加载
      if (empty($this->registeredEntityTypes)) {
        $this->loadRegisteredEntityTypes();
      }

      $result = isset($this->registeredEntityTypes[$entity_type_id]);

      \Drupal::logger('baas_entity_registry')->notice('检查实体类型 @type 是否注册: @result', [
        '@type' => $entity_type_id,
        '@result' => $result ? '是' : '否',
      ]);

      if ($result) {
        // 如果实体类型已注册但EntityTypeManager中不存在，尝试强制注册到EntityTypeManager
        if (!\Drupal::entityTypeManager()->hasDefinition($entity_type_id)) {
          $this->forceRegisterEntityType($entity_type_id);
        }
      }
    }
    finally {
      // 释放锁
      $this->loadLock = FALSE;
    }

    return $result;
  }

  /**
   * 强制注册实体类型到EntityTypeManager。
   *
   * @param string $entity_type_id
   *   实体类型ID。
   */
  protected function forceRegisterEntityType($entity_type_id) {
    if (isset($this->registeredEntityTypes[$entity_type_id])) {
      $info = $this->registeredEntityTypes[$entity_type_id];
      $class_name = str_replace('Drupal\\baas_entity\\Entity\\Dynamic\\', '', $info['class']);

      \Drupal::logger('baas_entity_registry')->notice('强制注册实体类型: @type, 类: @class', [
        '@type' => $entity_type_id,
        '@class' => $info['class'],
      ]);

      // 预加载实体类文件
      if (isset($info['file_path'])) {
        $real_path = \Drupal::service('file_system')->realpath($info['file_path']);
        if (file_exists($real_path)) {
          include_once $real_path;
          \Drupal::logger('baas_entity_registry')->notice('预加载实体类文件: @path', [
            '@path' => $real_path,
          ]);
        }
      }

      // 确保类存在
      if (class_exists($info['class'])) {
        // 创建反射类
        $reflection = new \ReflectionClass($info['class']);
        $annotation = $reflection->getDocComment();

        // 实例化实体类型定义
        $annotation_id = '@ContentEntityType';
        if (strpos($annotation, $annotation_id) !== FALSE) {
          try {
            // 获取所有实体类型
            $entity_types = \Drupal::entityTypeManager()->getDefinitions();

            // 创建模拟EntityType对象作为参数传递
            $mock_entity_type = new \Drupal\Core\Entity\ContentEntityType([
              'id' => $entity_type_id,
              'class' => $info['class'],
              'label' => $info['label'],
              'provider' => 'baas_entity',
              'entity_keys' => [
                'id' => 'id',
                'uuid' => 'uuid',
                'label' => 'title',
              ],
            ]);

            // 使用反射实例化类
            // 构建正确的参数格式，避免 TypeError 错误
            $values = [];
            $entity_instance = $reflection->newInstance($values, $mock_entity_type);
            $entity_types[$entity_type_id] = $mock_entity_type;

            // 更新EntityTypeManager的定义
            $property = new \ReflectionProperty(\Drupal::entityTypeManager(), 'definitions');
            $property->setAccessible(true);
            $property->setValue(\Drupal::entityTypeManager(), $entity_types);

            \Drupal::logger('baas_entity_registry')->notice('成功强制注册实体类型: @type', [
              '@type' => $entity_type_id,
            ]);
          }
          catch (\Exception $e) {
            \Drupal::logger('baas_entity_registry')->error('强制注册实体类型失败: @type, 错误: @error', [
              '@type' => $entity_type_id,
              '@error' => $e->getMessage(),
            ]);
          }
        }
      }
    }
  }

  /**
   * 注册实体类型到实体类型集合。
   *
   * @param array &$entity_types
   *   实体类型集合。
   */
  public function registerEntityTypes(array &$entity_types) {
    // 使用静态变量防止在同一个请求中重复执行
    static $processed = FALSE;

    if ($processed) {
      \Drupal::logger('baas_entity_registry')->debug('跳过重复的实体类型注册调用');
      return;
    }

    $processed = TRUE;

    // 获取已注册的实体类型
    $registered_types = $this->getRegisteredEntityTypes();

    // 如果为空则直接返回，避免无意义的日志
    if (empty($registered_types)) {
      return;
    }

    $entity_type_ids = array_keys($registered_types);
    \Drupal::logger('baas_entity_registry')->notice('注册 @count 个实体类型', [
      '@count' => count($entity_type_ids),
    ]);

    // 减少日志数量，只记录第一个和总数
    if (!empty($entity_type_ids)) {
      \Drupal::logger('baas_entity_registry')->notice('首个实体类型: @type', [
        '@type' => reset($entity_type_ids),
      ]);
    }

    foreach ($registered_types as $entity_type_id => $info) {
      // 已存在的实体类型，跳过
      if (isset($entity_types[$entity_type_id])) {
        continue;
      }

      // 加载必要的文件
      if (isset($info['file_path'])) {
        $real_path = \Drupal::service('file_system')->realpath($info['file_path']);
        if (file_exists($real_path)) {
          include_once $real_path;
        }
      }

      // 检查实体类是否存在
      if (!class_exists($info['class'])) {
        \Drupal::logger('baas_entity_registry')->error('无法加载实体类: @class', [
          '@class' => $info['class'],
        ]);
        continue;
      }

      // 尝试创建实体类型定义
      try {
        // 直接创建ContentEntityType定义
        $entity_type = new \Drupal\Core\Entity\ContentEntityType([
          'id' => $entity_type_id,
          'label' => $info['label'],
          'provider' => 'baas_entity',
          'class' => $info['class'],
          'base_table' => 'tenant_' . $entity_type_id,
          'entity_keys' => [
            'id' => 'id',
            'uuid' => 'uuid',
            'label' => 'title',
          ],
        ]);

        // 将实体类型添加到集合
        $entity_types[$entity_type_id] = $entity_type;
      }
      catch (\Exception $e) {
        \Drupal::logger('baas_entity_registry')->error('创建实体类型定义失败: @type, 错误: @error', [
          '@type' => $entity_type_id,
          '@error' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * 从实体定义创建新实例
   *
   * @param array $definition
   *   实体类型定义
   * @param array $values
   *   实体值
   *
   * @return object|null
   *   实体实例或NULL
   */
  public function newInstance(array $definition, array $values = []) {
    try {
      // 获取实体类型ID和类名
      $entity_type_id = $definition['id'];
      $class_name = $definition['class'];

      // 获取实体类型和存储服务
      $entity_type_manager = \Drupal::entityTypeManager();
      $entity_type = $entity_type_manager->getDefinition($entity_type_id);
      $storage = $entity_type_manager->getStorage($entity_type_id);

      // 使用正确的参数创建实例
      $entity = new $class_name($entity_type, $storage, $values);
      return $entity;
    }
    catch (\Exception $e) {
      \Drupal::logger('baas_entity_registry')->error('创建实体实例失败: @class, 错误: @error', [
        '@class' => $definition['class'] ?? 'unknown',
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * 获取租户的所有实体类型。
   *
   * @param string $tenant_id
   *   租户ID。
   *
   * @return array
   *   实体类型列表。
   */
  public function getTenantEntityTypes($tenant_id) {
    $tenant_entity_types = [];

    foreach ($this->registeredEntityTypes as $entity_type_id => $info) {
      if ($info['tenant_id'] == $tenant_id) {
        $tenant_entity_types[$entity_type_id] = $info;
      }
    }

    return $tenant_entity_types;
  }

}
