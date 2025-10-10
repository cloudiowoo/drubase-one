<?php
/*
 * @Date: 2025-05-14 00:02:46
 * @LastEditors: cloudio cloudio.woo@gmail.com
 * @LastEditTime: 2025-05-14 00:02:46
 * @FilePath: /drubase/web/modules/custom/baas_api/src/EventSubscriber/ApiTokenUsageSubscriber.php
 */

namespace Drupal\baas_api\EventSubscriber;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpFoundation\Request;

/**
 * API令牌使用时间更新事件订阅器.
 *
 * 监听API请求完成事件，更新API令牌的最后使用时间.
 */
class ApiTokenUsageSubscriber implements EventSubscriberInterface {

  /**
   * 数据库连接.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * 日志通道.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * 构造函数.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志通道工厂.
   */
  public function __construct(
    Connection $database,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->database = $database;
    $this->logger = $logger_factory->get('baas_api');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    // 使用较低优先级，确保在其他验证处理之后执行
    return [
      KernelEvents::RESPONSE => ['onResponse', -100],
    ];
  }

  /**
   * 响应事件处理.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   响应事件.
   */
  public function onResponse(ResponseEvent $event) {
    // 只处理主请求
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();

    // 只处理API请求
    $path = $request->getPathInfo();
    if (strpos($path, '/api/') !== 0) {
      return;
    }

    // 检查是否有API令牌
    $this->updateTokenUsageFromRequest($request);
  }

  /**
   * 从请求中获取API令牌并更新使用时间.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求.
   */
  protected function updateTokenUsageFromRequest(Request $request) {
    $auth_header = $request->headers->get('Authorization');
    $token = NULL;

    // 从Authorization: Bearer <token>获取令牌
    if ($auth_header && preg_match('/^Bearer\s+(.+)$/i', $auth_header, $matches)) {
      $token = $matches[1];
    }

    if (!$token) {
      return;
    }

    // 计算令牌的哈希值
    $token_hash = hash('sha256', $token);

    try {
      // 更新令牌的最后使用时间
      $affected = $this->database->update('baas_api_tokens')
        ->fields(['last_used' => time()])
        ->condition('token_hash', $token_hash)
        ->condition('status', 1)
        ->execute();

      if ($affected) {
        $this->logger->debug('已更新API令牌使用时间: @path', [
          '@path' => $request->getPathInfo(),
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('更新API令牌使用时间失败: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

}
