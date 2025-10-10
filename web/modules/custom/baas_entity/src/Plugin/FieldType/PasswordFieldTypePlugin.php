<?php

namespace Drupal\baas_entity\Plugin\FieldType;

/**
 * 密码字段类型插件。
 *
 * 用于安全存储密码，提供自动哈希和验证功能。
 */
class PasswordFieldTypePlugin extends BaseFieldTypePlugin
{

  /**
   * {@inheritdoc}
   */
  public function getFieldType(): string
  {
    return 'password';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string
  {
    return $this->t('Password');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string
  {
    return $this->t('A field for securely storing hashed passwords with automatic encryption.');
  }

  /**
   * {@inheritdoc}
   */
  public function getStorageSchema(): array
  {
    return [
      'type' => 'varchar',
      'length' => 255, // 足够存储哈希值
      'not null' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDrupalFieldType(): string
  {
    return 'string';
  }

  /**
   * {@inheritdoc}
   */
  public function getWidgetType(): string
  {
    return 'password';
  }

  /**
   * {@inheritdoc}
   */
  public function getFormatterType(): string
  {
    return 'password_hidden';
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultSettings(): array
  {
    return [
      'min_length' => 6,
      'max_length' => 128,
      'required' => TRUE,
      'hash_algorithm' => 'bcrypt',
      'hide_in_api' => TRUE, // 密码字段默认在API响应中隐藏
      'confirm_password' => FALSE,
      'password_policy' => [
        'require_uppercase' => FALSE,
        'require_lowercase' => FALSE,
        'require_numbers' => FALSE,
        'require_special_chars' => FALSE,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSettingsForm(array $settings, array $form, $form_state): array
  {
    $form = [];

    $form['min_length'] = $this->createSettingField(
      'min_length',
      $this->t('Minimum length'),
      'number',
      $settings['min_length'] ?? 6,
      $this->t('The minimum length of the password.')
    );

    $form['max_length'] = $this->createSettingField(
      'max_length',
      $this->t('Maximum length'),
      'number',
      $settings['max_length'] ?? 128,
      $this->t('The maximum length of the password before hashing.')
    );

    $form['required'] = $this->createSettingField(
      'required',
      $this->t('Required'),
      'checkbox',
      $settings['required'] ?? TRUE,
      $this->t('Whether this field is required.')
    );

    $form['hash_algorithm'] = $this->createSettingField(
      'hash_algorithm',
      $this->t('Hash algorithm'),
      'select',
      $settings['hash_algorithm'] ?? 'bcrypt',
      $this->t('The algorithm used to hash passwords.'),
      [
        'bcrypt' => 'bcrypt (recommended)',
        'argon2i' => 'Argon2i',
        'argon2id' => 'Argon2id',
      ]
    );

    $form['hide_in_api'] = $this->createSettingField(
      'hide_in_api',
      $this->t('Hide in API responses'),
      'checkbox',
      $settings['hide_in_api'] ?? TRUE,
      $this->t('Whether to hide this field in API responses for security.')
    );

    $form['confirm_password'] = $this->createSettingField(
      'confirm_password',
      $this->t('Require password confirmation'),
      'checkbox',
      $settings['confirm_password'] ?? FALSE,
      $this->t('Whether to require password confirmation on forms.')
    );

    // 密码策略设置
    $form['password_policy'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Password Policy'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
    ];

    $policy = $settings['password_policy'] ?? [];

    $form['password_policy']['require_uppercase'] = $this->createSettingField(
      'require_uppercase',
      $this->t('Require uppercase letters'),
      'checkbox',
      $policy['require_uppercase'] ?? FALSE,
      $this->t('Password must contain at least one uppercase letter.')
    );

    $form['password_policy']['require_lowercase'] = $this->createSettingField(
      'require_lowercase',
      $this->t('Require lowercase letters'),
      'checkbox',
      $policy['require_lowercase'] ?? FALSE,
      $this->t('Password must contain at least one lowercase letter.')
    );

    $form['password_policy']['require_numbers'] = $this->createSettingField(
      'require_numbers',
      $this->t('Require numbers'),
      'checkbox',
      $policy['require_numbers'] ?? FALSE,
      $this->t('Password must contain at least one number.')
    );

    $form['password_policy']['require_special_chars'] = $this->createSettingField(
      'require_special_chars',
      $this->t('Require special characters'),
      'checkbox',
      $policy['require_special_chars'] ?? FALSE,
      $this->t('Password must contain at least one special character.')
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateValue($value, array $settings, array $context = []): array
  {
    $errors = parent::validateValue($value, $settings, $context);

    // 如果是空值且不必填，跳过验证
    if (empty($value) && empty($settings['required'])) {
      return $errors;
    }

    // 检查最小长度
    $min_length = $settings['min_length'] ?? 6;
    if (strlen($value) < $min_length) {
      $errors[] = $this->t('Password must be at least @min characters long.', [
        '@min' => $min_length,
      ]);
    }

    // 检查最大长度
    $max_length = $settings['max_length'] ?? 128;
    if (strlen($value) > $max_length) {
      $errors[] = $this->t('Password cannot be longer than @max characters.', [
        '@max' => $max_length,
      ]);
    }

    // 密码策略验证
    $policy = $settings['password_policy'] ?? [];

    if (!empty($policy['require_uppercase']) && !preg_match('/[A-Z]/', $value)) {
      $errors[] = $this->t('Password must contain at least one uppercase letter.');
    }

    if (!empty($policy['require_lowercase']) && !preg_match('/[a-z]/', $value)) {
      $errors[] = $this->t('Password must contain at least one lowercase letter.');
    }

    if (!empty($policy['require_numbers']) && !preg_match('/[0-9]/', $value)) {
      $errors[] = $this->t('Password must contain at least one number.');
    }

    if (!empty($policy['require_special_chars']) && !preg_match('/[^A-Za-z0-9]/', $value)) {
      $errors[] = $this->t('Password must contain at least one special character.');
    }

    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function processValue($value, array $settings): mixed
  {
    // 如果值为空，返回空值
    if (empty($value)) {
      return $value;
    }

    // 如果值已经是哈希值（以$开头且长度>50），不再处理
    if (is_string($value) && strlen($value) > 50 && str_starts_with($value, '$')) {
      return $value;
    }

    // 对密码进行哈希处理
    return $this->hashPassword($value, $settings);
  }

  /**
   * {@inheritdoc}
   */
  public function formatValue($value, array $settings, string $format = 'default'): string
  {
    // 密码字段永远不显示实际值
    switch ($format) {
      case 'hidden':
        return '••••••••';

      case 'length':
        return $this->t('@count characters', ['@count' => strlen($value)]);

      case 'status':
        return empty($value) ? $this->t('Not set') : $this->t('Set');

      default:
        return '••••••••';
    }
  }

  /**
   * 哈希密码。
   *
   * @param string $password
   *   原始密码。
   * @param array $settings
   *   字段设置。
   *
   * @return string
   *   哈希后的密码。
   */
  protected function hashPassword(string $password, array $settings): string
  {
    $algorithm = $settings['hash_algorithm'] ?? 'bcrypt';
    $options = [];

    switch ($algorithm) {
      case 'argon2i':
        if (defined('PASSWORD_ARGON2I')) {
          return password_hash($password, PASSWORD_ARGON2I, $options);
        }
        // 如果不支持，回退到bcrypt
        // fallthrough

      case 'argon2id':
        if (defined('PASSWORD_ARGON2ID')) {
          return password_hash($password, PASSWORD_ARGON2ID, $options);
        }
        // 如果不支持，回退到bcrypt
        // fallthrough

      case 'bcrypt':
      default:
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
  }

  /**
   * 验证密码。
   *
   * @param string $password
   *   原始密码。
   * @param string $hash
   *   存储的哈希值。
   *
   * @return bool
   *   验证结果。
   */
  public function verifyPassword(string $password, string $hash): bool
  {
    return password_verify($password, $hash);
  }

  /**
   * {@inheritdoc}
   */
  public function supportsMultiple(): bool
  {
    return FALSE; // 密码字段不支持多值
  }

  /**
   * {@inheritdoc}
   */
  public function needsIndex(): bool
  {
    return FALSE; // 密码字段不需要索引
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int
  {
    return 10;
  }

  /**
   * 检查字段值是否应该在API响应中隐藏。
   *
   * @param array $settings
   *   字段设置。
   *
   * @return bool
   *   是否隐藏。
   */
  public function shouldHideInApi(array $settings): bool
  {
    return $settings['hide_in_api'] ?? TRUE;
  }
}