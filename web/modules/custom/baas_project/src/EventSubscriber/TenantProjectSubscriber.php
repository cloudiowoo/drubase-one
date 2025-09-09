<?php

declare(strict_types=1);

namespace Drupal\baas_project\EventSubscriber;

use Drupal\baas_tenant\Event\TenantEvent;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

/**
 * 租户项目事件订阅者 - 监听租户创建事件自动创建默认项目.
 */
class TenantProjectSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  protected readonly LoggerInterface $logger;

  /**
   * 构造函数.
   */
  public function __construct(
    protected readonly ProjectManagerInterface $projectManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('baas_project');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      TenantEvent::TENANT_CREATE => ['onTenantCreate', 10],
    ];
  }

  /**
   * 处理租户创建事件 - 记录租户创建但不自动创建项目.
   *
   * @param \Drupal\baas_tenant\Event\TenantEvent $event
   *   租户事件对象.
   */
  public function onTenantCreate(TenantEvent $event): void {
    try {
      $tenant_id = $event->getTenantId();
      $tenant_data = $event->getTenantData();
      
      // 新的权限模型：租户创建后不自动创建默认项目
      // 租户需要手动创建项目
      $this->logger->info('租户 @tenant 创建完成，项目需要手动创建', [
        '@tenant' => $tenant_id,
      ]);
      
      // 记录租户配置以供后续手动创建项目时参考
      $owner_uid = $tenant_data['owner_uid'] ?? 1;
      $settings = $tenant_data['settings'] ?? [];
      $tenant_type = $settings['type'] ?? 'standard';
      
      $this->logger->info('租户 @tenant 配置: 所有者=@owner, 类型=@type', [
        '@tenant' => $tenant_id,
        '@owner' => $owner_uid,
        '@type' => $tenant_type,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('租户创建事件处理异常: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * 根据租户类型获取项目限制.
   *
   * @param string $tenant_type
   *   租户类型.
   *
   * @return array
   *   项目限制配置.
   */
  protected function getProjectLimitsByTenantType(string $tenant_type): array {
    $limits = [
      'personal' => [
        'max_entities' => 20,
        'max_storage' => 256, // 256MB
        'max_api_calls' => 500,
      ],
      'standard' => [
        'max_entities' => 50,
        'max_storage' => 512, // 512MB
        'max_api_calls' => 1000,
      ],
      'enterprise' => [
        'max_entities' => 100,
        'max_storage' => 1024, // 1GB
        'max_api_calls' => 5000,
      ],
    ];

    return $limits[$tenant_type] ?? $limits['standard'];
  }

}