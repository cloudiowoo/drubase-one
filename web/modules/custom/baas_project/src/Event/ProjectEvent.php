<?php

declare(strict_types=1);

namespace Drupal\baas_project\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * 项目相关事件类。
 */
class ProjectEvent extends Event {

  // 事件常量
  public const PROJECT_CREATED = 'baas_project.project.created';
  public const PROJECT_UPDATED = 'baas_project.project.updated';
  public const PROJECT_DELETED = 'baas_project.project.deleted';
  public const MEMBER_ADDED = 'baas_project.member.added';
  public const MEMBER_REMOVED = 'baas_project.member.removed';
  public const MEMBER_ROLE_UPDATED = 'baas_project.member.role_updated';
  public const OWNERSHIP_TRANSFERRED = 'baas_project.ownership.transferred';
  public const PROJECT_ACCESSED = 'baas_project.project.accessed';
  public const PROJECT_SWITCHED = 'baas_project.project.switched';
  public const USAGE_ALERT = 'baas_project.usage.alert';

  /**
   * 构造函数。
   *
   * @param string $projectId
   *   项目ID。
   * @param array $data
   *   事件相关数据。
   */
  public function __construct(
    protected readonly string $projectId,
    protected readonly array $data = [],
  ) {}

  /**
   * 获取项目ID。
   *
   * @return string
   *   项目ID。
   */
  public function getProjectId(): string {
    return $this->projectId;
  }

  /**
   * 获取事件数据。
   *
   * @return array
   *   事件数据数组。
   */
  public function getData(): array {
    return $this->data;
  }

  /**
   * 获取特定的数据字段。
   *
   * @param string $key
   *   数据键名。
   * @param mixed $default
   *   默认值。
   *
   * @return mixed
   *   数据值。
   */
  public function get(string $key, mixed $default = null): mixed {
    return $this->data[$key] ?? $default;
  }

  /**
   * 检查是否包含特定数据键。
   *
   * @param string $key
   *   数据键名。
   *
   * @return bool
   *   是否包含该键。
   */
  public function has(string $key): bool {
    return isset($this->data[$key]);
  }

  /**
   * 获取租户ID（如果数据中包含）。
   *
   * @return string|null
   *   租户ID。
   */
  public function getTenantId(): ?string {
    return $this->data['tenant_id'] ?? null;
  }

  /**
   * 获取用户ID（如果数据中包含）。
   *
   * @return int|null
   *   用户ID。
   */
  public function getUserId(): ?int {
    return $this->data['user_id'] ?? null;
  }

  /**
   * 获取项目名称（如果数据中包含）。
   *
   * @return string|null
   *   项目名称。
   */
  public function getProjectName(): ?string {
    return $this->data['name'] ?? null;
  }

  /**
   * 获取用户角色（如果数据中包含）。
   *
   * @return string|null
   *   用户角色。
   */
  public function getRole(): ?string {
    return $this->data['role'] ?? null;
  }

  /**
   * 获取旧所有者ID（用于所有权转移事件）。
   *
   * @return int|null
   *   旧所有者ID。
   */
  public function getOldOwnerUid(): ?int {
    return $this->data['old_owner_uid'] ?? null;
  }

  /**
   * 获取新所有者ID（用于所有权转移事件）。
   *
   * @return int|null
   *   新所有者ID。
   */
  public function getNewOwnerUid(): ?int {
    return $this->data['new_owner_uid'] ?? null;
  }

  /**
   * 获取项目状态（如果数据中包含）。
   *
   * @return int|null
   *   项目状态。
   */
  public function getStatus(): ?int {
    return $this->data['status'] ?? null;
  }

  /**
   * 获取项目设置（如果数据中包含）。
   *
   * @return array
   *   项目设置数组。
   */
  public function getSettings(): array {
    return $this->data['settings'] ?? [];
  }

  /**
   * 检查是否为项目创建事件。
   *
   * @return bool
   *   是否为创建事件。
   */
  public function isCreated(): bool {
    return $this->has('created') && !$this->has('updated');
  }

  /**
   * 检查是否为项目更新事件。
   *
   * @return bool
   *   是否为更新事件。
   */
  public function isUpdated(): bool {
    return $this->has('updated');
  }

  /**
   * 检查是否为成员相关事件。
   *
   * @return bool
   *   是否为成员事件。
   */
  public function isMemberEvent(): bool {
    return $this->has('user_id') && $this->has('role');
  }

  /**
   * 检查是否为所有权转移事件。
   *
   * @return bool
   *   是否为所有权转移事件。
   */
  public function isOwnershipTransfer(): bool {
    return $this->has('old_owner_uid') && $this->has('new_owner_uid');
  }

  /**
   * 获取事件的时间戳。
   *
   * @return int
   *   时间戳。
   */
  public function getTimestamp(): int {
    return $this->data['timestamp'] ?? time();
  }

  /**
   * 转换为数组格式。
   *
   * @return array
   *   事件数据数组。
   */
  public function toArray(): array {
    return [
      'project_id' => $this->projectId,
      'data' => $this->data,
      'timestamp' => $this->getTimestamp(),
    ];
  }

  /**
   * 转换为JSON格式。
   *
   * @return string
   *   JSON字符串。
   */
  public function toJson(): string {
    return json_encode($this->toArray());
  }

}