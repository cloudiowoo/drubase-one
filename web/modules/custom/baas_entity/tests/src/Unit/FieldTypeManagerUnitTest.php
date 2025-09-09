<?php

namespace Drupal\Tests\baas_entity\Unit;

use PHPUnit\Framework\TestCase;
use Drupal\baas_entity\Service\FieldTypeManager;

/**
 * Tests the field type manager service.
 *
 * @group baas_entity
 */
class FieldTypeManagerUnitTest extends TestCase
{

  /**
   * The field type manager service.
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

    $this->fieldTypeManager = new FieldTypeManager();
  }

  /**
   * Tests that the field type manager service can be instantiated.
   */
  public function testFieldTypeManagerInstantiation()
  {
    $this->assertInstanceOf(FieldTypeManager::class, $this->fieldTypeManager);
  }

  /**
   * Tests that the field type manager has basic methods.
   */
  public function testFieldTypeManagerMethods()
  {
    $this->assertTrue(method_exists($this->fieldTypeManager, 'getAvailableTypes'));
    $this->assertTrue(method_exists($this->fieldTypeManager, 'getTypeInfo'));
    $this->assertTrue(method_exists($this->fieldTypeManager, 'validateValue'));
    $this->assertTrue(method_exists($this->fieldTypeManager, 'hasType'));
    $this->assertTrue(method_exists($this->fieldTypeManager, 'registerPlugin'));
  }

  /**
   * Tests that default field types are registered.
   */
  public function testDefaultFieldTypesRegistered()
  {
    $fieldTypes = $this->fieldTypeManager->getAvailableTypes();
    $this->assertIsArray($fieldTypes);
    $this->assertNotEmpty($fieldTypes, 'Field type manager should have default field types registered');

    // 验证默认注册的字段类型
    $this->assertArrayHasKey('string', $fieldTypes);
    $this->assertArrayHasKey('list_string', $fieldTypes);
    $this->assertArrayHasKey('list_integer', $fieldTypes);

    // 验证字段类型标签
    $this->assertEquals('String', $fieldTypes['string']);
    $this->assertEquals('String List', $fieldTypes['list_string']);
    $this->assertEquals('Integer List', $fieldTypes['list_integer']);
  }

  /**
   * Tests field type existence check.
   */
  public function testHasType()
  {
    // 测试默认注册的字段类型
    $this->assertTrue($this->fieldTypeManager->hasType('string'));
    $this->assertTrue($this->fieldTypeManager->hasType('list_string'));
    $this->assertTrue($this->fieldTypeManager->hasType('list_integer'));

    // 测试不存在的字段类型
    $this->assertFalse($this->fieldTypeManager->hasType('nonexistent_type'));
  }

  /**
   * Tests field type info for registered types.
   */
  public function testRegisteredFieldTypeInfo()
  {
    // 测试 string 字段类型信息
    $info = $this->fieldTypeManager->getTypeInfo('string');
    $this->assertIsArray($info);
    $this->assertEquals('string', $info['type']);
    $this->assertEquals('String', $info['label']);

    // 测试 list_string 字段类型信息
    $info = $this->fieldTypeManager->getTypeInfo('list_string');
    $this->assertIsArray($info);
    $this->assertEquals('list_string', $info['type']);
    $this->assertEquals('String List', $info['label']);

    // 测试 list_integer 字段类型信息
    $info = $this->fieldTypeManager->getTypeInfo('list_integer');
    $this->assertIsArray($info);
    $this->assertEquals('list_integer', $info['type']);
    $this->assertEquals('Integer List', $info['label']);
  }

  /**
   * Tests field type info for non-existent type.
   */
  public function testNonExistentFieldTypeInfo()
  {
    $info = $this->fieldTypeManager->getTypeInfo('nonexistent_type');
    $this->assertNull($info, 'Non-existent field type should return null');
  }

  /**
   * Tests field value validation for registered types.
   */
  public function testValidationForRegisteredTypes()
  {
    // 测试 string 字段验证
    $errors = $this->fieldTypeManager->validateValue('string', 'test value', ['max_length' => 255]);
    $this->assertIsArray($errors);
    $this->assertEmpty($errors, 'Valid string value should pass validation');

    // 测试 list_string 字段验证
    $settings = ['allowed_values' => ['option1' => 'Option 1', 'option2' => 'Option 2']];
    $errors = $this->fieldTypeManager->validateValue('list_string', 'option1', $settings);
    $this->assertIsArray($errors);
    $this->assertEmpty($errors, 'Valid list_string value should pass validation');

    // 测试 list_integer 字段验证
    $settings = ['allowed_values' => [1 => 'One', 2 => 'Two']];
    $errors = $this->fieldTypeManager->validateValue('list_integer', 1, $settings);
    $this->assertIsArray($errors);
    $this->assertEmpty($errors, 'Valid list_integer value should pass validation');
  }

  /**
   * Tests field value validation for non-existent type.
   */
  public function testValidationForNonExistentType()
  {
    $errors = $this->fieldTypeManager->validateValue('nonexistent_type', 'test value', []);
    $this->assertIsArray($errors);
    $this->assertNotEmpty($errors, 'Validation for non-existent type should return errors');
    $this->assertStringContainsString('Unknown field type', $errors[0]);
  }

