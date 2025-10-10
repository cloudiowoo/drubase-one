<?php

namespace Drupal\baas_auth\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * 认证服务实现。
 */
class AuthenticationService
{

  /**
   * 数据库连接。
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * JWT令牌管理器。
   *
   * @var \Drupal\baas_auth\Service\JwtTokenManagerInterface
   */
  protected $jwtTokenManager;

  /**
   * 密码服务。
   *
   * @var \Drupal\baas_auth\Service\PasswordService
   */
  protected $passwordService;

  /**
   * 日志记录器。
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * JWT黑名单服务。
   *
   * @var \Drupal\baas_auth\Service\JwtBlacklistServiceInterface
   */
  protected $blacklistService;

  /**
   * 实体类型管理器。
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * 构造函数。
   *
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接。
   * @param \Drupal\baas_auth\Service\JwtTokenManagerInterface $jwt_token_manager
   *   JWT令牌管理器。
   * @param \Drupal\baas_auth\Service\PasswordService $password_service
   *   密码服务。
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂。
   * @param \Drupal\baas_auth\Service\JwtBlacklistServiceInterface $blacklist_service
   *   JWT黑名单服务。
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   实体类型管理器。
   */
  public function __construct(
    Connection $database,
    JwtTokenManagerInterface $jwt_token_manager,
    PasswordService $password_service,
    LoggerChannelFactoryInterface $logger_factory,
    JwtBlacklistServiceInterface $blacklist_service,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->database = $database;
    $this->jwtTokenManager = $jwt_token_manager;
    $this->passwordService = $password_service;
    $this->logger = $logger_factory->get('baas_auth');
    $this->blacklistService = $blacklist_service;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * 用户登录。
   *
   * @param string $username
   *   用户名。
   * @param string $password
   *   密码。
   * @param string|null $tenant_id
   *   租户ID（可选，用于向后兼容）。
   * @param string|null $project_id
   *   项目ID（可选，用于向后兼容）。
   *
   * @return array|null
   *   登录成功返回用户信息和令牌，失败返回null。
   */
  public function login(string $username, string $password, ?string $tenant_id = null, ?string $project_id = null): ?array
  {
    $this->logger->info('简化登录: 用户名=@username', ['@username' => $username]);
    
    try {
      // 通过用户实体API查找用户
      $user_storage = $this->entityTypeManager->getStorage('user');
      $users = $user_storage->loadByProperties([
        'name' => $username,
        'status' => 1,
      ]);

      if (empty($users)) {
        $this->logger->warning('用户不存在: @username', ['@username' => $username]);
        return NULL;
      }

      /** @var \Drupal\user\UserInterface $user */
      $user = reset($users);

      // 验证密码
      if (!$this->passwordService->verifyPassword($password, $user->getPassword())) {
        $this->logger->warning('密码验证失败: @username', ['@username' => $username]);
        return NULL;
      }

      $this->logger->info('密码验证成功，开始权限检测');

      // 自动检测用户权限类型
      $auth_data = $this->detectUserPermissions($user, $tenant_id, $project_id);
      
      if (!$auth_data) {
        $this->logger->warning('用户 @username (ID: @user_id) 没有任何有效权限', [
          '@username' => $username,
          '@user_id' => $user->id(),
        ]);
        return NULL;
      }

      $this->logger->info('权限检测成功: 类型=@type, 租户=@tenant_id', [
        '@type' => $auth_data['access_type'],
        '@tenant_id' => $auth_data['tenant_id'],
      ]);

      // 将用户所有旧令牌加入黑名单（安全措施：确保只有最新登录的token有效）
      $this->blacklistService->blacklistUserTokens($user->id(), $auth_data['tenant_id']);
      $this->logger->info('已将用户 @user_id 在租户 @tenant_id 的所有旧令牌加入黑名单', [
        '@user_id' => $user->id(),
        '@tenant_id' => $auth_data['tenant_id'],
      ]);

      // 生成令牌
      $payload = [
        'sub' => $user->id(),
        'username' => $user->getAccountName(),
        'tenant_id' => $auth_data['tenant_id'],
        'access_type' => $auth_data['access_type'],
      ];

      if ($auth_data['project_id']) {
        $payload['project_id'] = $auth_data['project_id'];
      }

      $access_token = $this->jwtTokenManager->generateAccessToken($payload);
      $refresh_token = $this->jwtTokenManager->generateRefreshToken($payload);

      // 解析令牌获取过期时间
      $access_payload = $this->jwtTokenManager->parseToken($access_token);
      $refresh_payload = $this->jwtTokenManager->parseToken($refresh_token);

      $this->logger->info('用户登录成功: @username, 租户: @tenant_name (@tenant_id)', [
        '@username' => $username,
        '@tenant_name' => $auth_data['tenant_name'],
        '@tenant_id' => $auth_data['tenant_id'],
      ]);

      $result = [
        'user_id' => $user->id(),
        'username' => $user->getAccountName(),
        'email' => $user->getEmail(),
        'tenant_id' => $auth_data['tenant_id'],
        'tenant_name' => $auth_data['tenant_name'],
        'access_type' => $auth_data['access_type'],
        'access_token' => $access_token,
        'refresh_token' => $refresh_token,
        'expires_in' => ($access_payload['exp'] ?? 0) - time(), // 访问令牌剩余秒数
        'access_token_expires_at' => $access_payload['exp'] ?? 0, // 访问令牌过期时间戳
        'refresh_token_expires_at' => $refresh_payload['exp'] ?? 0, // 刷新令牌过期时间戳
      ];

      // 根据权限类型添加额外信息
      if ($auth_data['access_type'] === 'tenant') {
        $result['tenant_role'] = $auth_data['tenant_role'];
        $result['is_tenant_owner'] = $auth_data['is_tenant_owner'];
      }

      if ($auth_data['access_type'] === 'project') {
        $result['project_id'] = $auth_data['project_id'];
        $result['project_name'] = $auth_data['project_name'];
        $result['project_role'] = $auth_data['project_role'];
        $result['project_permissions'] = $auth_data['project_permissions'];
      }

      return $result;
    } catch (\Exception $e) {
      $this->logger->error('登录过程出错: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * 刷新令牌。
   *
   * @param string $refresh_token
   *   刷新令牌。
   *
   * @return array|null
   *   刷新成功返回新令牌，失败返回null。
   */
  public function refreshToken(string $refresh_token): ?array
  {
    try {
      $payload = $this->jwtTokenManager->validateToken($refresh_token);

      if (!$payload) {
        $this->logger->warning('刷新令牌无效');
        return NULL;
      }

      // 验证租户是否仍然有效
      $tenant_id = $payload['tenant_id'];
      $tenant = $this->database->select('baas_tenant_config', 't')
        ->fields('t', ['tenant_id', 'name', 'status'])
        ->condition('tenant_id', $tenant_id)
        ->condition('status', 1)
        ->execute()
        ->fetchAssoc();

      if (!$tenant) {
        $this->logger->warning('租户不存在或已停用，无法刷新令牌: @tenant_id', ['@tenant_id' => $tenant_id]);
        return NULL;
      }

      // 将用户所有旧令牌加入黑名单
      $user_id = $payload['sub'];

      $this->blacklistService->blacklistUserTokens($user_id, $tenant_id);

      $this->logger->info('已将用户 @user_id 在租户 @tenant_id 的所有令牌加入黑名单', [
        '@user_id' => $user_id,
        '@tenant_id' => $tenant_id,
      ]);

      // 生成新的访问令牌
      $new_payload = [
        'sub' => $payload['sub'],
        'username' => $payload['username'] ?? '',
        'tenant_id' => $payload['tenant_id'],
      ];

      $access_token = $this->jwtTokenManager->generateAccessToken($new_payload);

      // 解析令牌获取过期时间
      $access_payload = $this->jwtTokenManager->parseToken($access_token);

      return [
        'access_token' => $access_token,
        'user_id' => $payload['sub'],
        'tenant_id' => $payload['tenant_id'],
        'tenant_name' => $tenant['name'],
        'expires_in' => ($access_payload['exp'] ?? 0) - time(), // 访问令牌剩余秒数
        'access_token_expires_at' => $access_payload['exp'] ?? 0, // 访问令牌过期时间戳
      ];
    } catch (\Exception $e) {
      $this->logger->error('刷新令牌过程出错: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * 验证令牌。
   *
   * @param string $token
   *   令牌。
   *
   * @return array|null
   *   验证成功返回令牌信息，失败返回null。
   */
  public function verifyToken(string $token): ?array
  {
    return $this->jwtTokenManager->validateToken($token);
  }

  /**
   * 获取当前用户信息。
   *
   * @param int $user_id
   *   用户ID。
   *
   * @return array|null
   *   用户信息。
   */
  public function getCurrentUser(int $user_id): ?array
  {
    try {
      // 通过实体API获取用户
      $user_storage = $this->entityTypeManager->getStorage('user');
      /** @var \Drupal\user\UserInterface $user */
      $user = $user_storage->load($user_id);

      if (!$user || !$user->isActive()) {
        return NULL;
      }

      return [
        'uid' => $user->id(),
        'name' => $user->getAccountName(),
        'mail' => $user->getEmail(),
        'created' => $user->getCreatedTime(),
        'access' => $user->getLastAccessedTime(),
      ];
    } catch (\Exception $e) {
      $this->logger->error('获取用户信息失败: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * 修改用户密码。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $current_password
   *   当前密码。
   * @param string $new_password
   *   新密码。
   *
   * @return bool
   *   修改成功返回TRUE，失败返回FALSE。
   */
  public function changePassword(int $user_id, string $current_password, string $new_password): bool
  {
    try {
      // 验证新密码长度
      if (strlen($new_password) < 6) {
        $this->logger->warning('新密码长度不足: 用户ID @user_id', ['@user_id' => $user_id]);
        return FALSE;
      }

      // 通过实体API获取用户
      $user_storage = $this->entityTypeManager->getStorage('user');
      /** @var \Drupal\user\UserInterface $user */
      $user = $user_storage->load($user_id);

      if (!$user || !$user->isActive()) {
        $this->logger->warning('用户不存在或未激活: ID @user_id', ['@user_id' => $user_id]);
        return FALSE;
      }

      // 验证当前密码
      if (!$this->passwordService->verifyPassword($current_password, $user->getPassword())) {
        $this->logger->warning('当前密码验证失败: 用户 @username', ['@username' => $user->getAccountName()]);
        return FALSE;
      }

      // 使用用户实体API设置新密码
      $user->setPassword($new_password);
      $result = $user->save();

      if ($result) {
        $this->logger->info('用户密码修改成功: @username', ['@username' => $user->getAccountName()]);
        return TRUE;
      } else {
        $this->logger->error('用户保存失败: 用户ID @user_id', ['@user_id' => $user_id]);
        return FALSE;
      }
    } catch (\Exception $e) {
      $this->logger->error('修改密码过程出错: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * 自动检测用户权限类型。
   *
   * @param \Drupal\user\UserInterface $user
   *   用户对象。
   * @param string|null $preferred_tenant_id
   *   首选租户ID（用于向后兼容）。
   * @param string|null $preferred_project_id
   *   首选项目ID（用于向后兼容）。
   *
   * @return array|null
   *   权限数据或null。
   */
  protected function detectUserPermissions($user, ?string $preferred_tenant_id = null, ?string $preferred_project_id = null): ?array
  {
    $user_id = $user->id();
    $this->logger->info('检测用户权限: 用户ID=@user_id', ['@user_id' => $user_id]);

    // 1. 首先检查用户是否为任何租户的直接成员
    $tenant_memberships = $this->database->select('baas_user_tenant_mapping', 'utm')
      ->fields('utm', ['tenant_id', 'role', 'is_owner', 'status'])
      ->condition('user_id', $user_id)
      ->condition('status', 1)
      ->execute()
      ->fetchAll();

    if (!empty($tenant_memberships)) {
      $this->logger->info('用户是租户成员，找到 @count 个租户', ['@count' => count($tenant_memberships)]);
      
      // 如果指定了首选租户ID，优先使用
      if ($preferred_tenant_id) {
        foreach ($tenant_memberships as $membership) {
          if ($membership->tenant_id === $preferred_tenant_id) {
            return $this->buildTenantAuthData($membership);
          }
        }
      }
      
      // 否则使用第一个有效的租户
      foreach ($tenant_memberships as $membership) {
        $auth_data = $this->buildTenantAuthData($membership);
        if ($auth_data) {
          return $auth_data;
        }
      }
    }

    // 2. 如果用户不是任何租户的直接成员，检查项目成员身份
    $this->logger->info('用户不是租户成员，检查项目成员身份');
    
    $project_memberships = $this->database->select('baas_project_members', 'pm')
      ->fields('pm', ['project_id', 'role', 'permissions', 'status'])
      ->condition('user_id', $user_id)
      ->condition('status', 1)
      ->execute()
      ->fetchAll();

    if (empty($project_memberships)) {
      $this->logger->warning('用户既不是租户成员，也不是项目成员');
      return NULL;
    }

    $this->logger->info('用户是项目成员，找到 @count 个项目', ['@count' => count($project_memberships)]);

    // 如果指定了首选项目ID，优先使用
    if ($preferred_project_id) {
      foreach ($project_memberships as $membership) {
        if ($membership->project_id === $preferred_project_id) {
          $auth_data = $this->buildProjectAuthData($membership);
          if ($auth_data) {
            $this->logger->info('使用指定的项目ID: @project_id', ['@project_id' => $preferred_project_id]);
            return $auth_data;
          }
        }
      }
    }

    // 如果只有一个项目，自动选择
    if (count($project_memberships) == 1) {
      $membership = $project_memberships[0];
      $auth_data = $this->buildProjectAuthData($membership);
      if ($auth_data) {
        $this->logger->info('自动选择唯一项目: @project_id', ['@project_id' => $membership->project_id]);
        return $auth_data;
      }
    }

    // 如果有多个项目，选择第一个有效的项目
    $this->logger->info('用户有多个项目，选择第一个有效项目');
    foreach ($project_memberships as $membership) {
      $auth_data = $this->buildProjectAuthData($membership);
      if ($auth_data) {
        $this->logger->info('选择项目: @project_id', ['@project_id' => $membership->project_id]);
        return $auth_data;
      }
    }

    return NULL;
  }

  /**
   * 构建租户权限数据。
   *
   * @param object $membership
   *   租户成员关系数据。
   *
   * @return array|null
   *   权限数据或null。
   */
  protected function buildTenantAuthData($membership): ?array
  {
    // 验证租户是否存在且有效
    $tenant = $this->database->select('baas_tenant_config', 't')
      ->fields('t', ['tenant_id', 'name', 'status'])
      ->condition('tenant_id', $membership->tenant_id)
      ->condition('status', 1)
      ->execute()
      ->fetchAssoc();

    if (!$tenant) {
      $this->logger->warning('租户不存在或未激活: @tenant_id', ['@tenant_id' => $membership->tenant_id]);
      return NULL;
    }

    return [
      'access_type' => 'tenant',
      'tenant_id' => $membership->tenant_id,
      'tenant_name' => $tenant['name'],
      'tenant_role' => $membership->role,
      'is_tenant_owner' => (bool) $membership->is_owner,
      'project_id' => null,
      'project_name' => null,
      'project_role' => null,
      'project_permissions' => null,
    ];
  }

  /**
   * 构建项目权限数据。
   *
   * @param object $membership
   *   项目成员关系数据。
   *
   * @return array|null
   *   权限数据或null。
   */
  protected function buildProjectAuthData($membership): ?array
  {
    // 获取项目信息
    $project = $this->database->select('baas_project_config', 'p')
      ->fields('p', ['project_id', 'tenant_id', 'name', 'status'])
      ->condition('project_id', $membership->project_id)
      ->condition('status', 1)
      ->execute()
      ->fetchAssoc();

    if (!$project) {
      $this->logger->warning('项目不存在或未激活: @project_id', ['@project_id' => $membership->project_id]);
      return NULL;
    }

    // 验证项目所属的租户是否存在且有效
    $tenant = $this->database->select('baas_tenant_config', 't')
      ->fields('t', ['tenant_id', 'name', 'status'])
      ->condition('tenant_id', $project['tenant_id'])
      ->condition('status', 1)
      ->execute()
      ->fetchAssoc();

    if (!$tenant) {
      $this->logger->warning('项目所属租户不存在或未激活: @tenant_id', ['@tenant_id' => $project['tenant_id']]);
      return NULL;
    }

    return [
      'access_type' => 'project',
      'tenant_id' => $project['tenant_id'],
      'tenant_name' => $tenant['name'],
      'tenant_role' => null,
      'is_tenant_owner' => false,
      'project_id' => $membership->project_id,
      'project_name' => $project['name'],
      'project_role' => $membership->role,
      'project_permissions' => $membership->permissions ? json_decode($membership->permissions, true) : [],
    ];
  }
}
