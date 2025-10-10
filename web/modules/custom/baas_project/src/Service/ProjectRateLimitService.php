<?php

declare(strict_types=1);

namespace Drupal\baas_project\Service;

use Drupal\baas_api\Service\RateLimitService;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_project\Service\ResourceLimitManager;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * 项目级API限流服务.
 *
 * 在全局限流基础上提供项目级别的API限流控制，
 * 支持项目配置继承和限制不超过上级配置的机制。
 */
class ProjectRateLimitService
{

  /**
   * 日志器.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_api\Service\RateLimitService $baseLimitService
   *   基础限流服务.
   * @param \Drupal\baas_project\ProjectManagerInterface $projectManager
   *   项目管理器.
   * @param \Drupal\baas_project\Service\ResourceLimitManager $resourceManager
   *   资源限制管理器.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   日志工厂.
   */
  public function __construct(
    protected readonly RateLimitService $baseLimitService,
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly ResourceLimitManager $resourceManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('baas_project_rate_limit');
  }

  /**
   * 获取项目有效限流配置.
   *
   * @param string $project_id
   *   项目ID.
   *
   * @return array
   *   有效的限流配置.
   */
  public function getProjectRateLimits(string $project_id): array
  {
    try {
      $effective_limits = $this->resourceManager->getEffectiveLimits($project_id);
      
      // 从项目配置中提取限流设置
      $project_rate_limits = $effective_limits['rate_limits'] ?? [];
      
      // 确保不超过全局限制
      return $this->enforceGlobalLimits($project_rate_limits);
    } catch (\Exception $e) {
      $this->logger->error('Failed to get project rate limits: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
      ]);
      return [];
    }
  }

  /**
   * 检查项目级限流.
   *
   * @param string $project_id
   *   项目ID.
   * @param string $identifier_type
   *   标识符类型 (user/ip).
   * @param string $identifier
   *   标识符值.
   * @param string|null $endpoint
   *   API端点.
   *
   * @return array
   *   包含allowed, remaining, reset_time等信息的数组.
   */
  public function checkProjectRateLimit(
    string $project_id, 
    string $identifier_type,
    string $identifier,
    string $endpoint = null
  ): array {
    try {
      $project_limits = $this->getProjectRateLimits($project_id);
      
      $this->logger->debug('Project rate limits retrieved', [
        'project_id' => $project_id,
        'limits' => $project_limits,
        'enabled' => $project_limits['enable_project_rate_limiting'] ?? false,
      ]);
      
      if (!($project_limits['enable_project_rate_limiting'] ?? false)) {
        $this->logger->debug('Project rate limiting not enabled', ['project_id' => $project_id]);
        return ['allowed' => true]; // 项目级限流未启用
      }

      // 获取适用的限制配置
      $limits = $this->getApplicableLimits($project_limits, $identifier_type, $endpoint);
      
      $this->logger->debug('Project applicable limits check', [
        'project_id' => $project_id,
        'identifier_type' => $identifier_type,
        'endpoint' => $endpoint,
        'project_limits' => $project_limits,
        'applicable_limits' => $limits,
      ]);
      
      if (!$limits) {
        $this->logger->debug('No applicable limits found for project rate limiting', [
          'project_id' => $project_id,
          'identifier_type' => $identifier_type,
        ]);
        return ['allowed' => true]; // 无适用限制
      }

      // 构造项目级标识符，项目级限流不区分端点（统一限流）
      $project_identifier = "project:{$project_id}:{$identifier_type}:{$identifier}";
      
      $result = $this->baseLimitService->checkRateLimit(
        $project_identifier,
        'project_limit', // 使用固定的键名
        $limits['requests'],
        $limits['window']
      );

      // 如果项目限流通过，确保项目限流优先于全局限流
      if ($result['allowed']) {
        // 检查项目限制是否比全局限制更严格
        $global_config = \Drupal::config('baas_api.settings');
        $global_limits = $global_config->get('rate_limits.user.requests') ?? 120;
        
        if ($limits['requests'] < $global_limits) {
          // 项目限制更严格，标记为已处理，跳过全局限流
          $result['skip_global'] = true;
        }
      }

      // 记录项目级限流检查
      if (!$result['allowed']) {
        $this->logger->warning('Project rate limit exceeded', [
          'project_id' => $project_id,
          'identifier_type' => $identifier_type,
          'identifier' => $identifier,
          'endpoint' => $endpoint,
          'limit' => $limits['requests'],
          'window' => $limits['window'],
        ]);
      }

      return $result;
    } catch (\Exception $e) {
      $this->logger->error('Project rate limit check failed: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
        'identifier_type' => $identifier_type,
        'identifier' => $identifier,
      ]);
      // 错误时默认允许，避免阻塞正常请求
      return ['allowed' => true];
    }
  }

  /**
   * 获取适用的限制配置.
   *
   * @param array $project_limits
   *   项目限流配置.
   * @param string $identifier_type
   *   标识符类型.
   * @param string|null $endpoint
   *   API端点.
   *
   * @return array|null
   *   适用的限制配置.
   */
  protected function getApplicableLimits(array $project_limits, string $identifier_type, ?string $endpoint = null): ?array
  {
    // 检查端点特殊限制
    if ($endpoint && isset($project_limits['endpoints'])) {
      foreach ($project_limits['endpoints'] as $endpoint_pattern => $endpoint_limits) {
        if (str_starts_with($endpoint, $endpoint_pattern)) {
          return $endpoint_limits;
        }
      }
    }

    // 检查类型限制
    if (isset($project_limits[$identifier_type])) {
      return $project_limits[$identifier_type];
    }

    return null;
  }

  /**
   * 确保项目限制不超过全局限制.
   *
   * @param array $project_limits
   *   项目限流配置.
   *
   * @return array
   *   强制后的限流配置.
   */
  protected function enforceGlobalLimits(array $project_limits): array
  {
    try {
      $global_config = \Drupal::config('baas_api.settings');
      $global_limits = $global_config->get('rate_limits') ?? [];
      
      foreach (['user', 'ip'] as $type) {
        if (isset($project_limits[$type]) && isset($global_limits[$type])) {
          // 项目限制不能超过全局限制
          if (isset($project_limits[$type]['requests']) && isset($global_limits[$type]['requests'])) {
            $project_limits[$type]['requests'] = min(
              $project_limits[$type]['requests'],
              $global_limits[$type]['requests']
            );
          }
          
          // 确保burst不超过requests
          if (isset($project_limits[$type]['requests'])) {
            $project_limits[$type]['burst'] = min(
              $project_limits[$type]['burst'] ?? $project_limits[$type]['requests'],
              $project_limits[$type]['requests']
            );
          }
        }
      }
      
      return $project_limits;
    } catch (\Exception $e) {
      $this->logger->error('Failed to enforce global limits: @error', ['@error' => $e->getMessage()]);
      return $project_limits;
    }
  }

  /**
   * 获取项目限流统计信息.
   *
   * @param string $project_id
   *   项目ID.
   * @param string $identifier_type
   *   标识符类型.
   * @param string $identifier
   *   标识符值.
   *
   * @return array
   *   限流统计信息.
   */
  public function getProjectRateLimitStats(string $project_id, string $identifier_type, string $identifier): array
  {
    try {
      $project_limits = $this->getProjectRateLimits($project_id);
      
      if (!($project_limits['enable_project_rate_limiting'] ?? false)) {
        return [
          'enabled' => false,
          'message' => 'Project rate limiting not enabled',
        ];
      }

      $limits = $this->getApplicableLimits($project_limits, $identifier_type);
      
      if (!$limits) {
        return [
          'enabled' => true,
          'applicable' => false,
          'message' => 'No applicable limits found',
        ];
      }

      $project_identifier = "project:{$project_id}:{$identifier_type}:{$identifier}";
      $remaining_info = $this->baseLimitService->getRemainingLimits($project_identifier);

      return [
        'enabled' => true,
        'applicable' => true,
        'limit' => $limits['requests'],
        'window' => $limits['window'],
        'remaining' => $remaining_info['remaining'],
        'reset_time' => $remaining_info['reset_time'],
      ];
    } catch (\Exception $e) {
      $this->logger->error('Failed to get project rate limit stats: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
      ]);
      return [
        'enabled' => false,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * 规范化端点路径，移除查询参数.
   *
   * @param string $endpoint
   *   原始端点路径.
   *
   * @return string
   *   规范化后的端点路径.
   */
  protected function normalizeEndpoint(string $endpoint): string
  {
    // 移除查询参数
    $path = parse_url($endpoint, PHP_URL_PATH) ?: $endpoint;
    
    // 移除开头的斜杠并转换为小写
    $path = ltrim($path, '/');
    $path = strtolower($path);
    
    // 用下划线替换特殊字符，限制长度
    $path = preg_replace('/[^a-z0-9\/]/', '_', $path);
    $path = str_replace('/', '_', $path);
    
    // 限制长度防止键名过长
    if (strlen($path) > 50) {
      $path = substr($path, 0, 50) . '_' . md5($endpoint);
    }
    
    return $path ?: 'default';
  }

  /**
   * 重置项目级限流.
   *
   * @param string $project_id
   *   项目ID.
   * @param string|null $identifier_type
   *   标识符类型（可选，不提供则重置所有类型）.
   * @param string|null $identifier
   *   标识符值（可选）.
   */
  public function resetProjectRateLimits(string $project_id, ?string $identifier_type = null, ?string $identifier = null): void
  {
    try {
      if ($identifier_type && $identifier) {
        // 重置特定标识符的限制
        $project_identifier = "project:{$project_id}:{$identifier_type}:{$identifier}";
        $this->baseLimitService->resetLimits($project_identifier);
        
        $this->logger->info('Reset project rate limits for specific identifier', [
          'project_id' => $project_id,
          'identifier_type' => $identifier_type,
          'identifier' => $identifier,
        ]);
      } else {
        // 这里可以实现重置项目所有限流的逻辑
        // 由于baseLimitService没有提供批量重置功能，暂时记录日志
        $this->logger->info('Project rate limits reset requested', [
          'project_id' => $project_id,
          'scope' => 'all',
        ]);
      }
    } catch (\Exception $e) {
      $this->logger->error('Failed to reset project rate limits: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
      ]);
    }
  }

}