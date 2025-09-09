<?php

declare(strict_types=1);

namespace Drupal\baas_realtime\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\baas_realtime\Service\ConnectionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * 实时功能管理员控制器。
 */
class RealtimeAdminController extends ControllerBase
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
    protected readonly ConnectionManager $connectionManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('baas_realtime');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('baas_realtime.connection_manager'),
      $container->get('logger.factory')
    );
  }

  /**
   * 显示实时连接监控页面。
   *
   * @return array
   *   渲染数组。
   */
  public function connectionsList(): array {
    // 获取活跃连接
    $active_connections = $this->database->select('baas_realtime_connections', 'c')
      ->fields('c')
      ->condition('status', 'active')
      ->orderBy('connected_at', 'DESC')
      ->execute()
      ->fetchAll();

    // 获取统计信息
    $stats = [
      'total_connections' => $this->database->select('baas_realtime_connections')->countQuery()->execute()->fetchField(),
      'active_connections' => count($active_connections),
      'total_subscriptions' => $this->database->select('baas_realtime_subscriptions')->countQuery()->execute()->fetchField(),
      'messages_today' => $this->database->select('baas_realtime_messages')
        ->condition('created_at', strtotime('today'), '>=')
        ->countQuery()
        ->execute()
        ->fetchField(),
    ];

    $build = [
      '#theme' => 'baas_realtime_admin_connections',
      '#connections' => $active_connections,
      '#stats' => $stats,
      '#attached' => [
        'library' => ['baas_realtime/admin'],
      ],
    ];

    return $build;
  }

  /**
   * 显示实时统计报告页面。
   *
   * @return array
   *   渲染数组。
   */
  public function statistics(): array {
    // 获取连接统计
    $connection_stats = $this->database->query("
      SELECT 
        DATE(FROM_UNIXTIME(connected_at)) as date,
        COUNT(*) as connection_count
      FROM {baas_realtime_connections} 
      WHERE connected_at > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))
      GROUP BY DATE(FROM_UNIXTIME(connected_at))
      ORDER BY date DESC
    ")->fetchAll();

    // 获取消息统计
    $message_stats = $this->database->query("
      SELECT 
        DATE(FROM_UNIXTIME(created_at)) as date,
        COUNT(*) as message_count
      FROM {baas_realtime_messages} 
      WHERE created_at > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))
      GROUP BY DATE(FROM_UNIXTIME(created_at))
      ORDER BY date DESC
    ")->fetchAll();

    // 获取热门频道
    $popular_channels = $this->database->query("
      SELECT 
        channel_name,
        COUNT(*) as subscription_count
      FROM {baas_realtime_subscriptions} 
      GROUP BY channel_name
      ORDER BY subscription_count DESC
      LIMIT 10
    ")->fetchAll();

    $build = [
      '#theme' => 'baas_realtime_admin_stats',
      '#connection_stats' => $connection_stats,
      '#message_stats' => $message_stats,
      '#popular_channels' => $popular_channels,
      '#attached' => [
        'library' => ['baas_realtime/admin'],
      ],
    ];

    return $build;
  }

}