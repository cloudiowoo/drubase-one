<?php

declare(strict_types=1);

namespace Drupal\baas_project;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\baas_project\Event\ProjectEvent;
use Drupal\baas_project\Exception\ProjectException;

/**
 * 项目成员管理器实现.
 *
 * 提供完整的项目成员管理功能。
 */
class ProjectMemberManager implements ProjectMemberManagerInterface {

  /**
   * 日志记录器.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected readonly LoggerChannelInterface $logger;

  /**
   * 可用角色定义.
   */
  protected const AVAILABLE_ROLES = [
    'owner' => [
      'label' => 'Owner',
      'weight' => 100,
      'permissions' => ['view', 'edit', 'delete', 'manage_members', 'manage_settings', 'transfer_ownership'],
    ],
    'admin' => [
      'label' => 'Administrator',
      'weight' => 80,
      'permissions' => ['view', 'edit', 'manage_members', 'manage_settings'],
    ],
    'editor' => [
      'label' => 'Editor',
      'weight' => 60,
      'permissions' => ['view', 'edit'],
    ],
    'viewer' => [
      'label' => 'Viewer',
      'weight' => 40,
      'permissions' => ['view'],
    ],
  ];

  /**
   * 构造函数.
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly EventDispatcherInterface $eventDispatcher,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly AccountProxyInterface $currentUser,
    protected readonly CacheBackendInterface $cache,
  ) {
    $this->logger = $loggerFactory->get('baas_project_members');
  }

  /**
   * {@inheritdoc}
   */
  public function addMember(string $project_id, int $user_id, string $role, array $options = []): bool {
    try {
      // 验证角色.
      if (!$this->isValidRole($role)) {
        throw new ProjectException(
          "Invalid role: {$role}",
          ProjectException::INVALID_PROJECT_DATA,
          NULL,
          ['role' => $role]
        );
      }

      // 检查用户是否已是成员.
      if ($this->isMember($project_id, $user_id)) {
        throw new ProjectException(
          'User is already a project member',
          ProjectException::MEMBER_ALREADY_EXISTS,
          NULL,
          ['project_id' => $project_id, 'user_id' => $user_id]
        );
      }

      // 检查项目是否存在.
      if (!$this->projectExists($project_id)) {
        throw new ProjectException(
          'Project not found',
          ProjectException::PROJECT_NOT_FOUND,
          NULL,
          ['project_id' => $project_id]
        );
      }

      // 检查用户是否存在.
      $user_storage = $this->entityTypeManager->getStorage('user');
      $user = $user_storage->load($user_id);
      if (!$user) {
        throw new ProjectException(
          'User not found',
          ProjectException::USER_NOT_FOUND,
          NULL,
          ['user_id' => $user_id]
        );
      }

      // 开始事务.
      $transaction = $this->database->startTransaction();

      try {
        // 插入成员记录.
        $this->database->insert('baas_project_members')
          ->fields([
            'project_id' => $project_id,
            'user_id' => $user_id,
            'role' => $role,
            'status' => 1,
            'joined_at' => time(),
            'updated_at' => time(),
            'invited_by' => $options['invited_by'] ?? $this->currentUser->id(),
          ])
          ->execute();

        // 清除相关缓存.
        $this->clearMemberCache($project_id, $user_id);

        // 触发事件.
        $event = new ProjectEvent($project_id, [
          'user_id' => $user_id,
          'role' => $role,
          'invited_by' => $options['invited_by'] ?? $this->currentUser->id(),
        ]);
        $this->eventDispatcher->dispatch($event, ProjectEvent::MEMBER_ADDED);

        $this->logger->info('Added member @user_id to project @project_id with role @role', [
          '@user_id' => $user_id,
          '@project_id' => $project_id,
          '@role' => $role,
        ]);

        return TRUE;
      }
      catch (\Exception $e) {
        $transaction->rollBack();
        throw $e;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to add member @user_id to project @project_id: @error', [
        '@user_id' => $user_id,
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function removeMember(string $project_id, int $user_id, array $options = []): bool {
    try {
      // 检查用户是否为成员.
      if (!$this->isMember($project_id, $user_id)) {
        throw new ProjectException(
          'User is not a project member',
          ProjectException::MEMBER_NOT_FOUND,
          NULL,
          ['project_id' => $project_id, 'user_id' => $user_id]
        );
      }

      // 检查是否为项目所有者.
      $current_role = $this->getMemberRole($project_id, $user_id);
      if ($current_role === 'owner' && !($options['force_remove_owner'] ?? FALSE)) {
        throw new ProjectException(
          'Cannot remove project owner. Transfer ownership first.',
          ProjectException::CANNOT_REMOVE_OWNER,
          NULL,
          ['project_id' => $project_id, 'user_id' => $user_id]
        );
      }

      // 开始事务.
      $transaction = $this->database->startTransaction();

      try {
        // 删除成员记录.
        $affected_rows = $this->database->delete('baas_project_members')
          ->condition('project_id', $project_id)
          ->condition('user_id', $user_id)
          ->execute();

        if ($affected_rows === 0) {
          throw new ProjectException(
            'Failed to remove member',
            ProjectException::MEMBER_REMOVAL_FAILED,
            NULL,
            ['project_id' => $project_id, 'user_id' => $user_id]
          );
        }

        // 清除相关缓存.
        $this->clearMemberCache($project_id, $user_id);

        // 触发事件.
        $event = new ProjectEvent($project_id, [
          'user_id' => $user_id,
          'previous_role' => $current_role,
          'removed_by' => $this->currentUser->id(),
        ]);
        $this->eventDispatcher->dispatch($event, ProjectEvent::MEMBER_REMOVED);

        $this->logger->info('Removed member @user_id from project @project_id', [
          '@user_id' => $user_id,
          '@project_id' => $project_id,
        ]);

        return TRUE;
      }
      catch (\Exception $e) {
        $transaction->rollBack();
        throw $e;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to remove member @user_id from project @project_id: @error', [
        '@user_id' => $user_id,
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateMemberRole(string $project_id, int $user_id, string $new_role, array $options = []): bool {
    try {
      // 验证新角色.
      if (!$this->isValidRole($new_role)) {
        throw new ProjectException(
          "Invalid role: {$new_role}",
          ProjectException::INVALID_PROJECT_DATA,
          NULL,
          ['role' => $new_role]
        );
      }

      // 检查用户是否为成员.
      if (!$this->isMember($project_id, $user_id)) {
        throw new ProjectException(
          'User is not a project member',
          ProjectException::MEMBER_NOT_FOUND,
          NULL,
          ['project_id' => $project_id, 'user_id' => $user_id]
        );
      }

      $current_role = $this->getMemberRole($project_id, $user_id);

      // 检查是否尝试更改所有者角色.
      if ($current_role === 'owner' && $new_role !== 'owner') {
        throw new ProjectException(
          'Cannot change owner role. Use transferOwnership instead.',
          ProjectException::CANNOT_CHANGE_OWNER_ROLE,
          NULL,
          ['project_id' => $project_id, 'user_id' => $user_id]
        );
      }

      // 检查是否尝试设置为所有者.
      if ($new_role === 'owner' && $current_role !== 'owner') {
        throw new ProjectException(
          'Cannot set user as owner. Use transferOwnership instead.',
          ProjectException::CANNOT_SET_OWNER_ROLE,
          NULL,
          ['project_id' => $project_id, 'user_id' => $user_id]
        );
      }

      // 如果角色相同，无需更新.
      if ($current_role === $new_role) {
        return TRUE;
      }

      // 开始事务.
      $transaction = $this->database->startTransaction();

      try {
        // 更新成员角色.
        $affected_rows = $this->database->update('baas_project_members')
          ->fields([
            'role' => $new_role,
            'updated_at' => time(),
          ])
          ->condition('project_id', $project_id)
          ->condition('user_id', $user_id)
          ->execute();

        if ($affected_rows === 0) {
          throw new ProjectException(
            'Failed to update member role',
            ProjectException::MEMBER_UPDATE_FAILED,
            NULL,
            ['project_id' => $project_id, 'user_id' => $user_id]
          );
        }

        // 清除相关缓存.
        $this->clearMemberCache($project_id, $user_id);

        // 触发事件.
        $event = new ProjectEvent($project_id, [
          'user_id' => $user_id,
          'previous_role' => $current_role,
          'new_role' => $new_role,
          'updated_by' => $this->currentUser->id(),
        ]);
        $this->eventDispatcher->dispatch($event, ProjectEvent::MEMBER_ROLE_UPDATED);

        $this->logger->info('Updated member @user_id role from @old_role to @new_role in project @project_id', [
          '@user_id' => $user_id,
          '@old_role' => $current_role,
          '@new_role' => $new_role,
          '@project_id' => $project_id,
        ]);

        return TRUE;
      }
      catch (\Exception $e) {
        $transaction->rollBack();
        throw $e;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to update member role for @user_id in project @project_id: @error', [
        '@user_id' => $user_id,
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMembers(string $project_id, array $filters = [], array $options = []): array {
    try {
      $query = $this->database->select('baas_project_members', 'm')
        ->fields('m')
        ->condition('m.project_id', $project_id)
        ->condition('m.status', 1);

      // 添加用户信息.
      $query->leftJoin('users_field_data', 'u', 'm.user_id = u.uid');
      $query->addField('u', 'name', 'username');
      $query->addField('u', 'mail', 'email');
      $query->addField('u', 'created', 'user_created');
      $query->addField('u', 'access', 'user_access');
      $query->addField('u', 'status', 'user_status');

      // 应用过滤条件.
      if (!empty($filters['role'])) {
        if (is_array($filters['role'])) {
          $query->condition('m.role', $filters['role'], 'IN');
        }
        else {
          $query->condition('m.role', $filters['role']);
        }
      }

      if (!empty($filters['status'])) {
        $query->condition('m.status', $filters['status']);
      }

      if (!empty($filters['search'])) {
        $or = $query->orConditionGroup()
          ->condition('u.name', '%' . $filters['search'] . '%', 'LIKE')
          ->condition('u.mail', '%' . $filters['search'] . '%', 'LIKE');
        $query->condition($or);
      }

      // 排序.
      $sort_field = $options['sort'] ?? 'joined_at';
      $sort_direction = $options['direction'] ?? 'DESC';

      switch ($sort_field) {
        case 'role':
          // 按角色权重排序.
          $query->addExpression(
            "CASE m.role WHEN 'owner' THEN 100 WHEN 'admin' THEN 80 WHEN 'editor' THEN 60 WHEN 'viewer' THEN 40 ELSE 0 END",
            'role_weight'
          );
          $query->orderBy('role_weight', $sort_direction);
          break;

        case 'username':
          $query->orderBy('u.name', $sort_direction);
          break;

        case 'email':
          $query->orderBy('u.mail', $sort_direction);
          break;

        default:
          $query->orderBy('m.' . $sort_field, $sort_direction);
      }

      // 分页.
      if (!empty($options['limit'])) {
        $query->range($options['offset'] ?? 0, $options['limit']);
      }

      $results = $query->execute()->fetchAll();

      // 格式化结果.
      $members = [];
      foreach ($results as $row) {
        $members[] = [
          'user_id' => (int) $row->user_id,
          'username' => $row->username,
          'email' => $row->email,
          'role' => $row->role,
          'role_label' => self::AVAILABLE_ROLES[$row->role]['label'] ?? $row->role,
          'status' => (int) $row->status,
          'joined_at' => (int) $row->joined_at,
          'updated_at' => (int) $row->updated_at,
          'invited_by' => (int) $row->invited_by,
          'user_status' => (int) $row->user_status,
          'user_created' => (int) $row->user_created,
          'user_access' => (int) $row->user_access,
          'permissions' => $this->getRolePermissions($row->role),
        ];
      }

      return $members;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get members for project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMember(string $project_id, int $user_id): ?array {
    try {
      $cache_key = "baas_project:member:{$project_id}:{$user_id}";
      $cached = $this->cache->get($cache_key);

      if ($cached && $cached->valid) {
        return $cached->data;
      }

      $query = $this->database->select('baas_project_members', 'm')
        ->fields('m')
        ->condition('m.project_id', $project_id)
        ->condition('m.user_id', $user_id)
        ->condition('m.status', 1);

      // 添加用户信息.
      $query->leftJoin('users_field_data', 'u', 'm.user_id = u.uid');
      $query->addField('u', 'name', 'username');
      $query->addField('u', 'mail', 'email');
      $query->addField('u', 'created', 'user_created');
      $query->addField('u', 'access', 'user_access');
      $query->addField('u', 'status', 'user_status');

      $result = $query->execute()->fetchObject();

      if (!$result) {
        return NULL;
      }

      $member = [
        'user_id' => (int) $result->user_id,
        'username' => $result->username,
        'email' => $result->email,
        'role' => $result->role,
        'role_label' => self::AVAILABLE_ROLES[$result->role]['label'] ?? $result->role,
        'status' => (int) $result->status,
        'joined_at' => (int) $result->joined_at,
        'updated_at' => (int) $result->updated_at,
        'invited_by' => (int) $result->invited_by,
        'user_status' => (int) $result->user_status,
        'user_created' => (int) $result->user_created,
        'user_access' => (int) $result->user_access,
        'permissions' => $this->getRolePermissions($result->role),
      ];

      // 缓存30分钟.
      $this->cache->set($cache_key, $member, time() + 1800);
      return $member;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get member @user_id for project @project_id: @error', [
        '@user_id' => $user_id,
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isMember(string $project_id, string|int $user_id): bool {
    // 确保 user_id 是整数
    $user_id = (int) $user_id;
    try {
      $cache_key = "baas_project:is_member:{$project_id}:{$user_id}";
      $cached = $this->cache->get($cache_key);

      if ($cached && $cached->valid) {
        return $cached->data;
      }

      $is_member = (bool) $this->database->select('baas_project_members', 'm')
        ->condition('project_id', $project_id)
        ->condition('user_id', $user_id)
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      // 缓存15分钟.
      $this->cache->set($cache_key, $is_member, time() + 900);
      return $is_member;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to check membership for @user_id in project @project_id: @error', [
        '@user_id' => $user_id,
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMemberRole(string $project_id, string|int $user_id): ?string {
    // 确保 user_id 是整数
    $user_id = (int) $user_id;
    try {
      $cache_key = "baas_project:member_role:{$project_id}:{$user_id}";
      $cached = $this->cache->get($cache_key);

      if ($cached && $cached->valid) {
        return $cached->data;
      }

      $role = $this->database->select('baas_project_members', 'm')
        ->fields('m', ['role'])
        ->condition('project_id', $project_id)
        ->condition('user_id', $user_id)
        ->condition('status', 1)
        ->execute()
        ->fetchField();

      $role = $role ?: NULL;
      // 缓存30分钟.
      $this->cache->set($cache_key, $role, time() + 1800);
      return $role;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get member role for @user_id in project @project_id: @error', [
        '@user_id' => $user_id,
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function transferOwnership(string $project_id, int $current_owner_id, int $new_owner_id, array $options = []): bool {
    try {
      // 验证当前所有者.
      $current_role = $this->getMemberRole($project_id, $current_owner_id);
      if ($current_role !== 'owner') {
        throw new ProjectException(
          'Current user is not the project owner',
          ProjectException::NOT_PROJECT_OWNER,
          NULL,
          ['project_id' => $project_id, 'user_id' => $current_owner_id]
        );
      }

      // 验证新所有者是项目成员.
      if (!$this->isMember($project_id, $new_owner_id)) {
        throw new ProjectException(
          'New owner must be a project member',
          ProjectException::MEMBER_NOT_FOUND,
          NULL,
          ['project_id' => $project_id, 'user_id' => $new_owner_id]
        );
      }

      // 检查新所有者用户是否存在且活跃.
      $user_storage = $this->entityTypeManager->getStorage('user');
      $new_owner = $user_storage->load($new_owner_id);
      if (!$new_owner || $new_owner->status->value != 1) {
        throw new ProjectException(
          'New owner user not found or inactive',
          ProjectException::USER_NOT_FOUND,
          NULL,
          ['user_id' => $new_owner_id]
        );
      }

      // 开始事务.
      $transaction = $this->database->startTransaction();

      try {
        // 更新当前所有者为管理员.
        $this->database->update('baas_project_members')
          ->fields([
            'role' => $options['previous_owner_role'] ?? 'admin',
            'updated_at' => time(),
          ])
          ->condition('project_id', $project_id)
          ->condition('user_id', $current_owner_id)
          ->execute();

        // 更新新所有者角色.
        $this->database->update('baas_project_members')
          ->fields([
            'role' => 'owner',
            'updated_at' => time(),
          ])
          ->condition('project_id', $project_id)
          ->condition('user_id', $new_owner_id)
          ->execute();

        // 更新项目配置中的所有者.
        $this->database->update('baas_project_config')
          ->fields([
            'owner_uid' => $new_owner_id,
            'updated' => time(),
          ])
          ->condition('project_id', $project_id)
          ->execute();

        // 清除相关缓存.
        $this->clearMemberCache($project_id, $current_owner_id);
        $this->clearMemberCache($project_id, $new_owner_id);
        $this->clearProjectCache($project_id);

        // 触发事件.
        $event = new ProjectEvent($project_id, [
          'previous_owner_id' => $current_owner_id,
          'new_owner_id' => $new_owner_id,
          'previous_owner_role' => $options['previous_owner_role'] ?? 'admin',
        ]);
        $this->eventDispatcher->dispatch($event, ProjectEvent::OWNERSHIP_TRANSFERRED);

        $this->logger->info('Transferred ownership of project @project_id from @old_owner to @new_owner', [
          '@project_id' => $project_id,
          '@old_owner' => $current_owner_id,
          '@new_owner' => $new_owner_id,
        ]);

        return TRUE;
      }
      catch (\Exception $e) {
        $transaction->rollBack();
        throw $e;
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to transfer ownership of project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function addMembers(string $project_id, array $members, array $options = []): array {
    $results = [
      'success' => [],
      'failed' => [],
      'total' => count($members),
    ];

    foreach ($members as $member_data) {
      try {
        $user_id = $member_data['user_id'];
        $role = $member_data['role'];

        $this->addMember($project_id, $user_id, $role, $options);
        $results['success'][] = $user_id;
      }
      catch (\Exception $e) {
        $results['failed'][] = [
          'user_id' => $member_data['user_id'] ?? NULL,
          'error' => $e->getMessage(),
        ];
      }
    }

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function removeMembers(string $project_id, array $user_ids, array $options = []): array {
    $results = [
      'success' => [],
      'failed' => [],
      'total' => count($user_ids),
    ];

    foreach ($user_ids as $user_id) {
      try {
        $this->removeMember($project_id, $user_id, $options);
        $results['success'][] = $user_id;
      }
      catch (\Exception $e) {
        $results['failed'][] = [
          'user_id' => $user_id,
          'error' => $e->getMessage(),
        ];
      }
    }

    return $results;
  }

  /**
   * {@inheritdoc}
   */
  public function getUserProjects(int $user_id, array $filters = [], array $options = []): array {
    try {
      $query = $this->database->select('baas_project_members', 'm')
        ->fields('m', ['project_id', 'role', 'joined_at'])
        ->condition('m.user_id', $user_id)
        ->condition('m.status', 1);

      // 添加项目信息.
      $query->leftJoin('baas_project_config', 'p', 'm.project_id = p.project_id');
      $query->addField('p', 'name', 'project_name');
      $query->addField('p', 'machine_name');
      $query->addField('p', 'description');
      $query->addField('p', 'tenant_id');
      $query->addField('p', 'status', 'project_status');
      $query->addField('p', 'created', 'project_created');
      $query->addField('p', 'updated', 'project_updated');

      // 应用过滤条件.
      if (!empty($filters['tenant_id'])) {
        $query->condition('p.tenant_id', $filters['tenant_id']);
      }

      if (!empty($filters['role'])) {
        if (is_array($filters['role'])) {
          $query->condition('m.role', $filters['role'], 'IN');
        }
        else {
          $query->condition('m.role', $filters['role']);
        }
      }

      if (!empty($filters['status'])) {
        $query->condition('p.status', $filters['status']);
      }

      // 排序.
      $sort_field = $options['sort'] ?? 'joined_at';
      $sort_direction = $options['direction'] ?? 'DESC';
      $query->orderBy($sort_field, $sort_direction);

      // 分页.
      if (!empty($options['limit'])) {
        $query->range($options['offset'] ?? 0, $options['limit']);
      }

      $results = $query->execute()->fetchAll();

      // 格式化结果.
      $projects = [];
      foreach ($results as $row) {
        $projects[] = [
          'project_id' => $row->project_id,
          'project_name' => $row->project_name,
          'machine_name' => $row->machine_name,
          'description' => $row->description,
          'tenant_id' => $row->tenant_id,
          'role' => $row->role,
          'role_label' => self::AVAILABLE_ROLES[$row->role]['label'] ?? $row->role,
          'joined_at' => (int) $row->joined_at,
          'project_status' => (int) $row->project_status,
          'project_created' => (int) $row->project_created,
          'project_updated' => (int) $row->project_updated,
          'permissions' => $this->getRolePermissions($row->role),
        ];
      }

      return $projects;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get projects for user @user_id: @error', [
        '@user_id' => $user_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getMemberStats(string $project_id): array {
    try {
      $stats = [
        'total_members' => 0,
        'by_role' => [],
        'recent_joins' => 0,
        'active_members' => 0,
      ];

      // 总成员数和按角色统计.
      $query = $this->database->select('baas_project_members', 'm')
        ->fields('m', ['role'])
        ->condition('project_id', $project_id)
        ->condition('status', 1);

      $query->addExpression('COUNT(*)', 'count');
      $query->groupBy('role');

      $results = $query->execute()->fetchAll();

      foreach ($results as $row) {
        $stats['by_role'][$row->role] = (int) $row->count;
        $stats['total_members'] += (int) $row->count;
      }

      // 最近30天加入的成员.
      $recent_threshold = time() - (30 * 24 * 60 * 60);
      $stats['recent_joins'] = (int) $this->database->select('baas_project_members', 'm')
        ->condition('project_id', $project_id)
        ->condition('status', 1)
        ->condition('joined_at', $recent_threshold, '>=')
        ->countQuery()
        ->execute()
        ->fetchField();

      // 活跃成员（最近30天有登录的）.
      $query = $this->database->select('baas_project_members', 'm')
        ->condition('m.project_id', $project_id)
        ->condition('m.status', 1);

      $query->leftJoin('users_field_data', 'u', 'm.user_id = u.uid');
      $query->condition('u.access', $recent_threshold, '>=');

      $stats['active_members'] = (int) $query->countQuery()->execute()->fetchField();

      return $stats;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to get member stats for project @project_id: @error', [
        '@project_id' => $project_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isValidRole(string $role): bool {
    return array_key_exists($role, self::AVAILABLE_ROLES);
  }

  /**
   * {@inheritdoc}
   */
  public function getAvailableRoles(): array {
    return self::AVAILABLE_ROLES;
  }

  /**
   * {@inheritdoc}
   */
  public function hasRolePermission(string $role, string $permission): bool {
    $permissions = $this->getRolePermissions($role);
    return in_array($permission, $permissions, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getRolePermissions(string $role): array {
    return self::AVAILABLE_ROLES[$role]['permissions'] ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function inviteUser(string $project_id, string $email, string $role, int $inviter_id, array $options = []): array {
    // 邀请功能的实现将在后续版本中添加.
    throw new ProjectException(
      'User invitation feature not yet implemented',
      ProjectException::FEATURE_NOT_IMPLEMENTED,
      NULL
    );
  }

  /**
   * {@inheritdoc}
   */
  public function acceptInvitation(string $invitation_token, int $user_id, array $options = []): bool {
    // 邀请功能的实现将在后续版本中添加.
    throw new ProjectException(
      'User invitation feature not yet implemented',
      ProjectException::FEATURE_NOT_IMPLEMENTED,
      NULL
    );
  }

  /**
   * {@inheritdoc}
   */
  public function rejectInvitation(string $invitation_token, array $options = []): bool {
    // 邀请功能的实现将在后续版本中添加.
    throw new ProjectException(
      'User invitation feature not yet implemented',
      ProjectException::FEATURE_NOT_IMPLEMENTED,
      NULL
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getInvitations(string $project_id, array $filters = [], array $options = []): array {
    // 邀请功能的实现将在后续版本中添加.
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function revokeInvitation(string $invitation_token, array $options = []): bool {
    // 邀请功能的实现将在后续版本中添加.
    throw new ProjectException(
      'User invitation feature not yet implemented',
      ProjectException::FEATURE_NOT_IMPLEMENTED,
      NULL
    );
  }

  /**
   * 检查项目是否存在.
   *
   * @param string $project_id
   *   项目ID.
   *
   * @return bool
   *   项目是否存在.
   */
  protected function projectExists(string $project_id): bool {
    try {
      return (bool) $this->database->select('baas_project_config', 'p')
        ->condition('project_id', $project_id)
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * 清除成员相关缓存.
   *
   * @param string $project_id
   *   项目ID.
   * @param int $user_id
   *   用户ID.
   */
  protected function clearMemberCache(string $project_id, int $user_id): void {
    $cache_keys = [
      "baas_project:member:{$project_id}:{$user_id}",
      "baas_project:is_member:{$project_id}:{$user_id}",
      "baas_project:member_role:{$project_id}:{$user_id}",
    ];

    foreach ($cache_keys as $key) {
      $this->cache->delete($key);
    }
  }

  /**
   * 清除项目相关缓存.
   *
   * @param string $project_id
   *   项目ID.
   */
  protected function clearProjectCache(string $project_id): void {
    $cache_keys = [
      "baas_project:data:{$project_id}",
      "baas_project:exists:{$project_id}",
      "baas_project:tenant_id:{$project_id}",
    ];

    foreach ($cache_keys as $key) {
      $this->cache->delete($key);
    }
  }

}
