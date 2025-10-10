<?php

declare(strict_types=1);

namespace Drupal\baas_api\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\baas_api\Service\ApiManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * API事件订阅者.
 *
 * 监听和处理API相关的请求和响应事件.
 */
class ApiEventSubscriber implements EventSubscriberInterface {

  /**
   * API管理器服务.
   *
   * @var \Drupal\baas_api\Service\ApiManager
   */
  protected ApiManager $apiManager;

  /**
   * 日志通道.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * 当前用户.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected AccountInterface $currentUser;

  /**
   * 请求开始时间.
   *
   * @var float|null
   */
  protected ?float $requestStartTime = NULL;

  /**
   * 是否为API请求.
   *
   * @var bool
   */
  protected bool $isApiRequest = FALSE;

  /**
   * API信息.
   *
   * @var array
   */
  protected array $apiInfo = [];

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_api\Service\ApiManager $api_manager
   *   API管理器服务.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂服务.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   当前用户服务.
   */
  public function __construct(
    ApiManager $api_manager,
    LoggerChannelFactoryInterface $logger_factory,
    AccountInterface $current_user
  ) {
    $this->apiManager = $api_manager;
    $this->logger = $logger_factory->get('baas_api');
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 100],
      KernelEvents::RESPONSE => ['onKernelResponse', -100],
      KernelEvents::EXCEPTION => ['onKernelException', 0],
    ];
  }

  /**
   * 处理请求事件.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   请求事件.
   */
  public function onKernelRequest(RequestEvent $event) {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $path = $request->getPathInfo();

    // 检查是否为API请求
    if (strpos($path, '/api/') === 0) {
      $this->isApiRequest = TRUE;
      $this->requestStartTime = microtime(TRUE);
      $this->apiInfo = $this->apiManager->extractApiInfo($request);

      // 为API请求添加响应头
      $request->attributes->set('_api_request', TRUE);
      $request->attributes->set('_api_info', $this->apiInfo);
    }
  }

  /**
   * 处理响应事件.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   响应事件.
   */
  public function onKernelResponse(ResponseEvent $event) {
    if (!$event->isMainRequest() || !$this->isApiRequest || !$this->requestStartTime) {
      return;
    }

    $request = $event->getRequest();
    $response = $event->getResponse();

    // 获取API信息
    $api_info = $request->attributes->get('_api_info', $this->apiInfo);

    // 计算请求处理时间
    $request_time = microtime(TRUE) - $this->requestStartTime;
    $request_time_ms = round($request_time * 1000, 2);

    // 记录API请求日志
    if (\Drupal::state()->get('baas_api.logging_enabled', TRUE)) {
      $this->apiManager->logApiRequest(
        $api_info['tenant_id'],
        $api_info['endpoint'],
        $api_info['method'],
        $response->getStatusCode(),
        $request_time_ms,
        $api_info['ip'],
        $api_info['user_agent']
      );
    }

    // 为API响应添加标准头信息
    $response->headers->set('X-API-Version', \Drupal::config('baas_api.settings')->get('api_version') ?? 'v1');
    $response->headers->set('X-Request-ID', uniqid('api-', TRUE));
    $response->headers->set('X-Response-Time', $request_time_ms . 'ms');

    // 添加跨域头
    $this->addCorsHeaders($response);
  }

  /**
   * 处理异常事件.
   *
   * @param \Symfony\Component\HttpKernel\Event\ExceptionEvent $event
   *   异常事件.
   */
  public function onKernelException(ExceptionEvent $event) {
    if (!$this->isApiRequest) {
      return;
    }

    $exception = $event->getThrowable();
    $request = $event->getRequest();

    // 记录API异常
    $this->logger->error('API异常: @message', [
      '@message' => $exception->getMessage(),
    ]);

    // 这里不设置响应，让其他异常处理器处理
    // 如果需要自定义API异常响应，可以在这里处理
  }

  /**
   * 添加CORS头信息.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   响应对象.
   */
  protected function addCorsHeaders($response) {
    $config = \Drupal::config('baas_api.settings');

    // 检查是否启用CORS
    if (!$config->get('cors_enabled', TRUE)) {
      return;
    }

    // 设置允许的源
    $allowed_origins = $config->get('cors_allowed_origins', '*');
    $response->headers->set('Access-Control-Allow-Origin', $allowed_origins);

    // 设置允许的方法
    $allowed_methods = $config->get('cors_allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
    if (is_array($allowed_methods)) {
      $allowed_methods = implode(', ', $allowed_methods);
    }
    $response->headers->set('Access-Control-Allow-Methods', $allowed_methods);

    // 设置允许的头
    $allowed_headers = $config->get('cors_allowed_headers', 'Content-Type, Authorization, X-API-Key, X-Requested-With, X-BaaS-Project-ID, X-BaaS-Tenant-ID, x-baas-project-id, x-baas-tenant-id');
    $response->headers->set('Access-Control-Allow-Headers', $allowed_headers);

    // 设置其他CORS头
    $response->headers->set('Access-Control-Allow-Credentials', 'true');
    $response->headers->set('Access-Control-Max-Age', '3600');
  }

}