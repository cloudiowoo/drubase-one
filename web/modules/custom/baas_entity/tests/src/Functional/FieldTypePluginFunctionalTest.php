<?php

namespace Drupal\Tests\baas_entity\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\baas_entity\Service\FieldTypeManager;

/**
 * 字段类型插件功能测试。
 *
 * @group baas_entity
 */
class FieldTypePluginFunctionalTest extends BrowserTestBase
{

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['baas_entity'];

  /**
   * 管理员用户。
   *
   * @var \Drupal\user\UserInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    // 创建管理员用户
    $this->adminUser = $this->drupalCreateUser([
      'administer site configuration',
      'access administration pages',
    ]);
  }

  /**
   * 测试字段类型管理器服务可用性。
   */
  public function testFieldTypeManagerServiceAvailability(): void
  {
    // 登录管理员
    $this->drupalLogin($this->adminUser);

    // 获取字段类型管理器服务
    $fieldTypeManager = \Drupal::service('baas_entity.field_type_manager');
    $this->assertInstanceOf(FieldTypeManager::class, $fieldTypeManager);

    // 验证默认字段类型可用
    $availableTypes = $fieldTypeManager->getAvailableTypes();
    $this->assertIsArray($availableTypes);
    $this->assertArrayHasKey('string', $availableTypes);
  }

  /**
   * 测试字段类型信息页面。
   */
  public function testFieldTypeInfoPage(): void
  {
    // 登录管理员
    $this->drupalLogin($this->adminUser);

    // 访问系统状态页面（验证模块正常加载）
    $this->drupalGet('admin/reports/status');
    $this->assertSession()->statusCodeEquals(200);

    // 验证模块已启用
    $this->drupalGet('admin/modules');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('BaaS Entity');
  }

  /**
   * 测试字段类型配置。
   */
  public function testFieldTypeConfiguration(): void
  {
    // 登录管理员
    $this->drupalLogin($this->adminUser);

    // 获取字段类型管理器
    $fieldTypeManager = \Drupal::service('baas_entity.field_type_manager');

    // 测试字符串字段类型配置
    $stringInfo = $fieldTypeManager->getTypeInfo('string');
    $this->assertIsArray($stringInfo);
    $this->assertEquals('String', $stringInfo['label']);
    $this->assertEquals('A basic string field type', $stringInfo['description']);

    // 测试存储Schema
    $schema = $fieldTypeManager->getStorageSchema('string');
    $this->assertIsArray($schema);
    $this->assertEquals('varchar', $schema['type']);
    $this->assertEquals(255, $schema['length']);
  }

  /**
   * 测试字段值验证功能。
   */
  public function testFieldValueValidation(): void
  {
    // 登录管理员
    $this->drupalLogin($this->adminUser);

    // 获取字段类型管理器
    $fieldTypeManager = \Drupal::service('baas_entity.field_type_manager');

    // 测试有效字符串值
    $errors = $fieldTypeManager->validateValue('string', 'Valid string', [
      'max_length' => 255,
      'required' => FALSE,
    ]);
    $this->assertEmpty($errors);

    // 测试超长字符串值
    $longString = str_repeat('x', 300);
    $errors = $fieldTypeManager->validateValue('string', $longString, [
      'max_length' => 255,
    ]);
    $this->assertNotEmpty($errors);

    // 测试必填字段验证
    $errors = $fieldTypeManager->validateValue('string', '', [
      'required' => TRUE,
    ]);
    $this->assertNotEmpty($errors);
  }

  /**
   * 测试字段值处理功能。
   */
  public function testFieldValueProcessing(): void
  {
    // 登录管理员
    $this->drupalLogin($this->adminUser);

    // 获取字段类型管理器
    $fieldTypeManager = \Drupal::service('baas_entity.field_type_manager');

    // 测试字符串值处理（去除空白）
    $processed = $fieldTypeManager->processValue('string', '  test value  ', []);
    $this->assertEquals('test value', $processed);

    // 测试默认值处理
    $processed = $fieldTypeManager->processValue('string', '', [
      'default_value' => 'default text',
    ]);
    $this->assertEquals('default text', $processed);
  }

  /**
   * 测试字段值格式化功能。
   */
  public function testFieldValueFormatting(): void
  {
    // 登录管理员
    $this->drupalLogin($this->adminUser);

    // 获取字段类型管理器
    $fieldTypeManager = \Drupal::service('baas_entity.field_type_manager');

    // 测试默认格式化
    $formatted = $fieldTypeManager->formatValue('string', 'test value', [], 'default');
    $this->assertEquals('test value', $formatted);

    // 测试纯文本格式化
    $formatted = $fieldTypeManager->formatValue('string', '<b>bold text</b>', [], 'plain');
    $this->assertEquals('bold text', $formatted);
  }

  /**
   * 测试字段类型缓存功能。
   */
  public function testFieldTypeCaching(): void
  {
    // 登录管理员
    $this->drupalLogin($this->adminUser);

    // 获取字段类型管理器
    $fieldTypeManager = \Drupal::service('baas_entity.field_type_manager');

    // 第一次获取类型信息（创建缓存）
    $info1 = $fieldTypeManager->getTypeInfo('string');
    $this->assertIsArray($info1);

    // 第二次获取类型信息（使用缓存）
    $info2 = $fieldTypeManager->getTypeInfo('string');
    $this->assertEquals($info1, $info2);

    // 清除缓存
    $fieldTypeManager->clearCache('string');

    // 再次获取类型信息（重新创建缓存）
    $info3 = $fieldTypeManager->getTypeInfo('string');
    $this->assertEquals($info1, $info3);
  }

  /**
   * 测试错误处理。
   */
  public function testErrorHandling(): void
  {
    // 登录管理员
    $this->drupalLogin($this->adminUser);

    // 获取字段类型管理器
    $fieldTypeManager = \Drupal::service('baas_entity.field_type_manager');

    // 测试不存在的字段类型
    $plugin = $fieldTypeManager->getPlugin('nonexistent');
    $this->assertNull($plugin);

    $hasType = $fieldTypeManager->hasType('nonexistent');
    $this->assertFalse($hasType);

    $info = $fieldTypeManager->getTypeInfo('nonexistent');
    $this->assertNull($info);
  }
}
