<?php

namespace Drupal\baas_auth\Service;

/**
 * JWT令牌管理器接口。
 */
interface JwtTokenManagerInterface
{

  /**
   * 生成JWT访问令牌。
   *
   * @param array $payload
   *   令牌载荷数据。
   *
   * @return string
   *   JWT令牌字符串。
   */
  public function generateAccessToken(array $payload): string;

  /**
   * 生成JWT刷新令牌。
   *
   * @param array $payload
   *   令牌载荷数据。
   *
   * @return string
   *   JWT刷新令牌字符串。
   */
  public function generateRefreshToken(array $payload): string;

  /**
   * 验证JWT令牌。
   *
   * @param string $token
   *   JWT令牌字符串。
   *
   * @return array|null
   *   验证成功返回载荷数组，失败返回NULL。
   */
  public function validateToken(string $token): ?array;

  /**
   * 解析JWT令牌（不验证签名）。
   *
   * @param string $token
   *   JWT令牌字符串。
   *
   * @return array|null
   *   解析成功返回载荷数组，失败返回NULL。
   */
  public function parseToken(string $token): ?array;

  /**
   * 检查令牌是否已过期。
   *
   * @param array $payload
   *   令牌载荷数据。
   *
   * @return bool
   *   已过期返回TRUE，未过期返回FALSE。
   */
  public function isTokenExpired(array $payload): bool;
}
