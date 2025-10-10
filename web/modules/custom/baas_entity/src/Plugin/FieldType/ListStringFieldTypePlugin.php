<?php

namespace Drupal\baas_entity\Plugin\FieldType;

/**
 * 字符串列表字段类型插件。
 *
 * 用于存储预定义的字符串选项列表。
 */
class ListStringFieldTypePlugin extends BaseFieldTypePlugin
{

  /**
   * {@inheritdoc}
   */
  public function getFieldType(): string
  {
    return 'list_string';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string
  {
    return $this->t('String List');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string
  {
    return $this->t('A field for storing values from a predefined list of string options.');
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
    return 'list_string';
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
      $this->t('Enter allowed values, one per line. Format: key|label or just key if key and label are the same.')
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

      if (is_array($value)) {
        // 多值情况
        foreach ($value as $single_value) {
          if (!$this->isValueAllowed($single_value, $allowed_values)) {
            $errors[] = $this->t('The value "@value" is not allowed.', [
              '@value' => $single_value,
            ]);
          }
        }
      } else {
        // 单值情况
        if (!$this->isValueAllowed($value, $allowed_values)) {
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
      // 多值情况：过滤掉不允许的值
      return array_filter($value, function ($v) use ($allowed_values) {
        return $this->isValueAllowed($v, $allowed_values);
      });
    } else {
      // 单值情况：检查是否允许
      return $this->isValueAllowed($value, $allowed_values) ? $value : '';
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
        $labels[] = $allowed_values[$single_value] ?? $single_value;
      }
      return implode(', ', $labels);
    } else {
      // 单值情况
      return $allowed_values[$value] ?? $value;
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
