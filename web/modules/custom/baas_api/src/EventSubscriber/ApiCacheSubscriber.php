<?php

declare(strict_types=1);

namespace Drupal\baas_api\EventSubscriber;

use Drupal\baas_api\Service\ApiCacheServiceInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * API缓存事件订阅器。
 *
 * 处理API请求的缓存逻辑。
 */
class ApiCacheSubscriber implements EventSubscriberInterface
{

  /**
   * API缓存服务。
   *
   * @var \Drupal\baas_api\Service\ApiCacheServiceInterface
   */
  protected readonly ApiCacheServiceInterface $cacheService;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_api\Service\ApiCacheServiceInterface $cache_service
   *   API缓存服务。
   */
  public function __construct(ApiCacheServiceInterface $cache_service)
  {
    $this->cacheService = $cache_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array
  {
    // 设置优先级确保在认证之后但在路由之前执行
    return [
      KernelEvents::REQUEST => ['onRequest', 25],
      KernelEvents::RESPONSE => ['onResponse', -10],
    ];
  }

  /**
   * 处理请求事件 - 检查缓存。
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   请求事件。
   */
  public function onRequest(RequestEvent $event): void
  {
    // 只处理主请求
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    
    // 只缓存API请求
    if (!$this->isApiRequest($request)) {
      return;
    }

    // 检查缓存
    $cachedResponse = $this->cacheService->getCachedResponse($request);
    
    if ($cachedResponse instanceof JsonResponse) {
      // 设置缓存响应，停止进一步处理
      $event->setResponse($cachedResponse);
    }
  }

  /**
   * 处理响应事件 - 缓存响应。
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   响应事件。
   */
  public function onResponse(ResponseEvent $event): void
  {
    // 只处理主请求
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $response = $event->getResponse();

    // 只缓存API请求的JSON响应
    if (!$this->isApiRequest($request) || !$response instanceof JsonResponse) {
      return;
    }

    // 缓存响应
    $this->cacheService->cacheResponse($request, $response);
  }

  /**
   * 检查是否为API请求。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return bool
   *   是API请求返回true。
   */
  protected function isApiRequest($request): bool
  {
    $path = $request->getPathInfo();
    
    // 检查API路径模式
    $apiPaths = [
      '/api/',
      '/openapi.json',
    ];

    foreach ($apiPaths as $apiPath) {
      if (strpos($path, $apiPath) === 0) {
        return true;
      }
    }

    return false;
  }

}