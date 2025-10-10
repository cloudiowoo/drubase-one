<?php

declare(strict_types=1);

namespace Drupal\baas_api\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\baas_api\ApiResponse;
use Drupal\baas_api\Service\RateLimitService;
use Drupal\baas_tenant\TenantResolverInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * API请求事件订阅者.
 *
 * 处理API请求的速率限制和验证.
 */
class ApiRequestSubscriber implements EventSubscriberInterface
{

  /**
   * 速率限制器服务.
   *
   * @var \Drupal\baas_api\Service\RateLimitService
   */
  protected RateLimitService $rateLimiter;

  /**
   * 租户解析器服务.
   *
   * @var \Drupal\baas_tenant\TenantResolverInterface
   */
  protected TenantResolverInterface $tenantResolver;

  /**
   * 日志通道.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * 状态服务.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected StateInterface $state;

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_api\Service\RateLimiter $rate_limiter
   *   速率限制器服务.
   * @param \Drupal\baas_tenant\TenantResolverInterface $tenant_resolver
   *   租户解析器服务.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂服务.
   * @param \Drupal\Core\State\StateInterface $state
   *   状态服务.
   */
  public function __construct(
    RateLimitService $rate_limiter,
    TenantResolverInterface $tenant_resolver,
    LoggerChannelFactoryInterface $logger_factory,
    StateInterface $state
  ) {
    $this->rateLimiter = $rate_limiter;
    $this->tenantResolver = $tenant_resolver;
    $this->logger = $logger_factory->get('baas_api');
    $this->state = $state;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents()
  {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 200],
    ];
  }

  /**
   * 处理请求事件.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   请求事件.
   */
  public function onKernelRequest(RequestEvent $event)
  {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $path = $request->getPathInfo();

    // 检查是否为API请求
    if (strpos($path, '/api/') !== 0) {
      return;
    }

    // 获取请求信息
    $method = $request->getMethod();
    $ip = $request->getClientIp();

    // 对于OPTIONS请求不检查速率限制（预检请求）
    if ($method === 'OPTIONS') {
      return;
    }

    // 尝试解析租户
    $tenant_id = '*'; // 默认为全局
    $tenant = $this->tenantResolver->resolveTenant();
    if ($tenant) {
      $tenant_id = $tenant['tenant_id'];
    } elseif ($request->attributes->has('tenant_id')) {
      $tenant_id = $request->attributes->get('tenant_id');
    }

    // 检查速率限制
    if ($this->state->get('baas_api.rate_limiting_enabled', TRUE)) {
      $limit_result = $this->rateLimiter->checkLimit($tenant_id, $path, $method, $ip);

      // 如果超过限制，返回429响应
      if (!$limit_result['allowed']) {
        $this->logger->warning('API请求超过速率限制: @tenant_id, @path, @method, @ip', [
          '@tenant_id' => $tenant_id,
          '@path' => $path,
          '@method' => $method,
          '@ip' => $ip,
        ]);

        $response = ApiResponse::error(
          'API请求频率超过限制',
          429,
          ['detail' => '请降低请求频率后重试']
        );

        // 设置速率限制头
        $response->headers->set('X-RateLimit-Limit', (string) $limit_result['limit']);
        $response->headers->set('X-RateLimit-Remaining', (string) $limit_result['remaining']);
        $response->headers->set('X-RateLimit-Reset', (string) $limit_result['reset']);
        $response->headers->set('Retry-After', (string) $limit_result['reset']);

        $event->setResponse($response);
        return;
      }

      // 如果未超过限制，添加速率限制头
      $request->attributes->set('_rate_limit_info', $limit_result);
    }

    // 验证API请求
    $this->validateApiRequest($event);
  }

  /**
   * 验证API请求.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   请求事件.
   */
  protected function validateApiRequest(RequestEvent $event)
  {
    $request = $event->getRequest();
    $path = $request->getPathInfo();

    // 检查是否为公开API - 如果是公开的，完全跳过认证验证
    if ($this->isPublicApi($path)) {
      return;
    }

    // 健康检查端点不需要验证
    if ($path === '/api/health') {
      return;
    }

    // 文档端点不需要验证
    if ($path === '/api/docs' || strpos($path, '/api/tenant/') === 0 && strpos($path, '/docs') !== FALSE) {
      return;
    }

    // 检查认证头
    $auth_header = $request->headers->get('Authorization');
    $api_key = NULL;

    // 优先从Authorization: Bearer <token>获取令牌
    if ($auth_header && preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
      $api_key = $matches[1];
    }

    // 如果没有从Authorization头获取到，则从X-API-Key头获取
    if (!$api_key) {
      $api_key = $request->headers->get('X-API-Key');
    }

    if (empty($api_key)) {
      // 检查是否为公开API
      if (!$this->isPublicApi($path)) {
        $this->logger->notice('API请求缺少认证信息: @path', [
          '@path' => $path,
        ]);

        $response = ApiResponse::error(
          '未认证',
          401,
          ['detail' => '缺少认证信息，请提供有效的Authorization令牌或API密钥']
        );

        $event->setResponse($response);
        return;
      }
    }

    // 如果提供了API密钥，将其添加到请求属性中以便后续处理
    if (!empty($api_key)) {
      $request->attributes->set('_api_key', $api_key);
    }
  }

  /**
   * 检查是否为公开API.
   *
   * @param string $path
   *   API路径.
   *
   * @return bool
   *   是否为公开API.
   */
  protected function isPublicApi(string $path): bool
  {
    // 定义公开API列表 - 这些API不需要认证
    $public_apis = [
      '/api/health',
      '/api/docs',
      '/api/auth/login',
      '/api/auth/refresh',
      '/api/auth/verify',
    ];

    // 检查是否在公开API列表中
    return in_array($path, $public_apis) || strpos($path, '/api/public/') === 0;
  }
}
