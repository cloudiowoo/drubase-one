<?php
/*
 * @Date: 2025-06-01 20:36:28
 * @LastEditors: cloudio cloudio.woo@gmail.com
 * @LastEditTime: 2025-06-02 11:31:38
 * @FilePath: /drubase/web/modules/custom/baas_entity/tests/src/Unit/FieldTypePluginTest.php
 */

namespace Drupal\Tests\baas_entity\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 字段类型插件系统单元测试。
 *
 * @group baas_entity
 */
class FieldTypePluginTest extends TestCase
{

  /**
   * 字段类型管理器。
   *
   * @var \Drupal\baas_entity\Service\FieldTypeManager
   */
  protected $fieldTypeManager;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    // 手动加载必要的类
    require_once __DIR__ . '/../../../src/Plugin/FieldType/FieldTypePluginInterface.php';
    require_once __DIR__ . '/../../../src/Plugin/FieldType/BaseFieldTypePlugin.php';
    require_once __DIR__ . '/../../../src/Plugin/FieldType/StringFieldTypePlugin.php';
    require_once __DIR__ . '/../../../src/Plugin/FieldType/ListStringFieldTypePlugin.php';
    require_once __DIR__ . '/../../../src/Plugin/FieldType/ListIntegerFieldTypePlugin.php';
    require_once __DIR__ . '/../../../src/Service/FieldTypeManager.php';

