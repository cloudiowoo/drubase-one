<?php

declare(strict_types=1);

namespace Drupal\baas_tenant\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * 定义租户相关事件.
 */
class TenantEvent extends Event {

  /**
   * 租户创建事件.
   *
   * @var string
   */
  public const TENANT_CREATE = 'baas_tenant.tenant_create';

  /**
   * 租户更新事件.
   *
   * @var string
   */
  public const TENANT_UPDATE = 'baas_tenant.tenant_update';

  /**
   * 租户删除事件.
   *
   * @var string
   */
  public const TENANT_DELETE = 'baas_tenant.tenant_delete';

  /**
   * 构造函数.
   *
   * @param string $tenantId
   *   租户ID.
   * @param array $tenantData
   *   租户数据.
   */
  public function __construct(
    private readonly string $tenantId,
    private readonly array $tenantData = []
  ) {}

  /**
   * 获取租户ID.
   *
   * @return string
   *   租户ID.
   */
  public function getTenantId(): string {
    return $this->tenantId;
  }

  /**
   * 获取租户数据.
   *
   * @return array
   *   租户数据.
   */
  public function getTenantData(): array {
    return $this->tenantData;
  }

}
