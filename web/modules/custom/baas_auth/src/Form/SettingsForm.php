<?php

declare(strict_types=1);

namespace Drupal\baas_auth\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * BaaS认证模块设置表单
 *
 * 提供JWT、安全和权限相关的配置选项
 */
class SettingsForm extends ConfigFormBase
{

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'baas_auth_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array
  {
    return ['baas_auth.settings', 'baas_auth.jwt'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $config = $this->config('baas_auth.settings');
    $jwt_config = $this->config('baas_auth.jwt');

    // JWT配置部分
    $form['jwt'] = [
      '#type' => 'details',
      '#title' => $this->t('JWT令牌配置'),
      '#open' => TRUE,
    ];

    $form['jwt']['jwt_secret'] = [
      '#type' => 'textfield',
      '#title' => $this->t('JWT签名密钥'),
      '#description' => $this->t('用于JWT令牌签名的密钥，建议使用复杂的随机字符串'),
      '#default_value' => $jwt_config->get('secret_key') ?? $config->get('jwt.secret') ?? '',
      '#required' => TRUE,
      '#size' => 60,
    ];

    $form['jwt']['jwt_algorithm'] = [
      '#type' => 'select',
      '#title' => $this->t('签名算法'),
      '#description' => $this->t('JWT令牌的签名算法'),
      '#options' => [
        'HS256' => 'HS256 (HMAC using SHA-256)',
        'HS384' => 'HS384 (HMAC using SHA-384)',
        'HS512' => 'HS512 (HMAC using SHA-512)',
      ],
      '#default_value' => $jwt_config->get('algorithm') ?? $config->get('jwt.algorithm') ?? 'HS256',
      '#required' => TRUE,
    ];

    $form['jwt']['jwt_expire'] = [
      '#type' => 'number',
      '#title' => $this->t('访问令牌过期时间（秒）'),
      '#description' => $this->t('访问令牌的有效期。常用值：3600=1小时, 86400=1天, 604800=7天, 31536000=1年'),
      '#default_value' => $jwt_config->get('access_token_ttl') ?? $config->get('jwt.expire') ?? 3600,
      '#min' => 300,
      '#max' => 31536000, // 1年
      '#required' => TRUE,
    ];

    $form['jwt']['jwt_refresh_expire'] = [
      '#type' => 'number',
      '#title' => $this->t('刷新令牌过期时间（秒）'),
      '#description' => $this->t('刷新令牌的有效期。常用值：2592000=30天, 7776000=90天, 31536000=1年'),
      '#default_value' => $jwt_config->get('refresh_token_ttl') ?? $config->get('jwt.refresh_expire') ?? 2592000,
      '#min' => 86400, // 最少1天
      '#max' => 63072000, // 最多2年
      '#required' => TRUE,
    ];

    $form['jwt']['jwt_issuer'] = [
      '#type' => 'textfield',
      '#title' => $this->t('JWT发行者'),
      '#description' => $this->t('JWT令牌的发行者标识，通常使用域名'),
      '#default_value' => $jwt_config->get('issuer') ?? $config->get('jwt.issuer') ?? \Drupal::request()->getHost(),
      '#required' => TRUE,
    ];

    $form['jwt']['jwt_audience'] = [
      '#type' => 'textfield',
      '#title' => $this->t('JWT受众'),
      '#description' => $this->t('JWT令牌的目标受众'),
      '#default_value' => $jwt_config->get('audience') ?? $config->get('jwt.audience') ?? 'baas-clients',
      '#required' => TRUE,
    ];

    $form['jwt']['api_key_default_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('API密钥默认过期时间（秒）'),
      '#description' => $this->t('新创建API密钥的默认有效期（0表示永不过期）'),
      '#default_value' => $jwt_config->get('api_key_default_ttl') ?? $config->get('jwt.api_key_default_ttl') ?? 2592000, // 默认30天
      '#min' => 0,
      '#max' => 31536000, // 1年
      '#required' => TRUE,
    ];

    // 安全配置部分
    $form['security'] = [
      '#type' => 'details',
      '#title' => $this->t('安全配置'),
      '#open' => TRUE,
    ];

    $form['security']['max_login_attempts'] = [
      '#type' => 'number',
      '#title' => $this->t('最大登录尝试次数'),
      '#description' => $this->t('用户在被锁定前允许的最大登录失败次数'),
      '#default_value' => $config->get('security.max_login_attempts') ?? 5,
      '#min' => 1,
      '#max' => 20,
      '#required' => TRUE,
    ];

    $form['security']['lockout_duration'] = [
      '#type' => 'number',
      '#title' => $this->t('账户锁定时长（秒）'),
      '#description' => $this->t('账户被锁定后的恢复时间'),
      '#default_value' => $config->get('security.lockout_duration') ?? 900,
      '#min' => 60,
      '#max' => 7200,
      '#required' => TRUE,
    ];

    $form['security']['session_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('会话超时时间（秒）'),
      '#description' => $this->t('用户会话的最大空闲时间'),
      '#default_value' => $config->get('security.session_timeout') ?? 86400,
      '#min' => 300,
      '#max' => 604800,
      '#required' => TRUE,
    ];

    $form['security']['api_key_length'] = [
      '#type' => 'number',
      '#title' => $this->t('API密钥长度'),
      '#description' => $this->t('生成的API密钥字符长度'),
      '#default_value' => $config->get('security.api_key_length') ?? 32,
      '#min' => 16,
      '#max' => 128,
      '#required' => TRUE,
    ];

    $form['security']['password_min_length'] = [
      '#type' => 'number',
      '#title' => $this->t('密码最小长度'),
      '#description' => $this->t('用户密码的最小字符数'),
      '#default_value' => $config->get('security.password_min_length') ?? 8,
      '#min' => 6,
      '#max' => 32,
      '#required' => TRUE,
    ];

    $form['security']['require_special_chars'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('要求特殊字符'),
      '#description' => $this->t('密码必须包含特殊字符'),
      '#default_value' => $config->get('security.require_special_chars') ?? TRUE,
    ];

    // 权限配置部分
    $form['permissions'] = [
      '#type' => 'details',
      '#title' => $this->t('权限配置'),
      '#open' => TRUE,
    ];

    $form['permissions']['default_roles'] = [
      '#type' => 'textfield',
      '#title' => $this->t('默认角色'),
      '#description' => $this->t('新用户的默认角色，多个角色用逗号分隔'),
      '#default_value' => implode(', ', $config->get('permissions.default_roles') ?? ['authenticated']),
      '#required' => TRUE,
    ];

    $form['permissions']['admin_roles'] = [
      '#type' => 'textfield',
      '#title' => $this->t('管理员角色'),
      '#description' => $this->t('具有管理员权限的角色，多个角色用逗号分隔'),
      '#default_value' => implode(', ', $config->get('permissions.admin_roles') ?? ['administrator', 'super_admin']),
      '#required' => TRUE,
    ];

    $form['permissions']['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('权限缓存时间（秒）'),
      '#description' => $this->t('权限检查结果的缓存时间'),
      '#default_value' => $config->get('permissions.cache_ttl') ?? 3600,
      '#min' => 60,
      '#max' => 86400,
      '#required' => TRUE,
    ];

    $form['permissions']['tenant_isolation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用租户隔离'),
      '#description' => $this->t('严格执行租户间的数据隔离'),
      '#default_value' => $config->get('permissions.tenant_isolation') ?? TRUE,
    ];

    // 日志配置部分
    $form['logging'] = [
      '#type' => 'details',
      '#title' => $this->t('日志配置'),
      '#open' => FALSE,
    ];

    $form['logging']['enable_security_logs'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用安全日志'),
      '#description' => $this->t('记录认证和授权相关的安全事件'),
      '#default_value' => $config->get('logging.enable_security_logs') ?? TRUE,
    ];

    $form['logging']['log_level'] = [
      '#type' => 'select',
      '#title' => $this->t('日志级别'),
      '#description' => $this->t('记录的日志详细程度'),
      '#options' => [
        'error' => $this->t('错误'),
        'warning' => $this->t('警告'),
        'info' => $this->t('信息'),
        'debug' => $this->t('调试'),
      ],
      '#default_value' => $config->get('logging.log_level') ?? 'info',
      '#states' => [
        'visible' => [
          ':input[name="enable_security_logs"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['logging']['log_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('日志保留天数'),
      '#description' => $this->t('安全日志的保留时间'),
      '#default_value' => $config->get('logging.log_retention_days') ?? 90,
      '#min' => 1,
      '#max' => 365,
      '#states' => [
        'visible' => [
          ':input[name="enable_security_logs"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    parent::validateForm($form, $form_state);

    // 验证JWT密钥长度
    $jwt_secret = $form_state->getValue('jwt_secret');
    if (strlen($jwt_secret) < 32) {
      $form_state->setErrorByName('jwt_secret', $this->t('JWT密钥长度至少需要32个字符'));
    }

    // 验证过期时间逻辑
    $jwt_expire = (int) $form_state->getValue('jwt_expire');
    $jwt_refresh_expire = (int) $form_state->getValue('jwt_refresh_expire');
    if ($jwt_refresh_expire <= $jwt_expire) {
      $form_state->setErrorByName('jwt_refresh_expire', $this->t('刷新令牌过期时间必须大于访问令牌过期时间'));
    }

    // 验证角色配置格式
    $default_roles = $form_state->getValue('default_roles');
    if (!$this->validateRolesList($default_roles)) {
      $form_state->setErrorByName('default_roles', $this->t('默认角色格式无效，请使用逗号分隔的角色名称'));
    }

    $admin_roles = $form_state->getValue('admin_roles');
    if (!$this->validateRolesList($admin_roles)) {
      $form_state->setErrorByName('admin_roles', $this->t('管理员角色格式无效，请使用逗号分隔的角色名称'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $config = $this->config('baas_auth.settings');
    $jwt_config = $this->config('baas_auth.jwt');

    // 保存JWT配置到专用的JWT配置文件
    $jwt_config->set('secret_key', $form_state->getValue('jwt_secret'))
      ->set('algorithm', $form_state->getValue('jwt_algorithm'))
      ->set('access_token_ttl', (int) $form_state->getValue('jwt_expire'))
      ->set('refresh_token_ttl', (int) $form_state->getValue('jwt_refresh_expire'))
      ->set('issuer', $form_state->getValue('jwt_issuer'))
      ->set('audience', $form_state->getValue('jwt_audience'))
      ->set('api_key_default_ttl', (int) $form_state->getValue('api_key_default_ttl'))
      ->save();
      
    // 同时也保存到settings配置以兼容旧代码
    $config->set('jwt.secret', $form_state->getValue('jwt_secret'))
      ->set('jwt.algorithm', $form_state->getValue('jwt_algorithm'))
      ->set('jwt.expire', (int) $form_state->getValue('jwt_expire'))
      ->set('jwt.refresh_expire', (int) $form_state->getValue('jwt_refresh_expire'))
      ->set('jwt.issuer', $form_state->getValue('jwt_issuer'))
      ->set('jwt.audience', $form_state->getValue('jwt_audience'))
      ->set('jwt.api_key_default_ttl', (int) $form_state->getValue('api_key_default_ttl'));

    // 保存安全配置
    $config->set('security.max_login_attempts', (int) $form_state->getValue('max_login_attempts'))
      ->set('security.lockout_duration', (int) $form_state->getValue('lockout_duration'))
      ->set('security.session_timeout', (int) $form_state->getValue('session_timeout'))
      ->set('security.api_key_length', (int) $form_state->getValue('api_key_length'))
      ->set('security.password_min_length', (int) $form_state->getValue('password_min_length'))
      ->set('security.require_special_chars', (bool) $form_state->getValue('require_special_chars'));

    // 保存权限配置
    $default_roles = $this->parseRolesList($form_state->getValue('default_roles'));
    $admin_roles = $this->parseRolesList($form_state->getValue('admin_roles'));

    $config->set('permissions.default_roles', $default_roles)
      ->set('permissions.admin_roles', $admin_roles)
      ->set('permissions.cache_ttl', (int) $form_state->getValue('cache_ttl'))
      ->set('permissions.tenant_isolation', (bool) $form_state->getValue('tenant_isolation'));

    // 保存日志配置
    $config->set('logging.enable_security_logs', (bool) $form_state->getValue('enable_security_logs'))
      ->set('logging.log_level', $form_state->getValue('log_level'))
      ->set('logging.log_retention_days', (int) $form_state->getValue('log_retention_days'));

    $config->save();

    // 清除相关缓存
    \Drupal::cache('default')->deleteAll();
    \Drupal::service('cache.discovery')->deleteAll();

    parent::submitForm($form, $form_state);

    $this->messenger()->addMessage($this->t('BaaS认证模块配置已保存。'));
  }

  /**
   * 验证角色列表格式
   */
  private function validateRolesList(string $roles_string): bool
  {
    if (empty(trim($roles_string))) {
      return FALSE;
    }

    $roles = array_map('trim', explode(',', $roles_string));
    foreach ($roles as $role) {
      if (empty($role) || !preg_match('/^[a-zA-Z0-9_-]+$/', $role)) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * 解析角色列表字符串为数组
   */
  private function parseRolesList(string $roles_string): array
  {
    if (empty(trim($roles_string))) {
      return [];
    }

    return array_map('trim', explode(',', $roles_string));
  }
}
