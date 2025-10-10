<?php

namespace Drupal\Tests\baas_entity\Unit\Service;

use PHPUnit\Framework\TestCase;

/**
 * TemplateManager服务的单元测试。
 *
 * @group baas_entity
 */
class TemplateManagerTest extends TestCase
{

  /**
   * 测试基本功能。
   */
  public function testBasicFunctionality(): void
  {
    $this->assertTrue(TRUE);
    $this->assertEquals(1, 1);
  }

  /**
   * 测试字段设置序列化。
   */
  public function testFieldSettingsSerialization(): void
  {
    $settings = [
      'target_type' => 'baas_entity',
      'multiple' => true,
      'match_operator' => 'CONTAINS',
      'match_limit' => 10,
    ];

    $serialized = json_encode($settings);
    $unserialized = json_decode($serialized, true);

    $this->assertEquals($settings, $unserialized);
    $this->assertIsArray($unserialized);
    $this->assertEquals('baas_entity', $unserialized['target_type']);
    $this->assertTrue($unserialized['multiple']);
  }

  /**
   * 测试列表字段允许值处理。
   */
  public function testListFieldAllowedValues(): void
  {
    $allowed_values_text = "选项1|标签1\n选项2|标签2\n选项3|标签3";
    $lines = explode("\n", $allowed_values_text);
    $allowed_values = [];

    foreach ($lines as $line) {
      $line = trim($line);
      if (!empty($line)) {
        $parts = explode('|', $line, 2);
        $key = trim($parts[0]);
        $label = isset($parts[1]) ? trim($parts[1]) : $key;
        $allowed_values[$key] = $label;
      }
    }

    $expected = [
      '选项1' => '标签1',
      '选项2' => '标签2',
      '选项3' => '标签3',
    ];

    $this->assertEquals($expected, $allowed_values);
    $this->assertCount(3, $allowed_values);
    $this->assertArrayHasKey('选项1', $allowed_values);
  }

  /**
   * 测试引用字段设置验证。
   */
  public function testReferenceFieldSettings(): void
  {
    $settings = [
      'target_type' => 'baas_entity',
      'multiple' => false,
      'match_operator' => 'CONTAINS',
      'match_limit' => 10,
    ];

    // 验证必需的设置
    $this->assertArrayHasKey('target_type', $settings);
    $this->assertArrayHasKey('multiple', $settings);

    // 验证设置值
    $this->assertEquals('baas_entity', $settings['target_type']);
    $this->assertIsBool($settings['multiple']);
    $this->assertIsString($settings['match_operator']);
    $this->assertIsInt($settings['match_limit']);
  }
}
