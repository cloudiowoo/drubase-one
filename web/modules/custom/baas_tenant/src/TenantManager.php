<?php

declare(strict_types=1);

namespace Drupal\baas_tenant;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Cache;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\baas_tenant\Event\TenantEvent;
use Psr\Log\LoggerInterface;

/**
 * 租户管理服务.
 */
class TenantManager implements TenantManagerInterface
{

  /**
   * 日志频道.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected readonly LoggerInterface $logger;

  /**
   * 构造函数.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接服务.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   配置工厂服务.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   日志通道工厂服务.
   * @param \Drupal\Core\State\StateInterface $state
   *   状态服务.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   事件分发器.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   缓存服务.
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly StateInterface $state,
    protected readonly EventDispatcherInterface $eventDispatcher,
    protected readonly CacheBackendInterface $cache
  ) {
    $this->logger = $loggerFactory->get('baas_tenant');
  }

  /**
   * {@inheritdoc}
   */
  public function createTenant(string $name, int $owner_uid, array $settings = []): string|false
  {
    // 验证所有者用户ID
    if ($owner_uid <= 0) {
      $this->logger->error('无效的所有者用户ID: @uid', [
        '@uid' => $owner_uid,
      ]);
      return false;
    }

    // 验证用户存在且有效
    $user = \Drupal::entityTypeManager()->getStorage('user')->load($owner_uid);
    if (!$user || !$user->isActive()) {
      $this->logger->error('所有者用户不存在或已禁用: @uid', [
        '@uid' => $owner_uid,
      ]);
      return false;
    }
    $tenant_id = baas_tenant_generate_tenant_id($name);

    // 验证租户ID
    if (!baas_tenant_validate_tenant_id($tenant_id)) {
      $this->logger->error('无效的租户ID格式: @tenant_id', [
        '@tenant_id' => $tenant_id,
      ]);
      return false;
    }

    try {
      // 检查租户ID是否已存在
      $exists = $this->database->select('baas_tenant_config', 't')
        ->fields('t', ['tenant_id'])
        ->condition('tenant_id', $tenant_id)
        ->execute()
        ->fetchField();

      if ($exists) {
        $this->logger->error('租户ID已存在: @tenant_id', [
          '@tenant_id' => $tenant_id,
        ]);
        return false;
      }

      // 设置默认值
      $defaultSettings = [
        'max_entities' => 100,
        'max_storage' => 1024, // 1GB
        'max_requests' => 10000,
      ];

      // 合并用户设置和默认设置
      $settings = array_merge($defaultSettings, $settings);

      // 检查联系邮箱唯一性（如果提供了的话）
      if (!empty($settings['contact_email'])) {
        $existing = $this->database->select('baas_tenant_config', 't')
          ->fields('t', ['tenant_id'])
          ->condition('contact_email', $settings['contact_email'])
          ->execute()
          ->fetchField();
        
        if ($existing) {
          $this->logger->error('联系邮箱已被其他租户使用: @email', [
            '@email' => $settings['contact_email'],
          ]);
          return false;
        }
      }

      // 确保settings数据是有效的
      if (!is_array($settings)) {
        $this->logger->error('租户设置必须为数组: @settings', [
          '@settings' => print_r($settings, TRUE),
        ]);
        return false;
      }

      // 记录创建操作开始
      $this->logger->info('开始创建租户: @name (@id)', [
        '@name' => $name,
        '@id' => $tenant_id,
      ]);

      // 插入租户数据
      $timestamp = time();
      $settingsJson = json_encode($settings);
      $this->database->insert('baas_tenant_config')
        ->fields([
          'tenant_id' => $tenant_id,
          'name' => $name,
          'owner_uid' => $owner_uid,
          'organization_type' => $settings['organization_type'] ?? 'company',
          'contact_email' => $settings['contact_email'] ?? $user->getEmail(),
          'settings' => $settingsJson,
          'status' => 1,
          'created' => $timestamp,
          'updated' => $timestamp,
        ])
        ->execute();

      // 创建租户数据表
      $this->createTenantTables($tenant_id);

      // 自动创建用户-租户映射关系，设置为租户所有者
      $this->createTenantOwnerMapping($tenant_id, $owner_uid);

      // 新的权限模型：不自动创建默认项目，由租户手动创建
      // $this->createDefaultProject($tenant_id, $owner_uid);
      
      $this->logger->info('租户 @tenant_id 创建成功，未自动创建默认项目', [
        '@tenant_id' => $tenant_id,
      ]);

      // 触发租户创建事件
      $event = new TenantEvent($tenant_id, [
        'name' => $name,
        'owner_uid' => $owner_uid,
        'settings' => $settings,
      ]);

      $this->eventDispatcher->dispatch($event, TenantEvent::TENANT_CREATE);

      $this->logger->info('租户创建成功: @tenant_id', [
        '@tenant_id' => $tenant_id,
      ]);

      return $tenant_id;
    } catch (\Exception $e) {
      $this->logger->error('创建租户时出错: @error', [
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getTenant(string $tenant_id): array|false
  {
    try {
      $record = $this->database->select('baas_tenant_config', 't')
        ->fields('t')
        ->condition('tenant_id', $tenant_id)
        ->execute()
        ->fetchAssoc();

      if (!$record) {
        return false;
      }

      // 解析JSON设置 - 确保处理bytea数据
      if (!empty($record['settings'])) {
        try {
          // 确保settings是字符串
          $settingsStr = is_string($record['settings']) ? $record['settings'] : (string) $record['settings'];
          $record['settings'] = json_decode($settingsStr, TRUE);

          if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('解析租户设置JSON失败: @error', [
              '@error' => json_last_error_msg(),
            ]);
            $record['settings'] = [];
          }
        } catch (\Exception $e) {
          $this->logger->warning('解析租户设置失败: @error', [
            '@error' => $e->getMessage(),
          ]);
          $record['settings'] = [];
        }
      } else {
        $record['settings'] = [];
      }

      return $record;
    } catch (\Exception $e) {
      $this->logger->error('获取租户信息失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateTenant(string $tenant_id, array $data): bool
  {
    try {
      // 首先检查租户是否存在
      $tenant = $this->getTenant($tenant_id);
      if (!$tenant) {
        $this->logger->error('尝试更新不存在的租户: @tenant_id', [
          '@tenant_id' => $tenant_id,
        ]);
        return false;
      }

      // 准备要更新的字段
      $fields = [];

      if (isset($data['name'])) {
        $fields['name'] = $data['name'];
      }

      if (isset($data['settings'])) {
        // 合并现有设置和新设置
        $current_settings = $tenant['settings'] ?? [];
        $new_settings = array_merge($current_settings, $data['settings']);
        $fields['settings'] = json_encode($new_settings);
      }

      if (isset($data['status'])) {
        $fields['status'] = (int) $data['status'];
      }

      // 设置更新时间
      $fields['updated'] = time();

      if (empty($fields)) {
        // 没有要更新的数据
        return true;
      }

      // 更新数据库
      $this->database->update('baas_tenant_config')
        ->fields($fields)
        ->condition('tenant_id', $tenant_id)
        ->execute();

      // 清除缓存
      Cache::invalidateTags(['baas_tenant:' . $tenant_id]);

      // 触发租户更新事件
      $event = new TenantEvent($tenant_id, $data);
      $this->eventDispatcher->dispatch($event, TenantEvent::TENANT_UPDATE);

      $this->logger->info('租户更新成功: @tenant_id', [
        '@tenant_id' => $tenant_id,
      ]);

      return true;
    } catch (\Exception $e) {
      $this->logger->error('租户更新失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteTenant(string $tenant_id): bool
  {
    try {
      // 首先检查租户是否存在
      $tenant = $this->getTenant($tenant_id);
      if (!$tenant) {
        $this->logger->error('尝试删除不存在的租户: @tenant_id', [
          '@tenant_id' => $tenant_id,
        ]);
        return false;
      }

      // 删除租户表
      $this->deleteTenantTables($tenant_id);

      // 删除用户-租户映射记录
      $this->database->delete('baas_user_tenant_mapping')
        ->condition('tenant_id', $tenant_id)
        ->execute();

      // 删除租户配置
      $this->database->delete('baas_tenant_config')
        ->condition('tenant_id', $tenant_id)
        ->execute();

      // 删除租户使用记录
      $this->database->delete('baas_tenant_usage')
        ->condition('tenant_id', $tenant_id)
        ->execute();

      // 清除缓存
      Cache::invalidateTags(['baas_tenant:' . $tenant_id]);

      // 触发租户删除事件
      $event = new TenantEvent($tenant_id, $tenant);
      $this->eventDispatcher->dispatch($event, TenantEvent::TENANT_DELETE);

      $this->logger->info('租户删除成功: @tenant_id', [
        '@tenant_id' => $tenant_id,
      ]);

      return true;
    } catch (\Exception $e) {
      $this->logger->error('租户删除失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function listTenants(array $conditions = []): array
  {
    try {
      $query = $this->database->select('baas_tenant_config', 't')
        ->fields('t');

      // 应用筛选条件
      if (!empty($conditions['status'])) {
        $query->condition('status', $conditions['status']);
      }

      if (!empty($conditions['name'])) {
        $query->condition('name', '%' . $this->database->escapeLike($conditions['name']) . '%', 'LIKE');
      }

      // 排序和分页
      $query->orderBy('created', 'DESC');

      if (!empty($conditions['limit'])) {
        $query->range(0, (int) $conditions['limit']);
      }

      $result = $query->execute();
      $tenants = [];

      while ($record = $result->fetchAssoc()) {
        if (!empty($record['settings'])) {
          try {
            // 确保settings是字符串
            $settingsStr = is_string($record['settings']) ? $record['settings'] : (string) $record['settings'];
            $record['settings'] = json_decode($settingsStr, TRUE);

            if (json_last_error() !== JSON_ERROR_NONE) {
              $this->logger->warning('列表中解析租户设置JSON失败: @tenant_id', [
                '@tenant_id' => $record['tenant_id'],
              ]);
              $record['settings'] = [];
            }
          } catch (\Exception $e) {
            $record['settings'] = [];
          }
        } else {
          $record['settings'] = [];
        }
        $tenants[$record['tenant_id']] = $record;
      }

      return $tenants;
    } catch (\Exception $e) {
      $this->logger->error('获取租户列表失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createTenantTables(string $tenant_id): void
  {
    $prefix = baas_tenant_get_table_prefix($tenant_id);
    $schema = $this->database->schema();

    // 定义租户专用表
    $tables = [
      $prefix . 'entity_data' => [
        'description' => '租户实体数据表',
        'fields' => [
          'id' => [
            'type' => 'serial',
            'unsigned' => TRUE,
            'not null' => TRUE,
          ],
          'entity_type' => [
            'type' => 'varchar',
            'length' => 128,
            'not null' => TRUE,
          ],
          'data' => [
            'type' => 'text',
            'size' => 'big',
            'not null' => FALSE,
          ],
          'created' => [
            'type' => 'int',
            'not null' => TRUE,
            'default' => 0,
          ],
          'updated' => [
            'type' => 'int',
            'not null' => TRUE,
            'default' => 0,
          ],
        ],
        'primary key' => ['id'],
        'indexes' => [
          'entity_type' => ['entity_type'],
        ],
      ],
      $prefix . 'functions' => [
        'description' => '租户自定义函数表',
        'fields' => [
          'id' => [
            'type' => 'serial',
            'unsigned' => TRUE,
            'not null' => TRUE,
          ],
          'name' => [
            'type' => 'varchar',
            'length' => 128,
            'not null' => TRUE,
          ],
          'code' => [
            'type' => 'text',
            'size' => 'big',
            'not null' => TRUE,
          ],
          'status' => [
            'type' => 'int',
            'size' => 'tiny',
            'not null' => TRUE,
            'default' => 1,
          ],
          'created' => [
            'type' => 'int',
            'not null' => TRUE,
            'default' => 0,
          ],
          'updated' => [
            'type' => 'int',
            'not null' => TRUE,
            'default' => 0,
          ],
        ],
        'primary key' => ['id'],
        'unique keys' => [
          'name' => ['name'],
        ],
      ],
    ];

    foreach ($tables as $table_name => $table_schema) {
      if (!$schema->tableExists($table_name)) {
        $schema->createTable($table_name, $table_schema);
        $this->logger->info('为租户 @tenant_id 创建表: @table', [
          '@tenant_id' => $tenant_id,
          '@table' => $table_name,
        ]);
      }
    }
  }

  /**
   * 删除租户相关的数据库表.
   *
   * @param string $tenant_id
   *   租户ID.
   */
  protected function deleteTenantTables(string $tenant_id): void
  {
    $prefix = baas_tenant_get_table_prefix($tenant_id);
    $schema = $this->database->schema();

    // 查找当前数据库中所有与该租户相关的表
    // 使用PostgreSQL兼容语法查询表
    $tables = $this->database->query(
      "SELECT table_name FROM information_schema.tables
       WHERE table_name LIKE :prefix AND table_schema = 'public'",
      [':prefix' => $prefix . '%']
    )->fetchCol();

    foreach ($tables as $table) {
      if ($schema->tableExists($table)) {
        $schema->dropTable($table);
        $this->logger->info('删除租户表: @table', [
          '@table' => $table,
        ]);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkResourceLimits(string $tenant_id, string $resource_type, int $additionalCount = 1): bool
  {
    $tenant = $this->getTenant($tenant_id);
    if (!$tenant) {
      return false;
    }

    $settings = $tenant['settings'] ?? [];
    $usageCount = $this->getUsage($tenant_id, $resource_type);

    // 获取资源类型对应的限制配置
    $limits = [
      'api_calls' => $settings['max_requests'] ?? 10000,
      'entities' => $settings['max_entities'] ?? 100,
      'functions' => $settings['max_edge_functions'] ?? 50,
      'storage' => $settings['max_storage'] ?? 1024, // MB为单位，不需要转换为字节
    ];

    if (!isset($limits[$resource_type])) {
      return true; // 未设置限制的资源类型默认允许
    }

    return ($usageCount + $additionalCount) <= $limits[$resource_type];
  }

  /**
   * {@inheritdoc}
   */
  public function recordUsage(string $tenant_id, string $resource_type, int $count = 1): bool
  {
    try {
      $timestamp = time();
      $date = date('Y-m-d', $timestamp);

      // 先检查当天是否已有记录
      $exists = $this->database->select('baas_tenant_usage', 'u')
        ->fields('u', ['id'])
        ->condition('tenant_id', $tenant_id)
        ->condition('resource_type', $resource_type)
        ->condition('date', $date)
        ->execute()
        ->fetchField();

      if ($exists) {
        // 更新现有记录
        $this->database->update('baas_tenant_usage')
          ->expression('count', 'count + :count', [':count' => $count])
          ->condition('id', $exists)
          ->execute();
      } else {
        // 创建新记录
        $this->database->insert('baas_tenant_usage')
          ->fields([
            'tenant_id' => $tenant_id,
            'resource_type' => $resource_type,
            'date' => $date,
            'count' => $count,
            'timestamp' => $timestamp,
          ])
          ->execute();
      }

      return true;
    } catch (\Exception $e) {
      $this->logger->error('记录资源使用失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUsage(string $tenant_id, string $resource_type, int $period = 30): int
  {
    $start_time = time() - ($period * 86400);

    try {
      $count = $this->database->select('baas_tenant_usage', 'u')
        ->condition('tenant_id', $tenant_id)
        ->condition('resource_type', $resource_type)
        ->condition('timestamp', $start_time, '>=')
        ->countQuery()
        ->execute()
        ->fetchField();

      return (int) $count;
    } catch (\Exception $e) {
      $this->logger->error('获取租户使用统计失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function loadTenant(string $tenant_id): ?array
  {
    $tenant = $this->getTenant($tenant_id);
    return $tenant ?: NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function loadTenantByDomain(string $domain): ?array
  {
    try {
      $tenant = $this->database->select('baas_tenant_config', 't')
        ->fields('t')
        ->condition('settings', '%"domain":"' . $domain . '"%', 'LIKE')
        ->condition('status', 1)
        ->execute()
        ->fetchAssoc();

      if ($tenant) {
        try {
          // 确保settings是字符串
          $settingsStr = is_string($tenant['settings']) ? $tenant['settings'] : (string) $tenant['settings'];
          $tenant['settings'] = json_decode($settingsStr, TRUE);

          if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('解析租户设置JSON失败: @error', [
              '@error' => json_last_error_msg(),
            ]);
            $tenant['settings'] = [];
          }

          // 验证域名是否匹配
          if (isset($tenant['settings']['domain']) && $tenant['settings']['domain'] === $domain) {
            return $tenant;
          }
        } catch (\Exception $e) {
          $this->logger->warning('解析租户设置失败: @error', [
            '@error' => $e->getMessage(),
          ]);
        }
      }

      return NULL;
    } catch (\Exception $e) {
      $this->logger->error('通过域名加载租户失败: @error', [
        '@error' => $e->getMessage(),
        '@domain' => $domain,
      ]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function loadTenantByWildcardDomain(string $main_domain, string $subdomain): ?array
  {
    try {
      // 查找设置了通配符域名的租户
      $tenants = $this->database->select('baas_tenant_config', 't')
        ->fields('t')
        ->condition('settings', '%"wildcard_domain":"' . $main_domain . '"%', 'LIKE')
        ->condition('status', 1)
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      foreach ($tenants as $tenant) {
        try {
          // 确保settings是字符串
          $settingsStr = is_string($tenant['settings']) ? $tenant['settings'] : (string) $tenant['settings'];
          $settings = json_decode($settingsStr, TRUE);

          if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('解析租户设置JSON失败: @error', [
              '@error' => json_last_error_msg(),
              '@tenant_id' => $tenant['tenant_id'],
            ]);
            continue;
          }

          // 检查此租户是否配置了通配符域名，且子域名匹配
          if (
            isset($settings['wildcard_domain']) &&
            $settings['wildcard_domain'] === $main_domain &&
            (!isset($settings['allowed_subdomains']) ||
              in_array($subdomain, $settings['allowed_subdomains']))
          ) {
            $tenant['settings'] = $settings;
            return $tenant;
          }
        } catch (\Exception $e) {
          $this->logger->warning('解析租户设置失败: @tenant_id, @error', [
            '@tenant_id' => $tenant['tenant_id'],
            '@error' => $e->getMessage(),
          ]);
          continue;
        }
      }

      return NULL;
    } catch (\Exception $e) {
      $this->logger->error('通过通配符域名加载租户失败: @error', [
        '@error' => $e->getMessage(),
        '@domain' => $subdomain . '.' . $main_domain,
      ]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function loadTenantByApiKey(string $api_key): ?array
  {
    try {
      // 首先检查baas_api_tokens表中是否有匹配的token
      $token_query = $this->database->select('baas_api_tokens', 't')
        ->fields('t', ['tenant_id'])
        ->condition('token_hash', $this->hashApiToken($api_key))
        ->condition('status', 1);

      // 添加过期时间条件：永不过期(expires=0)或者未过期(expires > 当前时间)
      $or_group = $token_query->orConditionGroup()
        ->condition('expires', 0)
        ->condition('expires', time(), '>');
      $token_query->condition($or_group);

      $token_record = $token_query->execute()->fetchAssoc();

      if ($token_record) {
        // 如果在tokens表中找到有效的token，则加载对应的租户
        return $this->loadTenant($token_record['tenant_id']);
      }

      // 如果在tokens表中没有找到，则检查租户配置表中的API密钥
      $tenant = $this->database->select('baas_tenant_config', 't')
        ->fields('t')
        ->condition('settings', '%"api_key":"' . $api_key . '"%', 'LIKE')
        ->condition('status', 1)
        ->execute()
        ->fetchAssoc();

      if ($tenant) {
        try {
          // 确保settings是字符串
          $settingsStr = is_string($tenant['settings']) ? $tenant['settings'] : (string) $tenant['settings'];
          $settings = json_decode($settingsStr, TRUE);

          if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('解析租户设置JSON失败: @error', [
              '@error' => json_last_error_msg(),
              '@tenant_id' => $tenant['tenant_id'],
            ]);
            return NULL;
          }

          // 验证API密钥是否匹配
          if (isset($settings['api_key']) && $settings['api_key'] === $api_key) {
            $tenant['settings'] = $settings;
            return $tenant;
          }
        } catch (\Exception $e) {
          $this->logger->warning('解析租户设置失败: @tenant_id, @error', [
            '@tenant_id' => $tenant['tenant_id'],
            '@error' => $e->getMessage(),
          ]);
        }
      }

      return NULL;
    } catch (\Exception $e) {
      $this->logger->error('通过API密钥加载租户失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * 哈希处理API令牌。
   *
   * @param string $token
   *   原始令牌值。
   *
   * @return string
   *   哈希后的令牌值。
   */
  protected function hashApiToken(string $token): string
  {
    return hash('sha256', $token);
  }

  /**
   * 为租户生成新的API密钥.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return string|false
   *   成功时返回新生成的API密钥，失败时返回false.
   */
  public function generateApiKey(string $tenant_id): string|false
  {
    try {
      // 获取租户信息
      $tenant = $this->getTenant($tenant_id);
      if (!$tenant) {
        $this->logger->error('找不到租户: @tenant_id', [
          '@tenant_id' => $tenant_id,
        ]);
        return false;
      }

      // 生成32位随机API密钥
      $api_key = bin2hex(random_bytes(16));

      // 更新租户设置
      $settings = $tenant['settings'] ?? [];
      $settings['api_key'] = $api_key;

      // 保存设置
      $result = $this->updateTenant($tenant_id, ['settings' => $settings]);
      if (!$result) {
        $this->logger->error('无法为租户更新API密钥: @tenant_id', [
          '@tenant_id' => $tenant_id,
        ]);
        return false;
      }

      $this->logger->info('已为租户生成新的API密钥: @tenant_id', [
        '@tenant_id' => $tenant_id,
      ]);

      return $api_key;
    } catch (\Exception $e) {
      $this->logger->error('生成API密钥时出错: @error', [
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * 移除租户的API密钥.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return bool
   *   操作成功返回TRUE，否则返回FALSE.
   */
  public function removeApiKey(string $tenant_id): bool
  {
    try {
      // 获取租户信息
      $tenant = $this->getTenant($tenant_id);
      if (!$tenant) {
        $this->logger->error('找不到租户: @tenant_id', [
          '@tenant_id' => $tenant_id,
        ]);
        return false;
      }

      // 更新租户设置，移除API密钥
      $settings = $tenant['settings'] ?? [];
      if (isset($settings['api_key'])) {
        unset($settings['api_key']);

        // 保存设置
        $result = $this->updateTenant($tenant_id, ['settings' => $settings]);
        if (!$result) {
          $this->logger->error('无法移除租户API密钥: @tenant_id', [
            '@tenant_id' => $tenant_id,
          ]);
          return false;
        }

        $this->logger->info('已移除租户API密钥: @tenant_id', [
          '@tenant_id' => $tenant_id,
        ]);
      }

      return true;
    } catch (\Exception $e) {
      $this->logger->error('移除API密钥时出错: @error', [
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * 获取租户的API密钥.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return string|null
   *   成功时返回API密钥，未设置或失败时返回NULL.
   */
  public function getApiKey(string $tenant_id): ?string
  {
    try {
      // 获取租户信息
      $tenant = $this->getTenant($tenant_id);
      if (!$tenant) {
        $this->logger->error('找不到租户: @tenant_id', [
          '@tenant_id' => $tenant_id,
        ]);
        return null;
      }

      // 返回API密钥
      return $tenant['settings']['api_key'] ?? null;
    } catch (\Exception $e) {
      $this->logger->error('获取API密钥时出错: @error', [
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateTenantDomain(string $tenant_id, array $domain_config): bool
  {
    try {
      // 获取当前租户信息
      $tenant = $this->getTenant($tenant_id);
      if (!$tenant) {
        $this->logger->error('尝试更新不存在的租户域名配置: @tenant_id', [
          '@tenant_id' => $tenant_id,
        ]);
        return false;
      }

      // 获取当前设置
      $settings = $tenant['settings'] ?? [];

      // 更新域名配置
      if (isset($domain_config['domain'])) {
        $settings['domain'] = $domain_config['domain'];
      }

      if (isset($domain_config['wildcard_domain'])) {
        $settings['wildcard_domain'] = $domain_config['wildcard_domain'];
      }

      if (isset($domain_config['allowed_subdomains'])) {
        $settings['allowed_subdomains'] = $domain_config['allowed_subdomains'];
      }

      if (isset($domain_config['custom_domains'])) {
        $settings['custom_domains'] = $domain_config['custom_domains'];
      }

      // 更新租户设置
      $result = $this->updateTenant($tenant_id, ['settings' => $settings]);

      if ($result) {
        $this->logger->info('已更新租户域名配置: @tenant_id', [
          '@tenant_id' => $tenant_id,
          '@domain' => $domain_config['domain'] ?? 'none',
        ]);
      }

      return $result;
    } catch (\Exception $e) {
      $this->logger->error('更新租户域名配置失败: @error', [
        '@error' => $e->getMessage(),
        '@tenant_id' => $tenant_id,
      ]);
      return false;
    }
  }

  /**
   * 创建用户-租户映射关系，设置为租户所有者。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param int $owner_uid
   *   所有者用户ID。
   */
  protected function createTenantOwnerMapping(string $tenant_id, int $owner_uid): void
  {
    try {
      // 检查是否已存在映射关系
      $exists = $this->database->select('baas_user_tenant_mapping', 'm')
        ->condition('user_id', $owner_uid)
        ->condition('tenant_id', $tenant_id)
        ->countQuery()
        ->execute()
        ->fetchField();

      if (!$exists) {
        $this->database->insert('baas_user_tenant_mapping')
          ->fields([
            'user_id' => $owner_uid,
            'tenant_id' => $tenant_id,
            'role' => 'tenant_owner',
            'is_owner' => 1,
            'status' => 1,
            'created' => time(),
            'updated' => time(),
          ])
          ->execute();

        $this->logger->info('已创建租户所有者映射: tenant=@tenant_id, user=@user_id', [
          '@tenant_id' => $tenant_id,
          '@user_id' => $owner_uid,
        ]);
      }
    } catch (\Exception $e) {
      $this->logger->error('创建租户所有者映射失败: @error', [
        '@error' => $e->getMessage(),
        '@tenant_id' => $tenant_id,
        '@user_id' => $owner_uid,
      ]);
    }
  }

  /**
   * 为新租户创建默认项目。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param int $owner_uid
   *   租户所有者用户ID。
   */
  protected function createDefaultProject(string $tenant_id, int $owner_uid): void
  {
    try {
      // 检查是否启用自动创建默认项目
      $config = $this->configFactory->get('baas_tenant.settings');
      $auto_create_project = $config->get('auto_create_default_project') ?? true;
      
      if (!$auto_create_project) {
        $this->logger->info('跳过创建默认项目（配置已禁用）: @tenant_id', [
          '@tenant_id' => $tenant_id,
        ]);
        return;
      }

      // 检查baas_project模块是否可用
      if (!\Drupal::moduleHandler()->moduleExists('baas_project')) {
        $this->logger->warning('baas_project模块未启用，跳过创建默认项目: @tenant_id', [
          '@tenant_id' => $tenant_id,
        ]);
        return;
      }

      // 获取项目管理服务
      try {
        $project_manager = \Drupal::service('baas_project.manager');
      } catch (\Exception $e) {
        $this->logger->warning('无法获取项目管理服务，跳过创建默认项目: @tenant_id, @error', [
          '@tenant_id' => $tenant_id,
          '@error' => $e->getMessage(),
        ]);
        return;
      }

      // 创建默认项目
      $project_data = [
        'name' => '默认项目',
        'machine_name' => 'default',
        'description' => '租户的默认项目，用于快速开始使用',
        'owner_uid' => $owner_uid,
        'is_default' => true,
        'auto_created' => true,
        'status' => 1,
      ];
      
      $project_id = $project_manager->createProject($tenant_id, $project_data);

      if ($project_id) {
        $this->logger->info('已为租户创建默认项目: tenant=@tenant_id, project=@project_id', [
          '@tenant_id' => $tenant_id,
          '@project_id' => $project_id,
        ]);
      } else {
        $this->logger->error('创建默认项目失败: @tenant_id', [
          '@tenant_id' => $tenant_id,
        ]);
      }

    } catch (\Exception $e) {
      $this->logger->error('创建默认项目时发生异常: @tenant_id, @error', [
        '@tenant_id' => $tenant_id,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 创建租户（向后兼容的旧接口）。
   *
   * @deprecated 使用 createTenant() 并指定 owner_uid 参数
   */
  public function createTenantLegacy(string $name, array $settings = []): string|false
  {
    // 使用当前用户作为默认所有者，如果是匿名用户则使用管理员
    $current_user = \Drupal::currentUser();
    $owner_uid = $current_user->isAuthenticated() ? (int) $current_user->id() : 1;

    $this->logger->warning('使用了已弃用的 createTenantLegacy 方法，请更新代码使用 createTenant(name, owner_uid, settings)');

    return $this->createTenant($name, $owner_uid, $settings);
  }
}
