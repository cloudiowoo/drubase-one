<?php

declare(strict_types=1);

namespace Drupal\baas_file\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * 文件存储引擎管理器。
 */
class FileStorageManager
{

  /**
   * 配置工厂。
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * 文件系统服务。
   */
  protected FileSystemInterface $fileSystem;

  /**
   * 日志记录器。
   */
  protected $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('baas_file');
  }

  /**
   * 获取默认存储引擎。
   */
  public function getDefaultEngine(): string
  {
    return $this->configFactory->get('baas_file.settings')->get('default_engine') ?? 'local';
  }
}