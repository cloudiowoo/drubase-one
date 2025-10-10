<?php

declare(strict_types=1);

namespace Drupal\baas_api\Controller;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\baas_tenant\TenantManagerInterface;
use Drupal\baas_tenant\TenantResolverInterface;
use Drupal\baas_api\Service\ApiResponseService;
use Drupal\baas_api\Service\ApiValidationService;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * API控制器.
 *
 * 处理租户域名访问API的请求
 */
class ApiController extends BaseApiController {

  /**
   * 当前请求.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected Request $request;

  /**
   * HTTP内核.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected HttpKernelInterface $httpKernel;

  /**
   * 日志通道.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_api\Service\ApiResponseService $response_service
   *   API响应服务.
   * @param \Drupal\baas_api\Service\ApiValidationService $validation_service
   *   API验证服务.
   * @param \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface $permission_checker
   *   统一权限检查器.
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenantManager
   *   租户管理器.
   * @param \Drupal\baas_tenant\TenantResolverInterface $tenantResolver
   *   租户解析器.
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $httpKernel
   *   HTTP内核.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   日志工厂.
   */
  public function __construct(
    ApiResponseService $response_service,
    ApiValidationService $validation_service,
    UnifiedPermissionCheckerInterface $permission_checker,
    protected TenantManagerInterface $tenantManager,
    protected TenantResolverInterface $tenantResolver,
    HttpKernelInterface $httpKernel,
    LoggerChannelFactoryInterface $loggerFactory
  ) {
    parent::__construct($response_service, $validation_service, $permission_checker);
    $this->httpKernel = $httpKernel;
    $this->logger = $loggerFactory->get('baas_api');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_api.response'),
      $container->get('baas_api.validation'),
      $container->get('baas_auth.unified_permission_checker'),
      $container->get('baas_tenant.manager'),
      $container->get('baas_tenant.resolver'),
      $container->get('http_kernel'),
      $container->get('logger.factory')
    );
  }

  /**
   * 处理通过租户ID的API请求.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   当前请求.
   * @param string $tenant_id
   *   租户ID.
   * @param string $path
   *   API路径.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   响应对象.
   */
  public function handleRequest(Request $request, string $tenant_id, string $path = ''): Response {
    // 添加调试日志
    $this->logger->notice('ApiController::handleRequest 被调用: @method @path', [
      '@method' => $request->getMethod(),
      '@path' => $path,
    ]);

    // 保存当前请求
    $this->request = $request;

    // 验证租户是否存在
    $tenant = $this->tenantManager->getTenant($tenant_id);
    if (!$tenant) {
      return ApiResponse::error('租户不存在', 404, ['detail' => '指定的租户ID不存在或不可用']);
    }

    // 检查租户状态
    if (!isset($tenant['status']) || !$tenant['status']) {
      return ApiResponse::error('租户已禁用', 403, ['detail' => '此租户账户已被禁用']);
    }

    // 健康检查端点直接处理
    if ($path === 'health') {
      return $this->handleHealthRequest($tenant);
    }

    // 文档请求重定向
    if ($path === 'docs' || strpos($path, 'docs/') === 0) {
      return $this->redirectToTenantDocs($tenant, $path);
    }

    // 处理实体模板API请求
    if ($path === 'templates') {
      // 转发到EntityApiController::getTemplates
      $subRequest = $this->createSubRequest($request, "/api/v1/{$tenant_id}/templates");
      return $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
    }

    // 处理单个模板详情API请求
    if (preg_match('#^template/([^/]+)$#', $path, $matches)) {
      $templateName = $matches[1];
      // 转发到EntityApiController::getTemplate
      $subRequest = $this->createSubRequest($request, "/api/v1/{$tenant_id}/template/{$templateName}");
      return $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
    }

    // 处理实体列表API请求
    if (preg_match('#^entity/([^/]+)$#', $path, $matches)) {
      $entityType = $matches[1];
      $this->logger->notice('匹配到实体列表请求: @entity_type', [
        '@entity_type' => $entityType,
      ]);

      // 直接调用EntityApiController的方法
      $entityApiController = \Drupal::service('class_resolver')->getInstanceFromDefinition('\Drupal\baas_api\Controller\EntityApiController');
      return $entityApiController->getEntities($request, $tenant_id, $entityType);
    }

    // 处理单个实体API请求
    if (preg_match('#^entity/([^/]+)/([^/]+)$#', $path, $matches)) {
      $entityType = $matches[1];
      $entityId = $matches[2];
      // 转发到EntityApiController::getEntity
      $subRequest = $this->createSubRequest($request, "/api/v1/{$tenant_id}/entity/{$entityType}/{$entityId}");
      return $this->httpKernel->handle($subRequest, HttpKernelInterface::SUB_REQUEST);
    }

    // 对于其他请求，记录并转发
    $this->logger->info('处理租户API请求: @tenant_id, @path', [
      '@tenant_id' => $tenant_id,
      '@path' => $path,
    ]);

    // 在这里应该实现API请求的实际处理逻辑
    // 当前仅返回示例响应
    $data = [
      'api_version' => 'v1',
      'tenant_id' => $tenant_id,
      'path' => $path,
      'timestamp' => date('c'),
    ];

    $response = new JsonResponse($data);
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-Key, X-Requested-With, X-BaaS-Project-ID, X-BaaS-Tenant-ID, x-baas-project-id, x-baas-tenant-id');
    $response->headers->set('X-Tenant-ID', $tenant_id);

    return $response;
  }

  /**
   * 处理通过域名的API请求.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   当前请求.
   * @param string $path
   *   API路径.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   响应对象.
   */
  public function handleDomainRequest(Request $request, string $path = ''): Response {
    // 保存当前请求
    $this->request = $request;

    // 从请求域名解析租户
    $host = $request->getHost();
    $tenant = $this->tenantResolver->resolveTenantFromDomain($host);

    // 如果无法通过域名解析租户，返回错误
    if (!$tenant) {
      $this->logger->warning('未识别的域名: @host', ['@host' => $host]);
      return ApiResponse::error('未识别的域名', 404, ['detail' => '无法识别与此域名关联的租户']);
    }

    // 检查租户状态
    if (!isset($tenant['status']) || !$tenant['status']) {
      return ApiResponse::error('租户已禁用', 403, ['detail' => '此租户账户已被禁用']);
    }

    // 检查认证
    $apiKey = $request->headers->get('X-API-Key');
    $authHeader = $request->headers->get('Authorization');

    if (empty($apiKey) && empty($authHeader)) {
      // 如果没有认证头，并且不是公开API，则返回错误
      if (!$this->isPublicApi($path)) {
        return ApiResponse::error('未认证', 401, ['detail' => '缺少认证信息，请提供有效的令牌或API密钥']);
      }
    }

    // 如果有API密钥，验证它是否正确
    if (!empty($apiKey)) {
      $tenantSettings = $tenant['settings'] ?? [];
      if (!isset($tenantSettings['api_key']) || $tenantSettings['api_key'] !== $apiKey) {
        return ApiResponse::error('无效的API密钥', 401, ['detail' => '提供的API密钥无效']);
      }
    }

    // 处理健康检查请求
    if ($path === 'health') {
      return $this->handleHealthRequest($tenant);
    }

    // 处理文档请求
    if ($path === 'docs' || strpos($path, 'docs/') === 0) {
      return $this->redirectToTenantDocs($tenant, $path);
    }

    // 扩展点：直接处理子域名API请求
    // TODO: 未来实现直接处理子域名API请求，而不是重定向
    // 这将允许API URL格式为：tenant.domain.com/api/v1/resource
    // 而不需要在URL中包含租户ID
    // 实现步骤：
    // 1. 检查请求是否为API请求（路径以/api/开头）
    // 2. 解析API版本和资源路径
    // 3. 直接处理请求，而不是重定向
    // 4. 确保权限检查和租户隔离

    // 当前实现：重定向到标准API路径
    $redirectUrl = '/api/v1/' . $tenant['tenant_id'] . '/' . trim($path, '/');
    $this->logger->info('重定向到租户API: @tenant_id, @path', [
      '@tenant_id' => $tenant['tenant_id'],
      '@path' => $redirectUrl,
    ]);

    return new Response('', 307, ['Location' => $redirectUrl]);
  }

  /**
   * 处理健康检查请求.
   *
   * @param array $tenant
   *   租户信息.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   响应对象.
   */
  protected function handleHealthRequest(array $tenant): Response {
    $data = [
      'status' => 'ok',
      'version' => '1.0.0',
      'timestamp' => date('c'),
      'tenant' => [
        'id' => $tenant['tenant_id'],
        'name' => $tenant['name'],
      ],
      'services' => [
        'database' => true,
        'cache' => true,
      ],
    ];

    $response = new JsonResponse($data);
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-Key, X-Requested-With, X-BaaS-Project-ID, X-BaaS-Tenant-ID, x-baas-project-id, x-baas-tenant-id');

    return $response;
  }

  /**
   * 重定向到租户文档.
   *
   * @param array $tenant
   *   租户信息.
   * @param string $path
   *   API路径.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   响应对象.
   */
  protected function redirectToTenantDocs(array $tenant, string $path): Response {
    // 从路径中移除docs前缀
    $docsPath = str_replace('docs/', '', $path);
    $docsPath = $docsPath === 'docs' ? '' : $docsPath;

    // 构建文档URL
    $docsUrl = '/api/tenant/' . $tenant['tenant_id'] . '/docs';
    if (!empty($docsPath)) {
      $docsUrl .= '/' . $docsPath;
    }

    $this->logger->info('重定向到租户文档: @tenant_id, @path', [
      '@tenant_id' => $tenant['tenant_id'],
      '@path' => $docsUrl,
    ]);

    return new Response('', 307, ['Location' => $docsUrl]);
  }

  /**
   * 创建子请求用于内部路由处理.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   原始请求.
   * @param string $path
   *   子请求路径.
   *
   * @return \Symfony\Component\HttpFoundation\Request
   *   子请求对象.
   */
  protected function createSubRequest(Request $request, string $path): Request {
    $subRequest = Request::create(
      $path,
      $request->getMethod(),
      $request->query->all(),
      $request->cookies->all(),
      $request->files->all(),
      $request->server->all(),
      $request->getContent()
    );

    // 复制请求头
    foreach ($request->headers->all() as $key => $value) {
      $subRequest->headers->set($key, $value);
    }

    // 保持会话一致性
    if ($request->hasSession()) {
      $subRequest->setSession($request->getSession());
    }

    return $subRequest;
  }

  /**
   * 检查是否为公开API.
   *
   * @param string $path
   *   API路径.
   *
   * @return bool
   *   是否为公开API.
   */
  protected function isPublicApi(string $path): bool {
    // 定义公开API列表
    $publicPaths = [
      'health',
      'docs',
      'public',
    ];

    // 检查路径是否匹配公开API
    foreach ($publicPaths as $publicPath) {
      if (strpos($path, $publicPath) === 0 || $path === $publicPath) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
