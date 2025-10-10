<?php
/*
 * @Date: 2025-05-11 11:43:34
 * @LastEditors: cloudio cloudio.woo@gmail.com
 * @LastEditTime: 2025-05-11 13:02:34
 * @FilePath: /drubase/web/modules/custom/baas_tenant/src/EventSubscriber/TenantEventSubscriber.php
 */

declare(strict_types=1);

namespace Drupal\baas_tenant\EventSubscriber;

use Drupal\baas_tenant\Event\TenantEvent;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

/**
 * 租户事件订阅者.
 */
class TenantEventSubscriber implements EventSubscriberInterface {

  /**
   * 日志通道.
   */
  protected readonly LoggerInterface $logger;

  /**
   * 构造函数.
   */
  public function __construct(LoggerChannelFactoryInterface $loggerFactory) {
    $this->logger = $loggerFactory->get('baas_tenant');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      TenantEvent::TENANT_CREATE => 'onTenantCreate',
      TenantEvent::TENANT_UPDATE => 'onTenantUpdate',
      TenantEvent::TENANT_DELETE => 'onTenantDelete',
    ];
  }

  /**
   * 处理租户创建事件.
   *
   * @param \Drupal\baas_tenant\Event\TenantEvent $event
   *   租户事件.
   */
  public function onTenantCreate(TenantEvent $event): void {
    $tenant_id = $event->getTenantId();
    $tenant_data = $event->getTenantData();

    $this->logger->info('租户创建: @tenant_id', [
      '@tenant_id' => $tenant_id,
    ]);

    // 这里可以添加租户创建后的其他处理逻辑
  }

  /**
   * 处理租户更新事件.
   *
   * @param \Drupal\baas_tenant\Event\TenantEvent $event
   *   租户事件.
   */
  public function onTenantUpdate(TenantEvent $event): void {
    $tenant_id = $event->getTenantId();

    $this->logger->info('租户更新: @tenant_id', [
      '@tenant_id' => $tenant_id,
    ]);

    // 这里可以添加租户更新后的其他处理逻辑
  }

  /**
   * 处理租户删除事件.
   *
   * @param \Drupal\baas_tenant\Event\TenantEvent $event
   *   租户事件.
   */
  public function onTenantDelete(TenantEvent $event): void {
    $tenant_id = $event->getTenantId();

    $this->logger->info('租户删除: @tenant_id', [
      '@tenant_id' => $tenant_id,
    ]);

    // 这里可以添加租户删除后的其他处理逻辑
  }

}
