<?php

namespace Drupal\baas_api\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Token;
use Drupal\Component\Utility\Crypt;
use Psr\Log\LoggerInterface;

/**
 * 提供API令牌管理功能。
 */
class ApiTokenManager {
  use StringTranslationTrait;

  /**
   * 数据库连接。
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * 配置工厂。
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * 日志通道。
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * 时间服务。
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * 令牌服务。
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * 构造函数。
   *
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接。
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   配置工厂。
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志通道工厂。
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   时间服务。
   * @param \Drupal\Core\Utility\Token $token
   *   令牌服务。
   */
  public function __construct(
    Connection $database,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    TimeInterface $time,
    Token $token
  ) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('baas_api');
    $this->time = $time;
    $this->token = $token;
  }

  /**
   * 生成新的API令牌。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param array $scopes
   *   令牌作用域数组。
   * @param int $expires
   *   过期时间戳，0表示永不过期。
   * @param string $name
   *   令牌名称。
   *
   * @return array|false
   *   生成的令牌信息数组，或失败时返回FALSE。
   */
  public function generateToken($tenant_id, array $scopes = ['*'], $expires = 0, $name = '') {
    try {
      $token_value = $this->createTokenValue();
      $token_hash = $this->hashToken($token_value);

      $token_record = [
        'tenant_id' => $tenant_id,
        'token_hash' => $token_hash,
        'name' => $name ?: $this->t('API令牌 @time', ['@time' => date('Y-m-d H:i:s')]),
        'scopes' => json_encode($scopes),
        'created' => $this->time->getRequestTime(),
        'expires' => $expires,
        'last_used' => 0,
        'status' => 1,
      ];

      $this->database->insert('baas_api_tokens')
        ->fields($token_record)
        ->execute();

      $token_record['token'] = $token_value;
      unset($token_record['token_hash']);

      $this->logger->notice('为租户 @tenant 创建了新的API令牌', [
        '@tenant' => $tenant_id,
      ]);

      return $token_record;
    }
    catch (\Exception $e) {
      $this->logger->error('创建API令牌失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 验证API令牌。
   *
   * @param string $token
   *   要验证的令牌。
   * @param string $tenant_id
   *   租户ID。
   * @param array $required_scopes
   *   需要的作用域数组。
   *
   * @return bool|array
   *   如果令牌有效，返回令牌信息；否则返回FALSE。
   */
  public function validateToken($token, $tenant_id, array $required_scopes = []) {
    try {
      $token_hash = $this->hashToken($token);

      $query = $this->database->select('baas_api_tokens', 't')
        ->fields('t')
        ->condition('t.token_hash', $token_hash)
        ->condition('t.tenant_id', $tenant_id)
        ->condition('t.status', 1);

      $token_data = $query->execute()->fetchAssoc();

      if (!$token_data) {
        $this->logger->notice('API令牌验证失败: 令牌不存在或已禁用');
        return FALSE;
      }

      // 检查是否过期
      if ($token_data['expires'] > 0 && $token_data['expires'] < $this->time->getRequestTime()) {
        $this->logger->notice('API令牌验证失败: 令牌已过期');
        return FALSE;
      }

      // 检查作用域
      $token_scopes = json_decode($token_data['scopes'], TRUE);
      if (!$this->checkScopes($token_scopes, $required_scopes)) {
        $this->logger->notice('API令牌验证失败: 作用域不足');
        return FALSE;
      }

      // 更新最后使用时间
      $this->database->update('baas_api_tokens')
        ->fields(['last_used' => $this->time->getRequestTime()])
        ->condition('token_hash', $token_hash)
        ->execute();

      return $token_data;
    }
    catch (\Exception $e) {
      $this->logger->error('验证API令牌时发生错误: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 撤销API令牌。
   *
   * @param string $token_hash
   *   令牌哈希。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return bool
   *   操作是否成功。
   */
  public function revokeToken($token_hash, $tenant_id) {
    try {
      $affected = $this->database->update('baas_api_tokens')
        ->fields(['status' => 0])
        ->condition('token_hash', $token_hash)
        ->condition('tenant_id', $tenant_id)
        ->execute();

      if ($affected) {
        $this->logger->notice('已撤销租户 @tenant 的API令牌', [
          '@tenant' => $tenant_id,
        ]);
        return TRUE;
      }

      return FALSE;
    }
    catch (\Exception $e) {
      $this->logger->error('撤销API令牌失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 获取租户的所有活跃令牌。
   *
   * @param string $tenant_id
   *   租户ID。
   *
   * @return array
   *   令牌数组。
   */
  public function getTokens($tenant_id) {
    try {
      $query = $this->database->select('baas_api_tokens', 't')
        ->fields('t')
        ->condition('t.tenant_id', $tenant_id)
        ->orderBy('t.created', 'DESC');

      return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    }
    catch (\Exception $e) {
      $this->logger->error('获取API令牌列表失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * 创建新的令牌值。
   *
   * @return string
   *   生成的令牌值。
   */
  protected function createTokenValue() {
    return Crypt::randomBytesBase64(32);
  }

  /**
   * 对令牌进行哈希处理。
   *
   * @param string $token
   *   原始令牌。
   *
   * @return string
   *   哈希后的令牌。
   */
  protected function hashToken($token) {
    return hash('sha256', $token);
  }

  /**
   * 检查令牌是否具有所需的作用域。
   *
   * @param array $token_scopes
   *   令牌作用域。
   * @param array $required_scopes
   *   所需作用域。
   *
   * @return bool
   *   令牌是否具有所需的作用域。
   */
  protected function checkScopes(array $token_scopes, array $required_scopes) {
    // 如果令牌具有通配符作用域，则允许所有作用域
    if (in_array('*', $token_scopes)) {
      return TRUE;
    }

    // 如果没有指定所需作用域，则允许访问
    if (empty($required_scopes)) {
      return TRUE;
    }

    // 检查每个所需的作用域是否都在令牌作用域中
    foreach ($required_scopes as $scope) {
      if (!in_array($scope, $token_scopes)) {
        return FALSE;
      }
    }

    return TRUE;
  }
}
