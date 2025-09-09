<?php

namespace Drupal\baas_auth\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * JWT令牌管理服务实现。
 */
class JwtTokenManager implements JwtTokenManagerInterface
{

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
   * 日志记录器。
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * JWT黑名单服务。
   *
   * @var \Drupal\baas_auth\Service\JwtBlacklistServiceInterface
   */
  protected $blacklistService;

  /**
   * 构造函数。
   *
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接。
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   配置工厂。
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂。
   * @param \Drupal\baas_auth\Service\JwtBlacklistServiceInterface $blacklist_service
   *   JWT黑名单服务。
   */
  public function __construct(
    Connection $database,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    JwtBlacklistServiceInterface $blacklist_service
  ) {
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('baas_auth');
    $this->blacklistService = $blacklist_service;
  }

  /**
   * {@inheritdoc}
   */
  public function generateAccessToken(array $payload): string
  {
    $now = time();
    $config = $this->configFactory->get('baas_auth.jwt');
    $ttl = $config->get('access_token_ttl') ?? 3600; // 默认1小时
    $expiry = $now + $ttl;

    // 设置标准JWT声明
    $payload['iss'] = 'drubase';
    $payload['aud'] = 'drubase-api';
    $payload['iat'] = $now;
    $payload['exp'] = $expiry;
    $payload['jti'] = $this->generateJti();
    $payload['type'] = 'access';

    return JWT::encode($payload, $this->getSecret(), 'HS256');
  }

  /**
   * {@inheritdoc}
   */
  public function generateRefreshToken(array $payload): string
  {
    $now = time();
    $config = $this->configFactory->get('baas_auth.jwt');
    $ttl = $config->get('refresh_token_ttl') ?? 604800; // 默认7天
    $expiry = $now + $ttl;

    // 设置标准JWT声明
    $payload['iss'] = 'drubase';
    $payload['aud'] = 'drubase-api';
    $payload['iat'] = $now;
    $payload['exp'] = $expiry;
    $payload['jti'] = $this->generateJti();
    $payload['type'] = 'refresh';

    return JWT::encode($payload, $this->getSecret(), 'HS256');
  }

  /**
   * {@inheritdoc}
   */
  public function validateToken(string $token): ?array
  {
    try {
      $decoded = JWT::decode($token, new Key($this->getSecret(), 'HS256'));
      $payload = (array) $decoded;

      if ($this->isTokenExpired($payload)) {
        return NULL;
      }

      // 检查令牌是否在黑名单中
      $jti = $payload['jti'] ?? '';
      if (!empty($jti) && $this->blacklistService->isBlacklisted($jti)) {
        $this->logger->info('JWT令牌已被列入黑名单: @jti', ['@jti' => $jti]);
        return NULL;
      }

      // 检查令牌是否被时间戳黑名单策略阻止
      if ($this->blacklistService->isTokenBlacklistedByTimestamp($payload)) {
        $this->logger->info('JWT令牌被时间戳黑名单策略阻止，用户: @user_id，租户: @tenant_id，签发时间: @iat', [
          '@user_id' => $payload['sub'] ?? 'unknown',
          '@tenant_id' => $payload['tenant_id'] ?? 'unknown',
          '@iat' => isset($payload['iat']) ? date('Y-m-d H:i:s', $payload['iat']) : 'unknown',
        ]);
        return NULL;
      }

      return $payload;
    } catch (\Exception $e) {
      $this->logger->warning('JWT验证失败: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function parseToken(string $token): ?array
  {
    try {
      $decoded = JWT::decode($token, new Key($this->getSecret(), 'HS256'));
      return (array) $decoded;
    } catch (\Exception $e) {
      $this->logger->warning('JWT解析失败: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isTokenExpired(array $payload): bool
  {
    return isset($payload['exp']) && $payload['exp'] < time();
  }

  /**
   * 生成令牌ID。
   *
   * @return string
   *   唯一的令牌ID。
   */
  protected function generateJti(): string
  {
    return bin2hex(random_bytes(16));
  }

  /**
   * 获取JWT密钥。
   *
   * @return string
   *   JWT密钥。
   */
  protected function getSecret(): string
  {
    // 先尝试从JWT配置获取secret_key
    $jwt_config = $this->configFactory->get('baas_auth.jwt');
    $secret = $jwt_config->get('secret_key');
    
    // 如果JWT配置没有，则从settings配置获取
    if (empty($secret) || $secret === 'auto_generated_secret') {
      $settings_config = $this->configFactory->get('baas_auth.settings');
      $secret = $settings_config->get('jwt.secret');
    }

    if (empty($secret) || $secret === 'your-secret-key-here-please-change-in-production') {
      // 如果没有配置密钥，生成一个默认密钥
      $secret = 'drubase_default_jwt_secret_' . hash('sha256', 'drubase');
      $this->logger->warning('使用默认JWT密钥，建议在配置中设置自定义密钥。');
    }

    return $secret;
  }
}
