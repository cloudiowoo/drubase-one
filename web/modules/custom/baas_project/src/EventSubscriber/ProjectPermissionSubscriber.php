<?php

declare(strict_types=1);

namespace Drupal\baas_project\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\baas_project\Event\ProjectEvent;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * 项目权限事件订阅器。
 * 
 * 自动处理项目成员角色变更时的Drupal系统权限映射。
 */
class ProjectPermissionSubscriber implements EventSubscriberInterface
{

  protected readonly LoggerChannelInterface $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory
  ) {
    $this->logger = $loggerFactory->get('baas_project');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array
  {
    return [
      ProjectEvent::MEMBER_ADDED => ['onMemberAdded', 10],
      ProjectEvent::MEMBER_REMOVED => ['onMemberRemoved', 10],
      ProjectEvent::MEMBER_ROLE_UPDATED => ['onMemberRoleUpdated', 10],
    ];
  }

  /**
   * 处理成员添加事件。
   *
   * @param \Drupal\baas_project\Event\ProjectEvent $event
   *   项目事件。
   */
  public function onMemberAdded(ProjectEvent $event): void
  {
    $data = $event->getData();
    if (isset($data['user_id']) && isset($data['role'])) {
      $this->assignDrupalRole($data['user_id'], $data['role']);
    }
  }

  /**
   * 处理成员移除事件。
   *
   * @param \Drupal\baas_project\Event\ProjectEvent $event
   *   项目事件。
   */
  public function onMemberRemoved(ProjectEvent $event): void
  {
    $data = $event->getData();
    if (isset($data['user_id']) && isset($data['previous_role'])) {
      $this->revokeDrupalRole($data['user_id'], $data['previous_role']);
    }
  }

  /**
   * 处理成员角色更新事件。
   *
   * @param \Drupal\baas_project\Event\ProjectEvent $event
   *   项目事件。
   */
  public function onMemberRoleUpdated(ProjectEvent $event): void
  {
    $data = $event->getData();
    if (isset($data['user_id'], $data['previous_role'], $data['new_role'])) {
      // 移除旧角色
      $this->revokeDrupalRole($data['user_id'], $data['previous_role']);
      // 分配新角色
      $this->assignDrupalRole($data['user_id'], $data['new_role']);
    }
  }

  /**
   * 为用户分配Drupal系统角色。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $project_role
   *   项目角色。
   */
  protected function assignDrupalRole(int $user_id, string $project_role): void
  {
    try {
      $user_storage = $this->entityTypeManager->getStorage('user');
      $user = $user_storage->load($user_id);
      if (!$user) {
        return;
      }

      $role_mapping = $this->getProjectRoleMapping();
      $system_role_id = $role_mapping[$project_role]['role_id'] ?? 'project_viewer';

      // 确保系统角色存在
      $this->ensureSystemRoleExists($system_role_id, $role_mapping[$project_role] ?? []);

      // 检查用户是否已有该角色
      $user_roles = $user->getRoles();
      if (!in_array($system_role_id, $user_roles)) {
        $user->addRole($system_role_id);
        $user->save();

        $this->logger->info('为用户 @user_id 分配了 @role_id 角色', [
          '@user_id' => $user_id,
          '@role_id' => $system_role_id,
        ]);
      }
    } catch (\Exception $e) {
      $this->logger->error('为用户 @user_id 分配角色失败: @error', [
        '@user_id' => $user_id,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 移除用户的Drupal系统角色。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $project_role
   *   项目角色。
   */
  protected function revokeDrupalRole(int $user_id, string $project_role): void
  {
    try {
      $user_storage = $this->entityTypeManager->getStorage('user');
      $user = $user_storage->load($user_id);
      if (!$user) {
        return;
      }

      $role_mapping = $this->getProjectRoleMapping();
      $system_role_id = $role_mapping[$project_role]['role_id'] ?? 'project_viewer';

      // 检查用户是否还在其他项目中有相同角色
      if ($this->userHasRoleInOtherProjects($user_id, $project_role)) {
        $this->logger->info('用户 @user_id 在其他项目中仍有 @role 角色，不移除系统角色', [
          '@user_id' => $user_id,
          '@role' => $project_role,
        ]);
        return;
      }

      // 移除系统角色
      $user_roles = $user->getRoles();
      if (in_array($system_role_id, $user_roles)) {
        $user->removeRole($system_role_id);
        $user->save();

        $this->logger->info('从用户 @user_id 移除了 @role_id 角色', [
          '@user_id' => $user_id,
          '@role_id' => $system_role_id,
        ]);
      }
    } catch (\Exception $e) {
      $this->logger->error('从用户 @user_id 移除角色失败: @error', [
        '@user_id' => $user_id,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 检查用户是否在其他项目中有相同角色。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $project_role
   *   项目角色。
   *
   * @return bool
   *   如果用户在其他项目中有相同角色则返回TRUE。
   */
  protected function userHasRoleInOtherProjects(int $user_id, string $project_role): bool
  {
    $database = \Drupal::database();
    
    $count = $database->select('baas_project_members', 'm')
      ->condition('user_id', $user_id)
      ->condition('role', $project_role)
      ->condition('status', 1)
      ->countQuery()
      ->execute()
      ->fetchField();

    return $count > 0;
  }

  /**
   * 确保系统角色存在。
   *
   * @param string $role_id
   *   角色ID。
   * @param array $role_config
   *   角色配置。
   */
  protected function ensureSystemRoleExists(string $role_id, array $role_config): void
  {
    try {
      $role_storage = $this->entityTypeManager->getStorage('user_role');
      $role = $role_storage->load($role_id);

      if (!$role) {
        // 创建角色
        $role = $role_storage->create([
          'id' => $role_id,
          'label' => $role_config['label'] ?? $role_id,
          'weight' => $role_config['weight'] ?? 0,
        ]);
        $role->save();

        // 授予权限
        if (isset($role_config['permissions'])) {
          user_role_grant_permissions($role_id, $role_config['permissions']);
        }

        $this->logger->info('创建了系统角色 @role_id', ['@role_id' => $role_id]);
      }
    } catch (\Exception $e) {
      $this->logger->error('创建系统角色 @role_id 失败: @error', [
        '@role_id' => $role_id,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 获取项目角色到系统角色的映射。
   *
   * @return array
   *   角色映射配置。
   */
  protected function getProjectRoleMapping(): array
  {
    return [
      'member' => [
        'role_id' => 'project_viewer',
        'label' => '项目查看者',
        'weight' => 2,
        'permissions' => [
          'view baas project',
          'access baas project api',
        ],
      ],
      'editor' => [
        'role_id' => 'project_editor',
        'label' => '项目编辑者',
        'weight' => 3,
        'permissions' => [
          'view baas project',
          'edit baas project',
          'access baas project api',
        ],
      ],
      'admin' => [
        'role_id' => 'project_admin',
        'label' => '项目管理员',
        'weight' => 4,
        'permissions' => [
          'view baas project',
          'edit baas project',
          'manage baas project members',
          'access baas project api',
        ],
      ],
      'owner' => [
        'role_id' => 'project_owner',
        'label' => '项目拥有者',
        'weight' => 5,
        'permissions' => [
          'view baas project',
          'edit baas project',
          'delete baas project',
          'manage baas project members',
          'manage baas project settings',
          'transfer baas project ownership',
          'access baas project api',
        ],
      ],
    ];
  }
}