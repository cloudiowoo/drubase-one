<?php

declare(strict_types=1);

namespace Drupal\baas_project\Controller;

use Drupal\baas_api\Controller\BaseApiController;
use Drupal\baas_api\Service\ApiResponseService;
use Drupal\baas_api\Service\ApiValidationService;
use Drupal\baas_entity\Service\PasswordService;
use Drupal\baas_auth\Service\JwtTokenManagerInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * BaaS项目用户认证控制器。
 *
 * 专门处理BaaS项目实体中用户的认证，使用密码字段类型。
 */
class ProjectUserAuthController extends BaseApiController
{

  /**
   * 构造函数。
   */
  public function __construct(
    ApiResponseService $response_service,
    ApiValidationService $validation_service,
    protected readonly Connection $database,
    protected readonly PasswordService $passwordService,
    protected readonly JwtTokenManagerInterface $jwtTokenManager,
  ) {
    parent::__construct($response_service, $validation_service);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_api.response'),
      $container->get('baas_api.validation'),
      $container->get('database'),
      $container->get('baas_entity.password_service'),
      $container->get('baas_auth.jwt_token_manager'),
    );
  }

  /**
   * 用户注册（创建用户实体）。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function register(Request $request, string $tenant_id, string $project_id): JsonResponse
  {
    try {
      $content = $request->getContent();
      $data = json_decode($content, true);

      if (json_last_error() !== JSON_ERROR_NONE) {
        return $this->createErrorResponse('Invalid JSON data', 'INVALID_JSON', 400);
      }

      // 验证必需字段
      $required_fields = ['email', 'password'];
      foreach ($required_fields as $field) {
        if (empty($data[$field])) {
          return $this->createErrorResponse("Missing required field: {$field}", 'MISSING_FIELD', 400);
        }
      }

      // 设置默认用户名
      if (empty($data['username'])) {
        $data['username'] = explode('@', $data['email'])[0];
      }

      // 通过项目实体API创建用户
      $entity_controller = \Drupal::service('class_resolver')
        ->getInstanceFromDefinition(\Drupal\baas_entity\Controller\EntityDataWithFilesApiController::class);

      // 创建用户实体
      $create_request = Request::create(
        "/api/tenant/{$tenant_id}/project/{$project_id}/entity/users",
        'POST',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        json_encode($data)
      );

      $response = $entity_controller->createEntityWithFiles($create_request, $tenant_id, $project_id, 'users');
      $response_data = json_decode($response->getContent(), true);

      if (!$response_data['success']) {
        return $response; // 返回原始错误响应
      }

      // 注册成功，生成JWT令牌
      $user_data = $response_data['data']['entity'];
      $token_payload = [
        'user_id' => $user_data['id'],
        'email' => $user_data['email'],
        'username' => $user_data['username'],
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
        'entity_name' => 'users',
      ];

      $tokens = $this->jwtTokenManager->generateTokens($token_payload);

      return $this->createSuccessResponse([
        'user_id' => $user_data['id'],
        'access_token' => $tokens['access_token'],
        'refresh_token' => $tokens['refresh_token'],
        'expires_at' => $tokens['expires_at'],
        'user' => $user_data,
      ], '用户注册成功');

    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('用户注册失败: @error', [
        '@error' => $e->getMessage(),
      ]);

      return $this->createErrorResponse('注册失败', 'REGISTRATION_FAILED', 500);
    }
  }

  /**
   * 用户认证（登录）。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function authenticate(Request $request, string $tenant_id, string $project_id): JsonResponse
  {
    try {
      $content = $request->getContent();
      $data = json_decode($content, true);

      if (json_last_error() !== JSON_ERROR_NONE) {
        return $this->createErrorResponse('Invalid JSON data', 'INVALID_JSON', 400);
      }

      // 验证必需字段
      if (empty($data['password'])) {
        return $this->createErrorResponse('Missing password', 'MISSING_PASSWORD', 400);
      }

      if (empty($data['email']) && empty($data['username'])) {
        return $this->createErrorResponse('Missing email or username', 'MISSING_IDENTIFIER', 400);
      }

      // 使用密码服务验证用户
      $credentials = [
        'password' => $data['password'],
      ];

      if (!empty($data['email'])) {
        $credentials['email'] = $data['email'];
      } else {
        $credentials['username'] = $data['username'];
      }

      $user = $this->passwordService->verifyUserPassword($tenant_id, $project_id, 'users', $credentials);

      if (!$user) {
        return $this->createErrorResponse('Invalid credentials', 'AUTHENTICATION_FAILED', 401);
      }

      // 生成JWT令牌
      $token_payload = [
        'user_id' => $user['id'],
        'email' => $user['email'] ?? '',
        'username' => $user['username'] ?? '',
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
        'entity_name' => 'users',
      ];

      $tokens = $this->jwtTokenManager->generateTokens($token_payload);

      return $this->createSuccessResponse([
        'user_id' => $user['id'],
        'access_token' => $tokens['access_token'],
        'refresh_token' => $tokens['refresh_token'],
        'expires_at' => $tokens['expires_at'],
        'user' => $user,
      ], '登录成功');

    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('用户认证失败: @error', [
        '@error' => $e->getMessage(),
      ]);

      return $this->createErrorResponse('认证失败', 'AUTHENTICATION_ERROR', 500);
    }
  }

  /**
   * 修改密码。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $user_id
   *   用户ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function changePassword(Request $request, string $tenant_id, string $project_id, string $user_id): JsonResponse
  {
    try {
      $content = $request->getContent();
      $data = json_decode($content, true);

      if (json_last_error() !== JSON_ERROR_NONE) {
        return $this->createErrorResponse('Invalid JSON data', 'INVALID_JSON', 400);
      }

      // 验证必需字段
      $required_fields = ['current_password', 'new_password'];
      foreach ($required_fields as $field) {
        if (empty($data[$field])) {
          return $this->createErrorResponse("Missing required field: {$field}", 'MISSING_FIELD', 400);
        }
      }

      // 修改密码
      $success = $this->passwordService->changePassword(
        $tenant_id,
        $project_id,
        'users',
        $user_id,
        $data['current_password'],
        $data['new_password']
      );

      if (!$success) {
        return $this->createErrorResponse('Failed to change password', 'PASSWORD_CHANGE_FAILED', 400);
      }

      return $this->createSuccessResponse([], '密码修改成功');

    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('修改密码失败: @error', [
        '@error' => $e->getMessage(),
      ]);

      return $this->createErrorResponse('修改密码失败', 'PASSWORD_CHANGE_ERROR', 500);
    }
  }

  /**
   * 重置密码。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function resetPassword(Request $request, string $tenant_id, string $project_id): JsonResponse
  {
    try {
      $content = $request->getContent();
      $data = json_decode($content, true);

      if (json_last_error() !== JSON_ERROR_NONE) {
        return $this->createErrorResponse('Invalid JSON data', 'INVALID_JSON', 400);
      }

      // 验证必需字段
      $required_fields = ['email', 'new_password'];
      foreach ($required_fields as $field) {
        if (empty($data[$field])) {
          return $this->createErrorResponse("Missing required field: {$field}", 'MISSING_FIELD', 400);
        }
      }

      // 重置密码
      $success = $this->passwordService->resetPassword(
        $tenant_id,
        $project_id,
        'users',
        $data['email'],
        $data['new_password']
      );

      if (!$success) {
        return $this->createErrorResponse('Failed to reset password', 'PASSWORD_RESET_FAILED', 400);
      }

      return $this->createSuccessResponse([], '密码重置成功');

    } catch (\Exception $e) {
      \Drupal::logger('baas_project')->error('重置密码失败: @error', [
        '@error' => $e->getMessage(),
      ]);

      return $this->createErrorResponse('重置密码失败', 'PASSWORD_RESET_ERROR', 500);
    }
  }
}