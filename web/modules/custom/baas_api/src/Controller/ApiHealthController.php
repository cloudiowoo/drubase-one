<?php
/*
 * @Date: 2025-05-12 15:23:42
 * @LastEditors: cloudio cloudio.woo@gmail.com
 * @LastEditTime: 2025-05-13 16:56:04
 * @FilePath: /drubase/web/modules/custom/baas_api/src/Controller/ApiHealthController.php
 */

declare(strict_types=1);

namespace Drupal\baas_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * API健康检查控制器.
 *
 * 提供API健康状态检查功能.
 */
class ApiHealthController extends ControllerBase {

  /**
   * 构造函数.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   配置工厂.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory')
    );
  }

  /**
   * 检查API健康状态.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   健康状态响应.
   */
  public function checkHealth(): JsonResponse {
    // 获取API设置
    $config = $this->configFactory->get('baas_api.settings');
    $version = $config->get('api_version') ?? '1.0.0';

    // 构建响应
    $response = [
      'status' => 'ok',
      'version' => $version,
      'timestamp' => date('c'),
      'services' => [
        'database' => TRUE,
        'cache' => TRUE,
      ],
    ];

    // 返回JSON响应
    return new JsonResponse($response);
  }

}
