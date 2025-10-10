<?php

declare(strict_types=1);

namespace Drupal\baas_project;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Session\AccountInterface;
// use Drupal\baas_auth\UnifiedPermissionCheckerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Drupal\baas_project\Event\ProjectEvent;
use Drupal\baas_project\Exception\ProjectException;

/**
 * 项目管理服务实现。
 */
class ProjectManager implements ProjectManagerInterface
{

  protected readonly LoggerChannelInterface $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly ConfigFactoryInterface $configFactory,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly EventDispatcherInterface $eventDispatcher,
    protected readonly CacheBackendInterface $cache,
    protected readonly AccountInterface $currentUser,
  ) {
    $this->logger = $loggerFactory->get('baas_project');
  }

  /**
   * {@inheritdoc}
   */
  public function createProject(string $tenant_id, array $project_data): string|false
  {
    try {
      // 验证必需字段
      if (empty($project_data['name']) || empty($project_data['machine_name'])) {
        throw new ProjectException('Project name and machine_name are required.');
      }

      // 验证机器名可用性
      if (!$this->isMachineNameAvailable($tenant_id, $project_data['machine_name'])) {
        throw new ProjectException('Machine name already exists in this tenant.');
      }

      // 生成项目ID
      $project_id = $this->generateProjectId($tenant_id);

      // 准备项目数据
      $project_record = [
        'project_id' => $project_id,
        'tenant_id' => $tenant_id,
        'name' => $project_data['name'],
        'machine_name' => $project_data['machine_name'],
        'description' => $project_data['description'] ?? '',
        'status' => $project_data['status'] ?? 1,
        'settings' => json_encode($project_data['settings'] ?? []),
        'owner_uid' => $project_data['owner_uid'] ?? $this->currentUser->id(),
        'created' => time(),
        'updated' => time(),
      ];

      // 开始事务
      $transaction = $this->database->startTransaction();

      try {
        // 插入项目记录
        $this->database->insert('baas_project_config')
          ->fields($project_record)
          ->execute();

        // 添加所有者为项目成员
        $this->database->insert('baas_project_members')
          ->fields([
            'project_id' => $project_id,
            'user_id' => $project_record['owner_uid'],
            'role' => 'owner',
            'status' => 1,
            'joined_at' => time(),
            'updated_at' => time(),
          ])
          ->execute();

        // 触发项目创建事件
        $event = new ProjectEvent($project_id, $project_record);
        $this->eventDispatcher->dispatch($event, ProjectEvent::PROJECT_CREATED);

        // 清理缓存
        $this->clearProjectCache($tenant_id, $project_id);

        $this->logger->info('Project created: @project_id for tenant: @tenant_id', [
          '@project_id' => $project_id,
          '@tenant_id' => $tenant_id,
        ]);

        return $project_id;
      } catch (\Exception $e) {
        $transaction->rollBack();
        throw $e;
      }
    } catch (\Exception $e) {
      $this->logger->error('Failed to create project: @message', ['@message' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getProject(string $project_id): array|false
  {
    $cache_key = "baas_project:project:{$project_id}";

    // 尝试从缓存获取
    if ($cached = $this->cache->get($cache_key)) {
      return $cached->data;
    }

    try {
      $query = $this->database->select('baas_project_config', 'p')
        ->fields('p')
        ->condition('project_id', $project_id);

      $result = $query->execute()->fetchAssoc();

      if ($result) {
        // 解析JSON字段
        $result['settings'] = json_decode($result['settings'] ?? '{}', true);

        // 缓存结果
        $this->cache->set($cache_key, $result, time() + 3600);

        return $result;
      }

      return false;
    } catch (\Exception $e) {
      $this->logger->error('Failed to get project @project_id: @message', [
        '@project_id' => $project_id,
        '@message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateProject(string $project_id, array $data): bool
  {
    try {
      // 获取当前项目信息
      $current_project = $this->getProject($project_id);
      if (!$current_project) {
        throw new ProjectException('Project not found.');
      }

      // 验证机器名可用性（如果要更新机器名）
      if (isset($data['machine_name']) && $data['machine_name'] !== $current_project['machine_name']) {
        if (!$this->isMachineNameAvailable($current_project['tenant_id'], $data['machine_name'], $project_id)) {
          throw new ProjectException('Machine name already exists in this tenant.');
        }
      }

      // 准备更新数据
      $update_data = [];
      $allowed_fields = ['name', 'machine_name', 'description', 'status', 'settings'];

      foreach ($allowed_fields as $field) {
        if (isset($data[$field])) {
          if ($field === 'settings') {
            $update_data[$field] = json_encode($data[$field]);
          } else {
            $update_data[$field] = $data[$field];
          }
        }
      }

      $update_data['updated'] = time();

      // 执行更新
      $affected_rows = $this->database->update('baas_project_config')
        ->fields($update_data)
        ->condition('project_id', $project_id)
        ->execute();

      if ($affected_rows > 0) {
        // 触发项目更新事件
        $event = new ProjectEvent($project_id, array_merge($current_project, $update_data));
        $this->eventDispatcher->dispatch($event, ProjectEvent::PROJECT_UPDATED);

        // 清理缓存
        $this->clearProjectCache($current_project['tenant_id'], $project_id);

        $this->logger->info('Project updated: @project_id', ['@project_id' => $project_id]);
        return true;
      }

      return false;
    } catch (\Exception $e) {
      $this->logger->error('Failed to update project @project_id: @message', [
        '@project_id' => $project_id,
        '@message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteProject(string $project_id): bool
  {
    try {
      // 获取项目信息
      $project = $this->getProject($project_id);
      if (!$project) {
        throw new ProjectException('Project not found.');
      }

      // 检查是否有关联的实体模板
      $entity_count = $this->database->select('baas_entity_template', 'e')
        ->condition('project_id', $project_id)
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($entity_count > 0) {
        throw new ProjectException('Cannot delete project with existing entity templates.');
      }

      // 开始事务
      $transaction = $this->database->startTransaction();

      try {
        // 删除项目成员
        $this->database->delete('baas_project_members')
          ->condition('project_id', $project_id)
          ->execute();

        // 删除使用统计
        $this->database->delete('baas_project_usage')
          ->condition('project_id', $project_id)
          ->execute();

        // 删除项目配置
        $affected_rows = $this->database->delete('baas_project_config')
          ->condition('project_id', $project_id)
          ->execute();

        if ($affected_rows > 0) {
          // 触发项目删除事件
          $event = new ProjectEvent($project_id, $project);
          $this->eventDispatcher->dispatch($event, ProjectEvent::PROJECT_DELETED);

          // 清理缓存
          $this->clearProjectCache($project['tenant_id'], $project_id);

          $this->logger->info('Project deleted: @project_id', ['@project_id' => $project_id]);
          return true;
        }

        return false;
      } catch (\Exception $e) {
        $transaction->rollBack();
        throw $e;
      }
    } catch (\Exception $e) {
      $this->logger->error('Failed to delete project @project_id: @message', [
        '@project_id' => $project_id,
        '@message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function listTenantProjects(string $tenant_id, array $filters = []): array
  {
    try {
      $query = $this->database->select('baas_project_config', 'p')
        ->fields('p')
        ->condition('tenant_id', $tenant_id);

      // 应用过滤器
      if (isset($filters['status'])) {
        $query->condition('status', $filters['status']);
      }

      if (isset($filters['owner_uid'])) {
        $query->condition('owner_uid', $filters['owner_uid']);
      }

      // 排序
      $sort_field = $filters['sort'] ?? 'created';
      $sort_direction = $filters['direction'] ?? 'DESC';
      $query->orderBy($sort_field, $sort_direction);

      // 分页
      if (isset($filters['limit'])) {
        $query->range($filters['offset'] ?? 0, $filters['limit']);
      }

      $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

      // 处理JSON字段
      foreach ($results as &$project) {
        $project['settings'] = json_decode($project['settings'] ?? '{}', true);
      }

      return $results;
    } catch (\Exception $e) {
      $this->logger->error('Failed to list projects for tenant @tenant_id: @message', [
        '@tenant_id' => $tenant_id,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectByMachineName(string $tenant_id, string $machine_name): array|false
  {
    try {
      $query = $this->database->select('baas_project_config', 'p')
        ->fields('p')
        ->condition('tenant_id', $tenant_id)
        ->condition('machine_name', $machine_name);

      $result = $query->execute()->fetchAssoc();

      if ($result) {
        // 处理JSON字段
        $result['settings'] = json_decode($result['settings'] ?? '{}', true);
        return $result;
      }

      return false;
    } catch (\Exception $e) {
      $this->logger->error('Failed to get project by machine name @machine_name in tenant @tenant_id: @message', [
        '@machine_name' => $machine_name,
        '@tenant_id' => $tenant_id,
        '@message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function addProjectMember(string $project_id, int $user_id, string $role): bool
  {
    try {
      // 检查用户是否已经是成员
      if ($this->isProjectMember($project_id, $user_id)) {
        throw new ProjectException('User is already a project member.');
      }

      // 插入成员记录
      $affected_rows = $this->database->insert('baas_project_members')
        ->fields([
          'project_id' => $project_id,
          'user_id' => $user_id,
          'role' => $role,
          'status' => 1,
          'invited_by' => $this->currentUser->id(),
          'joined_at' => time(),
          'updated_at' => time(),
        ])
        ->execute();

      if ($affected_rows > 0) {
        // 触发成员添加事件
        $event = new ProjectEvent($project_id, ['user_id' => $user_id, 'role' => $role]);
        $this->eventDispatcher->dispatch($event, ProjectEvent::MEMBER_ADDED);

        // 清理缓存
        $project = $this->getProject($project_id);
        if ($project) {
          $this->clearProjectCache($project['tenant_id'], $project_id);
        }

        // 权限映射现在由ProjectPermissionSubscriber事件监听器处理
        $this->ensureUserHasViewPermission($user_id, $role);

        $this->logger->info('Added member @user_id to project @project_id with role @role', [
          '@user_id' => $user_id,
          '@project_id' => $project_id,
          '@role' => $role,
        ]);
        return true;
      }

      return false;
    } catch (\Exception $e) {
      $this->logger->error('Failed to add member to project @project_id: @message', [
        '@project_id' => $project_id,
        '@message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function removeProjectMember(string $project_id, int $user_id): bool
  {
    try {
      // 检查是否为项目所有者
      $project = $this->getProject($project_id);
      if ($project && $project['owner_uid'] == $user_id) {
        throw new ProjectException('Cannot remove project owner. Transfer ownership first.');
      }

      // 获取成员当前角色，用于事件通知
      $current_role = $this->getUserProjectRole($project_id, $user_id);
      if (!$current_role) {
        $current_role = 'member'; // 默认角色
      }

      // 删除成员记录
      $affected_rows = $this->database->delete('baas_project_members')
        ->condition('project_id', $project_id)
        ->condition('user_id', $user_id)
        ->execute();

      if ($affected_rows > 0) {
        // 触发成员移除事件
        $event = new ProjectEvent($project_id, [
          'user_id' => $user_id,
          'previous_role' => $current_role,
          'removed_by' => $this->currentUser->id(),
        ]);
        $this->eventDispatcher->dispatch($event, ProjectEvent::MEMBER_REMOVED);

        // 清理缓存
        if ($project) {
          $this->clearProjectCache($project['tenant_id'], $project_id);
        }

        $this->logger->info('Removed member @user_id from project @project_id', [
          '@user_id' => $user_id,
          '@project_id' => $project_id,
        ]);
        return true;
      }

      return false;
    } catch (\Exception $e) {
      $this->logger->error('Failed to remove member from project @project_id: @message', [
        '@project_id' => $project_id,
        '@message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateMemberRole(string $project_id, int $user_id, string $role): bool
  {
    try {
      // 获取用户当前角色，用于事件通知
      $previous_role = $this->getUserProjectRole($project_id, $user_id);
      if (!$previous_role) {
        throw new ProjectException('User is not a project member.');
      }

      // 如果角色没有变化，直接返回成功
      if ($previous_role === $role) {
        return true;
      }

      // 更新成员角色
      $affected_rows = $this->database->update('baas_project_members')
        ->fields([
          'role' => $role,
          'updated_at' => time(),
        ])
        ->condition('project_id', $project_id)
        ->condition('user_id', $user_id)
        ->execute();

      if ($affected_rows > 0) {
        // 触发角色更新事件
        $event = new ProjectEvent($project_id, [
          'user_id' => $user_id,
          'previous_role' => $previous_role,
          'new_role' => $role,
          'updated_by' => $this->currentUser->id(),
        ]);
        $this->eventDispatcher->dispatch($event, ProjectEvent::MEMBER_ROLE_UPDATED);

        // 清理缓存
        $project = $this->getProject($project_id);
        if ($project) {
          $this->clearProjectCache($project['tenant_id'], $project_id);
        }

        $this->logger->info('Updated member @user_id role to @role in project @project_id', [
          '@user_id' => $user_id,
          '@role' => $role,
          '@project_id' => $project_id,
        ]);
        return true;
      }

      return false;
    } catch (\Exception $e) {
      $this->logger->error('Failed to update member role in project @project_id: @message', [
        '@project_id' => $project_id,
        '@message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectMembers(string $project_id): array
  {
    try {
      $query = $this->database->select('baas_project_members', 'm');
      $query->leftJoin('users_field_data', 'u', 'm.user_id = u.uid');
      $query->fields('m', ['user_id', 'role', 'permissions', 'status', 'joined_at'])
        ->fields('u', ['name', 'mail']);
      $query->condition('m.project_id', $project_id)
        ->condition('m.status', 1)
        ->orderBy('m.joined_at', 'ASC');

      $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

      // 处理用户信息，确保有合适的显示名称
      foreach ($results as &$member) {
        $member['display_name'] = $member['name'] ?: $member['mail'] ?: 'User ' . $member['user_id'];
        $member['email'] = $member['mail'] ?: '';
      }

      return $results;
    } catch (\Exception $e) {
      $this->logger->error('Failed to get project members for @project_id: @message', [
        '@project_id' => $project_id,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function transferOwnership(string $project_id, int $new_owner_uid): bool
  {
    try {
      // 获取项目信息
      $project = $this->getProject($project_id);
      if (!$project) {
        throw new ProjectException('Project not found.');
      }

      // 检查新所有者是否为项目成员
      if (!$this->isProjectMember($project_id, $new_owner_uid)) {
        throw new ProjectException('New owner must be a project member.');
      }

      // 开始事务
      $transaction = $this->database->startTransaction();

      try {
        // 更新项目所有者
        $this->database->update('baas_project_config')
          ->fields([
            'owner_uid' => $new_owner_uid,
            'updated' => time(),
          ])
          ->condition('project_id', $project_id)
          ->execute();

        // 更新新所有者的角色为owner
        $this->database->update('baas_project_members')
          ->fields([
            'role' => 'owner',
            'updated_at' => time(),
          ])
          ->condition('project_id', $project_id)
          ->condition('user_id', $new_owner_uid)
          ->execute();

        // 将原所有者角色改为admin
        $this->database->update('baas_project_members')
          ->fields([
            'role' => 'admin',
            'updated_at' => time(),
          ])
          ->condition('project_id', $project_id)
          ->condition('user_id', $project['owner_uid'])
          ->execute();

        // 触发所有权转移事件
        $event = new ProjectEvent($project_id, [
          'old_owner_uid' => $project['owner_uid'],
          'new_owner_uid' => $new_owner_uid,
        ]);
        $this->eventDispatcher->dispatch($event, ProjectEvent::OWNERSHIP_TRANSFERRED);

        // 清理缓存
        $this->clearProjectCache($project['tenant_id'], $project_id);

        $this->logger->info('Transferred ownership of project @project_id from @old_owner to @new_owner', [
          '@project_id' => $project_id,
          '@old_owner' => $project['owner_uid'],
          '@new_owner' => $new_owner_uid,
        ]);

        return true;
      } catch (\Exception $e) {
        $transaction->rollBack();
        throw $e;
      }
    } catch (\Exception $e) {
      $this->logger->error('Failed to transfer ownership of project @project_id: @message', [
        '@project_id' => $project_id,
        '@message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function projectExists(string $project_id): bool
  {
    try {
      $count = $this->database->select('baas_project_config', 'p')
        ->condition('project_id', $project_id)
        ->countQuery()
        ->execute()
        ->fetchField();

      return $count > 0;
    } catch (\Exception $e) {
      $this->logger->error('Failed to check if project exists @project_id: @message', [
        '@project_id' => $project_id,
        '@message' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isMachineNameAvailable(string $tenant_id, string $machine_name, ?string $exclude_project_id = null): bool
  {
    try {
      $query = $this->database->select('baas_project_config', 'p')
        ->condition('tenant_id', $tenant_id)
        ->condition('machine_name', $machine_name);

      if ($exclude_project_id) {
        $query->condition('project_id', $exclude_project_id, '!=');
      }

      $count = $query->countQuery()->execute()->fetchField();

      return $count == 0;
    } catch (\Exception $e) {
      $this->logger->error('Failed to check machine name availability: @message', ['@message' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function generateProjectId(string $tenant_id): string
  {
    return $tenant_id . '_project_' . uniqid();
  }

  /**
   * {@inheritdoc}
   */
  public function getProjectStats(string $project_id): array
  {
    try {
      $stats = [];

      // 成员数量
      $stats['member_count'] = $this->database->select('baas_project_members', 'm')
        ->condition('project_id', $project_id)
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      // 实体模板数量
      $stats['entity_template_count'] = $this->database->select('baas_entity_template', 'e')
        ->condition('project_id', $project_id)
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      // 最近使用统计
      $usage_query = $this->database->select('baas_project_usage', 'u')
        ->fields('u', ['resource_type', 'usage_count', 'usage_size'])
        ->condition('project_id', $project_id)
        ->condition('period_start', strtotime('-30 days'), '>=')
        ->orderBy('period_start', 'DESC')
        ->range(0, 10);

      $stats['recent_usage'] = $usage_query->execute()->fetchAll(\PDO::FETCH_ASSOC);

      return $stats;
    } catch (\Exception $e) {
      $this->logger->error('Failed to get project stats for @project_id: @message', [
        '@project_id' => $project_id,
        '@message' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isProjectMember(string $project_id, int $user_id): bool
  {
    try {
      $count = $this->database->select('baas_project_members', 'm')
        ->condition('project_id', $project_id)
        ->condition('user_id', $user_id)
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      return $count > 0;
    } catch (\Exception $e) {
      $this->logger->error('Failed to check project membership: @message', ['@message' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUserProjectRole(string $project_id, int $user_id): string|false
  {
    try {
      $role = $this->database->select('baas_project_members', 'm')
        ->fields('m', ['role'])
        ->condition('project_id', $project_id)
        ->condition('user_id', $user_id)
        ->condition('status', 1)
        ->execute()
        ->fetchField();

      return $role ?: false;
    } catch (\Exception $e) {
      $this->logger->error('Failed to get user project role: @message', ['@message' => $e->getMessage()]);
      return false;
    }
  }

  /**
   * 清理项目相关缓存。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   */
  protected function clearProjectCache(string $tenant_id, string $project_id): void
  {
    $cache_tags = [
      "baas_project:project:{$project_id}",
      "baas_project:tenant:{$tenant_id}",
      "baas_project:members:{$project_id}",
    ];

    foreach ($cache_tags as $tag) {
      $this->cache->delete($tag);
    }
  }

  /**
   * 根据项目角色确保用户拥有相应的系统权限.
   *
   * @deprecated 现在由ProjectPermissionSubscriber事件监听器处理权限映射
   * @param int $user_id
   *   用户ID.
   * @param string $project_role
   *   项目角色 (member, editor, admin 或 owner).
   */
  protected function ensureUserHasViewPermission(int $user_id, string $project_role = 'member'): void
  {
    // 权限映射现在由ProjectPermissionSubscriber事件监听器自动处理
    // 这个方法保留是为了向后兼容，但实际权限分配由事件系统处理
    $this->logger->debug('权限映射由事件监听器处理 - 用户: @user_id, 角色: @role', [
      '@user_id' => $user_id,
      '@role' => $project_role,
    ]);
  }

  /**
   * 获取项目角色到系统角色的映射.
   *
   * 注意：这里的角色是项目内部角色，区别于系统级的"project_manager"角色：
   * - project_manager: 系统级角色，由baas_tenant模块管理，授予用户创建和管理项目的权限
   * - project_admin: 项目级角色，表示用户在特定项目中的管理员权限
   *
   * @return array
   *   项目角色映射配置.
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
          'access baas project content',
        ],
      ],
      'editor' => [
        'role_id' => 'project_editor',
        'label' => '项目编辑者',
        'weight' => 3,
        'permissions' => [
          'view baas project',
          'edit baas project content',
          'access baas project content',
          'create baas project content',
        ],
      ],
      'admin' => [
        'role_id' => 'project_admin',
        'label' => '项目管理员',
        'weight' => 4,
        'permissions' => [
          'view baas project',
          'edit baas project content',
          'access baas project content',
          'create baas project content',
          'delete baas project content',
          'manage baas project members',
        ],
      ],
      'owner' => [
        'role_id' => 'project_owner',
        'label' => '项目拥有者',
        'weight' => 5,
        'permissions' => [
          'view baas project',
          'edit baas project content',
          'access baas project content',
          'create baas project content',
          'delete baas project content',
          'manage baas project members',
          'manage baas project settings',
        ],
      ],
    ];
  }
}
