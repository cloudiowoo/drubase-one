<?php

declare(strict_types=1);

namespace Drupal\Tests\baas_api\Functional;

use GuzzleHttp\Promise;
use GuzzleHttp\RequestOptions;

/**
 * API性能集成测试。
 *
 * @group baas_api
 */
class ApiPerformanceTest extends ApiIntegrationTestBase
{

  /**
   * 测试API响应时间。
   */
  public function testApiResponseTime(): void
  {
    $start_time = microtime(true);
    
    $response = $this->apiRequest('GET', '/api/health');
    
    $end_time = microtime(true);
    $response_time = ($end_time - $start_time) * 1000; // 转换为毫秒
    
    $this->assertApiSuccess($response);
    $this->assertLessThan(1000, $response_time, 'API响应时间应该少于1秒');
  }

  /**
   * 测试并发请求处理。
   */
  public function testConcurrentRequests(): void
  {
    $client = $this->getHttpClient();
    $promises = [];
    
    // 创建10个并发请求
    for ($i = 0; $i < 10; $i++) {
      $promises[] = $client->requestAsync('GET', '/api/health', [
        RequestOptions::HEADERS => [
          'Authorization' => 'Bearer ' . $this->testJwtToken,
          'Content-Type' => 'application/json',
        ],
      ]);
    }
    
    $start_time = microtime(true);
    
    // 等待所有请求完成
    $responses = Promise\settle($promises)->wait();
    
    $end_time = microtime(true);
    $total_time = ($end_time - $start_time) * 1000;
    
    // 验证所有请求都成功
    foreach ($responses as $response) {
      $this->assertEquals('fulfilled', $response['state']);
      $this->assertEquals(200, $response['value']->getStatusCode());
    }
    
    // 并发请求的总时间应该明显少于顺序请求
    $this->assertLessThan(5000, $total_time, '10个并发请求应该在5秒内完成');
  }

  /**
   * 测试大量数据的分页性能。
   */
  public function testLargeDataPagination(): void
  {
    // 创建测试模板
    $this->createTestEntityTemplate('performance_test');
    
    // 创建大量测试数据
    for ($i = 1; $i <= 100; $i++) {
      $this->createTestEntityData('performance_test', [
        'title' => "Performance Test Entity {$i}",
        'description' => "Test entity for performance testing - {$i}",
      ]);
    }
    
    $start_time = microtime(true);
    
    // 测试分页查询
    $response = $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/performance_test?page=1&limit=20');
    
    $end_time = microtime(true);
    $response_time = ($end_time - $start_time) * 1000;
    
    $this->assertApiSuccess($response);
    $this->assertApiPagination($response);
    $this->assertLessThan(2000, $response_time, '分页查询应该在2秒内完成');
    
    $body = $this->getApiResponseBody($response);
    $this->assertCount(20, $body['data']);
    $this->assertEquals(100, $body['pagination']['total']);
  }

  /**
   * 测试复杂查询性能。
   */
  public function testComplexQueryPerformance(): void
  {
    // 创建带有多个字段的实体模板
    $this->createTestEntityTemplate('complex_entity', [
      [
        'name' => 'category',
        'type' => 'string',
        'label' => 'Category',
        'required' => false,
        'settings' => ['max_length' => 100],
      ],
      [
        'name' => 'priority',
        'type' => 'integer',
        'label' => 'Priority',
        'required' => false,
        'settings' => ['min' => 1, 'max' => 10],
      ],
      [
        'name' => 'status',
        'type' => 'boolean',
        'label' => 'Status',
        'required' => false,
        'settings' => [],
      ],
    ]);
    
    // 创建测试数据
    $categories = ['A', 'B', 'C'];
    $priorities = [1, 2, 3, 4, 5];
    $statuses = [true, false];
    
    for ($i = 1; $i <= 50; $i++) {
      $this->createTestEntityData('complex_entity', [
        'title' => "Complex Entity {$i}",
        'category' => $categories[array_rand($categories)],
        'priority' => $priorities[array_rand($priorities)],
        'status' => $statuses[array_rand($statuses)],
      ]);
    }
    
    $start_time = microtime(true);
    
    // 执行复杂查询
    $response = $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/complex_entity?category=A&priority=5&status=1&sort_field=title&sort_direction=DESC');
    
    $end_time = microtime(true);
    $response_time = ($end_time - $start_time) * 1000;
    
    $this->assertApiSuccess($response);
    $this->assertLessThan(3000, $response_time, '复杂查询应该在3秒内完成');
  }

  /**
   * 测试缓存性能。
   */
  public function testCachePerformance(): void
  {
    // 首次请求（无缓存）
    $start_time = microtime(true);
    $response1 = $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/templates');
    $end_time = microtime(true);
    $first_request_time = ($end_time - $start_time) * 1000;
    
    $this->assertApiSuccess($response1);
    
    // 第二次请求（有缓存）
    $start_time = microtime(true);
    $response2 = $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/templates');
    $end_time = microtime(true);
    $second_request_time = ($end_time - $start_time) * 1000;
    
    $this->assertApiSuccess($response2);
    
    // 缓存应该提高性能
    $this->assertLessThanOrEqual($first_request_time, $second_request_time);
    
    // 验证响应内容相同
    $this->assertEquals(
      $this->getApiResponseBody($response1),
      $this->getApiResponseBody($response2)
    );
  }

