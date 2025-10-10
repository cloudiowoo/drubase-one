<?php

namespace Drupal\baas_auth\Service;

use Drupal\Core\Password\PasswordInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * 密码服务实现。
 */
class PasswordService
{

  /**
   * 密码服务。
   *
   * @var \Drupal\Core\Password\PasswordInterface
   */
  protected $passwordHasher;

  /**
   * 日志记录器。
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * 构造函数。
   *
   * @param \Drupal\Core\Password\PasswordInterface $password_hasher
   *   密码哈希服务。
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂。
   */
  public function __construct(
    PasswordInterface $password_hasher,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->passwordHasher = $password_hasher;
    $this->logger = $logger_factory->get('baas_auth');
  }

  /**
   * 哈希密码。
   *
   * @param string $password
   *   明文密码。
   *
   * @return string
   *   哈希后的密码。
   */
  public function hashPassword(string $password): string
  {
    try {
      $hashed = $this->passwordHasher->hash($password);
      $this->logger->info('密码哈希成功');
      return $hashed;
    } catch (\Exception $e) {
      $this->logger->error('密码哈希失败: @message', ['@message' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * 验证密码。
   *
   * @param string $password
   *   明文密码。
   * @param string $hash
   *   哈希值。
   *
   * @return bool
   *   验证成功返回TRUE。
   */
  public function verifyPassword(string $password, string $hash): bool
  {
    try {
      $result = $this->passwordHasher->check($password, $hash);

      if ($result) {
        $this->logger->info('密码验证成功');
      } else {
        $this->logger->warning('密码验证失败');
      }

      return $result;
    } catch (\Exception $e) {
      $this->logger->error('密码验证出错: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * 检查密码是否需要重新哈希。
   *
   * @param string $hash
   *   密码哈希值。
   *
   * @return bool
   *   需要重新哈希返回TRUE。
   */
  public function needsRehash(string $hash): bool
  {
    try {
      return $this->passwordHasher->needsRehash($hash);
    } catch (\Exception $e) {
      $this->logger->error('检查密码重新哈希出错: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * 生成随机密码。
   *
   * @param int $length
   *   密码长度。
   *
   * @return string
   *   生成的随机密码。
   */
  public function generateRandomPassword(int $length = 12): string
  {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*';
    $charactersLength = strlen($characters);
    $randomPassword = '';

    for ($i = 0; $i < $length; $i++) {
      $randomPassword .= $characters[random_int(0, $charactersLength - 1)];
    }

    $this->logger->info('生成随机密码');
    return $randomPassword;
  }

  /**
   * 验证密码强度。
   *
   * @param string $password
   *   密码。
   *
   * @return array
   *   包含验证结果和错误信息的数组。
   */
  public function validatePasswordStrength(string $password): array
  {
    $errors = [];

    // 最小长度检查
    if (strlen($password) < 8) {
      $errors[] = '密码长度至少8位';
    }

    // 包含数字
    if (!preg_match('/[0-9]/', $password)) {
      $errors[] = '密码必须包含至少一个数字';
    }

    // 包含小写字母
    if (!preg_match('/[a-z]/', $password)) {
      $errors[] = '密码必须包含至少一个小写字母';
    }

    // 包含大写字母
    if (!preg_match('/[A-Z]/', $password)) {
      $errors[] = '密码必须包含至少一个大写字母';
    }

    // 包含特殊字符
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password)) {
      $errors[] = '密码必须包含至少一个特殊字符';
    }

    $is_valid = empty($errors);

    if ($is_valid) {
      $this->logger->info('密码强度验证通过');
    } else {
      $this->logger->warning('密码强度验证失败: @errors', ['@errors' => implode(', ', $errors)]);
    }

    return [
      'valid' => $is_valid,
      'errors' => $errors,
    ];
  }
}