  /**
   * Tests cache clearing functionality.
   */
  public function testCacheClear()
  {
    // This should not throw any exceptions
    $this->fieldTypeManager->clearCache();
    $this->fieldTypeManager->clearCache('string');

    // Test that the manager still works after cache clear
    $fieldTypes = $this->fieldTypeManager->getAvailableTypes();
    $this->assertIsArray($fieldTypes);
    $this->assertNotEmpty($fieldTypes, 'Field types should still be available after cache clear');
  }

  /**
   * Tests storage schema for registered types.
   */
  public function testStorageSchemaForRegisteredTypes()
  {
    // 测试 string 字段存储模式
    $schema = $this->fieldTypeManager->getStorageSchema('string');
    $this->assertIsArray($schema);
    $this->assertEquals('varchar', $schema['type']);

    // 测试 list_string 字段存储模式
    $schema = $this->fieldTypeManager->getStorageSchema('list_string');
    $this->assertIsArray($schema);
    $this->assertEquals('varchar', $schema['type']);

    // 测试 list_integer 字段存储模式
    $schema = $this->fieldTypeManager->getStorageSchema('list_integer');
    $this->assertIsArray($schema);
    $this->assertEquals('int', $schema['type']);
  }

  /**
   * Tests storage schema for non-existent type.
   */
  public function testStorageSchemaForNonExistentType()
  {
    $schema = $this->fieldTypeManager->getStorageSchema('nonexistent_type');
    $this->assertNull($schema, 'Storage schema for non-existent type should return null');
  }

  /**
   * Tests value processing for registered types.
   */
  public function testProcessValueForRegisteredTypes()
  {
    // 测试 string 字段值处理
    $value = '  test value  ';
    $processed = $this->fieldTypeManager->processValue('string', $value, ['max_length' => 255]);
    $this->assertEquals('test value', $processed, 'String value should be trimmed');

    // 测试 list_string 字段值处理
    $settings = ['allowed_values' => ['option1' => 'Option 1', 'option2' => 'Option 2']];
    $processed = $this->fieldTypeManager->processValue('list_string', 'option1', $settings);
    $this->assertEquals('option1', $processed, 'Valid list_string value should be preserved');

    // 测试 list_integer 字段值处理
    $settings = ['allowed_values' => [1 => 'One', 2 => 'Two']];
    $processed = $this->fieldTypeManager->processValue('list_integer', '1', $settings);
    $this->assertEquals(1, $processed, 'Valid list_integer value should be converted to integer');
  }

  /**
   * Tests value processing for non-existent type.
   */
  public function testProcessValueForNonExistentType()
  {
    $value = 'test value';
    $processed = $this->fieldTypeManager->processValue('nonexistent_type', $value, []);
    $this->assertEquals($value, $processed, 'Processing non-existent type should return original value');
  }

  /**
   * Tests value formatting for registered types.
   */
  public function testFormatValueForRegisteredTypes()
  {
    // 测试 string 字段值格式化
    $value = 'test value';
    $formatted = $this->fieldTypeManager->formatValue('string', $value, []);
    $this->assertEquals($value, $formatted, 'String value should be formatted as-is');

    // 测试 list_string 字段值格式化
    $settings = ['allowed_values' => ['option1' => 'Option 1', 'option2' => 'Option 2']];
    $formatted = $this->fieldTypeManager->formatValue('list_string', 'option1', $settings);
    $this->assertEquals('Option 1', $formatted, 'List_string value should be formatted with label');

    // 测试 list_integer 字段值格式化
    $settings = ['allowed_values' => [1 => 'One', 2 => 'Two']];
    $formatted = $this->fieldTypeManager->formatValue('list_integer', 1, $settings);
    $this->assertEquals('One', $formatted, 'List_integer value should be formatted with label');
  }

  /**
   * Tests value formatting for non-existent type.
   */
  public function testFormatValueForNonExistentType()
  {
    $value = 'test value';
    $formatted = $this->fieldTypeManager->formatValue('nonexistent_type', $value, []);
    $this->assertEquals($value, $formatted, 'Formatting non-existent type should return string value');
  }

  /**
   * Tests settings form for registered types.
   */
  public function testSettingsFormForRegisteredTypes()
  {
    // 测试 string 字段设置表单
    $form = $this->fieldTypeManager->getSettingsForm('string', [], [], null);
    $this->assertIsArray($form);

    // 测试 list_string 字段设置表单
    $settings = ['allowed_values' => ['option1' => 'Option 1'], 'multiple' => false, 'required' => false];
    $form = $this->fieldTypeManager->getSettingsForm('list_string', $settings, [], null);
    $this->assertIsArray($form);
    $this->assertArrayHasKey('allowed_values', $form);
    $this->assertArrayHasKey('multiple', $form);
    $this->assertArrayHasKey('required', $form);

    // 测试 list_integer 字段设置表单
    $settings = ['allowed_values' => [1 => 'One'], 'multiple' => false, 'required' => false];
    $form = $this->fieldTypeManager->getSettingsForm('list_integer', $settings, [], null);
    $this->assertIsArray($form);
    $this->assertArrayHasKey('allowed_values', $form);
    $this->assertArrayHasKey('multiple', $form);
    $this->assertArrayHasKey('required', $form);
  }

  /**
   * Tests settings form for non-existent type.
   */
  public function testSettingsFormForNonExistentType()
  {
    $form = $this->fieldTypeManager->getSettingsForm('nonexistent_type', [], [], null);
    $this->assertIsArray($form);
    $this->assertEmpty($form, 'Settings form for non-existent type should return empty array');
  }
}
