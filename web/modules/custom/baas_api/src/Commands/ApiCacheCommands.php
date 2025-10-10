<?php

declare(strict_types=1);

namespace Drupal\baas_api\Commands;

use Drupal\baas_api\Service\ApiCacheServiceInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * BaaS API缓存管理命令。
 *
 * 提供Drush命令用于管理API缓存。
 */
final class ApiCacheCommands extends DrushCommands
{

  /**
   * API缓存服务。
   *
   * @var \Drupal\baas_api\Service\ApiCacheServiceInterface
   */
  protected readonly ApiCacheServiceInterface $cacheService;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_api\Service\ApiCacheServiceInterface $cache_service
   *   API缓存服务。
   */
  public function __construct(ApiCacheServiceInterface $cache_service)
  {
    parent::__construct();
    $this->cacheService = $cache_service;
  }

  /**
   * 从容器中创建实例。
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   服务容器。
   *
   * @return static
   *   命令实例。
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_api.cache')
    );
  }

  /**
   * 清除所有API缓存。
   */
  #[CLI\Command(name: 'baas:cache:clear', aliases: ['bcc'])]
  #[CLI\Help(description: 'Clear all BaaS API cache')]
  public function clearCache(): void
  {
    $this->cacheService->invalidateCache();
    $this->logger()->success('All BaaS API cache cleared successfully.');
  }

  /**
   * 清除指定租户的缓存。
   *
   * @param string $tenant_id
   *   租户ID。
   */
  #[CLI\Command(name: 'baas:cache:clear-tenant')]
  #[CLI\Argument(name: 'tenant_id', description: 'Tenant ID to clear cache for')]
  #[CLI\Help(description: 'Clear BaaS API cache for a specific tenant')]
  public function clearTenantCache(string $tenant_id): void
  {
    $this->cacheService->invalidateTenantCache($tenant_id);
    $this->logger()->success("BaaS API cache for tenant '{$tenant_id}' cleared successfully.");
  }

  /**
   * 清除指定项目的缓存。
   *
   * @param string $project_id
   *   项目ID。
   */
  #[CLI\Command(name: 'baas:cache:clear-project')]
  #[CLI\Argument(name: 'project_id', description: 'Project ID to clear cache for')]
  #[CLI\Help(description: 'Clear BaaS API cache for a specific project')]
  public function clearProjectCache(string $project_id): void
  {
    $this->cacheService->invalidateProjectCache($project_id);
    $this->logger()->success("BaaS API cache for project '{$project_id}' cleared successfully.");
  }

  /**
   * 清除指定实体的缓存。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $entity_name
   *   实体名称。
   */
  #[CLI\Command(name: 'baas:cache:clear-entity')]
  #[CLI\Argument(name: 'tenant_id', description: 'Tenant ID')]
  #[CLI\Argument(name: 'entity_name', description: 'Entity name to clear cache for')]
  #[CLI\Help(description: 'Clear BaaS API cache for a specific entity')]
  public function clearEntityCache(string $tenant_id, string $entity_name): void
  {
    $this->cacheService->invalidateEntityCache($tenant_id, $entity_name);
    $this->logger()->success("BaaS API cache for entity '{$entity_name}' in tenant '{$tenant_id}' cleared successfully.");
  }

  /**
   * 预热API缓存。
   *
   * @param array $options
   *   命令选项。
   */
  #[CLI\Command(name: 'baas:cache:warmup')]
  #[CLI\Option(name: 'endpoints', description: 'Comma-separated list of endpoints to warm up')]
  #[CLI\Help(description: 'Warm up BaaS API cache for specified endpoints')]
  public function warmupCache(array $options = ['endpoints' => '']): void
  {
    $endpoints = [];
    if (!empty($options['endpoints'])) {
      $endpoints = explode(',', $options['endpoints']);
      $endpoints = array_map('trim', $endpoints);
    }

    $this->logger()->info('Starting API cache warmup...');
    $this->cacheService->warmupCache($endpoints);
    
    if (empty($endpoints)) {
      $this->logger()->success('BaaS API cache warmup completed for all configured endpoints.');
    } else {
      $this->logger()->success(sprintf(
        'BaaS API cache warmup completed for %d endpoints: %s',
        count($endpoints),
        implode(', ', $endpoints)
      ));
    }
  }

  /**
   * 显示缓存统计信息。
   */
  #[CLI\Command(name: 'baas:cache:stats')]
  #[CLI\Help(description: 'Show BaaS API cache statistics')]
  public function showCacheStats(): void
  {
    // 这里可以实现缓存统计功能
    // 目前显示基本信息
    $this->output()->writeln('<info>BaaS API Cache Statistics:</info>');
    $this->output()->writeln('Cache backend: Database');
    $this->output()->writeln('Cache bins: baas_api');
    $this->output()->writeln('');
    $this->output()->writeln('For detailed statistics, check the cache tables in your database.');
    $this->output()->writeln('Cache table: cache_baas_api');
  }

}