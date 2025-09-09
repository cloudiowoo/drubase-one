<?php

namespace Drupal\baas_auth\Service;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * 会话管理器服务实现。
 */
class SessionManager
{

  /**
   * 数据库连接。
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * 时间服务。
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * 请求堆栈。
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

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
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   时间服务。
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   请求堆栈。
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂。
   */
  public function __construct(
    Connection $database,
    TimeInterface $time,
    RequestStack $request_stack,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->database = $database;
    $this->time = $time;
    $this->requestStack = $request_stack;
    $this->logger = $logger_factory->get('baas_auth');
  }

  /**
   * 创建会话。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $tenant_id
   *   租户ID。
   * @param string $token_jti
   *   JWT令牌ID。
   *
   * @return int|null
   *   会话ID，失败返回null。
   */
  public function createSession(int $user_id, string $tenant_id, string $token_jti): ?int
  {
    try {
      $request = $this->requestStack->getCurrentRequest();
      $now = $this->time->getRequestTime();

      $id = $this->database->insert('baas_auth_sessions')
        ->fields([
          'user_id' => $user_id,
          'tenant_id' => $tenant_id,
          'token_jti' => $token_jti,
          'ip_address' => $request ? $request->getClientIp() : '',
          'user_agent' => $request ? $request->headers->get('User-Agent', '') : '',
          'created' => $now,
          'last_activity' => $now,
        ])
        ->execute();

      $this->logger->info('创建会话: 用户 @user_id, 租户 @tenant_id', [
        '@user_id' => $user_id,
        '@tenant_id' => $tenant_id,
      ]);

      return $id;
    } catch (\Exception $e) {
      $this->logger->error('创建会话失败: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * 获取用户会话列表。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return array
   *   会话列表。
   */
  public function getUserSessions(int $user_id, string $tenant_id): array
  {
    try {
      $results = $this->database->select('baas_auth_sessions', 's')
        ->fields('s')
        ->condition('user_id', $user_id)
        ->condition('tenant_id', $tenant_id)
        ->orderBy('last_activity', 'DESC')
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      return $results;
    } catch (\Exception $e) {
      $this->logger->error('获取用户会话失败: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * 更新会话活动时间。
   *
   * @param string $token_jti
   *   JWT令牌ID。
   *
   * @return bool
   *   更新成功返回TRUE。
   */
  public function updateSessionActivity(string $token_jti): bool
  {
    try {
      $this->database->update('baas_auth_sessions')
        ->fields(['last_activity' => $this->time->getRequestTime()])
        ->condition('token_jti', $token_jti)
        ->execute();

      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('更新会话活动时间失败: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * 删除会话。
   *
   * @param int $session_id
   *   会话ID。
   *
   * @return bool
   *   删除成功返回TRUE。
   */
  public function deleteSession(int $session_id): bool
  {
    try {
      $this->database->delete('baas_auth_sessions')
        ->condition('id', $session_id)
        ->execute();

      $this->logger->info('删除会话: @session_id', ['@session_id' => $session_id]);
      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('删除会话失败: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * 删除用户的所有会话。
   *
   * @param int $user_id
   *   用户ID。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return int
   *   删除的会话数量。
   */
  public function deleteAllUserSessions(int $user_id, string $tenant_id): int
  {
    try {
      $deleted = $this->database->delete('baas_auth_sessions')
        ->condition('user_id', $user_id)
        ->condition('tenant_id', $tenant_id)
        ->execute();

      $this->logger->info('删除用户所有会话: 用户 @user_id, 删除 @count 个会话', [
        '@user_id' => $user_id,
        '@count' => $deleted,
      ]);

      return $deleted;
    } catch (\Exception $e) {
      $this->logger->error('删除用户所有会话失败: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }

  /**
   * 清理过期会话。
   *
   * @param int $max_age
   *   最大会话年龄（秒）。
   *
   * @return int
   *   清理的会话数量。
   */
  public function cleanupExpiredSessions(int $max_age = 86400): int
  {
    try {
      $cutoff = $this->time->getRequestTime() - $max_age;

      $deleted = $this->database->delete('baas_auth_sessions')
        ->condition('last_activity', $cutoff, '<')
        ->execute();

      $this->logger->info('清理过期会话: @count 个', ['@count' => $deleted]);
      return $deleted;
    } catch (\Exception $e) {
      $this->logger->error('清理过期会话失败: @message', ['@message' => $e->getMessage()]);
      return 0;
    }
  }
}
