<?php
/*
 * @Date: 2025-05-11 11:43:51
 * @LastEditors: cloudio cloudio.woo@gmail.com
 * @LastEditTime: 2025-05-11 13:14:55
 * @FilePath: /drubase/web/modules/custom/baas_tenant/src/ApiResponse.php
 */

declare(strict_types=1);

namespace Drupal\baas_tenant;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Cache\CacheableResponseTrait;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * 提供标准化的API响应格式.
 */
class ApiResponse extends JsonResponse implements CacheableResponseInterface {
  use CacheableResponseTrait;

  /**
   * 生成成功响应.
   *
   * @param array $data
   *   响应数据.
   * @param int $status
   *   HTTP状态码.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheMetadata
   *   可缓存元数据.
   *
   * @return static
   *   JSON响应.
   */
  public static function success(
    array $data,
    int $status = 200,
    ?CacheableMetadata $cacheMetadata = null
  ): static {
    $response = new static([
      'success' => true,
      'data' => $data,
    ], $status);

    if ($cacheMetadata) {
      $response->addCacheableDependency($cacheMetadata);
    }

    return $response;
  }

  /**
   * 生成错误响应.
   *
   * @param string $message
   *   错误信息.
   * @param int $status
   *   HTTP状态码.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheMetadata
   *   可缓存元数据.
   *
   * @return static
   *   JSON响应.
   */
  public static function error(
    string $message,
    int $status = 400,
    ?CacheableMetadata $cacheMetadata = null
  ): static {
    $response = new static([
      'success' => false,
      'error' => [
        'message' => $message,
      ],
    ], $status);

    if ($cacheMetadata) {
      $response->addCacheableDependency($cacheMetadata);
    }

    return $response;
  }
}
