<?php

namespace Drupal\baas_entity\Plugin\FieldType;

/**
 * 字符串字段类型插件。
 *
 * 用于存储短文本字符串，具有最大长度限制。
 */
class StringFieldTypePlugin extends BaseFieldTypePlugin
{

  /**
   * {@inheritdoc}
   */
  public function getFieldType(): string
  {
    return 'string';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string
  {
    return $this->t('String');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string
  {
    return $this->t('A field for storing short text strings with a maximum length.');
  }

  /**
   * {@inheritdoc}
   */
  public function getStorageSchema(): array
  {
    return [
      'type' => 'varchar',
      'length' => 255,
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
    return 'string_textfield';
  }

  /**
   * {@inheritdoc}
   */
  public function getFormatterType(): string
  {
    return 'string';
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultSettings(): array
  {
    return [
      'max_length' => 255,
      'required' => FALSE,
      'default_value' => '',
      'placeholder' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSettingsForm(array $settings, array $form, $form_state): array
  {
    $form = [];

    $form['max_length'] = $this->createSettingField(
      'max_length',
      $this->t('Maximum length'),
      'number',
      $settings['max_length'] ?? 255,
      $this->t('The maximum length of the string in characters.')
    );

    $form['required'] = $this->createSettingField(
      'required',
      $this->t('Required'),
      'checkbox',
      $settings['required'] ?? FALSE,
      $this->t('Whether this field is required.')
    );

    $form['default_value'] = $this->createSettingField(
      'default_value',
      $this->t('Default value'),
      'textfield',
      $settings['default_value'] ?? '',
      $this->t('The default value for this field.')
    );

    $form['placeholder'] = $this->createSettingField(
      'placeholder',
      $this->t('Placeholder'),
      'textfield',
      $settings['placeholder'] ?? '',
      $this->t('Placeholder text for the input field.')
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateValue($value, array $settings, array $context = []): array
  {
    $errors = parent::validateValue($value, $settings, $context);

    if (!empty($value)) {
      $max_length = $settings['max_length'] ?? 255;
      if (strlen($value) > $max_length) {
        $errors[] = $this->t('The value cannot be longer than @max characters.', [
          '@max' => $max_length,
        ]);
      }
    }

    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function processValue($value, array $settings): mixed
  {
    // 去除前后空白字符
    $value = trim($value);

    // 如果值为空且有默认值，使用默认值
    if (empty($value) && !empty($settings['default_value'])) {
      $value = $settings['default_value'];
    }

    // 强制执行最大长度限制
    $max_length = $settings['max_length'] ?? 255;
    if (strlen($value) > $max_length) {
      $value = substr($value, 0, $max_length);
    }

    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function formatValue($value, array $settings, string $format = 'default'): string
  {
    switch ($format) {
      case 'plain':
        return strip_tags($value);

      case 'truncated':
        $max_length = $settings['display_length'] ?? 50;
        if (strlen($value) > $max_length) {
          return substr($value, 0, $max_length) . '...';
        }
        return $value;

      default:
        return $value;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function supportsMultiple(): bool
  {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function needsIndex(): bool
  {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int
  {
    return 0;
  }
}
