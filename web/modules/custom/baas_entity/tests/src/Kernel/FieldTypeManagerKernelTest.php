<?php

namespace Drupal\Tests\baas_entity\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\baas_entity\Service\FieldTypeManager;
use Drupal\baas_entity\Plugin\FieldType\StringFieldTypePlugin;

/**
 * 字段类型管理器内核测试。
 *
 * @group baas_entity
 * @coversDefaultClass \Drupal\baas_entity\Service\FieldTypeManager
 */
class FieldTypeManagerKernelTest extends KernelTestBase
{

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['baas_tenant', 'baas_entity'];

  /**
   * 字段类型管理器服务。
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

    // 安装模块配置
    $this->installConfig(['baas_entity']);

    // 获取字段类型管理器服务
    $this->fieldTypeManager = $this->container->get('baas_entity.field_type_manager');
  }

  /**
   * 测试字段类型管理器服务可用性。
   */
  public function testFieldTypeManagerServiceAvailability(): void
  {
    $this->assertInstanceOf(FieldTypeManager::class, $this->fieldTypeManager);
  }

  /**
   * 测试默认字段类型可用性。
   */
  public function testDefaultFieldTypesAvailable(): void
  {
    $availableTypes = $this->fieldTypeManager->getAvailableTypes();
    $this->assertIsArray($availableTypes);
    $this->assertArrayHasKey('string', $availableTypes);
  }

  /**
   * 测试字段类型信息获取。
   */
  public function testFieldTypeInfo(): void
  {
    $stringInfo = $this->fieldTypeManager->getTypeInfo('string');
    $this->assertIsArray($stringInfo);
    $this->assertEquals('String', $stringInfo['label']);
    $this->assertEquals('A basic string field type', $stringInfo['description']);
  }

  /**
   * 测试字段类型存储Schema。
   */
  public function testFieldTypeStorageSchema(): void
  {
    $schema = $this->fieldTypeManager->getStorageSchema('string');
    $this->assertIsArray($schema);
    $this->assertEquals('varchar', $schema['type']);
    $this->assertEquals(255, $schema['length']);
  }

  /**
   * 测试字段值验证。
   */
  public function testFieldValueValidation(): void
  {
    // 测试有效值
    $errors = $this->fieldTypeManager->validateValue('string', 'Valid string', [
      'max_length' => 255,
      'required' => FALSE,
    ]);
    $this->assertEmpty($errors);

    // 测试超长值
    $longString = str_repeat('x', 300);
    $errors = $this->fieldTypeManager->validateValue('string', $longString, [
      'max_length' => 255,
    ]);
    $this->assertNotEmpty($errors);

    // 测试必填字段
    $errors = $this->fieldTypeManager->validateValue('string', '', [
      'required' => TRUE,
    ]);
    $this->assertNotEmpty($errors);
  }

  /**
   * 测试字段值处理。
   */
  public function testFieldValueProcessing(): void
  {
    // 测试去除空白
    $processed = $this->fieldTypeManager->processValue('string', '  test value  ', []);
    $this->assertEquals('test value', $processed);

    // 测试默认值
    $processed = $this->fieldTypeManager->processValue('string', '', [
      'default_value' => 'default text',
    ]);
    $this->assertEquals('default text', $processed);
  }

  /**
   * 测试字段值格式化。
   */
  public function testFieldValueFormatting(): void
  {
    // 测试默认格式化
    $formatted = $this->fieldTypeManager->formatValue('string', 'test value', [], 'default');
    $this->assertEquals('test value', $formatted);

    // 测试纯文本格式化
    $formatted = $this->fieldTypeManager->formatValue('string', '<b>bold text</b>', [], 'plain');
    $this->assertEquals('bold text', $formatted);
  }

  /**
   * 测试错误处理。
   */
  public function testErrorHandling(): void
  {
    // 测试不存在的字段类型
    $plugin = $this->fieldTypeManager->getPlugin('nonexistent');
    $this->assertNull($plugin);

    $hasType = $this->fieldTypeManager->hasType('nonexistent');
    $this->assertFalse($hasType);

    $info = $this->fieldTypeManager->getTypeInfo('nonexistent');
    $this->assertNull($info);
  }

  /**
   * 测试字段类型插件注册和管理。
   *
   * @covers ::registerPlugin
   * @covers ::getPlugin
   * @covers ::hasType
   */
  public function testPluginManagement(): void
  {
    // 创建并注册字符串插件
    $stringPlugin = new StringFieldTypePlugin();
    $this->fieldTypeManager->registerPlugin($stringPlugin);

    // 验证插件注册
    $this->assertTrue($this->fieldTypeManager->hasType('string'));
    $this->assertInstanceOf(StringFieldTypePlugin::class, $this->fieldTypeManager->getPlugin('string'));
  }

  /**
   * 测试字段类型信息缓存。
   *
   * @covers ::getTypeInfo
   * @covers ::clearCache
   */
  public function testTypeInfoCaching(): void
  {
    // 注册字符串插件
    $stringPlugin = new StringFieldTypePlugin();
    $this->fieldTypeManager->registerPlugin($stringPlugin);

    // 第一次获取类型信息（应该创建缓存）
    $info1 = $this->fieldTypeManager->getTypeInfo('string');
    $this->assertIsArray($info1);

    // 第二次获取类型信息（应该使用缓存）
    $info2 = $this->fieldTypeManager->getTypeInfo('string');
    $this->assertEquals($info1, $info2);

    // 清除缓存
    $this->fieldTypeManager->clearCache('string');

    // 再次获取类型信息（应该重新创建缓存）
    $info3 = $this->fieldTypeManager->getTypeInfo('string');
    $this->assertEquals($info1, $info3);
  }

  /**
   * 测试设置表单获取。
   *
   * @covers ::getSettingsForm
   */
  public function testSettingsFormRetrieval(): void
  {
    // 注册字符串插件
    $stringPlugin = new StringFieldTypePlugin();
    $this->fieldTypeManager->registerPlugin($stringPlugin);

    // 获取设置表单
    $form = $this->fieldTypeManager->getSettingsForm('string', [
      'max_length' => 255,
      'required' => FALSE,
    ], [], NULL);

    $this->assertIsArray($form);
    $this->assertArrayHasKey('max_length', $form);
    $this->assertArrayHasKey('required', $form);
  }
}
