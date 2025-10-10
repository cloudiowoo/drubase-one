<?php

declare(strict_types=1);

namespace Drupal\baas_api\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * 统一的API响应服务.
 *
 * 提供标准化的API响应格式和错误处理方法。
 */
class ApiResponseService
{

  /**
   * Logger实例.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * 配置工厂.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * 构造函数.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   配置工厂.
   */
  public function __construct(
    LoggerChannelFactoryInterface $logger_factory,
    ConfigFactoryInterface $config_factory
  ) {
    $this->logger = $logger_factory->get('baas_api');
    $this->configFactory = $config_factory;
  }

  /**
   * 创建成功响应.
   *
   * @param mixed $data
   *   响应数据.
   * @param string $message
   *   成功消息.
   * @param array $meta
   *   额外的元数据.
   * @param array $pagination
   *   分页信息（可选）.
   * @param int $status
   *   HTTP状态码.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   标准化的成功响应.
   */
  public function createSuccessResponse(
    $data,
    string $message = '',
    array $meta = [],
    array $pagination = [],
    int $status = 200
  ): JsonResponse {
    $response_data = [
      'success' => true,
      'data' => $data,
      'meta' => $this->buildMeta($meta),
    ];

    // 添加消息（如果提供）
    if (!empty($message)) {
      $response_data['message'] = $message;
    }

    // 添加分页信息（如果提供）
    if (!empty($pagination)) {
      $response_data['pagination'] = $this->normalizePagination($pagination);
    }

    return new JsonResponse($response_data, $status);
  }

  /**
   * 创建错误响应.
   *
   * @param string $error
   *   错误消息.
   * @param string $code
   *   错误代码.
   * @param int $status
   *   HTTP状态码.
   * @param array $context
   *   错误上下文信息.
   * @param array $meta
   *   额外的元数据.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   标准化的错误响应.
   */
  public function createErrorResponse(
    string $error,
    string $code,
    int $status = 400,
    array $context = [],
    array $meta = []
  ): JsonResponse {
    $response_data = [
      'success' => false,
      'error' => [
        'message' => $error,
        'code' => $code,
      ],
      'meta' => $this->buildMeta($meta),
    ];

    // 添加上下文信息（如果提供）
    if (!empty($context)) {
      $response_data['error']['context'] = $context;
    }

    // 记录错误日志
    $this->logger->error('API错误: @code - @error', [
      '@code' => $code,
      '@error' => $error,
      'context' => $context,
    ]);

    return new JsonResponse($response_data, $status);
  }

  /**
   * 创建验证错误响应.
   *
   * @param array $errors
   *   验证错误列表.
   * @param string $message
   *   主错误消息.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   验证错误响应.
   */
  public function createValidationErrorResponse(
    array $errors,
    string $message = 'Validation failed'
  ): JsonResponse {
    return $this->createErrorResponse(
      $message,
      'VALIDATION_ERROR',
      422,
      ['validation_errors' => $errors]
    );
  }

  /**
   * 创建分页响应.
   *
   * @param array $items
   *   数据项列表.
   * @param int $total
   *   总记录数.
   * @param int $page
   *   当前页码.
   * @param int $limit
   *   每页记录数.
   * @param string $message
   *   成功消息.
   * @param array $meta
   *   额外的元数据.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   带分页信息的响应.
   */
  public function createPaginatedResponse(
    array $items,
    int $total,
    int $page,
    int $limit,
    string $message = '',
    array $meta = []
  ): JsonResponse {
    $pagination = [
      'page' => $page,
      'limit' => $limit,
      'total' => $total,
      'pages' => ceil($total / $limit),
      'has_prev' => $page > 1,
      'has_next' => $page < ceil($total / $limit),
    ];

    return $this->createSuccessResponse($items, $message, $meta, $pagination);
  }

  /**
   * 创建无内容响应（204 No Content）.
   *
   * @param string $message
   *   操作成功消息.
   * @param array $meta
   *   额外的元数据.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   无内容响应.
   */
  public function createNoContentResponse(
    string $message = 'Operation completed successfully',
    array $meta = []
  ): JsonResponse {
    return $this->createSuccessResponse(null, $message, $meta, [], 204);
  }

  /**
   * 创建创建成功响应（201 Created）.
   *
   * @param mixed $data
   *   创建的资源数据.
   * @param string $message
   *   创建成功消息.
   * @param array $meta
   *   额外的元数据.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   创建成功响应.
   */
  public function createCreatedResponse(
    $data,
    string $message = 'Resource created successfully',
    array $meta = []
  ): JsonResponse {
    return $this->createSuccessResponse($data, $message, $meta, [], 201);
  }

