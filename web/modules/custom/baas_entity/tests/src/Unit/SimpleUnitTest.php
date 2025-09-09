<?php
/*
 * @Date: 2025-06-01 21:11:44
 * @LastEditors: cloudio cloudio.woo@gmail.com
 * @LastEditTime: 2025-06-02 11:27:57
 * @FilePath: /drubase/web/modules/custom/baas_entity/tests/src/Unit/SimpleUnitTest.php
 */

namespace Drupal\Tests\baas_entity\Unit;

use PHPUnit\Framework\TestCase;

/**
 * 简单的单元测试。
 *
 * @group baas_entity
 */
class SimpleUnitTest extends TestCase
{

  /**
   * 测试基本的断言功能。
   */
  public function testBasicAssertion(): void
  {
    $this->assertTrue(TRUE);
    $this->assertEquals(1, 1);
    $this->assertIsString('test');
  }

  /**
   * 测试数组操作。
   */
  public function testArrayOperations(): void
  {
    $array = ['a', 'b', 'c'];
    $this->assertCount(3, $array);
    $this->assertContains('b', $array);
    $this->assertArrayHasKey(1, $array);
  }
}
