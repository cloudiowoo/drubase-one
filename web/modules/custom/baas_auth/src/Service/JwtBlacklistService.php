<?php

namespace Drupal\baas_auth\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * JWT黑名单服务实现。
 */
class JwtBlacklistService implements JwtBlacklistServiceInterface
{

  /**
   * 数据库连接。
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * 日志记录器。
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * 构造函数。
   *
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接。
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂。
   */
  public function __construct(Connection $database, LoggerChannelFactoryInterface $logger_factory)
  {
    $this->database = $database;
    $this->logger = $logger_factory->get('baas_auth');
  }

  /**
   * {@inheritdoc}
   */
  public function addToBlacklist(string $jti, int $user_id, string $tenant_id, int $expires_at): bool
  {
    try {
      $this->database->insert('baas_auth_jwt_blacklist')
        ->fields([
          'token_jti' => $jti,
          'user_id' => $user_id,
          'tenant_id' => $tenant_id,
          'expires_at' => $expires_at,
          'created' => time(),
        ])
        ->execute();

      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('添加JWT到黑名单失败: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isBlacklisted(string $jti): bool
  {
    try {
      $result = $this->database->select('baas_auth_jwt_blacklist', 'b')
        ->fields('b', ['token_jti'])
        ->condition('token_jti', $jti)
        ->condition('expires_at', time(), '>')
        ->execute()
        ->fetchField();

      return !empty($result);
    } catch (\Exception $e) {
      $this->logger->error('检查JWT黑名单状态失败: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isTokenBlacklistedByTimestamp(array $payload): bool
  {
    try {
      $user_id = $payload['sub'] ?? 0;
      $tenant_id = $payload['tenant_id'] ?? '';
      $token_issued_at = $payload['iat'] ?? 0;

      if (empty($user_id) || empty($tenant_id) || empty($token_issued_at)) {
        return FALSE;
      }

      // 查找是否有用户时间戳黑名单记录
      $blacklist_time = $this->database->select('baas_auth_jwt_blacklist', 'b')
        ->fields('b', ['created'])
        ->condition('token_jti', 'USER_TIMESTAMP_' . $user_id . '_' . $tenant_id)
        ->condition('expires_at', time(), '>')
        ->execute()
        ->fetchField();

      if ($blacklist_time) {
        // 如果令牌签发时间早于黑名单时间，则令牌无效
        return $token_issued_at < $blacklist_time;
      }

      return FALSE;
    } catch (\Exception $e) {
      $this->logger->error('检查JWT时间戳黑名单状态失败: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function cleanupExpired(): int
  {
    try {
      $deleted = $this->database->delete('baas_auth_jwt_blacklist')
        ->condition('expires_at', time(), '<=')
        ->execute();

      $this->logger->info('清理了 @count 条过期的JWT黑名单记录', ['@count' => $deleted]);
      return $deleted;
    } catch (\Exception $e) {
      $this->logger->error('清理过期JWT黑名单记录失败: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function blacklistUserTokens(int $user_id, string $tenant_id): bool
  {
    try {
      $now = time();
      $expires_at = $now + (30 * 24 * 3600); // 30天有效期

      // 使用特殊的JTI格式来标识这是一个时间戳黑名单
      $jti = 'USER_TIMESTAMP_' . $user_id . '_' . $tenant_id;

      // 删除之前的时间戳黑名单记录（如果存在）
      $this->database->delete('baas_auth_jwt_blacklist')
        ->condition('token_jti', $jti)
        ->execute();

      // 添加新的时间戳黑名单记录
      $result = $this->addToBlacklist($jti, $user_id, $tenant_id, $expires_at);

      if ($result) {
        $this->logger->info('已为用户 @user_id 在租户 @tenant_id 创建时间戳黑名单，时间: @time', [
          '@user_id' => $user_id,
          '@tenant_id' => $tenant_id,
          '@time' => date('Y-m-d H:i:s', $now),
        ]);
      }

      return $result;
    } catch (\Exception $e) {
      $this->logger->error('黑名单用户令牌失败: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }
}
