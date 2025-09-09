<?php

declare(strict_types=1);

namespace Drupal\baas_tenant\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Drupal\baas_tenant\TenantManagerInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Psr\Log\LoggerInterface;

/**
 * 用户注册事件订阅者 - 自动创建租户和默认项目.
 */
class UserRegistrationSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  protected readonly LoggerInterface $logger;

  /**
   * 构造函数.
   */
  public function __construct(
    protected readonly TenantManagerInterface $tenantManager,
    protected readonly ProjectManagerInterface $projectManager,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly AccountInterface $currentUser,
  ) {
    $this->logger = $loggerFactory->get('baas_tenant');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [];
  }

  /**
   * 为新注册用户创建租户（供hook调用）.
   *
   * @param \Drupal\user\UserInterface $user
   *   新注册的用户对象.
   */
  public function handleUserRegistration(UserInterface $user): void {
    $this->createTenantForUser($user);
  }

  /**
   * 为新注册用户创建租户和默认项目.
   *
   * @param \Drupal\user\UserInterface $user
   *   新注册的用户对象.
   */
  protected function createTenantForUser(UserInterface $user): void {
    try {
      // 检查是否是新用户（不是管理员创建的）
      if ($user->id() == 1 || $user->hasRole('administrator')) {
        return;
      }

      // 获取用户信息
      $username = $user->getAccountName();
      $email = $user->getEmail();
      $user_id = (int) $user->id();
      
      if (empty($username) || empty($email)) {
        $this->logger->warning('用户信息不完整，无法创建租户: uid=@uid', ['@uid' => $user_id]);
        return;
      }

      // 创建租户数据
      $tenant_name = $username . '_workspace';
      $tenant_settings = [
        'description' => $this->t('个人工作空间 - @username', ['@username' => $username]),
        'max_entities' => 50,  // 个人用户默认限制
        'max_storage' => 1024, // 1GB
        'max_requests' => 1000, // 每日API请求限制
        'type' => 'personal',   // 标记为个人租户
        'auto_created' => TRUE, // 标记为自动创建
      ];

      // 创建租户
      $tenant_id = $this->tenantManager->createTenant(
        $tenant_name,
        $user_id,
        $tenant_settings
      );
      
      if ($tenant_id) {
        $this->logger->info('为用户自动创建租户成功: user=@user, tenant=@tenant', [
          '@user' => $username,
          '@tenant' => $tenant_id,
        ]);

        // 创建默认项目
        $this->createDefaultProject($tenant_id, $user);

        // 添加用户到租户映射
        $this->addUserToTenant($user->id(), $tenant_id, 'owner');
      }
      else {
        $this->logger->error('为用户创建租户失败: user=@user', ['@user' => $username]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('用户注册自动创建租户异常: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * 为租户创建默认项目.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param \Drupal\user\UserInterface $user
   *   用户对象.
   */
  protected function createDefaultProject(string $tenant_id, UserInterface $user): void {
    try {
      $project_data = [
        'name' => '默认项目',
        'machine_name' => 'default',
        'description' => $this->t('系统自动创建的默认项目'),
        'settings' => [
          'max_entities' => 30,
          'max_storage' => 512, // 512MB
          'auto_created' => TRUE,
          'is_default' => TRUE,
        ],
        'owner_uid' => (int) $user->id(),
        'status' => 1,
      ];

      $project_id = $this->projectManager->createProject($tenant_id, $project_data);
      
      if ($project_id) {
        $this->logger->info('为租户创建默认项目成功: tenant=@tenant, project=@project', [
          '@tenant' => $tenant_id,
          '@project' => $project_id,
        ]);

        // 添加用户为项目所有者
        $this->projectManager->addProjectMember($project_id, (int) $user->id(), 'owner');
      }
      else {
        $this->logger->error('为租户创建默认项目失败: tenant=@tenant', ['@tenant' => $tenant_id]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('创建默认项目异常: @message', ['@message' => $e->getMessage()]);
    }
  }

  /**
   * 添加用户到租户映射.
   *
   * @param int $user_id
   *   用户ID.
   * @param string $tenant_id
   *   租户ID.
   * @param string $role
   *   用户角色.
   */
  protected function addUserToTenant(int $user_id, string $tenant_id, string $role = 'member'): void {
    try {
      // 这里需要调用用户-租户映射服务
      // 由于baas_auth模块的UserTenantMapping服务处理这个逻辑
      $user_tenant_mapping = \Drupal::service('baas_auth.user_tenant_mapping');
      
      if ($user_tenant_mapping) {
        $mapping_data = [
          'user_id' => $user_id,
          'tenant_id' => $tenant_id,
          'role' => $role,
          'status' => 1,
          'joined_at' => \Drupal::time()->getRequestTime(),
        ];
        
        $user_tenant_mapping->addUserToTenant($mapping_data);
        
        $this->logger->info('用户租户映射创建成功: user=@user, tenant=@tenant, role=@role', [
          '@user' => $user_id,
          '@tenant' => $tenant_id,
          '@role' => $role,
        ]);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('创建用户租户映射异常: @message', ['@message' => $e->getMessage()]);
    }
  }

}