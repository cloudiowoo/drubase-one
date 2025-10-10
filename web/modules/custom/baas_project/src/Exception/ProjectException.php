<?php

declare(strict_types=1);

namespace Drupal\baas_project\Exception;

/**
 * 项目管理相关异常类。
 */
class ProjectException extends \Exception {

  // 错误代码常量.
  public const PROJECT_NOT_FOUND = 1001;
  public const PROJECT_ACCESS_DENIED = 1002;
  public const PROJECT_ALREADY_EXISTS = 1003;
  public const INVALID_PROJECT_DATA = 1004;
  public const MEMBER_NOT_FOUND = 1005;
  public const MEMBER_ALREADY_EXISTS = 1006;
  public const INVALID_ROLE = 1007;
  public const OWNER_CANNOT_BE_REMOVED = 1008;
  public const PROJECT_HAS_DEPENDENCIES = 1009;
  public const MACHINE_NAME_TAKEN = 1010;
  public const INVALID_TENANT = 1011;
  public const QUOTA_EXCEEDED = 1012;
  public const PROJECT_INACTIVE = 1013;
  public const PERMISSION_DENIED = 1014;
  public const INVALID_OPERATION = 1015;
  public const PROJECT_CONTEXT_REQUIRED = 1016;
  // 添加缺少的常量.
  public const USER_NOT_FOUND = 1017;
  public const CANNOT_REMOVE_OWNER = 1018;
  public const MEMBER_REMOVAL_FAILED = 1019;
  public const CANNOT_CHANGE_OWNER_ROLE = 1020;
  public const CANNOT_SET_OWNER_ROLE = 1021;
  public const MEMBER_UPDATE_FAILED = 1022;
  public const NOT_PROJECT_OWNER = 1023;
  public const FEATURE_NOT_IMPLEMENTED = 1024;

  /**
   * 构造函数。
   *
   * @param string $message
   *   异常消息。.
   * @param int $code
   *   异常代码。.
   * @param \Throwable|null $previous
   *   前一个异常。.
   * @param array $context
   *   异常上下文数据。.
   */
  public function __construct(
    string $message = '',
    int $code = 0,
    ?\Throwable $previous = NULL,
    protected readonly array $context = [],
  ) {
    parent::__construct($message, $code, $previous);
  }

  /**
   * 获取异常上下文数据。
   *
   * @return array
   *   上下文数据数组。
   */
  public function getContext(): array {
    return $this->context;
  }

  /**
   * 获取特定的上下文数据。
   *
   * @param string $key
   *   数据键名。.
   * @param mixed $default
   *   默认值。.
   *
   * @return mixed
   *   上下文数据值。
   */
  public function getContextValue(string $key, mixed $default = NULL): mixed {
    return $this->context[$key] ?? $default;
  }

  /**
   * 创建项目未找到异常。
   *
   * @param string $project_id
   *   项目ID。.
   *
   * @return static
   *   异常实例。
   */
  public static function projectNotFound(string $project_id): static {
    return new static(
      "Project not found: {$project_id}",
      self::PROJECT_NOT_FOUND,
      NULL,
      ['project_id' => $project_id]
    );
  }

  /**
   * 创建项目访问被拒绝异常。
   *
   * @param string $project_id
   *   项目ID。.
   * @param int $user_id
   *   用户ID。.
   *
   * @return static
   *   异常实例。
   */
  public static function accessDenied(string $project_id, int $user_id): static {
    return new static(
      "Access denied to project: {$project_id} for user: {$user_id}",
      self::PROJECT_ACCESS_DENIED,
      NULL,
      ['project_id' => $project_id, 'user_id' => $user_id]
    );
  }

  /**
   * 创建项目已存在异常。
   *
   * @param string $tenant_id
   *   租户ID。.
   * @param string $machine_name
   *   机器名。.
   *
   * @return static
   *   异常实例。
   */
  public static function projectAlreadyExists(string $tenant_id, string $machine_name): static {
    return new static(
      "Project with machine name '{$machine_name}' already exists in tenant: {$tenant_id}",
      self::PROJECT_ALREADY_EXISTS,
      NULL,
      ['tenant_id' => $tenant_id, 'machine_name' => $machine_name]
    );
  }

  /**
   * 创建无效项目数据异常。
   *
   * @param array $errors
   *   验证错误数组。.
   *
   * @return static
   *   异常实例。
   */
  public static function invalidProjectData(array $errors): static {
    return new static(
      'Invalid project data: ' . implode(', ', $errors),
      self::INVALID_PROJECT_DATA,
      NULL,
      ['validation_errors' => $errors]
    );
  }

  /**
   * 创建成员未找到异常。
   *
   * @param string $project_id
   *   项目ID。.
   * @param int $user_id
   *   用户ID。.
   *
   * @return static
   *   异常实例。
   */
  public static function memberNotFound(string $project_id, int $user_id): static {
    return new static(
      "Member not found: user {$user_id} in project {$project_id}",
      self::MEMBER_NOT_FOUND,
      NULL,
      ['project_id' => $project_id, 'user_id' => $user_id]
    );
  }

  /**
   * 创建成员已存在异常。
   *
   * @param string $project_id
   *   项目ID。.
   * @param int $user_id
   *   用户ID。.
   *
   * @return static
   *   异常实例。
   */
  public static function memberAlreadyExists(string $project_id, int $user_id): static {
    return new static(
      "Member already exists: user {$user_id} in project {$project_id}",
      self::MEMBER_ALREADY_EXISTS,
      NULL,
      ['project_id' => $project_id, 'user_id' => $user_id]
    );
  }