    $this->fieldTypeManager = new \Drupal\baas_entity\Service\FieldTypeManager();
  }

  /**
   * 测试字段类型插件注册。
   */
  public function testPluginRegistration(): void
  {
    // 创建字符串字段类型插件
    $stringPlugin = new \Drupal\baas_entity\Plugin\FieldType\StringFieldTypePlugin();

    // 注册插件
    $this->fieldTypeManager->registerPlugin($stringPlugin);

    // 验证插件已注册
    $this->assertTrue($this->fieldTypeManager->hasType('string'));
    $this->assertInstanceOf(\Drupal\baas_entity\Plugin\FieldType\StringFieldTypePlugin::class, $this->fieldTypeManager->getPlugin('string'));
  }

  /**
   * 测试列表字段类型插件注册。
   */
  public function testListPluginRegistration(): void
  {
    // 验证默认注册的列表插件
    $this->assertTrue($this->fieldTypeManager->hasType('list_string'));
    $this->assertTrue($this->fieldTypeManager->hasType('list_integer'));

    $this->assertInstanceOf(\Drupal\baas_entity\Plugin\FieldType\ListStringFieldTypePlugin::class, $this->fieldTypeManager->getPlugin('list_string'));
    $this->assertInstanceOf(\Drupal\baas_entity\Plugin\FieldType\ListIntegerFieldTypePlugin::class, $this->fieldTypeManager->getPlugin('list_integer'));
  }

  /**
   * 测试获取可用字段类型。
   */
  public function testGetAvailableTypes(): void
  {
    // 注册字符串插件
    $stringPlugin = new \Drupal\baas_entity\Plugin\FieldType\StringFieldTypePlugin();
    $this->fieldTypeManager->registerPlugin($stringPlugin);

    // 获取可用类型
    $types = $this->fieldTypeManager->getAvailableTypes();

    // 验证返回的类型
    $this->assertIsArray($types);
    $this->assertArrayHasKey('string', $types);
    $this->assertArrayHasKey('list_string', $types);
    $this->assertArrayHasKey('list_integer', $types);
    $this->assertEquals('String', $types['string']);
    $this->assertEquals('String List', $types['list_string']);
    $this->assertEquals('Integer List', $types['list_integer']);
  }

  /**
   * 测试字段类型信息获取。
   */
  public function testGetTypeInfo(): void
  {
    // 注册字符串插件
    $stringPlugin = new \Drupal\baas_entity\Plugin\FieldType\StringFieldTypePlugin();
    $this->fieldTypeManager->registerPlugin($stringPlugin);

    // 获取类型信息
    $info = $this->fieldTypeManager->getTypeInfo('string');

    // 验证信息结构
    $this->assertIsArray($info);
    $this->assertEquals('string', $info['type']);
    $this->assertEquals('String', $info['label']);
    $this->assertEquals('string', $info['drupal_type']);
    $this->assertEquals('string_textfield', $info['widget_type']);
    $this->assertEquals('string', $info['formatter_type']);
    $this->assertTrue($info['supports_multiple']);
    $this->assertTrue($info['needs_index']);
    $this->assertEquals(0, $info['weight']);
  }

  /**
   * 测试列表字段类型信息获取。
   */
  public function testListTypeInfo(): void
  {
    // 测试 list_string 类型信息
    $info = $this->fieldTypeManager->getTypeInfo('list_string');
    $this->assertIsArray($info);
    $this->assertEquals('list_string', $info['type']);
    $this->assertEquals('String List', $info['label']);
    $this->assertEquals('list_string', $info['drupal_type']);
    $this->assertEquals('options_select', $info['widget_type']);
    $this->assertEquals('list_default', $info['formatter_type']);
    $this->assertTrue($info['supports_multiple']);
    $this->assertTrue($info['needs_index']);

    // 测试 list_integer 类型信息
    $info = $this->fieldTypeManager->getTypeInfo('list_integer');
    $this->assertIsArray($info);
    $this->assertEquals('list_integer', $info['type']);
    $this->assertEquals('Integer List', $info['label']);
    $this->assertEquals('list_integer', $info['drupal_type']);
    $this->assertEquals('options_select', $info['widget_type']);
    $this->assertEquals('list_default', $info['formatter_type']);
    $this->assertTrue($info['supports_multiple']);
    $this->assertTrue($info['needs_index']);
  }

  /**
   * 测试存储Schema获取。
   */
  public function testGetStorageSchema(): void
  {
    // 注册字符串插件
    $stringPlugin = new \Drupal\baas_entity\Plugin\FieldType\StringFieldTypePlugin();
    $this->fieldTypeManager->registerPlugin($stringPlugin);

    // 获取存储Schema
    $schema = $this->fieldTypeManager->getStorageSchema('string');

    // 验证Schema结构
    $this->assertIsArray($schema);
    $this->assertEquals('varchar', $schema['type']);
    $this->assertEquals(255, $schema['length']);
    $this->assertFalse($schema['not null']);
  }

  /**
   * 测试列表字段存储Schema获取。
   */
  public function testListStorageSchema(): void
  {
    // 测试 list_string Schema
    $schema = $this->fieldTypeManager->getStorageSchema('list_string');
    $this->assertIsArray($schema);
    $this->assertEquals('varchar', $schema['type']);
    $this->assertEquals(255, $schema['length']);
    $this->assertFalse($schema['not null']);

    // 测试 list_integer Schema
    $schema = $this->fieldTypeManager->getStorageSchema('list_integer');
    $this->assertIsArray($schema);
    $this->assertEquals('int', $schema['type']);
    $this->assertFalse($schema['not null']);
  }

  /**
   * 测试字段值验证。
   */
  public function testValidateValue(): void
  {
    // 注册字符串插件
    $stringPlugin = new \Drupal\baas_entity\Plugin\FieldType\StringFieldTypePlugin();
    $this->fieldTypeManager->registerPlugin($stringPlugin);

    // 测试有效值
    $errors = $this->fieldTypeManager->validateValue('string', 'test value', ['max_length' => 255]);
    $this->assertEmpty($errors);

    // 测试超长值
    $longValue = str_repeat('a', 300);
    $errors = $this->fieldTypeManager->validateValue('string', $longValue, ['max_length' => 255]);
    $this->assertNotEmpty($errors);

    // 测试必填验证
    $errors = $this->fieldTypeManager->validateValue('string', '', [], ['required' => TRUE]);
    $this->assertNotEmpty($errors);
  }

  /**
   * 测试列表字段值验证。
   */
  public function testListValidateValue(): void
  {
    // 测试 list_string 验证
    $settings = [
      'allowed_values' => ['option1' => 'Option 1', 'option2' => 'Option 2'],
      'multiple' => false,
      'required' => false,
    ];

    // 测试有效值
    $errors = $this->fieldTypeManager->validateValue('list_string', 'option1', $settings);
    $this->assertEmpty($errors);

    // 测试无效值
    $errors = $this->fieldTypeManager->validateValue('list_string', 'invalid_option', $settings);
    $this->assertNotEmpty($errors);

    // 测试多值
    $settings['multiple'] = true;
    $errors = $this->fieldTypeManager->validateValue('list_string', ['option1', 'option2'], $settings);
    $this->assertEmpty($errors);

    $errors = $this->fieldTypeManager->validateValue('list_string', ['option1', 'invalid'], $settings);
    $this->assertNotEmpty($errors);

    // 测试 list_integer 验证
    $settings = [
      'allowed_values' => [1 => 'One', 2 => 'Two', 3 => 'Three'],
      'multiple' => false,
      'required' => false,
    ];

    // 测试有效值
    $errors = $this->fieldTypeManager->validateValue('list_integer', 1, $settings);
    $this->assertEmpty($errors);

    // 测试无效值
    $errors = $this->fieldTypeManager->validateValue('list_integer', 99, $settings);
    $this->assertNotEmpty($errors);

    // 测试多值
    $settings['multiple'] = true;
    $errors = $this->fieldTypeManager->validateValue('list_integer', [1, 2], $settings);
    $this->assertEmpty($errors);

    $errors = $this->fieldTypeManager->validateValue('list_integer', [1, 99], $settings);
    $this->assertNotEmpty($errors);
  }

  /**
   * 测试字段值处理。
   */
  public function testProcessValue(): void
  {
    // 注册字符串插件
    $stringPlugin = new \Drupal\baas_entity\Plugin\FieldType\StringFieldTypePlugin();
    $this->fieldTypeManager->registerPlugin($stringPlugin);

    // 测试值处理
    $processed = $this->fieldTypeManager->processValue('string', '  test value  ', ['max_length' => 255]);
    $this->assertEquals('test value', $processed);

    // 测试空值处理
    $processed = $this->fieldTypeManager->processValue('string', '', ['default_value' => 'default']);
    $this->assertEquals('default', $processed);

    // 测试超长值截断
    $longValue = str_repeat('a', 300);
    $processed = $this->fieldTypeManager->processValue('string', $longValue, ['max_length' => 255]);
    $this->assertEquals(255, strlen($processed));
  }

  /**
   * 测试列表字段值处理。
   */
  public function testListProcessValue(): void
  {
    // 测试 list_string 值处理
    $settings = [
      'allowed_values' => ['option1' => 'Option 1', 'option2' => 'Option 2'],
      'multiple' => false,
    ];

    // 测试有效值
    $processed = $this->fieldTypeManager->processValue('list_string', 'option1', $settings);
    $this->assertEquals('option1', $processed);

    // 测试无效值
    $processed = $this->fieldTypeManager->processValue('list_string', 'invalid', $settings);
    $this->assertEquals('', $processed);

    // 测试多值处理
    $settings['multiple'] = true;
    $processed = $this->fieldTypeManager->processValue('list_string', ['option1', 'invalid', 'option2'], $settings);
    $this->assertEquals(['option1', 'option2'], $processed);

    // 测试 list_integer 值处理
    $settings = [
      'allowed_values' => [1 => 'One', 2 => 'Two', 3 => 'Three'],
      'multiple' => false,
    ];

    // 测试有效值
    $processed = $this->fieldTypeManager->processValue('list_integer', '1', $settings);
    $this->assertEquals(1, $processed);

    // 测试无效值
    $processed = $this->fieldTypeManager->processValue('list_integer', '99', $settings);
    $this->assertEquals(0, $processed);

    // 测试多值处理
    $settings['multiple'] = true;
    $processed = $this->fieldTypeManager->processValue('list_integer', ['1', '99', '2'], $settings);
    $this->assertEquals([1, 2], $processed);
  }

  /**
   * 测试字段值格式化。
   */
  public function testFormatValue(): void
  {
    // 注册字符串插件
    $stringPlugin = new \Drupal\baas_entity\Plugin\FieldType\StringFieldTypePlugin();
    $this->fieldTypeManager->registerPlugin($stringPlugin);

    // 测试默认格式化
    $formatted = $this->fieldTypeManager->formatValue('string', 'test value', [], 'default');
    $this->assertEquals('test value', $formatted);

    // 测试纯文本格式化
    $formatted = $this->fieldTypeManager->formatValue('string', '<b>test</b>', [], 'plain');
    $this->assertEquals('test', $formatted);

    // 测试截断格式化
    $longValue = str_repeat('a', 100);
    $formatted = $this->fieldTypeManager->formatValue('string', $longValue, ['display_length' => 50], 'truncated');
    $this->assertEquals(53, strlen($formatted)); // 50 + '...'
  }

  /**
   * 测试列表字段值格式化。
   */
  public function testListFormatValue(): void
  {
    // 测试 list_string 格式化
    $settings = [
      'allowed_values' => ['option1' => 'Option 1', 'option2' => 'Option 2'],
    ];

    // 测试单值格式化
    $formatted = $this->fieldTypeManager->formatValue('list_string', 'option1', $settings);
    $this->assertEquals('Option 1', $formatted);

    // 测试多值格式化
    $formatted = $this->fieldTypeManager->formatValue('list_string', ['option1', 'option2'], $settings);
    $this->assertEquals('Option 1, Option 2', $formatted);

    // 测试 list_integer 格式化
    $settings = [
      'allowed_values' => [1 => 'One', 2 => 'Two', 3 => 'Three'],
    ];

    // 测试单值格式化
    $formatted = $this->fieldTypeManager->formatValue('list_integer', 1, $settings);
    $this->assertEquals('One', $formatted);

    // 测试多值格式化
    $formatted = $this->fieldTypeManager->formatValue('list_integer', [1, 2], $settings);
    $this->assertEquals('One, Two', $formatted);
  }

  /**
   * 测试列表字段设置表单。
   */
  public function testListSettingsForm(): void
  {
    // 测试 list_string 设置表单
    $settings = [
      'allowed_values' => ['option1' => 'Option 1', 'option2' => 'Option 2'],
      'multiple' => false,
      'required' => false,
    ];

    $form = $this->fieldTypeManager->getSettingsForm('list_string', $settings, [], null);
    $this->assertIsArray($form);
    $this->assertArrayHasKey('allowed_values', $form);
    $this->assertArrayHasKey('multiple', $form);
    $this->assertArrayHasKey('required', $form);

    // 测试 list_integer 设置表单
    $settings = [
      'allowed_values' => [1 => 'One', 2 => 'Two'],
      'multiple' => false,
      'required' => false,
    ];

    $form = $this->fieldTypeManager->getSettingsForm('list_integer', $settings, [], null);
    $this->assertIsArray($form);
    $this->assertArrayHasKey('allowed_values', $form);
    $this->assertArrayHasKey('multiple', $form);
    $this->assertArrayHasKey('required', $form);
  }

  /**
   * 测试列表字段默认设置。
   */
  public function testListDefaultSettings(): void
  {
    // 测试 list_string 默认设置
    $plugin = $this->fieldTypeManager->getPlugin('list_string');
    $defaultSettings = $plugin->getDefaultSettings();

    $this->assertIsArray($defaultSettings);
    $this->assertArrayHasKey('allowed_values', $defaultSettings);
    $this->assertArrayHasKey('multiple', $defaultSettings);
    $this->assertArrayHasKey('required', $defaultSettings);
    $this->assertEquals([], $defaultSettings['allowed_values']);
    $this->assertFalse($defaultSettings['multiple']);
    $this->assertFalse($defaultSettings['required']);

    // 测试 list_integer 默认设置
    $plugin = $this->fieldTypeManager->getPlugin('list_integer');
    $defaultSettings = $plugin->getDefaultSettings();

    $this->assertIsArray($defaultSettings);
    $this->assertArrayHasKey('allowed_values', $defaultSettings);
    $this->assertArrayHasKey('multiple', $defaultSettings);
    $this->assertArrayHasKey('required', $defaultSettings);
    $this->assertEquals([], $defaultSettings['allowed_values']);
    $this->assertFalse($defaultSettings['multiple']);
    $this->assertFalse($defaultSettings['required']);
  }

  /**
   * 测试不存在的字段类型。
   */
  public function testNonExistentType(): void
  {
    // 测试不存在的类型
    $this->assertFalse($this->fieldTypeManager->hasType('nonexistent'));
    $this->assertNull($this->fieldTypeManager->getPlugin('nonexistent'));
    $this->assertNull($this->fieldTypeManager->getTypeInfo('nonexistent'));

    // 测试不存在类型的Schema
    $schema = $this->fieldTypeManager->getStorageSchema('nonexistent');
    $this->assertNull($schema);

    // 测试错误验证
    $errors = $this->fieldTypeManager->validateValue('nonexistent', 'value', []);
    $this->assertNotEmpty($errors);
    $this->assertStringContainsString('Unknown field type', $errors[0]);
  }
}
