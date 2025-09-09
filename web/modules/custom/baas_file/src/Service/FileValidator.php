<?php

declare(strict_types=1);

namespace Drupal\baas_file\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * 文件验证服务。
 */
class FileValidator
{

  /**
   * 配置工厂。
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * 日志记录器。
   */
  protected $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('baas_file');
  }

  /**
   * 验证文件是否安全。
   */
  public function validateFile($file): array
  {
    $errors = [];
    
    // 基础验证逻辑
    if (!$file) {
      $errors[] = '文件不存在';
    }

    return $errors;
  }
}