  /**
   * 创建无效角色异常。
   *
   * @param string $role
   *   角色名称。.
   *
   * @return static
   *   异常实例。
   */
  public static function invalidRole(string $role): static {
    return new static(
      "Invalid role: {$role}",
      self::INVALID_ROLE,
      NULL,
      ['role' => $role]
    );
  }

  /**
   * 创建所有者不能被移除异常。
   *
   * @param string $project_id
   *   项目ID。.
   *
   * @return static
   *   异常实例。
   */
  public static function ownerCannotBeRemoved(string $project_id): static {
    return new static(
      "Project owner cannot be removed from project: {$project_id}. Transfer ownership first.",
      self::OWNER_CANNOT_BE_REMOVED,
      NULL,
      ['project_id' => $project_id]
    );
  }

  /**
   * 创建项目有依赖异常。
   *
   * @param string $project_id
   *   项目ID。.
   * @param array $dependencies
   *   依赖列表。.
   *
   * @return static
   *   异常实例。
   */
  public static function projectHasDependencies(string $project_id, array $dependencies): static {
    return new static(
      "Cannot delete project {$project_id} due to existing dependencies: " . implode(', ', $dependencies),
      self::PROJECT_HAS_DEPENDENCIES,
      NULL,
      ['project_id' => $project_id, 'dependencies' => $dependencies]
    );
  }

  /**
   * 创建机器名已被占用异常。
   *
   * @param string $tenant_id
   *   租户ID。.
   * @param string $machine_name
   *   机器名。.
   *
   * @return static
   *   异常实例。
   */
  public static function machineNameTaken(string $tenant_id, string $machine_name): static {
    return new static(
      "Machine name '{$machine_name}' is already taken in tenant: {$tenant_id}",
      self::MACHINE_NAME_TAKEN,
      NULL,
      ['tenant_id' => $tenant_id, 'machine_name' => $machine_name]
    );
  }

  /**
   * 创建无效租户异常。
   *
   * @param string $tenant_id
   *   租户ID。.
   *
   * @return static
   *   异常实例。
   */
  public static function invalidTenant(string $tenant_id): static {
    return new static(
      "Invalid or inactive tenant: {$tenant_id}",
      self::INVALID_TENANT,
      NULL,
      ['tenant_id' => $tenant_id]
    );
  }

  /**
   * 创建配额超限异常。
   *
   * @param string $resource_type
   *   资源类型。.
   * @param int $current
   *   当前使用量。.
   * @param int $limit
   *   限制量。.
   *
   * @return static
   *   异常实例。
   */
  public static function quotaExceeded(string $resource_type, int $current, int $limit): static {
    return new static(
      "Quota exceeded for {$resource_type}: {$current}/{$limit}",
      self::QUOTA_EXCEEDED,
      NULL,
      ['resource_type' => $resource_type, 'current' => $current, 'limit' => $limit]
    );
  }

  /**
   * 创建项目未激活异常。
   *
   * @param string $project_id
   *   项目ID。.
   *
   * @return static
   *   异常实例。
   */
  public static function projectInactive(string $project_id): static {
    return new static(
      "Project is inactive: {$project_id}",
      self::PROJECT_INACTIVE,
      NULL,
      ['project_id' => $project_id]
    );
  }

  /**
   * 创建权限被拒绝异常。
   *
   * @param string $permission
   *   权限名称。.
   * @param string $context
   *   上下文信息。.
   *
   * @return static
   *   异常实例。
   */
  public static function permissionDenied(string $permission, string $context = ''): static {
    $message = "Permission denied: {$permission}";
    if ($context) {
      $message .= " in context: {$context}";
    }

    return new static(
      $message,
      self::PERMISSION_DENIED,
      NULL,
      ['permission' => $permission, 'context' => $context]
    );
  }

  /**
   * 创建无效操作异常。
   *
   * @param string $operation
   *   操作名称。.
   * @param string $reason
   *   原因。.
   *
   * @return static
   *   异常实例。
   */
  public static function invalidOperation(string $operation, string $reason = ''): static {
    $message = "Invalid operation: {$operation}";
    if ($reason) {
      $message .= ". Reason: {$reason}";
    }

    return new static(
      $message,
      self::INVALID_OPERATION,
      NULL,
      ['operation' => $operation, 'reason' => $reason]
    );
  }

  /**
   * 转换为数组格式。
   *
   * @return array
   *   异常数据数组。
   */
  public function toArray(): array {
    return [
      'message' => $this->getMessage(),
      'code' => $this->getCode(),
      'file' => $this->getFile(),
      'line' => $this->getLine(),
      'context' => $this->getContext(),
      'trace' => $this->getTraceAsString(),
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

  /**
   * 获取错误代码。
   *
   * @return string
   *   错误代码。
   */
  public function getErrorCode(): string {
    return (string) $this->getCode();
  }

  /**
   * 获取HTTP状态码。
   *
   * @return int
   *   HTTP状态码。
   */
  public function getHttpStatusCode(): int {
    // 根据错误代码映射到HTTP状态码.
    return match ($this->getCode()) {
      self::PROJECT_NOT_FOUND => 404,
      self::PROJECT_ACCESS_DENIED, self::PERMISSION_DENIED => 403,
      self::INVALID_PROJECT_DATA, self::INVALID_ROLE, self::INVALID_TENANT, self::INVALID_OPERATION => 400,
      self::QUOTA_EXCEEDED => 429,
      self::MACHINE_NAME_TAKEN => 409,
      self::PROJECT_CONTEXT_REQUIRED => 400,
      default => 500,
    };
  }

}
