<?php
/*
 * @Date: 2025-05-12 09:35:34
 * @LastEditors: cloudio cloudio.woo@gmail.com
 * @LastEditTime: 2025-05-12 09:35:34
 * @FilePath: /drubase/web/modules/custom/baas_tenant/src/EventSubscriber/TenantRequestSubscriber.php
 */

declare(strict_types=1);

namespace Drupal\baas_tenant\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Drupal\baas_tenant\TenantResolver;
use Psr\Log\LoggerInterface;
use Drupal\Core\State\StateInterface;

/**
 * 租户请求事件订阅器.
 *
 * 在请求初始化时识别当前租户。
 */
class TenantRequestSubscriber implements EventSubscriberInterface
{

  /**
   * 构造函数.
   */
  public function __construct(
    protected readonly TenantResolver $tenantResolver,
    protected readonly LoggerInterface $logger,
    protected readonly StateInterface $state
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array
  {
    // 优先级设为高，确保在其他事件处理前识别租户
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 100],
    ];
  }

  /**
   * 处理请求事件.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   请求事件.
   */
  public function onKernelRequest(RequestEvent $event): void
  {
    // 只处理主请求，忽略子请求
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();

    // 跳过Drupal内部路径，如管理页面等
    $path = $request->getPathInfo();
    if (
      strpos($path, '/admin/') === 0 ||
      strpos($path, '/core/') === 0 ||
      strpos($path, '/system/') === 0 ||
      $path === '/update.php' ||
      $path === '/cron' ||
      $path === '/batch'
    ) {
      return;
    }

    // 解析租户
    $tenant = $this->tenantResolver->resolveTenant();

    if ($tenant) {
      // 设置请求属性，以便在控制器中使用
      $request->attributes->set('_tenant', $tenant);
      $request->attributes->set('_tenant_id', $tenant['tenant_id']);

      // 在调试模式下记录日志
      if ($this->state->get('baas_tenant.debug_mode', FALSE)) {
        $this->logger->debug('已识别租户: @tenant_id, 路径: @path', [
          '@tenant_id' => $tenant['tenant_id'],
          '@path' => $path,
        ]);
      }
    }
  }
}
