<?php

declare(strict_types=1);

namespace Drupal\baas_project\EventSubscriber;

use Drupal\baas_project\Service\ProjectRateLimitService;
use Drupal\baas_api\Service\ApiResponseService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * 项目级API速率限制事件订阅器.
 *
 * 在全局限流之后进行项目级限流检查。
 */
class ProjectRateLimitSubscriber implements EventSubscriberInterface
{

  /**
   * 项目限流服务.
   *
   * @var \Drupal\baas_project\Service\ProjectRateLimitService
   */
  protected ProjectRateLimitService $projectRateLimitService;

  /**
   * API响应服务.
   *
   * @var \Drupal\baas_api\Service\ApiResponseService
   */
  protected ApiResponseService $responseService;

  /**
   * 日志器.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_project\Service\ProjectRateLimitService $project_rate_limit_service
   *   项目限流服务.
   * @param \Drupal\baas_api\Service\ApiResponseService $response_service
   *   API响应服务.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂.
   */
  public function __construct(
    ProjectRateLimitService $project_rate_limit_service,
    ApiResponseService $response_service,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->projectRateLimitService = $project_rate_limit_service;
    $this->responseService = $response_service;
    $this->logger = $logger_factory->get('baas_project');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array
  {
    return [
      // 优先级280，在全局限流(250)之前执行，因为项目级限流通常更严格
      KernelEvents::REQUEST => ['onKernelRequest', 280],
    ];
  }

  /**
   * 处理内核请求事件.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   请求事件.
   */
  public function onKernelRequest(RequestEvent $event): void
  {
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    
    // 检查是否为API请求 - 如果不是，直接返回，不记录日志
    if (!$this->isApiRequest($request)) {
      return;
    }

    // 检查是否为免限流端点
    if ($this->isRateLimitExempt($request)) {
      return;
    }

    // 获取项目ID
    $project_id = $this->extractProjectId($request);
    if (!$project_id) {
      return; // 非项目相关请求，不记录日志
    }

    // 获取客户端标识信息
    $client_info = $this->getClientInfo($request);
    
    // 检查项目级限流
    $result = $this->projectRateLimitService->checkProjectRateLimit(
      $project_id,
      $client_info['type'], 
      $client_info['identifier'],
      $request->getPathInfo()
    );

    if (!$result['allowed']) {
      // 标记项目级限流已拒绝请求，防止全局限流重复检查
      $request->attributes->set('project_rate_limit_rejected', true);
      
      $response = $this->createProjectRateLimitError($result, $project_id);
      $event->setResponse($response);
      return;
    }

    // 添加项目限流信息到请求属性
    $request->attributes->set('project_rate_limit_info', $result);
    
    // 如果项目限流决定跳过全局限流，设置标记
    if (!empty($result['skip_global'])) {
      $request->attributes->set('skip_global_rate_limit', true);
    }
    
    // 只在调试模式下记录成功通过的日志
    if (\Drupal::config('baas_api.settings')->get('debug_mode')) {
      $this->logger->debug('Project rate limit check passed', [
        'project_id' => $project_id,
        'client_type' => $client_info['type'],
        'client_id' => $client_info['identifier'],
        'remaining' => $result['remaining'] ?? 'unknown',
        'limit' => $result['limit'] ?? 'unknown',
      ]);
    }
  }

  /**
   * 检查是否为API请求.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求.
   *
   * @return bool
   *   是否为API请求.
   */
  protected function isApiRequest(Request $request): bool
  {
    $path = $request->getPathInfo();
    return str_starts_with($path, '/api/');
  }

  /**
   * 检查是否为免速率限制的端点.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求.
   *
   * @return bool
   *   是否免速率限制.
   */
  protected function isRateLimitExempt(Request $request): bool
  {
    $path = $request->getPathInfo();
    
    // 免速率限制的端点列表
    $exempt_endpoints = [
      '/api/health',
      '/api/docs',
    ];

    foreach ($exempt_endpoints as $endpoint) {
      if (str_starts_with($path, $endpoint)) {
        return true;
      }
    }

    return false;
  }

  /**
   * 从请求中提取项目ID.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求.
   *
   * @return string|null
   *   项目ID，如果无法提取则返回null.
   */
  protected function extractProjectId(Request $request): ?string
  {
    // 方法1: 从路由参数中获取
    $route_project_id = $request->attributes->get('project_id');
    if ($route_project_id) {
      return $route_project_id;
    }

    // 方法2: 从认证数据中获取
    $auth_data = $request->attributes->get('auth_data');
    if ($auth_data && isset($auth_data['project_id'])) {
      return $auth_data['project_id'];
    }

    // 方法3: 从URL路径中解析
    $path = $request->getPathInfo();
    
    // 匹配形如 /api/v1/{tenant_id}/projects/{project_id}/ 的路径
    if (preg_match('#/api/v\d+/[^/]+/projects/([a-zA-Z0-9_]+)(?:/|$)#', $path, $matches)) {
      return $matches[1];
    }

    // 匹配形如 /api/{tenant_id}/projects/{project_id}/ 的路径  
    if (preg_match('#/api/[^/]+/projects/([a-zA-Z0-9_]+)(?:/|$)#', $path, $matches)) {
      return $matches[1];
    }

    // 匹配形如 /api/tenant/{tenant_id}/project/{project_id}/ 的路径
    if (preg_match('#/api/tenant/[^/]+/project/([a-zA-Z0-9_]+)(?:/|$)#', $path, $matches)) {
      return $matches[1];
    }

    return null;
  }

  /**
   * 获取客户端信息.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求.
   *
   * @return array
   *   包含type和identifier的客户端信息.
   */
  protected function getClientInfo(Request $request): array
  {
    $auth_data = $request->attributes->get('auth_data');
    
    if ($auth_data && isset($auth_data['user_id'])) {
      // 已认证用户
      return [
        'type' => 'user',
        'identifier' => (string)$auth_data['user_id'],
      ];
    }

    // 未认证用户，使用IP地址
    return [
      'type' => 'ip',
      'identifier' => $request->getClientIp() ?: 'unknown',
    ];
  }

  /**
   * 创建项目级速率限制错误响应.
   *
   * @param array $rate_limit_result
   *   速率限制结果.
   * @param string $project_id
   *   项目ID.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   错误响应.
   */
  protected function createProjectRateLimitError(array $rate_limit_result, string $project_id): \Symfony\Component\HttpFoundation\Response
  {
    $this->logger->warning('Project rate limit exceeded', [
      'project_id' => $project_id,
      'limit' => $rate_limit_result['limit'] ?? 'unknown',
      'remaining' => $rate_limit_result['remaining'] ?? 0,
      'reset_time' => $rate_limit_result['reset_time'] ?? 0,
    ]);

    $context = [
      'project_id' => $project_id,
      'limit' => $rate_limit_result['limit'] ?? 0,
      'remaining' => $rate_limit_result['remaining'] ?? 0,
      'reset_time' => $rate_limit_result['reset_time'] ?? 0,
      'retry_after' => $rate_limit_result['retry_after'] ?? 60,
    ];

    $response = $this->responseService->createErrorResponse(
      'Project rate limit exceeded',
      'PROJECT_RATE_LIMIT_EXCEEDED',
      429,
      $context
    );

    // 添加项目级速率限制头部
    $response->headers->set('X-Project-RateLimit-Limit', (string) ($rate_limit_result['limit'] ?? 0));
    $response->headers->set('X-Project-RateLimit-Remaining', (string) ($rate_limit_result['remaining'] ?? 0));
    $response->headers->set('X-Project-RateLimit-Reset', (string) ($rate_limit_result['reset_time'] ?? 0));
    $response->headers->set('X-Project-ID', $project_id);
    $response->headers->set('Retry-After', (string) ($rate_limit_result['retry_after'] ?? 60));

    return $response;
  }

}