<?php

declare(strict_types=1);

namespace Drupal\baas_realtime\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * 连接管理服务。
 */
class ConnectionManager
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
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly RealtimeManagerInterface $realtimeManager,
  ) {
    $this->logger = $loggerFactory->get('baas_realtime');
  }

  /**
   * 创建新连接。
   *
   * @param array $connection_data
   *   连接数据。
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE。
   */
  public function createConnection(array $connection_data): bool {
    try {
      $this->database->insert('baas_realtime_connections')
        ->fields($connection_data)
        ->execute();

      $this->logger->debug('Created connection: @connection_id', [
        '@connection_id' => $connection_data['connection_id'],
      ]);

      return TRUE;

    } catch (\Exception $e) {
      $this->logger->error('Failed to create connection: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 获取连接信息。
   *
   * @param string $connection_id
   *   连接ID。
   *
   * @return array|null
   *   连接信息或NULL。
   */
  public function getConnection(string $connection_id): ?array {
    try {
      $result = $this->database->select('baas_realtime_connections', 'c')
        ->fields('c')
        ->condition('connection_id', $connection_id)
        ->execute()
        ->fetchAssoc();

      if ($result) {
        // 解析metadata JSON
        if (!empty($result['metadata'])) {
          $result['metadata'] = json_decode($result['metadata'], TRUE);
        }
        return $result;
      }

      return NULL;

    } catch (\Exception $e) {
      $this->logger->error('Failed to get connection: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * 更新连接心跳。
   *
   * @param string $connection_id
   *   连接ID。
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE。
   */
  public function updateHeartbeat(string $connection_id): bool {
    try {
      $updated = $this->database->update('baas_realtime_connections')
        ->fields(['last_heartbeat' => time()])
        ->condition('connection_id', $connection_id)
        ->execute();

      return $updated > 0;

    } catch (\Exception $e) {
      $this->logger->error('Failed to update heartbeat: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 更新连接状态。
   *
   * @param string $connection_id
   *   连接ID。
   * @param string $status
   *   新状态。
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE。
   */
  public function updateConnectionStatus(string $connection_id, string $status): bool {
    try {
      $updated = $this->database->update('baas_realtime_connections')
        ->fields(['status' => $status])
        ->condition('connection_id', $connection_id)
        ->execute();

      $this->logger->debug('Updated connection status: @connection_id -> @status', [
        '@connection_id' => $connection_id,
        '@status' => $status,
      ]);

      return $updated > 0;

    } catch (\Exception $e) {
      $this->logger->error('Failed to update connection status: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 删除连接。
   *
   * @param string $connection_id
   *   连接ID。
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE。
   */
  public function removeConnection(string $connection_id): bool {
    try {
      // 删除相关订阅
      $this->database->delete('baas_realtime_subscriptions')
        ->condition('connection_id', $connection_id)
        ->execute();

      // 删除连接
      $deleted = $this->database->delete('baas_realtime_connections')
        ->condition('connection_id', $connection_id)
        ->execute();

      $this->logger->debug('Removed connection: @connection_id', [
        '@connection_id' => $connection_id,
      ]);

      return $deleted > 0;

    } catch (\Exception $e) {
      $this->logger->error('Failed to remove connection: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 创建频道订阅。
   *
   * @param array $subscription_data
   *   订阅数据。
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE。
   */
  public function createSubscription(array $subscription_data): bool {
    try {
      // 检查是否已存在相同订阅
      $existing = $this->database->select('baas_realtime_subscriptions', 's')
        ->fields('s', ['id'])
        ->condition('connection_id', $subscription_data['connection_id'])
        ->condition('channel_name', $subscription_data['channel_name'])
        ->execute()
        ->fetchField();

      if ($existing) {
        // 更新现有订阅
        $this->database->update('baas_realtime_subscriptions')
          ->fields($subscription_data)
          ->condition('id', $existing)
          ->execute();
      } else {
        // 创建新订阅
        $this->database->insert('baas_realtime_subscriptions')
          ->fields($subscription_data)
          ->execute();
      }

      $this->logger->debug('Created subscription: @connection_id -> @channel', [
        '@connection_id' => $subscription_data['connection_id'],
        '@channel' => $subscription_data['channel_name'],
      ]);

      return TRUE;

    } catch (\Exception $e) {
      $this->logger->error('Failed to create subscription: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 移除频道订阅。
   *
   * @param string $connection_id
   *   连接ID。
   * @param string $channel_name
   *   频道名。
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE。
   */
  public function removeSubscription(string $connection_id, string $channel_name): bool {
    try {
      $deleted = $this->database->delete('baas_realtime_subscriptions')
        ->condition('connection_id', $connection_id)
        ->condition('channel_name', $channel_name)
        ->execute();

      $this->logger->debug('Removed subscription: @connection_id -> @channel', [
        '@connection_id' => $connection_id,
        '@channel' => $channel_name,
      ]);

      return $deleted > 0;

    } catch (\Exception $e) {
      $this->logger->error('Failed to remove subscription: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 获取连接的所有订阅。
   *
   * @param string $connection_id
   *   连接ID。
   *
   * @return array
   *   订阅列表。
   */
  public function getConnectionSubscriptions(string $connection_id): array {
    try {
      $results = $this->database->select('baas_realtime_subscriptions', 's')
        ->fields('s')
        ->condition('connection_id', $connection_id)
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      // 解析JSON字段
      foreach ($results as &$result) {
        if (!empty($result['filters'])) {
          $result['filters'] = json_decode($result['filters'], TRUE);
        }
        if (!empty($result['event_types'])) {
          $result['event_types'] = explode(',', $result['event_types']);
        }
      }

      return $results;

    } catch (\Exception $e) {
      $this->logger->error('Failed to get connection subscriptions: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * 获取频道的所有订阅者。
   *
   * @param string $channel_name
   *   频道名。
   *
   * @return array
   *   订阅者列表。
   */
  public function getChannelSubscribers(string $channel_name): array {
    try {
      $results = $this->database->select('baas_realtime_subscriptions', 's')
        ->fields('s', ['connection_id'])
        ->fields('c', ['user_id', 'project_id', 'tenant_id', 'socket_id'])
        ->join('baas_realtime_connections', 'c', 's.connection_id = c.connection_id')
        ->condition('s.channel_name', $channel_name)
        ->condition('c.status', 'connected')
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      return $results;

    } catch (\Exception $e) {
      $this->logger->error('Failed to get channel subscribers: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * 获取项目的所有连接。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $status
   *   连接状态。
   *
   * @return array
   *   连接列表。
   */
  public function getProjectConnections(string $project_id, string $status = 'connected'): array {
    try {
      $query = $this->database->select('baas_realtime_connections', 'c')
        ->fields('c')
        ->condition('project_id', $project_id);

      if ($status) {
        $query->condition('status', $status);
      }

      $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

      // 解析metadata JSON
      foreach ($results as &$result) {
        if (!empty($result['metadata'])) {
          $result['metadata'] = json_decode($result['metadata'], TRUE);
        }
      }

      return $results;

    } catch (\Exception $e) {
      $this->logger->error('Failed to get project connections: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * 清理过期连接。
   *
   * @param int $timeout
   *   超时时间（秒）。
   *
   * @return int
   *   清理的连接数。
   */
  public function cleanupExpiredConnections(int $timeout = 300): int {
    try {
      $expired_time = time() - $timeout;

      // 获取过期连接ID
      $expired_connections = $this->database->select('baas_realtime_connections', 'c')
        ->fields('c', ['connection_id'])
        ->condition('last_heartbeat', $expired_time, '<')
        ->condition('status', 'connected')
        ->execute()
        ->fetchCol();

      if (empty($expired_connections)) {
        return 0;
      }

      // 删除相关订阅
      $this->database->delete('baas_realtime_subscriptions')
        ->condition('connection_id', $expired_connections, 'IN')
        ->execute();

      // 删除过期连接
      $deleted = $this->database->delete('baas_realtime_connections')
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

}