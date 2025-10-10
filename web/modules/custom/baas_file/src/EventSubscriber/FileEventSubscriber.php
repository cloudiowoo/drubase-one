<?php

declare(strict_types=1);

namespace Drupal\baas_file\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\baas_file\Service\FileUsageTracker;
use Drupal\baas_file\Service\FileAccessChecker;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * 文件事件订阅器。
 */
class FileEventSubscriber implements EventSubscriberInterface
{

  /**
   * 文件使用统计跟踪器。
   */
  protected FileUsageTracker $usageTracker;

  /**
   * 文件访问检查器。
   */
  protected FileAccessChecker $accessChecker;

  /**
   * 日志记录器。
   */
  protected $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    FileUsageTracker $usage_tracker,
    FileAccessChecker $access_checker,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->usageTracker = $usage_tracker;
    $this->accessChecker = $access_checker;
    $this->logger = $logger_factory->get('baas_file');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array
  {
    return [
      'baas_file.file_uploaded' => 'onFileUploaded',
      'baas_file.file_deleted' => 'onFileDeleted',
    ];
  }

  /**
   * 处理文件上传事件。
   */
  public function onFileUploaded($event): void
  {
    $data = $event->getData();
    $project_id = $data['project_id'] ?? '';
    
    if ($project_id) {
      $this->usageTracker->updateProjectStats($project_id);
    }
  }

  /**
   * 处理文件删除事件。
   */
  public function onFileDeleted($event): void
  {
    $data = $event->getData();
    $project_id = $data['project_id'] ?? '';
    
    if ($project_id) {
      $this->usageTracker->updateProjectStats($project_id);
    }
  }
}