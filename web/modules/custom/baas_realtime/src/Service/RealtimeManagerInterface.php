<?php

declare(strict_types=1);

namespace Drupal\baas_realtime\Service;

/**
 * Interface for the Realtime Manager service.
 */
interface RealtimeManagerInterface
{

  /**
   * 启用实时数据同步。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $settings
   *   配置设置。
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE。
   */
  public function enableRealtime(string $project_id, array $settings = []): bool;

  /**
   * 禁用实时数据同步。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE。
   */
  public function disableRealtime(string $project_id): bool;

  /**
   * 获取项目的实时配置。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   配置数组。
   */
  public function getProjectRealtimeConfig(string $project_id): array;

  /**
   * 为表添加实时触发器。
   *
   * @param string $table_name
   *   表名。
   * @param array $options
   *   选项。
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE。
   */
  public function addTableTrigger(string $table_name, array $options = []): bool;

  /**
   * 移除表的实时触发器。
   *
   * @param string $table_name
   *   表名。
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE。
   */
  public function removeTableTrigger(string $table_name): bool;

  /**
   * 获取活跃连接统计。
   *
   * @param string|null $project_id
   *   项目ID，为空时返回全部统计。
   *
   * @return array
   *   统计数据。
   */
  public function getConnectionStats(?string $project_id = null): array;

  /**
   * 清理过期连接。
   *
   * @param int $timeout
   *   超时时间（秒）。
   *
   * @return int
   *   清理的连接数。
   */
  public function cleanupExpiredConnections(int $timeout = 300): int;

  /**
   * 发送广播消息。
   *
   * @param string $channel
   *   频道名。
   * @param array $payload
   *   消息内容。
   * @param array $options
   *   选项。
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE。
   */
  public function broadcast(string $channel, array $payload, array $options = []): bool;

}