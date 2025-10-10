<?php
/*
 * @Date: 2025-05-12 15:15:20
 * @LastEditors: cloudio cloudio.woo@gmail.com
 * @LastEditTime: 2025-05-13 23:34:55
 * @FilePath: /drubase/web/modules/custom/baas_api/src/ApiResponse.php
 */

declare(strict_types=1);

namespace Drupal\baas_api;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * 提供统一的API响应格式。
 */
class ApiResponse {

  /**
   * 创建成功响应。
   *
   * @param array $data
   *   响应数据。
   * @param int $status
   *   HTTP状态码，默认200。
   * @param array $headers
   *   HTTP头信息。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应对象。
   */
  public static function success(array $data = [], int $status = 200, array $headers = []): JsonResponse {
    $response_data = [
      'success' => TRUE,
      'data' => $data,
    ];

    $response = new JsonResponse($response_data, $status, $headers);
    self::addDefaultHeaders($response);

    return $response;
  }

  /**
   * 创建错误响应。
   *
   * @param string $message
   *   错误消息。
   * @param int $status
   *   HTTP状态码，默认400。
   * @param array $details
   *   错误详情。
   * @param array $headers
   *   HTTP头信息。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应对象。
   */
  public static function error(string $message, int $status = 400, array $details = [], array $headers = []): JsonResponse {
    $response_data = [
      'success' => FALSE,
      'error' => [
        'message' => $message,
        'status' => $status,
      ],
    ];

    if (!empty($details)) {
      $response_data['error']['details'] = $details;
    }

    $response = new JsonResponse($response_data, $status, $headers);
    self::addDefaultHeaders($response);

    return $response;
  }

  /**
   * 创建分页响应。
   *
   * @param array $items
   *   分页项目。
   * @param int $total
   *   总记录数。
   * @param int $page
   *   当前页码。
   * @param int $limit
   *   每页记录数。
   * @param array $headers
   *   HTTP头信息。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应对象。
   */
  public static function paginated(array $items, int $total, int $page, int $limit, array $headers = []): JsonResponse {
    $total_pages = ceil($total / max(1, $limit));

    $response_data = [
      'success' => TRUE,
      'data' => [
        'items' => $items,
        'pagination' => [
          'total' => $total,
          'page' => $page,
          'limit' => $limit,
          'pages' => $total_pages,
        ],
      ],
    ];

    $response = new JsonResponse($response_data, 200, $headers);
    self::addDefaultHeaders($response);

    return $response;
  }

  /**
   * 添加默认HTTP头信息。
   *
   * @param \Symfony\Component\HttpFoundation\JsonResponse $response
   *   JSON响应对象。
   */
  private static function addDefaultHeaders(JsonResponse $response): void {
    // 添加CORS和API版本头信息
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-API-Key, X-Requested-With, X-BaaS-Project-ID, X-BaaS-Tenant-ID, x-baas-project-id, x-baas-tenant-id');
    $response->headers->set('X-API-Version', 'v1');
  }

}
