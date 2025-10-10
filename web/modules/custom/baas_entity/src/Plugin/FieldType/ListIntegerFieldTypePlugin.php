<?php

namespace Drupal\baas_entity\Plugin\FieldType;

/**
 * 整数列表字段类型插件。
 *
 * 用于存储预定义的整数选项列表。
 */
class ListIntegerFieldTypePlugin extends BaseFieldTypePlugin
{

  /**
   * {@inheritdoc}
   */
  public function getFieldType(): string
  {
    return 'list_integer';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string
  {
    return $this->t('Integer List');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string
  {
    return $this->t('A field for storing values from a predefined list of integer options.');
  }

  /**
   * {@inheritdoc}
   */
  public function getStorageSchema(): array
  {
    return [
      'type' => 'int',
      'not null' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDrupalFieldType(): string
  {
    return 'list_integer';
  }

  /**
   * {@inheritdoc}
   */
  public function getWidgetType(): string
  {
    return 'options_select';
  }

  /**
   * {@inheritdoc}
   */
  public function getFormatterType(): string
  {
    return 'list_default';
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultSettings(): array
  {
    return [
      'allowed_values' => [],
      'multiple' => FALSE,
      'required' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSettingsForm(array $settings, array $form, $form_state): array
  {
    $form = [];

    $form['allowed_values'] = $this->createSettingField(
      'allowed_values',
      $this->t('Allowed values'),
      'textarea',
      $this->formatAllowedValuesForTextarea($settings['allowed_values'] ?? null),
      $this->t('Enter allowed integer values, one per line. Format: number|label or just number if number and label are the same.')
    );

    $form['multiple'] = $this->createSettingField(
      'multiple',
      $this->t('Allow multiple values'),
      'checkbox',
      $settings['multiple'] ?? FALSE,
      $this->t('Allow users to select multiple values.')
    );

    $form['required'] = $this->createSettingField(
      'required',
      $this->t('Required'),
      'checkbox',
      $settings['required'] ?? FALSE,
      $this->t('Whether this field is required.')
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
      $allowed_values = $settings['allowed_values'] ?? [];

      // 转换为整数
      $int_value = is_numeric($value) ? (int) $value : $value;

      if (is_array($int_value)) {
        // 多值情况
        foreach ($int_value as $single_value) {
          $single_int = is_numeric($single_value) ? (int) $single_value : $single_value;
          if (!$this->isValueAllowed($single_int, $allowed_values)) {
            $errors[] = $this->t('The value "@value" is not allowed.', [
              '@value' => $single_value,
            ]);
          }
        }
      } else {
        // 单值情况
        if (!$this->isValueAllowed($int_value, $allowed_values)) {
          $errors[] = $this->t('The value "@value" is not allowed.', [
            '@value' => $value,
          ]);
        }
      }
    }

    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function processValue($value, array $settings): mixed
  {
    $allowed_values = $settings['allowed_values'] ?? [];

    if (empty($value)) {
      return $value;
    }

    if (is_array($value)) {
      // 多值情况：过滤掉不允许的值并转换为整数
      return array_filter(array_map('intval', $value), function ($v) use ($allowed_values) {
        return $this->isValueAllowed($v, $allowed_values);
      });
    } else {
      // 单值情况：转换为整数并检查是否允许
      $int_value = is_numeric($value) ? (int) $value : 0;
      return $this->isValueAllowed($int_value, $allowed_values) ? $int_value : 0;
    }
  }

  /**
   * 检查值是否在允许的值列表中。
   *
   * @param mixed $value
   *   要检查的值。
   * @param array $allowed_values
   *   允许的值列表，可以是简单数组或关联数组。
   *
   * @return bool
   *   如果值被允许则返回TRUE。
   */
  protected function isValueAllowed($value, array $allowed_values): bool
  {
    // 如果是关联数组（key => label格式），检查键
    if ($this->isAssociativeArray($allowed_values)) {
      return array_key_exists($value, $allowed_values);
    }

    // 如果是简单数组，检查值
    return in_array($value, $allowed_values, TRUE);
  }

  /**
   * 检查数组是否为关联数组。
   *
   * @param array $array
   *   要检查的数组。
   *
   * @return bool
   *   如果是关联数组则返回TRUE。
   */
  protected function isAssociativeArray(array $array): bool
  {
    if (empty($array)) {
      return FALSE;
    }
    return array_keys($array) !== range(0, count($array) - 1);
  }

  /**
   * {@inheritdoc}
   */
  public function formatValue($value, array $settings, string $format = 'default'): string
  {
    $allowed_values = $settings['allowed_values'] ?? [];

    if (empty($value)) {
      return '';
    }

    if (is_array($value)) {
      // 多值情况
      $labels = [];
      foreach ($value as $single_value) {
        $int_value = (int) $single_value;
        $labels[] = $allowed_values[$int_value] ?? $int_value;
      }
      return implode(', ', $labels);
    } else {
      // 单值情况
      $int_value = (int) $value;
      return $allowed_values[$int_value] ?? $int_value;
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
   * 将允许值数组格式化为文本区域显示格式。
   *
   * @param array|null $allowed_values
   *   允许值数组，格式为 [key => label, ...]
   *
   * @return string
   *   格式化后的文本，每行一个值
   */
  protected function formatAllowedValuesForTextarea(?array $allowed_values): string
  {
    if (empty($allowed_values) || !is_array($allowed_values)) {
      return '';
    }

    $lines = [];
    foreach ($allowed_values as $key => $label) {
      if ($key === $label) {
        $lines[] = $key;
      } else {
        $lines[] = $key . '|' . $label;
      }
    }
    return implode("\n", $lines);
  }
}
