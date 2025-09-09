<?php

namespace Drupal\baas_auth\Service;

/**
 * JWT黑名单服务接口。
 */
interface JwtBlacklistServiceInterface
{

  /**
   * 将JWT令牌添加到黑名单。
   *
   * @param string $jti
   *   JWT令牌ID。
   * @param int $user_id
   *   用户ID。
   * @param string $tenant_id
   *   租户ID。
   * @param int $expires_at
   *   过期时间戳。
   *
   * @return bool
   *   添加成功返回TRUE，失败返回FALSE。
   */
  public function addToBlacklist(string $jti, int $user_id, string $tenant_id, int $expires_at): bool;

  /**
   * 检查JWT令牌是否在黑名单中。
   *
   * @param string $jti
   *   JWT令牌ID。
   *
   * @return bool
   *   在黑名单中返回TRUE，不在返回FALSE。
   */
  public function isBlacklisted(string $jti): bool;

  /**
   * 清理过期的黑名单记录。
   *
   * @return int
   *   清理的记录数量。
   */
  public function cleanupExpired(): int;

  /**
   * 将用户的所有令牌添加到黑名单。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return bool
   *   操作成功返回TRUE，失败返回FALSE。
   */
  public function blacklistUserTokens(int $user_id, string $tenant_id): bool;

  /**
   * 检查令牌是否被用户时间戳黑名单策略阻止。
   *
   * @param array $payload
   *   JWT令牌载荷。
   *
   * @return bool
   *   被黑名单返回TRUE，未被黑名单返回FALSE。
   */
  public function isTokenBlacklistedByTimestamp(array $payload): bool;
}
