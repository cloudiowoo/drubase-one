<?php

declare(strict_types=1);

namespace Drupal\baas_api\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Request;

/**
 * 事件订阅器，用于支持HTTP方法重写.
 *
 * 处理非标准HTTP方法（如PUT/DELETE）的请求，支持X-HTTP-Method-Override头.
 */
class ApiMethodOverrideSubscriber implements EventSubscriberInterface {

  /**
   * 日志通道.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * 构造函数.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   日志工厂服务.
   */
  public function __construct(LoggerChannelFactoryInterface $loggerFactory) {
    $this->logger = $loggerFactory->get('baas_api');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // 在其他事件处理之前处理方法重写.
    $events[KernelEvents::REQUEST][] = ['onRequest', 100];
    return $events;
  }

  /**
   * 处理请求事件.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   请求事件.
   */
  public function onRequest(RequestEvent $event): void {
    $request = $event->getRequest();

    // 仅处理API路径
    if (!$this->isApiRequest($request)) {
      return;
    }

    // 检查X-HTTP-Method-Override头
    if ($request->headers->has('X-HTTP-Method-Override')) {
      $method = $request->headers->get('X-HTTP-Method-Override');
      $this->logger->notice('重写HTTP方法: @original_method -> @new_method', [
        '@original_method' => $request->getMethod(),
        '@new_method' => $method,
      ]);
      $request->setMethod($method);
    }

    // 处理_method查询参数
    if ($request->query->has('_method')) {
      $method = $request->query->get('_method');
      $this->logger->notice('重写HTTP方法(查询参数): @original_method -> @new_method', [
        '@original_method' => $request->getMethod(),
        '@new_method' => $method,
      ]);
      $request->setMethod($method);
    }

    // 处理POST请求中的_method表单字段
    if ($request->isMethod('POST') && $request->request->has('_method')) {
      $method = $request->request->get('_method');
      $this->logger->notice('重写HTTP方法(表单字段): POST -> @new_method', [
        '@new_method' => $method,
      ]);
      $request->setMethod($method);
    }
  }

  /**
   * 检查请求是否为API请求.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象.
   *
   * @return bool
   *   是否为API请求.
   */
  protected function isApiRequest(Request $request): bool {
    $path = $request->getPathInfo();
    return strpos($path, '/api/') === 0;
  }

}
