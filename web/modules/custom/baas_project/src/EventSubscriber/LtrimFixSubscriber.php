<?php

declare(strict_types=1);

namespace Drupal\baas_project\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * 临时修复ltrim()错误的事件订阅者。
 */
class LtrimFixSubscriber implements EventSubscriberInterface
{

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array
  {
    return [
      KernelEvents::REQUEST => [
        ['onKernelRequest', 100],
      ],
    ];
  }

  /**
   * 在请求处理开始时设置错误处理。
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   请求事件。
   */
  public function onKernelRequest(RequestEvent $event): void
  {
    // 设置自定义错误处理器来捕获ltrim()错误
    set_error_handler([$this, 'handleLtrimError'], E_DEPRECATED);
  }

  /**
   * 处理ltrim()弃用错误。
   *
   * @param int $errno
   *   错误级别。
   * @param string $errstr
   *   错误消息。
   * @param string $errfile
   *   错误文件。
   * @param int $errline
   *   错误行号。
   *
   * @return bool
   *   是否处理了错误。
   */
  public function handleLtrimError(int $errno, string $errstr, string $errfile, int $errline): bool
  {
    // 只处理ltrim()相关的弃用错误
    if ($errno === E_DEPRECATED && 
        strpos($errstr, 'ltrim(): Passing null to parameter') !== false &&
        strpos($errfile, 'DefaultPluginManager.php') !== false) {
      
      // 记录错误但不显示给用户
      \Drupal::logger('baas_project')->warning('Caught ltrim() deprecation error: @error in @file:@line', [
        '@error' => $errstr,
        '@file' => $errfile,
        '@line' => $errline,
      ]);
      
      // 返回true表示我们已经处理了这个错误
      return true;
    }
    
    // 让其他错误继续正常处理
    return false;
  }

}