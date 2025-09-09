<?php

namespace Drupal\baas_entity\Plugin\FieldType;

/**
 * 字段类型插件基类。
 *
 * 提供字段类型插件的通用默认实现。
 */
abstract class BaseFieldTypePlugin implements FieldTypePluginInterface
{

  /**
   * {@inheritdoc}
   */
  public function getFieldType(): string
  {
    return 'base';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string
  {
    return $this->t('Base Field Type');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string
  {
    return $this->t('Base field type plugin.');
  }

  /**
   * {@inheritdoc}
   */
  public function getStorageSchema(): array
  {
    return [
      'type' => 'text',
      'size' => 'normal',
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
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getSettingsForm(array $settings, array $form, $form_state): array
  {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function validateValue($value, array $settings, array $context = []): array
  {
    $errors = [];

    // 检查必填字段
    if (!empty($context['required']) && empty($value)) {
      $errors[] = $this->t('This field is required.');
    }

    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function processValue($value, array $settings): mixed
  {
    return $value;
  }

  /**
   * {@inheritdoc}
   */
  public function formatValue($value, array $settings, string $format = 'default'): string
  {
    return (string) $value;
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
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int
  {
    return 0;
  }

  /**
   * 检查值是否为空。
   *
   * @param mixed $value
   *   要检查的值。
   *
   * @return bool
   *   如果值为空则返回TRUE。
   */
  protected function isEmpty($value): bool
  {
    return $value === NULL || $value === '' || $value === [];
  }

  /**
   * 创建设置表单字段。
   *
   * @param string $name
   *   字段名称。
   * @param string $title
   *   字段标题。
   * @param string $type
   *   字段类型。
   * @param mixed $default_value
   *   默认值。
   * @param string $description
   *   字段描述。
   *
   * @return array
   *   表单字段数组。
   */
  protected function createSettingField(string $name, string $title, string $type = 'textfield', $default_value = '', string $description = ''): array
  {
    return [
      '#type' => $type,
      '#title' => $title,
      '#default_value' => $default_value,
      '#description' => $description,
    ];
  }

  /**
   * 简单的翻译函数。
   *
   * @param string $string
   *   要翻译的字符串。
   * @param array $args
   *   替换参数。
   *
   * @return string
   *   翻译后的字符串。
   */
  protected function t(string $string, array $args = []): string
  {
    if (function_exists('t')) {
      return t($string, $args);
    }
    return strtr($string, $args);
  }
}
