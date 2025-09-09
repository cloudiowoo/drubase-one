<?php
/*
 * @Date: 2025-05-12 10:00:34
 * @LastEditors: cloudio cloudio.woo@gmail.com
 * @LastEditTime: 2025-05-12 10:00:34
 * @FilePath: /drubase/web/modules/custom/baas_tenant/src/Controller/TenantApiController.php
 */

declare(strict_types=1);

namespace Drupal\baas_tenant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\baas_tenant\TenantResolver;
use Drupal\baas_tenant\ApiResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * 租户API控制器.
 *
 * 提供租户相关的API端点，用于演示租户识别和访问检查功能。
 */
class TenantApiController extends ControllerBase {

  /**
   * 租户解析器.
   *
   * @var \Drupal\baas_tenant\TenantResolver
   */
  protected $tenantResolver;

  /**
   * 日志服务.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_tenant\TenantResolver $tenant_resolver
   *   租户解析器.
   * @param \Psr\Log\LoggerInterface $logger
   *   日志服务.
   */
  public function __construct(TenantResolver $tenant_resolver, LoggerInterface $logger) {
    $this->tenantResolver = $tenant_resolver;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('baas_tenant.resolver'),
      $container->get('logger.channel.baas_tenant')
    );
  }

  /**
   * 获取当前租户信息.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   包含租户信息的JSON响应.
   */
  public function getCurrentTenant(Request $request) {
    // 通过请求属性获取租户信息
    $tenant = $request->attributes->get('_tenant');

    if (!$tenant) {
      // 如果请求属性中没有，尝试从解析器获取
      $tenant = $this->tenantResolver->getCurrentTenant();
    }

    if (!$tenant) {
      return ApiResponse::error('未找到租户信息', 404);
    }

    // 移除敏感信息
    $safe_tenant = [
      'tenant_id' => $tenant['tenant_id'],
      'name' => $tenant['name'],
      'status' => (bool) $tenant['status'],
      'created' => (int) $tenant['created'],
    ];

    // 加入调试信息
    if ($this->currentUser()->hasPermission('administer baas tenants')) {
      $safe_tenant['debug'] = [
        'request_method' => $request->getMethod(),
        'request_path' => $request->getPathInfo(),
        'hostname' => $request->getHost(),
        'has_api_key' => (bool) $request->headers->get('X-API-Key'),
      ];
    }

    return ApiResponse::success($safe_tenant);
  }

  /**
   * 获取特定租户信息.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   包含租户信息的JSON响应.
   */
  public function getTenantInfo(Request $request, $tenant_id) {
    // 根据ID加载租户
    $tenant = $this->tenantResolver->resolveTenantFromId($tenant_id);

    if (!$tenant) {
      return ApiResponse::error('未找到租户: ' . $tenant_id, 404);
    }

    // 移除敏感信息
    $safe_tenant = [
      'tenant_id' => $tenant['tenant_id'],
      'name' => $tenant['name'],
      'status' => (bool) $tenant['status'],
      'created' => (int) $tenant['created'],
    ];

    return ApiResponse::success($safe_tenant);
  }

  /**
   * 获取租户资源使用统计.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   包含资源使用统计的JSON响应.
   */
  public function getTenantUsage(Request $request, $tenant_id) {
    // 根据ID加载租户
    $tenant = $this->tenantResolver->resolveTenantFromId($tenant_id);

    if (!$tenant) {
      return ApiResponse::error('未找到租户: ' . $tenant_id, 404);
    }

    // 获取租户管理器服务
    $tenant_manager = \Drupal::service('baas_tenant.manager');

    // 获取不同类型的资源使用统计
    $usage = [
      'api_calls' => $tenant_manager->getUsage($tenant_id, 'api_call', 30),
      'storage' => $tenant_manager->getUsage($tenant_id, 'storage', 30),
      'entities' => $tenant_manager->getUsage($tenant_id, 'entity', 30),
      'functions' => $tenant_manager->getUsage($tenant_id, 'function', 30),
    ];

    // 记录API调用
    $tenant_manager->recordUsage($tenant_id, 'api_call');

    return ApiResponse::success([
      'tenant_id' => $tenant_id,
      'usage' => $usage,
      'period' => '30天',
    ]);
  }
}
