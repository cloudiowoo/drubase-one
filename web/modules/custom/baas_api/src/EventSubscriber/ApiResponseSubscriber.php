<?php

declare(strict_types=1);

namespace Drupal\baas_api\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * API响应处理事件订阅器。
 *
 * 统一处理API响应，添加必要的头部信息和日志记录。
 */
class ApiResponseSubscriber implements EventSubscriberInterface
{

  /**
   * 日志器。
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * 构造函数。
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂。
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory)
  {
    $this->logger = $logger_factory->get('baas_api');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array
  {
    return [
      KernelEvents::RESPONSE => ['onKernelResponse', -200],
    ];
  }

  /**
   * 处理内核响应事件。
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   响应事件。
   */
  public function onKernelResponse(ResponseEvent $event): void
  {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $response = $event->getResponse();

    // 检查是否为API请求
    if (!$this->isApiRequest($request)) {
      return;
    }

    // 添加CORS头部
    $this->addCorsHeaders($response, $request);

    // 添加安全头部
    $this->addSecurityHeaders($response);

    // 添加API版本头部
    $this->addApiVersionHeaders($response);

    // 添加速率限制头部
    $this->addRateLimitHeaders($response, $request);

    // 添加请求ID头部
    $this->addRequestIdHeaders($response, $request);

    // 记录API调用日志
    $this->logApiCall($request, $response);
  }

  /**
   * 检查是否为API请求。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求。
   *
   * @return bool
   *   是否为API请求。
   */
  protected function isApiRequest(Request $request): bool
  {
    $path = $request->getPathInfo();
    return strpos($path, '/api/') === 0;
  }

  /**
   * 添加CORS头部。
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   HTTP响应。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求。
   */
  protected function addCorsHeaders($response, Request $request): void
  {
    $origin = $request->headers->get('Origin');
    
    // 允许的源列表（这里应该从配置中获取）
    $allowed_origins = [
      'http://localhost:3000',
      'https://localhost:3000',
      'http://127.0.0.1:3000',
      'https://127.0.0.1:3000',
      'http://localhost:19006',  // Expo Web 开发服务器端口
      'https://localhost:19006',
      'http://127.0.0.1:19006',
      'https://127.0.0.1:19006',
      'http://localhost:8081',   // Metro bundler 端口
      'https://localhost:8081',
      'http://127.0.0.1:8081',
      'https://127.0.0.1:8081',
    ];

    if ($origin && in_array($origin, $allowed_origins)) {
      $response->headers->set('Access-Control-Allow-Origin', $origin);
    } else {
      $response->headers->set('Access-Control-Allow-Origin', '*');
    }

    $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-Key, X-Requested-With, X-BaaS-Project-ID, X-BaaS-Tenant-ID, x-baas-project-id, x-baas-tenant-id');
    $response->headers->set('Access-Control-Allow-Credentials', 'true');
    $response->headers->set('Access-Control-Max-Age', '86400');

    // 处理预检请求
    if ($request->getMethod() === 'OPTIONS') {
      $response->setStatusCode(200);
      $response->setContent('');
    }
  }

  /**
   * 添加安全头部。
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   HTTP响应。
   */
  protected function addSecurityHeaders($response): void
  {
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('X-Frame-Options', 'DENY');
    $response->headers->set('X-XSS-Protection', '1; mode=block');
    $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->headers->set('Content-Security-Policy', "default-src 'self'");
  }

  /**
   * 添加API版本头部。
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   HTTP响应。
   */
  protected function addApiVersionHeaders($response): void
  {
    $response->headers->set('X-API-Version', 'v1');
    $response->headers->set('X-API-Server', 'BaaS-Platform');
  }

  /**
   * 添加速率限制头部。
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   HTTP响应。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求。
   */
  protected function addRateLimitHeaders($response, Request $request): void
  {
    $rate_limit_info = $request->attributes->get('rate_limit_info');
    
    if ($rate_limit_info) {
      $response->headers->set('X-RateLimit-Limit', (string) $rate_limit_info['limit']);
      $response->headers->set('X-RateLimit-Remaining', (string) $rate_limit_info['remaining']);
      $response->headers->set('X-RateLimit-Reset', (string) $rate_limit_info['reset_time']);
    }
  }

  /**
   * 添加请求ID头部。
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   HTTP响应。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求。
   */
  protected function addRequestIdHeaders($response, Request $request): void
  {
    // 从请求中获取或生成请求ID
    $request_id = $request->headers->get('X-Request-ID');
    
    if (!$request_id) {
      $request_id = 'req_' . uniqid() . '_' . substr(md5((string) microtime(true)), 0, 8);
    }

    $response->headers->set('X-Request-ID', $request_id);
  }

  /**
   * 记录API调用日志。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求。
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   HTTP响应。
   */
  protected function logApiCall(Request $request, $response): void
  {
    $auth_data = $request->attributes->get('auth_data');
    $start_time = $request->server->get('REQUEST_TIME_FLOAT');
    $response_time = $start_time ? round((microtime(true) - $start_time) * 1000, 2) : 0;

    $log_data = [
      '@method' => $request->getMethod(),
      '@path' => $request->getPathInfo(),
      '@status' => $response->getStatusCode(),
      '@response_time' => $response_time,
      '@user_id' => $auth_data['user_id'] ?? 'anonymous',
      '@tenant_id' => $auth_data['tenant_id'] ?? 'N/A',
      '@ip' => $request->getClientIp(),
      '@user_agent' => $request->headers->get('User-Agent', 'N/A'),
    ];

    if ($response->getStatusCode() >= 400) {
      $this->logger->warning('API调用失败: @method @path - @status (@response_time ms) - 用户: @user_id, 租户: @tenant_id, IP: @ip', $log_data);
    } else {
      $this->logger->info('API调用成功: @method @path - @status (@response_time ms) - 用户: @user_id, 租户: @tenant_id', $log_data);
    }

    // 记录详细的性能指标
    if ($response_time > 1000) {
      $this->logger->warning('API响应时间过长: @method @path - @response_time ms', $log_data);
    }

    // 对于JSON响应，记录响应大小
    if ($response instanceof JsonResponse) {
      $content_length = strlen($response->getContent());
      if ($content_length > 1024 * 1024) { // 1MB
        $this->logger->warning('API响应过大: @method @path - @size bytes', [
          '@method' => $request->getMethod(),
          '@path' => $request->getPathInfo(),
          '@size' => $content_length,
        ]);
      }
    }
  }

}