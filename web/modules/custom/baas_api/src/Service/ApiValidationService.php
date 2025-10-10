<?php

declare(strict_types=1);

namespace Drupal\baas_api\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * 统一的API验证服务.
 *
 * 提供标准化的数据验证方法。
 */
class ApiValidationService
{

  /**
   * Logger实例.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * 验证规则定义.
   *
   * @var array
   */
  protected array $validationRules = [
    'email' => '/^[^\s@]+@[^\s@]+\.[^\s@]+$/',
    'uuid' => '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
    'tenant_id' => '/^[a-zA-Z0-9_-]{3,32}$/',
    'project_id' => '/^[a-zA-Z0-9_-]{3,32}$/',
    'entity_name' => '/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/',
    'field_name' => '/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/',
    'username' => '/^[a-zA-Z0-9_-]{3,30}$/',
    'password' => '/^.{8,128}$/', // 最少8位，最多128位
  ];

  /**
   * 构造函数.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory)
  {
    $this->logger = $logger_factory->get('baas_api_validation');
  }

  /**
   * 验证字段值.
   *
   * @param mixed $value
   *   要验证的值.
   * @param array $rules
   *   验证规则.
   * @param string $field_name
   *   字段名称.
   *
   * @return array
   *   验证结果.
   */
  public function validateField($value, array $rules, string $field_name = 'field'): array
  {
    $errors = [];

    foreach ($rules as $rule => $params) {
      $result = $this->applyRule($value, $rule, $params, $field_name);
      if (!$result['valid']) {
        $errors[] = $result['error'];
      }
    }

    return [
      'valid' => empty($errors),
      'errors' => $errors,
    ];
  }

  /**
   * 验证数据数组.
   *
   * @param array $data
   *   要验证的数据.
   * @param array $rules
   *   验证规则定义.
   *
   * @return array
   *   验证结果.
   */
  public function validateData(array $data, array $rules): array
  {
    $errors = [];

    foreach ($rules as $field => $field_rules) {
      $value = $data[$field] ?? null;
      $result = $this->validateField($value, $field_rules, $field);
      
      if (!$result['valid']) {
        $errors[$field] = $result['errors'];
      }
    }

    return [
      'valid' => empty($errors),
      'errors' => $errors,
    ];
  }

  /**
   * 验证租户ID格式.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array
   *   验证结果.
   */
  public function validateTenantId(string $tenant_id): array
  {
    return $this->validateField($tenant_id, [
      'required' => true,
      'pattern' => 'tenant_id',
    ], 'tenant_id');
  }

  /**
   * 验证项目ID格式.
   *
   * @param string $project_id
   *   项目ID.
   *
   * @return array
   *   验证结果.
   */
  public function validateProjectId(string $project_id): array
  {
    return $this->validateField($project_id, [
      'required' => true,
      'pattern' => 'project_id',
    ], 'project_id');
  }

  /**
   * 验证实体名称格式.
   *
   * @param string $entity_name
   *   实体名称.
   *
   * @return array
   *   验证结果.
   */
  public function validateEntityName(string $entity_name): array
  {
    return $this->validateField($entity_name, [
      'required' => true,
      'pattern' => 'entity_name',
      'min_length' => 1,
      'max_length' => 64,
    ], 'entity_name');
  }

  /**
   * 验证字段名称格式.
   *
   * @param string $field_name
   *   字段名称.
   *
   * @return array
   *   验证结果.
   */
  public function validateFieldName(string $field_name): array
  {
    return $this->validateField($field_name, [
      'required' => true,
      'pattern' => 'field_name',
      'min_length' => 1,
      'max_length' => 64,
    ], 'field_name');
  }

  /**
   * 验证邮箱格式.
   *
   * @param string $email
   *   邮箱地址.
   *
   * @return array
   *   验证结果.
   */
  public function validateEmail(string $email): array
  {
    return $this->validateField($email, [
      'required' => true,
      'pattern' => 'email',
      'max_length' => 255,
    ], 'email');
  }

  /**
   * 验证密码强度.
   *
   * @param string $password
   *   密码.
   *
   * @return array
   *   验证结果.
   */
  public function validatePassword(string $password): array
  {
    return $this->validateField($password, [
      'required' => true,
      'pattern' => 'password',
      'min_length' => 8,
      'max_length' => 128,
    ], 'password');
  }

  /**
   * 验证UUID格式.
   *
   * @param string $uuid
   *   UUID字符串.
   *
   * @return array
   *   验证结果.
   */
  public function validateUuid(string $uuid): array
  {
    return $this->validateField($uuid, [
      'required' => true,
      'pattern' => 'uuid',
    ], 'uuid');
  }

  /**
   * 验证分页参数.
   *
   * @param int $page
   *   页码.
   * @param int $limit
   *   每页记录数.
   * @param int $max_limit
   *   最大每页记录数.
   *
   * @return array
   *   验证结果.
   */
  public function validatePaginationParams(int $page, int $limit, int $max_limit = 100): array
  {
    $errors = [];

    if ($page < 1) {
      $errors[] = 'Page must be greater than 0';
    }

    if ($limit < 1) {
      $errors[] = 'Limit must be greater than 0';
    }

    if ($limit > $max_limit) {
      $errors[] = "Limit cannot exceed {$max_limit}";
    }

    return [
      'valid' => empty($errors),
      'errors' => $errors,
    ];
  }

