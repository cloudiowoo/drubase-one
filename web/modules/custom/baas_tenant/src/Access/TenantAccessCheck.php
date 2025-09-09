<?php
/*
 * @Date: 2025-05-12 09:45:34
 * @LastEditors: cloudio cloudio.woo@gmail.com
 * @LastEditTime: 2025-05-12 09:45:34
 * @FilePath: /drubase/web/modules/custom/baas_tenant/src/Access/TenantAccessCheck.php
 */

declare(strict_types=1);

namespace Drupal\baas_tenant\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\baas_tenant\TenantResolver;
use Drupal\baas_tenant\Service\TenantPermissionChecker;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * 租户访问权限检查器.
 *
 * 用于检查当前用户是否有权访问租户资源。
 * 路由定义中可使用 '_tenant_access' 要求进行租户权限检查。
 */
class TenantAccessCheck implements AccessInterface
{

  /**
   * 租户解析器.
   *
   * @var \Drupal\baas_tenant\TenantResolver
   */
  protected $tenantResolver;

  /**
   * 请求栈.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * 日志服务.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * 租户权限检查服务.
   *
   * @var \Drupal\baas_tenant\Service\TenantPermissionChecker
   */
  protected $permissionChecker;

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_tenant\TenantResolver $tenant_resolver
   *   租户解析器.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   请求栈.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂.
   * @param \Drupal\baas_tenant\Service\TenantPermissionChecker $permission_checker
   *   租户权限检查服务.
   */
  public function __construct(
    TenantResolver $tenant_resolver,
    RequestStack $request_stack,
    LoggerChannelFactoryInterface $logger_factory,
    TenantPermissionChecker $permission_checker
  ) {
    $this->tenantResolver = $tenant_resolver;
    $this->requestStack = $request_stack;
    $this->logger = $logger_factory->get('baas_tenant');
    $this->permissionChecker = $permission_checker;
  }

  /**
   * 检查当前用户是否有权访问租户资源.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户.
   * @param string|null $tenant_id
   *   租户ID，如果未提供则从请求中解析.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果.
   */
  public function access(?AccountInterface $account = NULL, ?string $tenant_id = NULL): AccessResultInterface
  {
    // 如果没有提供账户，使用当前用户
    if ($account === NULL) {
      $account = \Drupal::currentUser();
    }

    // 如果未提供租户ID，尝试从请求中获取
    if ($tenant_id === NULL) {
      $tenant_id = $this->getTenantIdFromRequest();
    }

    // 如果仍未找到租户ID，拒绝访问
    if (!$tenant_id) {
      $this->logger->warning('访问被拒绝：无法识别租户');
      return AccessResult::forbidden('No tenant identified')->setCacheMaxAge(0);
    }

    // 从路由中获取操作类型
    $operation = $this->getOperationFromRequest();

    // 根据操作类型检查权限
    $access_granted = FALSE;

    switch ($operation) {
      case 'view':
        $access_granted = $this->permissionChecker->canViewTenant($account, $tenant_id);
        break;

      case 'edit':
        $access_granted = $this->permissionChecker->canEditTenant($account, $tenant_id);
        break;

      case 'delete':
        $access_granted = $this->permissionChecker->canDeleteTenant($account, $tenant_id);
        break;

      case 'manage_members':
        $access_granted = $this->permissionChecker->canManageTenantMembers($account, $tenant_id);
        break;

      case 'view_members':
        $access_granted = $this->permissionChecker->canViewTenantMembers($account, $tenant_id);
        break;

      case 'manage_api_keys':
        $access_granted = $this->permissionChecker->canManageTenantApiKeys($account, $tenant_id);
        break;

      case 'access_resources':
        $access_granted = $this->permissionChecker->canAccessTenantResources($account, $tenant_id);
        break;

      case 'transfer_ownership':
        $access_granted = $this->permissionChecker->canTransferTenantOwnership($account, $tenant_id);
        break;

      default:
        // 默认使用资源访问权限
        $access_granted = $this->permissionChecker->canAccessTenantResources($account, $tenant_id);
        break;
    }

    if ($access_granted) {
      return AccessResult::allowed()
        ->addCacheContexts(['user.permissions'])
        ->addCacheTags(['tenant:' . $tenant_id, 'user:' . $account->id()]);
    }

    $this->logger->warning('用户 @uid 对租户 @tenant_id 的 @operation 操作被拒绝：权限不足', [
      '@uid' => $account->id(),
      '@tenant_id' => $tenant_id,
      '@operation' => $operation,
    ]);

    return AccessResult::forbidden('Access denied to tenant operation')
      ->addCacheContexts(['user.permissions'])
      ->addCacheTags(['tenant:' . $tenant_id, 'user:' . $account->id()]);
  }

  /**
   * 从请求中获取操作类型.
   *
   * @return string
   *   操作类型.
   */
  protected function getOperationFromRequest(): string
  {
    $request = $this->requestStack->getCurrentRequest();

    if (!$request) {
      return 'view';
    }

    // 从路由选项中获取操作类型
    $route = $request->attributes->get('_route_object');
    if ($route && $route->hasOption('_tenant_operation')) {
      return $route->getOption('_tenant_operation');
    }

    // 默认操作类型
    return 'view';
  }

  /**
   * 从请求中获取租户ID.
   *
   * @return string|null
   *   租户ID或NULL.
   */
  protected function getTenantIdFromRequest(): ?string
  {
    $request = $this->requestStack->getCurrentRequest();

    if (!$request) {
      return NULL;
    }

    // 先从路由参数中查找
    if ($request->attributes->has('tenant_id')) {
      return $request->attributes->get('tenant_id');
    }

    // 再从请求属性中查找
    if ($request->attributes->has('_tenant_id')) {
      return $request->attributes->get('_tenant_id');
    }

    // 最后尝试从解析器中获取
    $tenant = $this->tenantResolver->getCurrentTenant();
    if ($tenant && isset($tenant['tenant_id'])) {
      return $tenant['tenant_id'];
    }

    return NULL;
  }

  /**
   * 检查用户是否可以创建租户.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   用户账户.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果.
   */
  public function canCreateTenant(AccountInterface $account): AccessResultInterface
  {
    if ($this->permissionChecker->canCreateTenant($account)) {
      return AccessResult::allowed()
        ->addCacheContexts(['user.permissions']);
    }

    return AccessResult::forbidden('Cannot create tenant')
      ->addCacheContexts(['user.permissions']);
  }
}