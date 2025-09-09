<?php

declare(strict_types=1);

namespace Drupal\baas_project\EventSubscriber;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\user\UserInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Drupal\baas_project\Event\ProjectEvent;
use Drupal\baas_project\ProjectUsageTrackerInterface;
use Drupal\baas_project\ProjectMemberManagerInterface;

/**
 * 项目事件订阅器。
 *
 * 处理项目相关的系统事件。
 */
class ProjectEventSubscriber implements EventSubscriberInterface
{

  protected readonly LoggerChannelInterface $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly MailManagerInterface $mailManager,
    protected readonly ProjectUsageTrackerInterface $usageTracker,
    protected readonly ProjectMemberManagerInterface $memberManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('baas_project_events');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array
  {
    return [
      ProjectEvent::PROJECT_CREATED => ['onProjectCreated', 100],
      ProjectEvent::PROJECT_UPDATED => ['onProjectUpdated', 100],
      ProjectEvent::PROJECT_DELETED => ['onProjectDeleted', 100],
      ProjectEvent::MEMBER_ADDED => ['onMemberAdded', 100],
      ProjectEvent::MEMBER_REMOVED => ['onMemberRemoved', 100],
      ProjectEvent::MEMBER_ROLE_UPDATED => ['onMemberRoleUpdated', 100],
      ProjectEvent::OWNERSHIP_TRANSFERRED => ['onOwnershipTransferred', 100],
      ProjectEvent::USAGE_ALERT => ['onUsageAlert', 100],
    ];
  }

