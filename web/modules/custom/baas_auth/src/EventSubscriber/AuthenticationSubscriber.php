<?php

declare(strict_types=1);

namespace Drupal\baas_auth\EventSubscriber;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\baas_auth\Service\JwtTokenManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * 认证事件订阅器.
 *
 * 处理API请求的认证信息，设置auth_data属性.
 */
class AuthenticationSubscriber implements EventSubscriberInterface
{

  /**
   * JWT令牌管理器.
   *
   * @var \Drupal\baas_auth\Service\JwtTokenManagerInterface
   */
  protected JwtTokenManagerInterface $jwtTokenManager;

  /**
   * 日志通道.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_auth\Service\JwtTokenManagerInterface $jwt_token_manager
   *   JWT令牌管理器.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂服务.
   */
  public function __construct(
    JwtTokenManagerInterface $jwt_token_manager,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->jwtTokenManager = $jwt_token_manager;
    $this->logger = $logger_factory->get('baas_auth');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array
  {
    return [
      KernelEvents::REQUEST => ['onKernelRequest', 10],
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
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $path = $request->getPathInfo();

    // 只处理API请求
    if (strpos($path, '/api/') !== 0) {
      return;
    }

    $this->logger->debug('AuthenticationSubscriber: 处理API请求 @path', ['@path' => $path]);

    // 检查Authorization头
    $authorization = $request->headers->get('Authorization');
    if (!$authorization || strpos($authorization, 'Bearer ') !== 0) {
      $this->logger->debug('AuthenticationSubscriber: 没有找到Bearer令牌');
      return;
    }

    $token = substr($authorization, 7); // 移除 "Bearer " 前缀

    try {
      // 验证JWT令牌
      $payload = $this->jwtTokenManager->validateToken($token);

      if (!$payload) {
        $this->logger->debug('AuthenticationSubscriber: JWT令牌验证失败');
        return;
      }

      $this->logger->debug('AuthenticationSubscriber: 获取到用户载荷 @payload', ['@payload' => json_encode($payload)]);

      // 设置auth_data属性
      $auth_data = [
        'user_id' => (int) $payload['sub'],
        'tenant_id' => $payload['tenant_id'],
        'username' => $payload['username'] ?? 'unknown',
        'permissions' => $payload['permissions'] ?? [],
        'token_type' => $payload['type'] ?? 'access',
      ];

      $request->attributes->set('auth_data', $auth_data);

      $this->logger->debug('AuthenticationSubscriber: 设置认证数据: user_id=@user_id, tenant_id=@tenant_id', [
        '@user_id' => $auth_data['user_id'],
        '@tenant_id' => $auth_data['tenant_id'],
      ]);
    } catch (\Exception $e) {
      $this->logger->debug('AuthenticationSubscriber: JWT令牌处理异常: @message', ['@message' => $e->getMessage()]);
    }
  }
}
