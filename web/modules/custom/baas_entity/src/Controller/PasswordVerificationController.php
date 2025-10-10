<?php

declare(strict_types=1);

namespace Drupal\baas_entity\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\baas_entity\Service\FieldTypeManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 密码验证控制器。
 */
class PasswordVerificationController extends ControllerBase {

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly FieldTypeManager $fieldTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('baas_entity.field_type_manager'),
    );
  }

  /**
   * 验证用户密码。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function verifyPassword(Request $request): JsonResponse {
    try {
      // 获取请求头中的租户和项目信息
      $tenant_id = $request->headers->get('X-BaaS-Tenant-ID');
      $project_id = $request->headers->get('X-BaaS-Project-ID');

      if (!$tenant_id || !$project_id) {
        return new JsonResponse([
          'success' => false,
          'error' => '缺少租户或项目信息',
          'code' => 'MISSING_TENANT_PROJECT',
        ], Response::HTTP_BAD_REQUEST);
      }

      // 解析请求数据
      $content = $request->getContent();
      if (empty($content)) {
        return new JsonResponse([
          'success' => false,
          'error' => '请求数据为空',
          'code' => 'EMPTY_REQUEST',
        ], Response::HTTP_BAD_REQUEST);
      }

      $data = json_decode($content, true);
      if (json_last_error() !== JSON_ERROR_NONE) {
        return new JsonResponse([
          'success' => false,
          'error' => '无效的JSON数据',
          'code' => 'INVALID_JSON',
        ], Response::HTTP_BAD_REQUEST);
      }

      if (!isset($data['credentials'])) {
        return new JsonResponse([
          'success' => false,
          'error' => '缺少认证信息',
          'code' => 'MISSING_CREDENTIALS',
        ], Response::HTTP_BAD_REQUEST);
      }

      $credentials = $data['credentials'];
      $email = $credentials['email'] ?? null;
      $password = $credentials['password'] ?? null;

      if (!$email || !$password) {
        return new JsonResponse([
          'success' => false,
          'error' => '邮箱和密码不能为空',
          'code' => 'MISSING_EMAIL_PASSWORD',
        ], Response::HTTP_BAD_REQUEST);
      }

      // 生成表名 - 转换为完整的ID格式
      $full_tenant_id = "tenant_" . $tenant_id;
      $full_project_id = "tenant_" . $tenant_id . "_project_" . $project_id;
      
      $table_name_generator = \Drupal::service('baas_project.table_name_generator');
      $table_name = $table_name_generator->generateTableName($full_tenant_id, $full_project_id, 'users');

      // 检查表是否存在
      if (!$this->database->schema()->tableExists($table_name)) {
        return new JsonResponse([
          'success' => false,
          'error' => '用户表不存在',
          'code' => 'USER_TABLE_NOT_FOUND',
        ], Response::HTTP_NOT_FOUND);
      }

      // 查找用户
      $query = $this->database->select($table_name, 'u')
        ->fields('u')
        ->condition('email', $email)
        ->range(0, 1);

      $user = $query->execute()->fetchAssoc();

      if (!$user) {
        return new JsonResponse([
          'success' => false,
          'error' => '用户不存在',
          'code' => 'USER_NOT_FOUND',
        ], Response::HTTP_NOT_FOUND);
      }

      // 获取密码字段插件
      $passwordPlugin = $this->fieldTypeManager->getPlugin('password');

      // 验证密码
      $stored_password = $user['password'] ?? $user['password_hash'] ?? null;
      if (!$stored_password) {
        return new JsonResponse([
          'success' => false,
          'error' => '用户密码数据不完整',
          'code' => 'INCOMPLETE_PASSWORD_DATA',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      if (!$passwordPlugin->verifyPassword($password, $stored_password)) {
        return new JsonResponse([
          'success' => false,
          'error' => '密码错误',
          'code' => 'INVALID_PASSWORD',
        ], Response::HTTP_UNAUTHORIZED);
      }

      // 验证成功，返回用户信息（不包含密码）
      unset($user['password'], $user['password_hash']);

      return new JsonResponse([
        'success' => true,
        'data' => $user,
        'message' => '密码验证成功',
      ]);

    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('密码验证失败: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => false,
        'error' => '密码验证时发生内部错误',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 创建错误响应。
   */
  protected function createErrorResponse(string $message, string $code, int $status = Response::HTTP_BAD_REQUEST): JsonResponse {
    return new JsonResponse([
      'success' => false,
      'error' => $message,
      'code' => $code,
    ], $status);
  }

}