  /**
   * 处理项目创建事件。
   *
   * @param \Drupal\baas_project\Event\ProjectEvent $event
   *   项目事件。
   */
  public function onProjectCreated(ProjectEvent $event): void
  {
    try {
      $project_id = $event->getProjectId();
      $project_data = $event->getData();

      $this->logger->info('Project created: @project_id (@name)', [
        '@project_id' => $project_id,
        '@name' => $project_data['name'] ?? 'Unknown',
      ]);

      // 记录项目创建的使用统计
      $this->usageTracker->recordUsage(
        $project_id,
        'project_created',
        1,
        [
          'created_by' => $project_data['owner_uid'] ?? $this->currentUser->id(),
          'tenant_id' => $project_data['tenant_id'] ?? null,
          'is_migration' => $project_data['migration'] ?? false,
        ]
      );

      // 如果不是迁移创建的项目，发送欢迎邮件
      if (!($project_data['migration'] ?? false)) {
        $this->sendProjectCreatedNotification($project_id, $project_data);
      }

      // 记录活动日志
      $this->recordProjectActivity($project_id, 'project_created', [
        'project_name' => $project_data['name'] ?? null,
        'created_by' => $project_data['owner_uid'] ?? $this->currentUser->id(),
      ]);
    } catch (\Exception $e) {
      $this->logger->error('Error handling project created event: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 处理项目更新事件。
   *
   * @param \Drupal\baas_project\Event\ProjectEvent $event
   *   项目事件。
   */
  public function onProjectUpdated(ProjectEvent $event): void
  {
    try {
      $project_id = $event->getProjectId();
      $project_data = $event->getData();

      $this->logger->info('Project updated: @project_id', [
        '@project_id' => $project_id,
      ]);

      // 记录项目更新的使用统计
      $this->usageTracker->recordUsage(
        $project_id,
        'project_updated',
        1,
        [
          'updated_by' => $this->currentUser->id(),
          'changes' => $project_data['changes'] ?? [],
        ]
      );

      // 记录活动日志
      $this->recordProjectActivity($project_id, 'project_updated', [
        'updated_by' => $this->currentUser->id(),
        'changes' => $project_data['changes'] ?? [],
      ]);
    } catch (\Exception $e) {
      $this->logger->error('Error handling project updated event: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 处理项目删除事件。
   *
   * @param \Drupal\baas_project\Event\ProjectEvent $event
   *   项目事件。
   */
  public function onProjectDeleted(ProjectEvent $event): void
  {
    try {
      $project_id = $event->getProjectId();
      $project_data = $event->getData();

      $this->logger->info('Project deleted: @project_id', [
        '@project_id' => $project_id,
      ]);

      // 通知所有项目成员
      $this->sendProjectDeletedNotification($project_id, $project_data);

      // 清理相关的使用统计数据（可选）
      if ($project_data['cleanup_usage'] ?? false) {
        $this->usageTracker->resetUsage($project_id);
      }

      // 记录活动日志
      $this->recordProjectActivity($project_id, 'project_deleted', [
        'deleted_by' => $this->currentUser->id(),
        'project_name' => $project_data['name'] ?? null,
        'cleanup_usage' => $project_data['cleanup_usage'] ?? false,
      ]);
    } catch (\Exception $e) {
      $this->logger->error('Error handling project deleted event: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 处理成员添加事件。
   *
   * @param \Drupal\baas_project\Event\ProjectEvent $event
   *   项目事件。
   */
  public function onMemberAdded(ProjectEvent $event): void
  {
    try {
      $project_id = $event->getProjectId();
      $event_data = $event->getData();
      $user_id = $event_data['user_id'];
      $role = $event_data['role'];

      $this->logger->info('Member added to project @project_id: user @user_id with role @role', [
        '@project_id' => $project_id,
        '@user_id' => $user_id,
        '@role' => $role,
      ]);

      // 记录成员添加的使用统计
      $this->usageTracker->recordUsage(
        $project_id,
        'member_added',
        1,
        [
          'user_id' => $user_id,
          'role' => $role,
          'invited_by' => $event_data['invited_by'] ?? null,
        ]
      );

      // 发送欢迎邮件给新成员
      $this->sendMemberAddedNotification($project_id, $user_id, $role);

      // 记录活动日志
      $this->recordProjectActivity($project_id, 'member_added', [
        'user_id' => $user_id,
        'role' => $role,
        'invited_by' => $event_data['invited_by'] ?? $this->currentUser->id(),
      ]);
    } catch (\Exception $e) {
      $this->logger->error('Error handling member added event: @error', [
        '@error' => $e->getMessage(),
      ]);
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
    try {
      $project_id = $event->getProjectId();
      $event_data = $event->getData();
      $user_id = $event_data['user_id'];
      $previous_role = $event_data['previous_role'];

      $this->logger->info('Member removed from project @project_id: user @user_id (was @role)', [
        '@project_id' => $project_id,
        '@user_id' => $user_id,
        '@role' => $previous_role,
      ]);

      // 记录成员移除的使用统计
      $this->usageTracker->recordUsage(
        $project_id,
        'member_removed',
        1,
        [
          'user_id' => $user_id,
          'previous_role' => $previous_role,
          'removed_by' => $event_data['removed_by'] ?? null,
        ]
      );

      // 发送移除通知邮件
      $this->sendMemberRemovedNotification($project_id, $user_id, $previous_role);

      // 记录活动日志
      $this->recordProjectActivity($project_id, 'member_removed', [
        'user_id' => $user_id,
        'previous_role' => $previous_role,
        'removed_by' => $event_data['removed_by'] ?? $this->currentUser->id(),
      ]);
    } catch (\Exception $e) {
      $this->logger->error('Error handling member removed event: @error', [
        '@error' => $e->getMessage(),
      ]);
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
    try {
      $project_id = $event->getProjectId();
      $event_data = $event->getData();
      $user_id = $event_data['user_id'];
      $previous_role = $event_data['previous_role'];
      $new_role = $event_data['new_role'];

      $this->logger->info('Member role updated in project @project_id: user @user_id from @old_role to @new_role', [
        '@project_id' => $project_id,
        '@user_id' => $user_id,
        '@old_role' => $previous_role,
        '@new_role' => $new_role,
      ]);

      // 记录角色更新的使用统计
      $this->usageTracker->recordUsage(
        $project_id,
        'member_role_updated',
        1,
        [
          'user_id' => $user_id,
          'previous_role' => $previous_role,
          'new_role' => $new_role,
          'updated_by' => $event_data['updated_by'] ?? null,
        ]
      );

      // 发送角色更新通知邮件
      $this->sendMemberRoleUpdatedNotification($project_id, $user_id, $previous_role, $new_role);

      // 记录活动日志
      $this->recordProjectActivity($project_id, 'member_role_updated', [
        'user_id' => $user_id,
        'previous_role' => $previous_role,
        'new_role' => $new_role,
        'updated_by' => $event_data['updated_by'] ?? $this->currentUser->id(),
      ]);
    } catch (\Exception $e) {
      $this->logger->error('Error handling member role updated event: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 处理所有权转移事件。
   *
   * @param \Drupal\baas_project\Event\ProjectEvent $event
   *   项目事件。
   */
  public function onOwnershipTransferred(ProjectEvent $event): void
  {
    try {
      $project_id = $event->getProjectId();
      $event_data = $event->getData();
      $previous_owner_id = $event_data['previous_owner_id'];
      $new_owner_id = $event_data['new_owner_id'];

      $this->logger->info('Ownership transferred for project @project_id: from user @old_owner to user @new_owner', [
        '@project_id' => $project_id,
        '@old_owner' => $previous_owner_id,
        '@new_owner' => $new_owner_id,
      ]);

      // 记录所有权转移的使用统计
      $this->usageTracker->recordUsage(
        $project_id,
        'ownership_transferred',
        1,
        [
          'previous_owner_id' => $previous_owner_id,
          'new_owner_id' => $new_owner_id,
          'previous_owner_role' => $event_data['previous_owner_role'] ?? 'admin',
        ]
      );

      // 发送所有权转移通知邮件
      $this->sendOwnershipTransferredNotification($project_id, $previous_owner_id, $new_owner_id);

      // 记录活动日志
      $this->recordProjectActivity($project_id, 'ownership_transferred', [
        'previous_owner_id' => $previous_owner_id,
        'new_owner_id' => $new_owner_id,
        'previous_owner_role' => $event_data['previous_owner_role'] ?? 'admin',
      ]);
    } catch (\Exception $e) {
      $this->logger->error('Error handling ownership transferred event: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 处理使用警告事件。
   *
   * @param \Drupal\baas_project\Event\ProjectEvent $event
   *   项目事件。
   */
  public function onUsageAlert(ProjectEvent $event): void
  {
    try {
      $project_id = $event->getProjectId();
      $event_data = $event->getData();
      $alerts = $event_data['alerts'] ?? [];

      $this->logger->warning('Usage alerts triggered for project @project_id: @count alerts', [
        '@project_id' => $project_id,
        '@count' => count($alerts),
      ]);

      // 发送使用警告通知邮件
      $this->sendUsageAlertNotification($project_id, $alerts);

      // 记录活动日志
      $this->recordProjectActivity($project_id, 'usage_alert', [
        'alert_count' => count($alerts),
        'alerts' => $alerts,
        'resource_type' => $event_data['resource_type'] ?? null,
      ]);
    } catch (\Exception $e) {
      $this->logger->error('Error handling usage alert event: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 发送项目创建通知邮件。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $project_data
   *   项目数据。
   */
  protected function sendProjectCreatedNotification(string $project_id, array $project_data): void
  {
    try {
      $owner_uid = $project_data['owner_uid'] ?? null;
      if (!$owner_uid) {
        return;
      }

      /** @var \Drupal\user\UserInterface|null $owner */
      $owner = $this->entityTypeManager->getStorage('user')->load($owner_uid);
      if (!$owner || !$owner->isActive()) {
        return;
      }

      $params = [
        'project_id' => $project_id,
        'project_name' => $project_data['name'] ?? 'Unnamed Project',
        'project_description' => $project_data['description'] ?? '',
        'owner_name' => $owner->getDisplayName(),
      ];

      $this->mailManager->mail(
        'baas_project',
        'project_created',
        $owner->getEmail(),
        $owner->getPreferredLangcode(),
        $params
      );
    } catch (\Exception $e) {
      $this->logger->error('Failed to send project created notification: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 发送项目删除通知邮件。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $project_data
   *   项目数据。
   */
  protected function sendProjectDeletedNotification(string $project_id, array $project_data): void
  {
    try {
      // 获取所有项目成员
      $members = $this->memberManager->getMembers($project_id);

      foreach ($members as $member) {
        /** @var \Drupal\user\UserInterface|null $user */
        $user = $this->entityTypeManager->getStorage('user')->load($member['user_id']);
        if (!$user || !$user->isActive()) {
          continue;
        }

        $params = [
          'project_id' => $project_id,
          'project_name' => $project_data['name'] ?? 'Unknown Project',
          'member_name' => $user->getDisplayName(),
          'member_role' => $member['role'],
        ];

        $this->mailManager->mail(
          'baas_project',
          'project_deleted',
          $user->getEmail(),
          $user->getPreferredLangcode(),
          $params
        );
      }
    } catch (\Exception $e) {
      $this->logger->error('Failed to send project deleted notification: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 发送成员添加通知邮件。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $user_id
   *   用户ID。
   * @param string $role
   *   角色。
   */
  protected function sendMemberAddedNotification(string $project_id, int $user_id, string $role): void
  {
    try {
      /** @var \Drupal\user\UserInterface|null $user */
      $user = $this->entityTypeManager->getStorage('user')->load($user_id);
      if (!$user || !$user->isActive()) {
        return;
      }

      // 获取项目信息
      $project_info = $this->getProjectInfo($project_id);
      if (!$project_info) {
        return;
      }

      $params = [
        'project_id' => $project_id,
        'project_name' => $project_info['name'],
        'member_name' => $user->getDisplayName(),
        'role' => $role,
        'role_label' => $this->memberManager->getAvailableRoles()[$role]['label'] ?? $role,
      ];

      $this->mailManager->mail(
        'baas_project',
        'member_added',
        $user->getEmail(),
        $user->getPreferredLangcode(),
        $params
      );
    } catch (\Exception $e) {
      $this->logger->error('Failed to send member added notification: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 发送成员移除通知邮件。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $user_id
   *   用户ID。
   * @param string $previous_role
   *   之前的角色。
   */
  protected function sendMemberRemovedNotification(string $project_id, int $user_id, string $previous_role): void
  {
    try {
      /** @var \Drupal\user\UserInterface|null $user */
      $user = $this->entityTypeManager->getStorage('user')->load($user_id);
      if (!$user || !$user->isActive()) {
        return;
      }

      // 获取项目信息
      $project_info = $this->getProjectInfo($project_id);
      if (!$project_info) {
        return;
      }

      $params = [
        'project_id' => $project_id,
        'project_name' => $project_info['name'],
        'member_name' => $user->getDisplayName(),
        'previous_role' => $previous_role,
      ];

      $this->mailManager->mail(
        'baas_project',
        'member_removed',
        $user->getEmail(),
        $user->getPreferredLangcode(),
        $params
      );
    } catch (\Exception $e) {
      $this->logger->error('Failed to send member removed notification: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 发送成员角色更新通知邮件。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $user_id
   *   用户ID。
   * @param string $previous_role
   *   之前的角色。
   * @param string $new_role
   *   新角色。
   */
  protected function sendMemberRoleUpdatedNotification(string $project_id, int $user_id, string $previous_role, string $new_role): void
  {
    try {
      /** @var \Drupal\user\UserInterface|null $user */
      $user = $this->entityTypeManager->getStorage('user')->load($user_id);
      if (!$user || !$user->isActive()) {
        return;
      }

      // 获取项目信息
      $project_info = $this->getProjectInfo($project_id);
      if (!$project_info) {
        return;
      }

      $available_roles = $this->memberManager->getAvailableRoles();

      $params = [
        'project_id' => $project_id,
        'project_name' => $project_info['name'],
        'member_name' => $user->getDisplayName(),
        'previous_role' => $previous_role,
        'previous_role_label' => $available_roles[$previous_role]['label'] ?? $previous_role,
        'new_role' => $new_role,
        'new_role_label' => $available_roles[$new_role]['label'] ?? $new_role,
      ];

      $this->mailManager->mail(
        'baas_project',
        'member_role_updated',
        $user->getEmail(),
        $user->getPreferredLangcode(),
        $params
      );
    } catch (\Exception $e) {
      $this->logger->error('Failed to send member role updated notification: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 发送所有权转移通知邮件。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $previous_owner_id
   *   之前的所有者ID。
   * @param int $new_owner_id
   *   新所有者ID。
   */
  protected function sendOwnershipTransferredNotification(string $project_id, int $previous_owner_id, int $new_owner_id): void
  {
    try {
      /** @var \Drupal\user\UserInterface|null $previous_owner */
      $previous_owner = $this->entityTypeManager->getStorage('user')->load($previous_owner_id);
      /** @var \Drupal\user\UserInterface|null $new_owner */
      $new_owner = $this->entityTypeManager->getStorage('user')->load($new_owner_id);

      if (!$previous_owner || !$new_owner) {
        return;
      }

      // 获取项目信息
      $project_info = $this->getProjectInfo($project_id);
      if (!$project_info) {
        return;
      }

      $params = [
        'project_id' => $project_id,
        'project_name' => $project_info['name'],
        'previous_owner_name' => $previous_owner->getDisplayName(),
        'new_owner_name' => $new_owner->getDisplayName(),
      ];

      // 通知之前的所有者
      if ($previous_owner->isActive()) {
        $this->mailManager->mail(
          'baas_project',
          'ownership_transferred_previous',
          $previous_owner->getEmail(),
          $previous_owner->getPreferredLangcode(),
          $params
        );
      }

      // 通知新所有者
      if ($new_owner->isActive()) {
        $this->mailManager->mail(
          'baas_project',
          'ownership_transferred_new',
          $new_owner->getEmail(),
          $new_owner->getPreferredLangcode(),
          $params
        );
      }
    } catch (\Exception $e) {
      $this->logger->error('Failed to send ownership transferred notification: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 发送使用警告通知邮件。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $alerts
   *   警告信息。
   */
  protected function sendUsageAlertNotification(string $project_id, array $alerts): void
  {
    try {
      // 获取项目所有者和管理员
      $members = $this->memberManager->getMembers($project_id, ['role' => ['owner', 'admin']]);

      // 获取项目信息
      $project_info = $this->getProjectInfo($project_id);
      if (!$project_info) {
        return;
      }

      foreach ($members as $member) {
        /** @var \Drupal\user\UserInterface|null $user */
        $user = $this->entityTypeManager->getStorage('user')->load($member['user_id']);
        if (!$user || !$user->isActive()) {
          continue;
        }

        $params = [
          'project_id' => $project_id,
          'project_name' => $project_info['name'],
          'member_name' => $user->getDisplayName(),
          'alerts' => $alerts,
          'alert_count' => count($alerts),
        ];

        $this->mailManager->mail(
          'baas_project',
          'usage_alert',
          $user->getEmail(),
          $user->getPreferredLangcode(),
          $params
        );
      }
    } catch (\Exception $e) {
      $this->logger->error('Failed to send usage alert notification: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 记录项目活动日志。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $activity_type
   *   活动类型。
   * @param array $activity_data
   *   活动数据。
   */
  protected function recordProjectActivity(string $project_id, string $activity_type, array $activity_data): void
  {
    try {
      // 这里可以实现项目活动日志记录
      // 例如插入到专门的活动日志表中
      $this->logger->debug('Project activity recorded: @project_id - @type', [
        '@project_id' => $project_id,
        '@type' => $activity_type,
      ]);
    } catch (\Exception $e) {
      $this->logger->error('Failed to record project activity: @error', [
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * 获取项目信息。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array|null
   *   项目信息或NULL。
   */
  protected function getProjectInfo(string $project_id): ?array
  {
    try {
      $project = $this->database->select('baas_project_config', 'p')
        ->fields('p', ['name', 'description', 'tenant_id'])
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchAssoc();

      return $project ?: null;
    } catch (\Exception $e) {
      $this->logger->error('Failed to get project info for @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }
}