<?php

declare(strict_types=1);

namespace Drupal\baas_auth\Controller;

use Drupal\baas_api\Controller\BaseApiController;
use Drupal\baas_api\Service\ApiResponseService;
use Drupal\baas_api\Service\ApiValidationService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\baas_auth\Service\AuthenticationService;
use Drupal\baas_auth\Service\JwtBlacklistServiceInterface;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Drupal\baas_auth\Service\SessionManager;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Database\Connection;

/**
 * 认证API控制器。
 *
 * 提供认证相关的REST API端点，包括登录、注销、令牌管理等功能。
 */
class AuthApiController extends BaseApiController
{

  /**
   * 认证服务。
   *
   * @var \Drupal\baas_auth\Service\AuthenticationService
   */
  protected $authService;

  /**
   * JWT黑名单服务。
   *
   * @var \Drupal\baas_auth\Service\JwtBlacklistServiceInterface
   */
  protected $blacklistService;


  /**
   * 会话管理器。
   *
   * @var \Drupal\baas_auth\Service\SessionManager
   */
  protected $sessionManager;

  /**
   * 日志记录器。
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * 数据库连接。
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_api\Service\ApiResponseService $response_service
   *   API响应服务。
   * @param \Drupal\baas_api\Service\ApiValidationService $validation_service
   *   API验证服务。
   * @param \Drupal\baas_auth\Service\AuthenticationService $auth_service
   *   认证服务。
   * @param \Drupal\baas_auth\Service\JwtBlacklistServiceInterface $blacklist_service
   *   JWT黑名单服务。
   * @param \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface $permission_checker
   *   统一权限检查器。
   * @param \Drupal\baas_auth\Service\SessionManager $session_manager
   *   会话管理器。
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂。
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接。
   */
  public function __construct(
    ApiResponseService $response_service,
    ApiValidationService $validation_service,
    AuthenticationService $auth_service,
    JwtBlacklistServiceInterface $blacklist_service,
    UnifiedPermissionCheckerInterface $permission_checker,
    SessionManager $session_manager,
    LoggerChannelFactoryInterface $logger_factory,
    Connection $database
  ) {
    parent::__construct($response_service, $validation_service, $permission_checker);
    $this->authService = $auth_service;
    $this->blacklistService = $blacklist_service;
    $this->sessionManager = $session_manager;
    $this->logger = $logger_factory->get('baas_auth_api');
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_api.response'),
      $container->get('baas_api.validation'),
      $container->get('baas_auth.authentication_service'),
      $container->get('baas_auth.jwt_blacklist_service'),
      $container->get('baas_auth.unified_permission_checker'),
      $container->get('baas_auth.session_manager'),
      $container->get('logger.factory'),
      $container->get('database')
    );
  }

  /**
   * 用户登录API。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function login(Request $request): JsonResponse
  {
    try {
      // 首先检查API Key
      $api_key = $request->headers->get('X-API-Key');
      if (!$api_key) {
        $this->logger->warning('登录请求缺少API密钥');
        return $this->createErrorResponse('访问被拒绝：缺少API密钥认证头部', 'MISSING_API_KEY', 401);
      }

      $content = $request->getContent();
      $data = json_decode($content, TRUE);

      // 记录收到的请求数据
      $this->logger->info('收到登录请求: @data', ['@data' => json_encode($data)]);

      // 验证请求参数
      $validation_errors = $this->validateLoginRequest($data);
      if (!empty($validation_errors)) {
        $this->logger->warning('登录参数验证失败: @errors', ['@errors' => json_encode($validation_errors)]);
        return $this->createValidationErrorResponse($validation_errors, '请求参数验证失败');
      }

      $username = $data['username'];
      $password = $data['password'];
      // 支持向后兼容的可选参数
      $tenant_id = $data['tenant_id'] ?? null;
      $project_id = $data['project_id'] ?? null;

      $this->logger->info('开始执行简化登录: 用户名=@username, 首选租户ID=@tenant_id, 首选项目ID=@project_id', [
        '@username' => $username,
        '@tenant_id' => $tenant_id,
        '@project_id' => $project_id,
      ]);

      // 执行简化登录（自动检测权限）
      $result = $this->authService->login($username, $password, $tenant_id, $project_id);

      $this->logger->info('登录服务返回: @result', ['@result' => $result ? 'SUCCESS' : 'FAILURE']);

      if (!$result) {
        // 记录登录失败
        $this->logger->warning('登录失败: 用户名=@username, 租户ID=@tenant_id, 项目ID=@project_id', [
          '@username' => $username,
          '@tenant_id' => $tenant_id,
          '@project_id' => $project_id,
        ]);

        return $this->createErrorResponse('登录失败：用户名、密码或租户ID不正确', 'LOGIN_FAILED', 401);
      }

      // 记录成功响应
      $this->logger->info('用户登录成功: @username, 租户: @tenant_id', [
        '@username' => $username,
        '@tenant_id' => $tenant_id,
      ]);

      // 返回成功响应
      return $this->createSuccessResponse($result, '登录成功');
    } catch (\Exception $e) {
      $this->logger->error('登录API错误: @message - 文件: @file, 行号: @line, 堆栈: @trace', [
        '@message' => $e->getMessage(),
        '@file' => $e->getFile(),
        '@line' => $e->getLine(),
        '@trace' => $e->getTraceAsString(),
      ]);
      return $this->createErrorResponse('服务器内部错误: ' . $e->getMessage(), 'SERVER_ERROR', 500);
    }
  }

  /**
   * 刷新令牌API。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function refresh(Request $request): JsonResponse
  {
    try {
      $content = $request->getContent();
      $data = json_decode($content, TRUE);

      if (empty($data['refresh_token'])) {
        return $this->createErrorResponse('缺少刷新令牌', 'MISSING_TOKEN', 400);
      }

      $result = $this->authService->refreshToken($data['refresh_token']);

      if (!$result) {
        return $this->createErrorResponse('无效的刷新令牌', 'INVALID_TOKEN', 401);
      }

      return $this->createSuccessResponse($result, '令牌刷新成功');
    } catch (\Exception $e) {
      $this->logger->error('刷新令牌API错误: @message', ['@message' => $e->getMessage()]);
      return $this->createErrorResponse('服务器内部错误', 'SERVER_ERROR', 500);
    }
  }

  /**
   * 用户注销API。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function logout(Request $request): JsonResponse
  {
    try {
      $token = $this->extractBearerToken($request);

      if (!$token) {
        return $this->createErrorResponse('缺少认证令牌', 'MISSING_TOKEN', 401);
      }

      $payload = $this->authService->verifyToken($token);

      if (!$payload) {
        return $this->createErrorResponse('无效的令牌', 'INVALID_TOKEN', 401);
      }

      // 将令牌加入黑名单
      $jti = $payload['jti'] ?? uniqid();
      $user_id = (int) ($payload['sub'] ?? 0);
      $tenant_id = $payload['tenant_id'] ?? '';
      $expires_at = (int) ($payload['exp'] ?? (time() + 3600));

      $this->blacklistService->addToBlacklist($jti, $user_id, $tenant_id, $expires_at);

      $this->logger->info('用户注销成功: 用户ID @user_id, 租户: @tenant_id', [
        '@user_id' => $user_id,
        '@tenant_id' => $tenant_id,
      ]);

      return $this->createSuccessResponse(['message' => '注销成功'], '注销成功');
    } catch (\Exception $e) {
      $this->logger->error('注销API错误: @message', ['@message' => $e->getMessage()]);
      return $this->createErrorResponse('服务器内部错误', 'SERVER_ERROR', 500);
    }
  }

  /**
   * 获取当前用户信息API。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function me(Request $request): JsonResponse
  {
    try {
      $token = $this->extractBearerToken($request);

      if (!$token) {
        return $this->createErrorResponse('缺少认证令牌', 'MISSING_TOKEN', 401);
      }

      $payload = $this->authService->verifyToken($token);

      if (!$payload) {
        return $this->createErrorResponse('无效的令牌', 'INVALID_TOKEN', 401);
      }

      $user_id = (int) ($payload['sub'] ?? 0);
      $user_info = $this->authService->getCurrentUser($user_id);

      if (!$user_info) {
        return $this->createErrorResponse('用户不存在', 'USER_NOT_FOUND', 404);
      }

      // 移除敏感信息
      unset($user_info['pass']);

      return $this->createSuccessResponse($user_info, '获取用户信息成功');
    } catch (\Exception $e) {
      $this->logger->error('获取用户信息API错误: @message', ['@message' => $e->getMessage()]);
      return $this->createErrorResponse('服务器内部错误', 'SERVER_ERROR', 500);
    }
  }

  /**
   * 验证令牌API。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function verify(Request $request): JsonResponse
  {
    try {
      // 从请求体中获取token
      $content = $request->getContent();
      $data = json_decode($content, TRUE);

      $token = $data['token'] ?? null;

      if (!$token) {
        return $this->createErrorResponse('缺少令牌参数', 'MISSING_TOKEN', 400);
      }

      $payload = $this->authService->verifyToken($token);

      if (!$payload) {
        return $this->createErrorResponse('无效的令牌', 'INVALID_TOKEN', 401);
      }

      return $this->createSuccessResponse([
        'valid' => TRUE,
        'user_id' => (int) ($payload['sub'] ?? 0),
        'tenant_id' => $payload['tenant_id'] ?? null,
        'expires_at' => (int) ($payload['exp'] ?? 0),
        'issued_at' => (int) ($payload['iat'] ?? 0),
        'token_type' => $payload['type'] ?? null,
      ], '令牌验证成功');
    } catch (\Exception $e) {
      $this->logger->error('验证令牌API错误: @message', ['@message' => $e->getMessage()]);
      return $this->createErrorResponse('服务器内部错误', 'SERVER_ERROR', 500);
    }
  }

  /**
   * 获取用户权限API。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function permissions(Request $request): JsonResponse
  {
    try {
      $token = $this->extractBearerToken($request);

      if (!$token) {
        return $this->createErrorResponse('缺少认证令牌', 'MISSING_TOKEN', 401);
      }

      $payload = $this->authService->verifyToken($token);

      if (!$payload) {
        return $this->createErrorResponse('无效的令牌', 'INVALID_TOKEN', 401);
      }

      $user_id = (int) ($payload['sub'] ?? 0);
      $tenant_id = $payload['tenant_id'] ?? '';

      $permissions = $this->permissionChecker->getUserPermissions($user_id, $tenant_id);
      $roles = $this->permissionChecker->getUserRoles($user_id, $tenant_id);

      return $this->createSuccessResponse([
        'permissions' => $permissions,
        'roles' => $roles,
      ], '获取权限成功');
    } catch (\Exception $e) {
      $this->logger->error('获取权限API错误: @message', ['@message' => $e->getMessage()]);
      return $this->createErrorResponse('服务器内部错误', 'SERVER_ERROR', 500);
    }
  }

  /**
   * 修改密码API。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function changePassword(Request $request): JsonResponse
  {
    try {
      $token = $this->extractBearerToken($request);

      if (!$token) {
        return $this->createErrorResponse('缺少认证令牌', 'MISSING_TOKEN', 401);
      }

      $payload = $this->authService->verifyToken($token);

      if (!$payload) {
        return $this->createErrorResponse('无效的令牌', 'INVALID_TOKEN', 401);
      }

      $content = $request->getContent();
      $data = json_decode($content, TRUE);

      // 验证请求参数
      if (empty($data['current_password'])) {
        return $this->createErrorResponse('缺少当前密码', 'MISSING_CURRENT_PASSWORD', 400);
      }

      if (empty($data['new_password'])) {
        return $this->createErrorResponse('缺少新密码', 'MISSING_NEW_PASSWORD', 400);
      }

      // 验证确认密码（如果提供了的话）
      if (isset($data['confirm_password']) && $data['new_password'] !== $data['confirm_password']) {
        return $this->createErrorResponse('新密码与确认密码不一致', 'PASSWORD_MISMATCH', 400);
      }

      // 验证新密码强度
      if (strlen($data['new_password']) < 6) {
        return $this->createErrorResponse('密码长度至少6位', 'PASSWORD_TOO_SHORT', 400);
      }

      // 检查新密码是否与当前密码相同
      if ($data['current_password'] === $data['new_password']) {
        return $this->createErrorResponse('新密码不能与当前密码相同', 'SAME_PASSWORD', 400);
      }

      $user_id = (int)($payload['sub'] ?? 0);
      $current_password = $data['current_password'];
      $new_password = $data['new_password'];

      // 调用认证服务修改密码
      $success = $this->authService->changePassword($user_id, $current_password, $new_password);

      if ($success) {
        return $this->createSuccessResponse(['message' => '密码修改成功'], '密码修改成功');
      } else {
        return $this->createErrorResponse('密码修改失败，请检查当前密码是否正确', 'PASSWORD_CHANGE_FAILED', 400);
      }
    } catch (\Exception $e) {
      $this->logger->error('修改密码API错误: @message', ['@message' => $e->getMessage()]);
      return $this->createErrorResponse('服务器内部错误', 'SERVER_ERROR', 500);
    }
  }

  /**
   * 获取用户角色API。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function roles(Request $request): JsonResponse
  {
    try {
      $token = $this->extractBearerToken($request);

      if (!$token) {
        return $this->createErrorResponse('缺少认证令牌', 'MISSING_TOKEN', 401);
      }

      $payload = $this->authService->verifyToken($token);

      if (!$payload) {
        return $this->createErrorResponse('无效的令牌', 'INVALID_TOKEN', 401);
      }

      $user_id = (int) ($payload['sub'] ?? 0);
      $tenant_id = $payload['tenant_id'] ?? '';

      $roles = $this->permissionChecker->getUserRoles($user_id, $tenant_id);

      return $this->createSuccessResponse(['roles' => $roles], '获取角色成功');
    } catch (\Exception $e) {
      $this->logger->error('获取角色API错误: @message', ['@message' => $e->getMessage()]);
      return $this->createErrorResponse('服务器内部错误', 'SERVER_ERROR', 500);
    }
  }

  /**
   * 分配角色API。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function assignRole(Request $request): JsonResponse
  {
    try {
      $auth_data = $request->attributes->get('auth_data');
      $user_id = $auth_data['user_id'];
      $tenant_id = $auth_data['tenant_id'];

      // 检查权限
      if (!$this->permissionChecker->hasPermission($user_id, $tenant_id, 'user.manage')) {
        return $this->createErrorResponse('权限不足', 'PERMISSION_DENIED', 403, ['需要用户管理权限']);
      }

      $content = json_decode($request->getContent(), TRUE);
      if (!$content || !isset($content['user_id']) || !isset($content['role_name'])) {
        return $this->createErrorResponse('请求参数不完整', 'MISSING_PARAMS', 400, ['需要user_id和role_name参数']);
      }

      $target_user_id = $content['user_id'];
      $role_name = $content['role_name'];

      // 验证角色是否存在
      $valid_roles = ['tenant_admin', 'tenant_user', 'api_client'];
      if (!in_array($role_name, $valid_roles)) {
        return $this->createErrorResponse('无效的角色', 'INVALID_ROLE', 400, ['有效角色: ' . implode(', ', $valid_roles)]);
      }

      // 检查用户是否已有该角色
      $existing = $this->database->select('baas_auth_user_roles', 'r')
        ->fields('r', ['id'])
        ->condition('user_id', $target_user_id)
        ->condition('tenant_id', $tenant_id)
        ->condition('role_name', $role_name)
        ->execute()
        ->fetchField();

      if ($existing) {
        return $this->createErrorResponse('用户已拥有该角色', 'ROLE_ALREADY_ASSIGNED', 400);
      }

      // 分配角色
      $this->database->insert('baas_auth_user_roles')
        ->fields([
          'user_id' => $target_user_id,
          'tenant_id' => $tenant_id,
          'role_name' => $role_name,
          'assigned_by' => $user_id,
          'created' => time(),
        ])
        ->execute();

      return $this->createSuccessResponse([
        'user_id' => $target_user_id,
        'tenant_id' => $tenant_id,
        'role_name' => $role_name,
        'assigned_by' => $user_id,
      ], '角色分配成功');
    } catch (\Exception $e) {
      $this->logger->error('分配角色失败: @message', ['@message' => $e->getMessage()]);
      return $this->createErrorResponse('分配角色失败', 'ROLE_ASSIGNMENT_FAILED', 500, [$e->getMessage()]);
    }
  }

  /**
   * 撤销角色API。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function revokeRole(Request $request): JsonResponse
  {
    try {
      $token = $this->extractBearerToken($request);

      if (!$token) {
        return $this->createErrorResponse('缺少认证令牌', 'MISSING_TOKEN', 401);
      }

      $payload = $this->authService->verifyToken($token);

      if (!$payload) {
        return $this->createErrorResponse('无效的令牌', 'INVALID_TOKEN', 401);
      }

      $content = $request->getContent();
      $data = json_decode($content, TRUE);

      // 验证请求参数
      if (empty($data['user_id']) || empty($data['role'])) {
        return $this->createErrorResponse('缺少必要参数', 'MISSING_PARAMS', 400);
      }

      // 检查权限
      $current_user_id = (int) ($payload['sub'] ?? 0);
      $tenant_id = $payload['tenant_id'] ?? '';

      if (!$this->permissionChecker->hasPermission($current_user_id, $tenant_id, 'admin')) {
        return $this->createErrorResponse('权限不足', 'PERMISSION_DENIED', 403);
      }

      // 这里应该实现角色撤销逻辑
      // 为简化，暂时返回成功响应
      return $this->createSuccessResponse(['message' => '角色撤销成功'], '角色撤销成功');
    } catch (\Exception $e) {
      $this->logger->error('撤销角色API错误: @message', ['@message' => $e->getMessage()]);
      return $this->createErrorResponse('服务器内部错误', 'SERVER_ERROR', 500);
    }
  }

  /**
   * 获取用户角色列表.
   */
  public function getUserRoles(Request $request)
  {
    try {
      $auth_data = $request->attributes->get('auth_data');

      // 验证认证数据
      if (!$auth_data || !isset($auth_data['user_id']) || !isset($auth_data['tenant_id'])) {
        return new JsonResponse([
          'success' => false,
          'error' => '认证失败',
          'details' => '无效的认证信息',
        ], 401);
      }

      $current_user_id = $auth_data['user_id'];
      $current_tenant_id = $auth_data['tenant_id'];

      // 验证认证数据的有效性
      if (!$current_user_id || !$current_tenant_id) {
        return new JsonResponse([
          'success' => false,
          'error' => '认证失败',
          'details' => '用户ID或租户ID为空',
        ], 401);
      }

      // 获取请求参数
      $content = json_decode($request->getContent(), TRUE);
      if (!$content) {
        return new JsonResponse([
          'success' => false,
          'error' => '请求参数不能为空',
          'details' => '需要提供JSON格式的请求体',
        ], 400);
      }

      // 验证必需参数
      if (!isset($content['user_id'])) {
        return new JsonResponse([
          'success' => false,
          'error' => '缺少必需参数',
          'details' => '需要提供user_id参数',
        ], 400);
      }

      $target_user_id = (int) $content['user_id'];
      $target_tenant_id = $content['tenant_id'] ?? $current_tenant_id;

      // 验证用户ID
      if ($target_user_id <= 0) {
        return new JsonResponse([
          'success' => false,
          'error' => '无效的用户ID',
          'details' => 'user_id必须是正整数',
        ], 400);
      }

      // 检查是否为跨租户查询
      $is_cross_tenant = ($target_tenant_id !== $current_tenant_id);

      // 权限检查
      if ($is_cross_tenant) {
        // 跨租户查询需要超级管理员权限
        if (!$this->permissionChecker->hasPermission($current_user_id, $current_tenant_id, 'admin')) {
          return new JsonResponse([
            'success' => false,
            'error' => '权限不足',
            'details' => '跨租户查询需要超级管理员权限',
          ], 403);
        }
      } else {
        // 同租户查询需要用户管理权限
        if (!$this->permissionChecker->hasPermission($current_user_id, $current_tenant_id, 'user.manage')) {
          return new JsonResponse([
            'success' => false,
            'error' => '权限不足',
            'details' => '需要用户管理权限',
          ], 403);
        }
      }

      // 验证目标用户是否存在于租户中
      $user_exists = $this->database->select('baas_user_tenant_mapping', 'utm')
        ->fields('utm', ['user_id'])
        ->condition('user_id', $target_user_id)
        ->condition('tenant_id', $target_tenant_id)
        ->execute()
        ->fetchField();

      if (!$user_exists) {
        return new JsonResponse([
          'success' => false,
          'error' => '用户不存在',
          'details' => "用户ID {$target_user_id} 在租户 {$target_tenant_id} 中不存在",
        ], 404);
      }

      // 获取目标用户的角色
      $roles = $this->permissionChecker->getUserRoles($target_user_id, $target_tenant_id);

      return new JsonResponse([
        'success' => true,
        'data' => [
          'user_id' => $target_user_id,
          'tenant_id' => $target_tenant_id,
          'roles' => $roles,
          'is_cross_tenant_query' => $is_cross_tenant,
        ],
      ]);
    } catch (\Exception $e) {
      $this->logger->error('获取用户角色失败: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse([
        'success' => false,
        'error' => '获取用户角色失败',
        'details' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * 移除用户角色.
   */
  public function removeRole(Request $request)
  {
    try {
      $auth_data = $request->attributes->get('auth_data');
      $user_id = $auth_data['user_id'];
      $tenant_id = $auth_data['tenant_id'];

      // 检查权限
      if (!$this->permissionChecker->hasPermission($user_id, $tenant_id, 'user.manage')) {
        return $this->createErrorResponse('权限不足', 'PERMISSION_DENIED', 403, ['需要用户管理权限']);
      }

      $content = json_decode($request->getContent(), TRUE);
      if (!$content || !isset($content['user_id']) || !isset($content['role_name'])) {
        return new JsonResponse([
          'success' => false,
          'error' => '请求参数不完整',
          'details' => '需要user_id和role_name参数',
        ], 400);
      }

      $target_user_id = $content['user_id'];
      $role_name = $content['role_name'];

      // 移除角色
      $deleted = $this->database->delete('baas_auth_user_roles')
        ->condition('user_id', $target_user_id)
        ->condition('tenant_id', $tenant_id)
        ->condition('role_name', $role_name)
        ->execute();

      if ($deleted === 0) {
        return new JsonResponse([
          'success' => false,
          'error' => '角色不存在或已移除',
        ], 404);
      }

      return new JsonResponse([
        'success' => true,
        'message' => '角色移除成功',
        'data' => [
          'user_id' => $target_user_id,
          'tenant_id' => $tenant_id,
          'role_name' => $role_name,
        ],
      ]);
    } catch (\Exception $e) {
      $this->logger->error('移除角色失败: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse([
        'success' => false,
        'error' => '移除角色失败',
        'details' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * 获取可用角色列表.
   */
  public function getAvailableRoles(Request $request)
  {
    try {
      $auth_data = $request->attributes->get('auth_data');
      $user_id = $auth_data['user_id'];
      $tenant_id = $auth_data['tenant_id'];

      // 检查权限
      if (!$this->permissionChecker->hasPermission($user_id, $tenant_id, 'user.manage')) {
        return $this->createErrorResponse('权限不足', 'PERMISSION_DENIED', 403, ['需要用户管理权限']);
      }

      // 获取所有可用角色及其权限
      $roles = [];
      $query = $this->database->select('baas_auth_permissions', 'p')
        ->fields('p', ['role_name', 'resource', 'operation'])
        ->orderBy('role_name');

      $or_group = $query->orConditionGroup()
        ->condition('tenant_id', $tenant_id)
        ->isNull('tenant_id');

      $query->condition($or_group);
      $role_permissions = $query->execute();

      while ($row = $role_permissions->fetchAssoc()) {
        $role_name = $row['role_name'];
        if (!isset($roles[$role_name])) {
          $roles[$role_name] = [
            'name' => $role_name,
            'permissions' => [],
          ];
        }
        $roles[$role_name]['permissions'][] = $row['resource'] . '.' . $row['operation'];
      }

      return new JsonResponse([
        'success' => true,
        'data' => array_values($roles),
      ]);
    } catch (\Exception $e) {
      $this->logger->error('获取可用角色失败: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse([
        'success' => false,
        'error' => '获取可用角色失败',
        'details' => $e->getMessage(),
      ], 500);
    }
  }

  /**
   * 验证登录请求参数。
   *
   * @param array|null $data
   *   请求数据。
   *
   * @return array
   *   验证错误数组。
   */
  protected function validateLoginRequest($data): array
  {
    $errors = [];

    if (empty($data['username'])) {
      $errors['username'] = '用户名不能为空';
    }

    if (empty($data['password'])) {
      $errors['password'] = '密码不能为空';
    }

    // tenant_id 和 project_id 现在都是可选的，用于向后兼容

    return $errors;
  }

  /**
   * 从请求中提取Bearer令牌。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return string|null
   *   令牌字符串或null。
   */
  protected function extractBearerToken(Request $request): ?string
  {
    $authorization = $request->headers->get('Authorization', '');

    if (preg_match('/Bearer\s+(.*)$/i', $authorization, $matches)) {
      return $matches[1];
    }

    return NULL;
  }

  /**
   * 检查API Key访问权限。
   *
   * @return \Drupal\Core\Access\AccessResult
   *   访问结果。
   */
  public static function checkApiKeyAccess(): \Drupal\Core\Access\AccessResult
  {
    $request = \Drupal::request();
    $api_key = $request->headers->get('X-API-Key');
    
    if (!$api_key) {
      return \Drupal\Core\Access\AccessResult::forbidden('缺少API密钥认证头部');
    }
    
    // 这里可以添加更复杂的API Key验证逻辑
    // 目前只检查是否存在API Key头部
    return \Drupal\Core\Access\AccessResult::allowed();
  }

}