  /**
   * 测试批量创建性能。
   */
  public function testBatchCreatePerformance(): void
  {
    $this->createTestEntityTemplate('batch_test');
    
    $start_time = microtime(true);
    
    // 批量创建多个实体
    $promises = [];
    for ($i = 1; $i <= 20; $i++) {
      $promises[] = $this->getHttpClient()->requestAsync('POST', '/api/v1/' . $this->testTenant['tenant_id'] . '/batch_test', [
        RequestOptions::HEADERS => [
          'Authorization' => 'Bearer ' . $this->testJwtToken,
          'Content-Type' => 'application/json',
        ],
        RequestOptions::JSON => [
          'title' => "Batch Entity {$i}",
          'description' => "Created in batch {$i}",
        ],
      ]);
    }
    
    $responses = Promise\settle($promises)->wait();
    
    $end_time = microtime(true);
    $total_time = ($end_time - $start_time) * 1000;
    
    // 验证所有创建都成功
    foreach ($responses as $response) {
      $this->assertEquals('fulfilled', $response['state']);
      $this->assertEquals(201, $response['value']->getStatusCode());
    }
    
    $this->assertLessThan(10000, $total_time, '批量创建20个实体应该在10秒内完成');
  }

  /**
   * 测试API网关性能。
   */
  public function testApiGatewayPerformance(): void
  {
    // 测试多个不同的API端点
    $endpoints = [
      '/api/health',
      '/api/v1/' . $this->testTenant['tenant_id'] . '/templates',
      '/api/docs',
    ];
    
    $total_time = 0;
    $request_count = 0;
    
    foreach ($endpoints as $endpoint) {
      for ($i = 0; $i < 5; $i++) {
        $start_time = microtime(true);
        
        if ($endpoint === '/api/docs') {
          $response = $this->anonymousRequest('GET', $endpoint);
        } else {
          $response = $this->apiRequest('GET', $endpoint);
        }
        
        $end_time = microtime(true);
        $request_time = ($end_time - $start_time) * 1000;
        
        $total_time += $request_time;
        $request_count++;
        
        $this->assertLessThan(2000, $request_time, "单个请求 {$endpoint} 应该在2秒内完成");
      }
    }
    
    $average_time = $total_time / $request_count;
    $this->assertLessThan(1000, $average_time, '平均API响应时间应该少于1秒');
  }

  /**
   * 测试内存使用情况。
   */
  public function testMemoryUsage(): void
  {
    $memory_start = memory_get_usage(true);
    
    // 执行一系列API操作
    $this->createTestEntityTemplate('memory_test');
    
    for ($i = 1; $i <= 10; $i++) {
      $this->createTestEntityData('memory_test', [
        'title' => "Memory Test Entity {$i}",
      ]);
    }
    
    $response = $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/memory_test');
    $this->assertApiSuccess($response);
    
    $memory_end = memory_get_usage(true);
    $memory_used = $memory_end - $memory_start;
    
    // 内存使用应该在合理范围内（小于50MB）
    $this->assertLessThan(50 * 1024 * 1024, $memory_used, '内存使用应该在合理范围内');
  }

  /**
   * 测试数据库查询性能。
   */
  public function testDatabaseQueryPerformance(): void
  {
    // 启用查询日志
    $connection = $this->container->get('database');
    $connection->enableQueryLog();
    
    // 创建测试数据
    $this->createTestEntityTemplate('db_test');
    
    for ($i = 1; $i <= 20; $i++) {
      $this->createTestEntityData('db_test', [
        'title' => "DB Test Entity {$i}",
      ]);
    }
    
    // 清除查询日志
    $connection->clearQueryLog();
    
    $start_time = microtime(true);
    
    // 执行API请求
    $response = $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/db_test');
    
    $end_time = microtime(true);
    $response_time = ($end_time - $start_time) * 1000;
    
    $this->assertApiSuccess($response);
    
    // 检查查询数量
    $queries = $connection->getQueryLog();
    $query_count = count($queries);
    
    $this->assertLessThan(10, $query_count, '查询数量应该保持在合理范围内');
    $this->assertLessThan(2000, $response_time, '数据库查询响应时间应该少于2秒');
  }

  /**
   * 测试负载均衡性能。
   */
  public function testLoadBalancingPerformance(): void
  {
    // 模拟多个并发用户
    $user_count = 5;
    $requests_per_user = 10;
    
    $all_promises = [];
    
    for ($user = 1; $user <= $user_count; $user++) {
      for ($request = 1; $request <= $requests_per_user; $request++) {
        $all_promises[] = $this->getHttpClient()->requestAsync('GET', '/api/health', [
          RequestOptions::HEADERS => [
            'Authorization' => 'Bearer ' . $this->testJwtToken,
            'X-User-Simulation' => "user_{$user}",
          ],
        ]);
      }
    }
    
    $start_time = microtime(true);
    
    $responses = Promise\settle($all_promises)->wait();
    
    $end_time = microtime(true);
    $total_time = ($end_time - $start_time) * 1000;
    
    // 验证所有请求都成功
    $success_count = 0;
    foreach ($responses as $response) {
      if ($response['state'] === 'fulfilled' && $response['value']->getStatusCode() === 200) {
        $success_count++;
      }
    }
    
    $this->assertEquals($user_count * $requests_per_user, $success_count, '所有并发请求都应该成功');
    
    // 平均每个请求的时间应该合理
    $average_time = $total_time / ($user_count * $requests_per_user);
    $this->assertLessThan(1000, $average_time, '平均请求时间应该少于1秒');
  }

}