<?php

namespace Drupal\baas_entity\Plugin\FieldType;

/**
 * 字段类型插件接口。
 *
 * 定义所有字段类型插件必须实现的方法。
 */
interface FieldTypePluginInterface
{

  /**
   * 获取字段类型标识符。
   *
   * @return string
   *   字段类型标识符（如：string, url, color等）。
   */
  public function getFieldType(): string;

  /**
   * 获取字段类型显示名称。
   *
   * @return string
   *   字段类型的人类可读名称。
   */
  public function getLabel(): string;

  /**
   * 获取字段类型描述。
   *
   * @return string
   *   字段类型的详细描述。
   */
  public function getDescription(): string;

  /**
   * 获取数据库存储Schema定义。
   *
   * @return array
   *   数据库字段Schema定义。
   */
  public function getStorageSchema(): array;

  /**
   * 获取对应的Drupal字段类型。
   *
   * @return string
   *   Drupal核心字段类型。
   */
  public function getDrupalFieldType(): string;

  /**
   * 获取默认的Widget类型。
   *
   * @return string
   *   Widget类型标识符。
   */
  public function getWidgetType(): string;

  /**
   * 获取默认的Formatter类型。
   *
   * @return string
   *   Formatter类型标识符。
   */
  public function getFormatterType(): string;

  /**
   * 获取字段默认设置。
   *
   * @return array
   *   默认设置数组。
   */
  public function getDefaultSettings(): array;

  /**
   * 获取字段设置表单。
   *
   * @param array $settings
   *   当前设置值。
   * @param array $form
   *   表单数组。
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   表单状态。
   *
   * @return array
   *   设置表单数组。
   */
  public function getSettingsForm(array $settings, array $form, $form_state): array;

  /**
   * 验证字段值。
   *
   * @param mixed $value
   *   要验证的值。
   * @param array $settings
   *   字段设置。
   * @param array $context
   *   验证上下文（如租户ID、实体ID等）。
   *
   * @return array
   *   验证错误数组，空数组表示验证通过。
   */
  public function validateValue($value, array $settings, array $context = []): array;

  /**
   * 处理字段值（保存前的数据处理）。
   *
   * @param mixed $value
   *   原始值。
   * @param array $settings
   *   字段设置。
   *
   * @return mixed
   *   处理后的值。
   */
  public function processValue($value, array $settings);

  /**
   * 格式化字段值用于显示。
   *
   * @param mixed $value
   *   字段值。
   * @param array $settings
   *   字段设置。
   * @param string $format
   *   显示格式。
   *
   * @return mixed
   *   格式化后的值。
   */
  public function formatValue($value, array $settings, string $format = 'default');

  /**
   * 检查字段类型是否支持多值。
   *
   * @return bool
   *   是否支持多值。
   */
  public function supportsMultiple(): bool;

  /**
   * 检查字段类型是否需要数据库索引。
   *
   * @return bool
   *   是否需要索引。
   */
  public function needsIndex(): bool;

  /**
   * 获取字段类型的权重（用于排序）。
   *
   * @return int
   *   权重值，数字越小越靠前。
   */
  public function getWeight(): int;
}