  /**
   * 验证JSON请求体.
   *
   * @param string $content
   *   请求内容.
   * @param array $required_fields
   *   必需字段列表.
   * @param array $allowed_fields
   *   允许的字段列表（空表示允许所有字段）.
   *
   * @return array
   *   包含验证结果的数组.
   */
  public function validateJsonRequest(
    string $content,
    array $required_fields = [],
    array $allowed_fields = []
  ): array {
    // 解析JSON
    $data = json_decode($content, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
      return [
        'valid' => false,
        'error' => 'Invalid JSON format: ' . json_last_error_msg(),
        'code' => 'INVALID_JSON',
      ];
    }

    if (!is_array($data)) {
      return [
        'valid' => false,
        'error' => 'Request body must be a JSON object',
        'code' => 'INVALID_REQUEST_FORMAT',
      ];
    }

    // 验证必需字段
    $missing_fields = [];
    foreach ($required_fields as $field) {
      if (!isset($data[$field])) {
        $missing_fields[] = $field;
      }
    }

    if (!empty($missing_fields)) {
      return [
        'valid' => false,
        'error' => 'Missing required fields: ' . implode(', ', $missing_fields),
        'code' => 'MISSING_REQUIRED_FIELDS',
        'context' => ['missing_fields' => $missing_fields],
      ];
    }

    // 验证允许的字段
    if (!empty($allowed_fields)) {
      $invalid_fields = array_values(array_diff(array_keys($data), $allowed_fields));
      if (!empty($invalid_fields)) {
        return [
          'valid' => false,
          'error' => 'Invalid fields: ' . implode(', ', $invalid_fields),
          'code' => 'INVALID_FIELDS',
          'context' => ['invalid_fields' => $invalid_fields],
        ];
      }
    }

    return [
      'valid' => true,
      'data' => $data,
    ];
  }

  /**
   * 解析分页参数.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象.
   * @param int $default_limit
   *   默认每页记录数.
   * @param int $max_limit
   *   最大每页记录数.
   *
   * @return array
   *   包含page、limit和offset的数组.
   */
  public function parsePaginationParams(
    Request $request,
    int $default_limit = 20,
    int $max_limit = 100
  ): array {
    $page = max(1, (int) $request->query->get('page', 1));
    $limit = min($max_limit, max(1, (int) $request->query->get('limit', $default_limit)));

    return [
      'page' => $page,
      'limit' => $limit,
      'offset' => ($page - 1) * $limit,
    ];
  }

  /**
   * 解析排序参数.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象.
   * @param array $allowed_fields
   *   允许排序的字段列表.
   * @param string $default_field
   *   默认排序字段.
   * @param string $default_direction
   *   默认排序方向.
   *
   * @return array
   *   包含field和direction的数组.
   */
  public function parseSortParams(
    Request $request,
    array $allowed_fields = ['id', 'created', 'updated'],
    string $default_field = 'id',
    string $default_direction = 'DESC'
  ): array {
    $field = $request->query->get('sort_field', $default_field);
    $direction = strtoupper($request->query->get('sort_direction', $default_direction));

    // 验证排序字段
    if (!in_array($field, $allowed_fields)) {
      $field = $default_field;
    }

    // 验证排序方向
    if (!in_array($direction, ['ASC', 'DESC'])) {
      $direction = $default_direction;
    }

    return [
      'field' => $field,
      'direction' => $direction,
    ];
  }

  /**
   * 解析筛选参数.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象.
   * @param array $allowed_filters
   *   允许的筛选字段列表.
   *
   * @return array
   *   筛选参数数组.
   */
  public function parseFilterParams(Request $request, array $allowed_filters = []): array
  {
    $filters = [];
    
    foreach ($allowed_filters as $filter) {
      $value = $request->query->get($filter);
      if ($value !== null && $value !== '') {
        $filters[$filter] = $value;
      }
    }

    return $filters;
  }

  /**
   * 构建标准元数据.
   *
   * @param array $additional_meta
   *   额外的元数据.
   *
   * @return array
   *   标准化的元数据.
   */
  protected function buildMeta(array $additional_meta = []): array
  {
    return array_merge([
      'timestamp' => date('c'),
      'api_version' => 'v1',
      'request_id' => $this->generateRequestId(),
      'server_time' => microtime(true),
    ], $additional_meta);
  }

  /**
   * 规范化分页信息.
   *
   * @param array $pagination
   *   分页信息.
   *
   * @return array
   *   规范化的分页信息.
   */
  protected function normalizePagination(array $pagination): array
  {
    $normalized = [
      'page' => $pagination['page'] ?? 1,
      'limit' => $pagination['limit'] ?? 20,
      'total' => $pagination['total'] ?? 0,
    ];

    $normalized['pages'] = ceil($normalized['total'] / $normalized['limit']);
    $normalized['has_prev'] = $normalized['page'] > 1;
    $normalized['has_next'] = $normalized['page'] < $normalized['pages'];

    return $normalized;
  }

  /**
   * 生成唯一请求ID.
   *
   * @return string
   *   唯一的请求ID.
   */
  protected function generateRequestId(): string
  {
    return 'req_' . uniqid() . '_' . substr(md5((string) microtime(true)), 0, 8);
  }

}