  /**
   * 验证排序参数.
   *
   * @param string $field
   *   排序字段.
   * @param string $direction
   *   排序方向.
   * @param array $allowed_fields
   *   允许的排序字段.
   *
   * @return array
   *   验证结果.
   */
  public function validateSortParams(string $field, string $direction, array $allowed_fields): array
  {
    $errors = [];

    if (!in_array($field, $allowed_fields)) {
      $errors[] = "Invalid sort field: {$field}. Allowed fields: " . implode(', ', $allowed_fields);
    }

    if (!in_array(strtoupper($direction), ['ASC', 'DESC'])) {
      $errors[] = "Invalid sort direction: {$direction}. Must be ASC or DESC";
    }

    return [
      'valid' => empty($errors),
      'errors' => $errors,
    ];
  }

  /**
   * 应用单个验证规则.
   *
   * @param mixed $value
   *   要验证的值.
   * @param string $rule
   *   规则名称.
   * @param mixed $params
   *   规则参数.
   * @param string $field_name
   *   字段名称.
   *
   * @return array
   *   验证结果.
   */
  protected function applyRule($value, string $rule, $params, string $field_name): array
  {
    switch ($rule) {
      case 'required':
        return $this->validateRequired($value, $params, $field_name);

      case 'type':
        return $this->validateType($value, $params, $field_name);

      case 'min_length':
        return $this->validateMinLength($value, $params, $field_name);

      case 'max_length':
        return $this->validateMaxLength($value, $params, $field_name);

      case 'pattern':
        return $this->validatePattern($value, $params, $field_name);

      case 'in':
        return $this->validateIn($value, $params, $field_name);

      case 'numeric':
        return $this->validateNumeric($value, $field_name);

      case 'min':
        return $this->validateMin($value, $params, $field_name);

      case 'max':
        return $this->validateMax($value, $params, $field_name);

      default:
        return ['valid' => true];
    }
  }

  /**
   * 验证必填字段.
   */
  protected function validateRequired($value, bool $required, string $field_name): array
  {
    if ($required && ($value === null || $value === '')) {
      return [
        'valid' => false,
        'error' => "{$field_name} is required",
      ];
    }

    return ['valid' => true];
  }

  /**
   * 验证数据类型.
   */
  protected function validateType($value, string $type, string $field_name): array
  {
    $actual_type = gettype($value);
    
    if ($actual_type !== $type) {
      return [
        'valid' => false,
        'error' => "{$field_name} must be of type {$type}, got {$actual_type}",
      ];
    }

    return ['valid' => true];
  }

  /**
   * 验证最小长度.
   */
  protected function validateMinLength($value, int $min_length, string $field_name): array
  {
    if (is_string($value) && strlen($value) < $min_length) {
      return [
        'valid' => false,
        'error' => "{$field_name} must be at least {$min_length} characters long",
      ];
    }

    return ['valid' => true];
  }

  /**
   * 验证最大长度.
   */
  protected function validateMaxLength($value, int $max_length, string $field_name): array
  {
    if (is_string($value) && strlen($value) > $max_length) {
      return [
        'valid' => false,
        'error' => "{$field_name} cannot exceed {$max_length} characters",
      ];
    }

    return ['valid' => true];
  }

  /**
   * 验证正则表达式模式.
   */
  protected function validatePattern($value, string $pattern_name, string $field_name): array
  {
    if (!isset($this->validationRules[$pattern_name])) {
      return [
        'valid' => false,
        'error' => "Unknown validation pattern: {$pattern_name}",
      ];
    }

    $pattern = $this->validationRules[$pattern_name];
    
    if (is_string($value) && !preg_match($pattern, $value)) {
      return [
        'valid' => false,
        'error' => "{$field_name} format is invalid",
      ];
    }

    return ['valid' => true];
  }

  /**
   * 验证值是否在允许列表中.
   */
  protected function validateIn($value, array $allowed_values, string $field_name): array
  {
    if (!in_array($value, $allowed_values)) {
      return [
        'valid' => false,
        'error' => "{$field_name} must be one of: " . implode(', ', $allowed_values),
      ];
    }

    return ['valid' => true];
  }

  /**
   * 验证数值类型.
   */
  protected function validateNumeric($value, string $field_name): array
  {
    if (!is_numeric($value)) {
      return [
        'valid' => false,
        'error' => "{$field_name} must be numeric",
      ];
    }

    return ['valid' => true];
  }

  /**
   * 验证最小值.
   */
  protected function validateMin($value, $min_value, string $field_name): array
  {
    if (is_numeric($value) && $value < $min_value) {
      return [
        'valid' => false,
        'error' => "{$field_name} must be at least {$min_value}",
      ];
    }

    return ['valid' => true];
  }

  /**
   * 验证最大值.
   */
  protected function validateMax($value, $max_value, string $field_name): array
  {
    if (is_numeric($value) && $value > $max_value) {
      return [
        'valid' => false,
        'error' => "{$field_name} cannot exceed {$max_value}",
      ];
    }

    return ['valid' => true];
  }

}