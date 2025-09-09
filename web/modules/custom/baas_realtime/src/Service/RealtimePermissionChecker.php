<?php

declare(strict_types=1);

namespace Drupal\baas_realtime\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Drupal\baas_auth\Service\JwtTokenManagerInterface;
use Drupal\baas_auth\Service\JwtBlacklistServiceInterface;
use Drupal\baas_auth\Service\ApiKeyManagerInterface;
use Drupal\baas_tenant\TenantManager;
use Drupal\baas_project\ProjectManager;

/**
 * 实时权限检查服务。
 */
class RealtimePermissionChecker
{

  /**
   * Logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly UnifiedPermissionCheckerInterface $permissionChecker,
    protected readonly JwtTokenManagerInterface $jwtTokenManager,
    protected readonly JwtBlacklistServiceInterface $jwtBlacklist,
    protected readonly ApiKeyManagerInterface $apiKeyManager,
    protected readonly TenantManager $tenantManager,
    protected readonly ProjectManager $projectManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('baas_realtime');
  }

  /**
   * 验证WebSocket连接权限。
   *
   * @param string $api_key
   *   API密钥。
   * @param string $jwt_token
   *   JWT令牌。
   * @param string|null $project_id
   *   项目ID（可选，从URL参数获取）。
   *
   * @return array|null
   *   连接信息数组或NULL。
   *
   * @throws \Drupal\baas_realtime\Exception\RealtimeException
   */
  public function validateConnection(string $api_key, string $jwt_token, ?string $project_id = null): ?array {
    try {
      // 验证API密钥获取项目信息
      $project = $this->validateApiKey($api_key);
      if (!$project) {
        $this->logger->warning('Invalid API key provided for realtime connection');
        return NULL;
      }

      // 验证JWT token获取用户信息
      $user = $this->validateJwtToken($jwt_token);
      if (!$user) {
        $this->logger->warning('Invalid JWT token provided for realtime connection');
        return NULL;
      }

      // 确定要检查的项目ID - 优先使用URL参数，否则使用API密钥中的项目ID
      $target_project_id = $project_id ?? $project['project_id'];
      
      if (!$target_project_id) {
        $this->logger->warning('No project ID provided for realtime connection');
        return NULL;
      }

      // 使用统一权限检查器验证用户是否可以访问该项目
      $can_access_project = $this->permissionChecker->canAccessProject(
        $user['user_id'],
        $target_project_id
      );

      if (!$can_access_project) {
        $this->logger->warning('User @user_id has no access to project @project_id', [
          '@user_id' => $user['user_id'],
          '@project_id' => $target_project_id,
        ]);
        return NULL;
      }

      // 检查用户是否有实时功能权限
      $has_realtime_access = $this->permissionChecker->checkProjectPermission(
        (int) $user['user_id'],
        $target_project_id,
        'access realtime'
      );

      if (!$has_realtime_access) {
        $this->logger->warning('User @user_id lacks realtime access permission for project @project_id', [
          '@user_id' => $user['user_id'],
          '@project_id' => $target_project_id,
        ]);
        return NULL;
      }

      // 获取用户的实时权限
      $permissions = $this->getUserRealtimePermissions($user['user_id'], $target_project_id);

      return [
        'user_id' => $user['user_id'],
        'project_id' => $target_project_id,
        'tenant_id' => $project['tenant_id'],
        'permissions' => $permissions,
        'validated_at' => time(),
      ];

    } catch (\Exception $e) {
      $this->logger->warning('Connection validation failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * 验证频道订阅权限。
   *
   * @param array $connection
   *   连接信息。
   * @param string $channel
   *   频道名。
   *
   * @return bool
   *   有权限返回TRUE，否则返回FALSE。
   */
  public function validateChannelSubscription(array $connection, string $channel): bool {
    try {
      // 解析频道类型和目标
      [$channel_type, $target] = $this->parseChannel($channel);

      switch ($channel_type) {
        case 'table':
          return $this->validateTableSubscription($connection, $target);

        case 'presence':
          return $this->validatePresenceSubscription($connection, $target);

        case 'broadcast':
          return $this->validateBroadcastSubscription($connection, $target);

        default:
          $this->logger->warning('Unknown channel type: @type', [
            '@type' => $channel_type,
          ]);
          return FALSE;
      }

    } catch (\Exception $e) {
      $this->logger->error('Channel subscription validation failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 检查行级安全权限。
   *
   * @param array $connection
   *   连接信息。
   * @param string $table_name
   *   表名。
   * @param array|null $record
   *   记录数据。
   *
   * @return bool
   *   有权限返回TRUE，否则返回FALSE。
   */
  public function checkRowLevelSecurity(array $connection, string $table_name, ?array $record): bool {
    if (!$record) {
      return TRUE;
    }

    try {
      // 检查记录是否属于用户的项目
      if (isset($record['project_id'])) {
        if ($record['project_id'] !== $connection['project_id']) {
          return FALSE;
        }
      }

      // 检查用户权限（如只能看自己的数据）
      if (isset($record['user_id']) && $this->isUserPrivateTable($table_name)) {
        return $record['user_id'] === $connection['user_id'];
      }

      // 检查租户隔离
      if (isset($record['tenant_id'])) {
        return $record['tenant_id'] === $connection['tenant_id'];
      }

      return TRUE;

    } catch (\Exception $e) {
      $this->logger->error('Row level security check failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 过滤敏感字段。
   *
   * @param array $record
   *   原始记录。
   * @param array $connection
   *   连接信息。
   *
   * @return array
   *   过滤后的记录。
   */
  public function filterSensitiveFields(array $record, array $connection): array {
    // 定义敏感字段
    $sensitive_fields = [
      'password',
      'password_hash',
      'salt',
      'token',
      'secret',
      'private_key',
      'api_key',
    ];

    // 检查用户是否有查看敏感数据的权限
    $can_view_sensitive = $this->permissionChecker->checkProjectPermission(
      (int) $connection['user_id'],
      $connection['project_id'],
      'view sensitive data'
    );

    if ($can_view_sensitive) {
      return $record;
    }

    // 过滤敏感字段
    foreach ($sensitive_fields as $field) {
      if (isset($record[$field])) {
        unset($record[$field]);
      }
    }

    return $record;
  }

  /**
   * 验证API密钥。
   *
   * @param string $api_key
   *   API密钥。
   *
   * @return array|null
   *   项目信息或NULL。
   */
  protected function validateApiKey(string $api_key): ?array {
    try {
      if (empty($api_key)) {
        return NULL;
      }

      // 使用现有的API Key管理器验证
      $api_key_info = $this->apiKeyManager->validateApiKey($api_key);

      if (!$api_key_info) {
        return NULL;
      }

      // 检查API Key是否已过期
      if (isset($api_key_info['expires_at']) && $api_key_info['expires_at'] < time()) {
        $this->logger->warning('API key has expired', ['api_key_id' => $api_key_info['id']]);
        return NULL;
      }

      // 检查API Key是否被禁用
      if (empty($api_key_info['status']) || $api_key_info['status'] != 1) {
        $this->logger->warning('API key is disabled', ['api_key_id' => $api_key_info['id']]);
        return NULL;
      }

      return [
        'project_id' => $api_key_info['project_id'] ?? null,
        'tenant_id' => $api_key_info['tenant_id'] ?? null,
        'user_id' => (int) $api_key_info['user_id'],
        'api_key_id' => $api_key_info['id'],
        'api_key_name' => $api_key_info['name'],
        'permissions' => $api_key_info['permissions'] ?? [],
        'scopes' => $api_key_info['scopes'] ?? [],
      ];

    } catch (\Exception $e) {
      $this->logger->error('API key validation failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * 验证JWT令牌。
   *
   * @param string $jwt_token
   *   JWT令牌。
   *
   * @return array|null
   *   用户信息或NULL。
   */
  protected function validateJwtToken(string $jwt_token): ?array {
    try {
      if (empty($jwt_token)) {
        return NULL;
      }

      // 使用现有的JWT令牌管理器验证
      $payload = $this->jwtTokenManager->validateToken($jwt_token);

      if (!$payload) {
        return NULL;
      }

      // 检查令牌是否在黑名单中
      if ($this->jwtBlacklist->isBlacklisted($payload['jti'])) {
        $this->logger->warning('JWT token has been revoked', ['jti' => $payload['jti']]);
        return NULL;
      }

      // 检查令牌是否过期
      if (isset($payload['exp']) && $payload['exp'] < time()) {
        $this->logger->warning('JWT token has expired', ['jti' => $payload['jti']]);
        return NULL;
      }

      return [
        'user_id' => (int) $payload['sub'],
        'tenant_id' => $payload['tenant_id'] ?? null,
        'project_id' => $payload['project_id'] ?? null,
        'username' => $payload['username'] ?? 'unknown',
        'email' => $payload['email'] ?? null,
        'roles' => $payload['roles'] ?? [],
        'permissions' => $payload['permissions'] ?? [],
        'token_type' => $payload['type'] ?? 'access',
        'token_jti' => $payload['jti'],
        'token_exp' => $payload['exp'],
      ];

    } catch (\Exception $e) {
      $this->logger->error('JWT validation failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * 获取用户实时权限。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   权限数组。
   */
  protected function getUserRealtimePermissions(int $user_id, string $project_id): array {
    $permissions = [];

    // 检查实时功能相关权限
    $realtime_permissions = [
      'access realtime',
      'view realtime',
      'manage realtime',
      'view realtime data',
      'broadcast realtime messages',
      'manage realtime presence',
    ];

    foreach ($realtime_permissions as $permission) {
      $permissions[$permission] = $this->permissionChecker->checkProjectPermission(
        (int) $user_id,
        $project_id,
        $permission
      );
    }

    return $permissions;
  }

  /**
   * 解析频道名。
   *
   * @param string $channel
   *   频道名。
   *
   * @return array
   *   [频道类型, 目标]。
   */
  protected function parseChannel(string $channel): array {
    // 频道格式: type:target
    // 例如: table:users, presence:room1, broadcast:notifications
    $parts = explode(':', $channel, 2);
    
    if (count($parts) !== 2) {
      throw new \InvalidArgumentException("Invalid channel format: {$channel}");
    }

    return $parts;
  }

  /**
   * 验证表订阅权限。
   *
   * @param array $connection
   *   连接信息。
   * @param string $table_name
   *   表名。
   *
   * @return bool
   */
  protected function validateTableSubscription(array $connection, string $table_name): bool {
    // 检查表是否属于用户的项目
    if (!$this->isProjectTable($connection['project_id'], $table_name)) {
      return FALSE;
    }

    // 检查用户是否有读取权限
    return $this->permissionChecker->checkProjectPermission(
      (int) $connection['user_id'],
      $connection['project_id'],
      'view realtime data'
    );
  }

  /**
   * 验证在线状态订阅权限。
   *
   * @param array $connection
   *   连接信息。
   * @param string $room
   *   房间名。
   *
   * @return bool
   */
  protected function validatePresenceSubscription(array $connection, string $room): bool {
    return $this->permissionChecker->checkProjectPermission(
      (int) $connection['user_id'],
      $connection['project_id'],
      'manage realtime presence'
    );
  }

  /**
   * 验证广播订阅权限。
   *
   * @param array $connection
   *   连接信息。
   * @param string $channel
   *   频道名。
   *
   * @return bool
   */
  protected function validateBroadcastSubscription(array $connection, string $channel): bool {
    return $this->permissionChecker->checkProjectPermission(
      (int) $connection['user_id'],
      $connection['project_id'],
      'view realtime data'
    );
  }

  /**
   * 检查表是否属于项目。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $table_name
   *   表名。
   *
   * @return bool
   */
  protected function isProjectTable(string $project_id, string $table_name): bool {
    // 检查表名是否符合项目表命名规范
    // 格式: baas_{hash}_{entity_name}
    if (strpos($table_name, 'baas_') !== 0) {
      return FALSE;
    }

    // TODO: 实现更严格的项目表验证逻辑
    return TRUE;
  }

  /**
   * 检查是否为用户私有表。
   *
   * @param string $table_name
   *   表名。
   *
   * @return bool
   */
  protected function isUserPrivateTable(string $table_name): bool {
    // 定义用户私有表模式
    $private_patterns = [
      'user_private',
      'user_session',
      'user_profile',
    ];

    foreach ($private_patterns as $pattern) {
      if (strpos($table_name, $pattern) !== FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

}