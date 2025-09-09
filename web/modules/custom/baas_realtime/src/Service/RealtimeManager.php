<?php

declare(strict_types=1);

namespace Drupal\baas_realtime\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Drupal\baas_tenant\TenantManagerInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * 实时数据同步管理服务。
 */
class RealtimeManager implements RealtimeManagerInterface
{

  /**
   * Logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly EventDispatcherInterface $eventDispatcher,
    protected readonly UnifiedPermissionCheckerInterface $permissionChecker,
    protected readonly TenantManagerInterface $tenantManager,
    protected readonly ProjectManagerInterface $projectManager,
  ) {
    $this->logger = $loggerFactory->get('baas_realtime');
  }

  /**
   * {@inheritdoc}
   */
  public function enableRealtime(string $project_id, array $settings = []): bool {
    try {
      // 验证项目存在
      $project = $this->projectManager->getProject($project_id);
      if (!$project) {
        $this->logger->error('Project not found: @project_id', [
          '@project_id' => $project_id,
        ]);
        return FALSE;
      }

      // 获取项目的动态表
      $tables = $this->getProjectTables($project_id);
      
      // 为每个表添加触发器
      $success_count = 0;
      foreach ($tables as $table_name) {
        if ($this->addTableTrigger($table_name, $settings)) {
          $success_count++;
        }
      }

      // 保存项目实时配置
      $this->saveProjectRealtimeConfig($project_id, [
        'enabled' => TRUE,
        'settings' => $settings,
        'enabled_at' => time(),
        'tables' => $tables,
      ]);

      $this->logger->info('Enabled realtime for project @project_id with @count tables', [
        '@project_id' => $project_id,
        '@count' => $success_count,
      ]);

      return $success_count > 0;

    } catch (\Exception $e) {
      $this->logger->error('Failed to enable realtime for project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function disableRealtime(string $project_id): bool {
    try {
      $config = $this->getProjectRealtimeConfig($project_id);
      if (!$config['enabled']) {
        return TRUE;
      }

      // 移除表触发器
      $success_count = 0;
      foreach ($config['tables'] ?? [] as $table_name) {
        if ($this->removeTableTrigger($table_name)) {
          $success_count++;
        }
      }

      // 清理相关连接
      $this->cleanupProjectConnections($project_id);

      // 更新配置
      $this->saveProjectRealtimeConfig($project_id, [
        'enabled' => FALSE,
        'disabled_at' => time(),
      ]);

      $this->logger->info('Disabled realtime for project @project_id', [
        '@project_id' => $project_id,
      ]);

      return TRUE;

    } catch (\Exception $e) {
      $this->logger->error('Failed to disable realtime for project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectRealtimeConfig(string $project_id): array {
    $config = $this->configFactory->get('baas_realtime.projects')->get($project_id);
    return $config ?? [
      'enabled' => FALSE,
      'settings' => [],
      'tables' => [],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function addTableTrigger(string $table_name, array $options = []): bool {
    try {
      $trigger_name = "realtime_trigger_{$table_name}";
      
      // 检查表是否存在
      if (!$this->database->schema()->tableExists($table_name)) {
        $this->logger->warning('Table does not exist: @table', [
          '@table' => $table_name,
        ]);
        return FALSE;
      }

      // 删除已存在的触发器
      $this->database->query("DROP TRIGGER IF EXISTS {$trigger_name} ON {$table_name}");

      // 创建新触发器
      $trigger_sql = "
        CREATE TRIGGER {$trigger_name}
        AFTER INSERT OR UPDATE OR DELETE ON {$table_name}
        FOR EACH ROW EXECUTE FUNCTION notify_realtime_change();
      ";

      $this->database->query($trigger_sql);

      $this->logger->debug('Added realtime trigger for table: @table', [
        '@table' => $table_name,
      ]);

      return TRUE;

    } catch (\Exception $e) {
      $this->logger->error('Failed to add trigger for table @table: @error', [
        '@table' => $table_name,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function removeTableTrigger(string $table_name): bool {
    try {
      $trigger_name = "realtime_trigger_{$table_name}";
      $this->database->query("DROP TRIGGER IF EXISTS {$trigger_name} ON {$table_name}");

      $this->logger->debug('Removed realtime trigger for table: @table', [
        '@table' => $table_name,
      ]);

      return TRUE;

    } catch (\Exception $e) {
      $this->logger->error('Failed to remove trigger for table @table: @error', [
        '@table' => $table_name,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getConnectionStats(?string $project_id = null): array {
    try {
      $query = $this->database->select('baas_realtime_connections', 'c')
        ->fields('c', ['status'])
        ->condition('status', 'connected');

      if ($project_id) {
        $query->condition('project_id', $project_id);
      }

      $query->addExpression('COUNT(*)', 'count');
      $query->groupBy('status');

      $results = $query->execute()->fetchAllAssoc('status');

      $stats = [
        'total_connections' => 0,
        'connected' => 0,
        'project_id' => $project_id,
        'timestamp' => time(),
      ];

      foreach ($results as $status => $result) {
        $stats['total_connections'] += (int) $result->count;
        $stats[$status] = (int) $result->count;
      }

      return $stats;

    } catch (\Exception $e) {
      $this->logger->error('Failed to get connection stats: @error', [
        '@error' => $e->getMessage(),
      ]);
      return ['error' => $e->getMessage()];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function cleanupExpiredConnections(int $timeout = 300): int {
    try {
      $expired_time = time() - $timeout;

      $expired_connections = $this->database->select('baas_realtime_connections', 'c')
        ->fields('c', ['connection_id'])
        ->condition('last_heartbeat', $expired_time, '<')
        ->condition('status', 'connected')
        ->execute()
        ->fetchCol();

      if (empty($expired_connections)) {
        return 0;
      }

      // 删除过期连接
      $deleted = $this->database->delete('baas_realtime_connections')
        ->condition('connection_id', $expired_connections, 'IN')
        ->execute();

      // 删除相关订阅
      $this->database->delete('baas_realtime_subscriptions')
        ->condition('connection_id', $expired_connections, 'IN')
        ->execute();

      $this->logger->info('Cleaned up @count expired connections', [
        '@count' => $deleted,
      ]);

      return $deleted;

    } catch (\Exception $e) {
      $this->logger->error('Failed to cleanup expired connections: @error', [
        '@error' => $e->getMessage(),
      ]);
      return 0;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function broadcast(string $channel, array $payload, array $options = []): bool {
    try {
      // 保存消息到历史表
      $this->database->insert('baas_realtime_messages')
        ->fields([
          'channel_name' => $channel,
          'event_type' => 'broadcast',
          'payload' => json_encode($payload),
          'metadata' => json_encode($options),
          'created_at' => time(),
          'expires_at' => isset($options['expires_at']) ? $options['expires_at'] : time() + 86400,
        ])
        ->execute();

      // 通过PostgreSQL NOTIFY发送消息
      $notification_payload = json_encode([
        'type' => 'broadcast',
        'channel' => $channel,
        'payload' => $payload,
        'options' => $options,
        'timestamp' => time(),
      ]);

      $this->database->query("SELECT pg_notify('realtime_broadcast', :payload)", [
        ':payload' => $notification_payload,
      ]);

      $this->logger->debug('Broadcast message sent to channel: @channel', [
        '@channel' => $channel,
      ]);

      return TRUE;

    } catch (\Exception $e) {
      $this->logger->error('Failed to broadcast message: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 获取项目的动态表列表。
   */
  protected function getProjectTables(string $project_id): array {
    try {
      $project_data = $this->projectManager->getProject($project_id);
      $tenant_id = $project_data['tenant_id'];
      
      // 生成表名前缀
      $combined_hash = substr(md5($tenant_id . '_' . $project_id), 0, 6);
      $table_prefix = "baas_{$combined_hash}_";

      // 查找所有匹配的表
      $tables = [];
      $schema = $this->database->schema();
      $all_tables = $schema->findTables($table_prefix . '%');

      foreach ($all_tables as $table_name) {
        if (strpos($table_name, $table_prefix) === 0) {
          $tables[] = $table_name;
        }
      }

      return $tables;

    } catch (\Exception $e) {
      $this->logger->error('Failed to get project tables for @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * 保存项目实时配置。
   */
  protected function saveProjectRealtimeConfig(string $project_id, array $config): void {
    $current_config = $this->getProjectRealtimeConfig($project_id);
    $updated_config = array_merge($current_config, $config);

    $this->configFactory->getEditable('baas_realtime.projects')
      ->set($project_id, $updated_config)
      ->save();
  }

  /**
   * 清理项目相关连接。
   */
  protected function cleanupProjectConnections(string $project_id): void {
    try {
      // 获取要删除的连接ID
      $connection_ids = $this->database->select('baas_realtime_connections', 'c')
        ->fields('c', ['connection_id'])
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchCol();

      if (!empty($connection_ids)) {
        // 删除订阅
        $this->database->delete('baas_realtime_subscriptions')
          ->condition('connection_id', $connection_ids, 'IN')
          ->execute();

        // 删除连接
        $this->database->delete('baas_realtime_connections')
          ->condition('project_id', $project_id)
          ->execute();

        $this->logger->debug('Cleaned up @count connections for project @project_id', [
          '@count' => count($connection_ids),
          '@project_id' => $project_id,
        ]);
      }

    } catch (\Exception $e) {
      $this->logger->error('Failed to cleanup project connections: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

}