<?php

declare(strict_types=1);

namespace Drupal\baas_api\EventSubscriber;

use Drupal\baas_api\Service\ApiResponseService;
use Drupal\baas_api\Service\RateLimitService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * API速率限制事件订阅器。
 *
 * 对API请求进行速率限制检查。
 */
class ApiRateLimitSubscriber implements EventSubscriberInterface
{

  /**
   * API响应服务。
   *
   * @var \Drupal\baas_api\Service\ApiResponseService
   */
  protected ApiResponseService $responseService;

  /**
   * 速率限制服务。
   *
   * @var \Drupal\baas_api\Service\RateLimitService
   */
  protected RateLimitService $rateLimitService;

  /**
   * 日志器。
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_api\Service\ApiResponseService $response_service
   *   API响应服务。
   * @param \Drupal\baas_api\Service\RateLimitService $rate_limit_service
   *   速率限制服务。
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂。
   */
  public function __construct(
    ApiResponseService $response_service,
    RateLimitService $rate_limit_service,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->responseService = $response_service;
    $this->rateLimitService = $rate_limit_service;
    $this->logger = $logger_factory->get('baas_api');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array
  {
    return [
      // 优先级250，在认证(300)之后，但在其他处理之前
      KernelEvents::REQUEST => ['onKernelRequest', 250],
    ];
  }

  /**
   * 处理内核请求事件。
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   请求事件。
   */
  public function onKernelRequest(RequestEvent $event): void
  {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();

    // 检查全局限流是否启用
    $config = \Drupal::config('baas_api.settings');
    if (!$config->get('enable_rate_limiting')) {
      return; // 全局限流已禁用
    }

    // 如果项目级限流已经拒绝了请求，不需要再检查全局限流
    if ($request->attributes->get('project_rate_limit_rejected')) {
      return;
    }

    // 如果项目级限流决定跳过全局限流（项目限制更严格）
    if ($request->attributes->get('skip_global_rate_limit')) {
      return;
    }

    // 检查是否为API请求
    if (!$this->isApiRequest($request)) {
      return;
    }

    // 检查是否为免速率限制的端点
    if ($this->isRateLimitExempt($request)) {
      return;
    }

    // 获取速率限制配置（已包含标识符）
    $rate_limit_config = $this->getRateLimitConfig($request);

    // 检查速率限制
    // 注意：checkRateLimit的第一个参数是完整标识符，第二个参数是端点键
    $rate_limit_result = $this->rateLimitService->checkRateLimit(
      $rate_limit_config['key'],  // 使用配置中的标识符
      $request->getPathInfo(),     // 使用路径作为端点键
      $rate_limit_config['limit'],
      $rate_limit_config['window']
    );

    if (!$rate_limit_result['allowed']) {
      $response = $this->createRateLimitError($rate_limit_result);
      $event->setResponse($response);
      return;
    }

    // 添加速率限制头部信息
    $request->attributes->set('rate_limit_info', [
      'limit' => $rate_limit_result['limit'],
      'remaining' => $rate_limit_result['remaining'],
      'reset_time' => $rate_limit_result['reset_time'],
    ]);

    // 只在调试模式下记录成功通过的日志
    if (\Drupal::config('baas_api.settings')->get('debug_mode')) {
      $this->logger->debug('API速率限制检查通过: @client_id - 剩余: @remaining/@limit', [
        '@client_id' => $rate_limit_config['key'],
        '@remaining' => $rate_limit_result['remaining'],
        '@limit' => $rate_limit_result['limit'],
      ]);
    }
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
   * 检查是否为免速率限制的端点。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求。
   *
   * @return bool
   *   是否免速率限制。
   */
  protected function isRateLimitExempt(Request $request): bool
  {
    $path = $request->getPathInfo();
    
    // 免速率限制的端点列表
    $exempt_endpoints = [
      '/api/health',
      '/api/docs',
    ];

    foreach ($exempt_endpoints as $endpoint) {
      if (strpos($path, $endpoint) === 0) {
        return true;
      }
    }

    return false;
  }

  /**
   * 获取客户端标识。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求。
   *
   * @return string
   *   客户端标识。
   */
  protected function getClientId(Request $request): string
  {
    $auth_data = $request->attributes->get('auth_data');
    
    if ($auth_data) {
      // 已认证用户，使用用户ID和租户ID
      return sprintf('user_%d_tenant_%s', 
        $auth_data['user_id'], 
        $auth_data['tenant_id'] ?? 'global'
      );
    }

    // 未认证用户，使用IP地址
    return 'ip_' . $request->getClientIp();
  }

  /**
   * 获取速率限制配置。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求。
   *
   * @return array
   *   速率限制配置。
   */
  protected function getRateLimitConfig(Request $request): array
  {
    $path = $request->getPathInfo();
    $method = $request->getMethod();
    $auth_data = $request->attributes->get('auth_data');
    
    // 从配置读取限流设置
    $config = \Drupal::config('baas_api.settings');
    $rate_limits = $config->get('rate_limits') ?? [];
    
    // 根据认证状态选择限流级别
    if ($auth_data && isset($auth_data['user_id'])) {
      // 用户级限流
      $user_limits = $rate_limits['user'] ?? ['requests' => 60, 'window' => 60, 'burst' => 10];
      $identifier = 'user:' . $auth_data['user_id'];
      $limit_config = [
        'limit' => (int) $user_limits['requests'],
        'window' => (int) $user_limits['window'],
        'key' => $identifier,
      ];
    } else {
      // IP级限流
      $ip_limits = $rate_limits['ip'] ?? ['requests' => 30, 'window' => 60, 'burst' => 5];
      $identifier = 'ip:' . $request->getClientIp();
      $limit_config = [
        'limit' => (int) $ip_limits['requests'],
        'window' => (int) $ip_limits['window'],
        'key' => $identifier,
      ];
    }
    
    // 特殊端点可以覆盖默认配置（如认证端点）
    if (strpos($path, '/api/auth/') === 0) {
      // 认证端点使用更严格的限制
      $limit_config['limit'] = min($limit_config['limit'], 10);
      $limit_config['window'] = 60;
    }

    return $limit_config;
  }

  /**
   * 创建速率限制错误响应。
   *
   * @param array $rate_limit_result
   *   速率限制结果。
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   错误响应。
   */
  protected function createRateLimitError(array $rate_limit_result): \Symfony\Component\HttpFoundation\Response
  {
    $this->logger->warning('API速率限制超出: 限制=@limit, 重置时间=@reset_time', [
      '@limit' => $rate_limit_result['limit'],
      '@reset_time' => date('Y-m-d H:i:s', $rate_limit_result['reset_time']),
    ]);

    $context = [
      'limit' => $rate_limit_result['limit'],
      'remaining' => $rate_limit_result['remaining'],
      'reset_time' => $rate_limit_result['reset_time'],
      'retry_after' => $rate_limit_result['retry_after'],
    ];

    $response = $this->responseService->createErrorResponse(
      'Rate limit exceeded',
      'RATE_LIMIT_EXCEEDED',
      429,
      $context
    );

    // 添加速率限制头部
    $response->headers->set('X-RateLimit-Limit', (string) $rate_limit_result['limit']);
    $response->headers->set('X-RateLimit-Remaining', (string) $rate_limit_result['remaining']);
    $response->headers->set('X-RateLimit-Reset', (string) $rate_limit_result['reset_time']);
    $response->headers->set('Retry-After', (string) $rate_limit_result['retry_after']);

    return $response;
  }

}