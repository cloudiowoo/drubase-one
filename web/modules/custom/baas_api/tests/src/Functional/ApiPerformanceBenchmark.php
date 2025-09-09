<?php

declare(strict_types=1);

namespace Drupal\Tests\baas_api\Functional;

/**
 * API性能基准测试。
 *
 * 测试API端点的响应时间和性能指标。
 *
 * @group baas_api
 * @group performance
 */
class ApiPerformanceBenchmark extends ApiIntegrationTestBase
{

  /**
   * 测试租户。
   *
   * @var array
   */
  protected array $testTenant;

  /**
   * 测试项目。
   *
   * @var array
   */
  protected array $testProject;

  /**
   * JWT令牌。
   *
   * @var string
   */
  protected string $jwtToken;

  /**
   * 性能基准值（毫秒）。
   *
   * @var array
   */
  protected array $performanceBenchmarks = [
    'authentication' => 100,    // 认证应该在100ms内完成
    'simple_get' => 200,        // 简单GET请求应该在200ms内完成
    'entity_create' => 300,     // 实体创建应该在300ms内完成
    'entity_list' => 250,       // 实体列表应该在250ms内完成
    'entity_update' => 300,     // 实体更新应该在300ms内完成
    'entity_delete' => 200,     // 实体删除应该在200ms内完成
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    // 创建测试租户
    $tenantManager = $this->container->get('baas_tenant.manager');
    $this->testTenant = $tenantManager->createTenant(
      'Performance Test Tenant',
      $this->testUser->id(),
      ['description' => 'API性能测试租户']
    );

    // 创建测试项目
    $projectManager = $this->container->get('baas_project.manager');
    $this->testProject = $projectManager->createProject(
      $this->testTenant['tenant_id'],
      [
        'name' => 'Performance Test Project',
        'machine_name' => 'performance_test_project',
        'description' => 'API性能测试项目',
      ],
      $this->testUser->id()
    );

    // 生成JWT令牌
    $jwtManager = $this->container->get('baas_auth.jwt_token_manager');
    $this->jwtToken = $jwtManager->generateToken([
      'sub' => $this->testUser->id(),
      'tenant_id' => $this->testTenant['tenant_id'],
      'project_id' => $this->testProject['project_id'],
      'username' => $this->testUser->getAccountName(),
      'email' => $this->testUser->getEmail(),
      'roles' => ['tenant_owner'],
    ]);

    // 创建测试实体模板
    $templateManager = $this->container->get('baas_entity.template_manager');
    $templateManager->createTemplate([
      'tenant_id' => $this->testTenant['tenant_id'],
      'project_id' => $this->testProject['project_id'],
      'name' => 'performance_test_entity',
      'label' => 'Performance Test Entity',
      'fields' => [
        [
          'name' => 'name',
          'type' => 'string',
          'label' => 'Name',
          'required' => true,
        ],
        [
          'name' => 'description',
          'type' => 'text',
          'label' => 'Description',
          'required' => false,
        ],
        [
          'name' => 'status',
          'type' => 'boolean',
          'label' => 'Status',
          'required' => false,
        ],
      ],
    ]);
  }

  /**
   * 测试认证端点性能。
   */
  public function testAuthenticationPerformance(): void
  {
    $authData = [
      'username' => $this->testUser->getAccountName(),
      'password' => 'test_password',
      'tenant_id' => $this->testTenant['tenant_id'],
    ];

    $startTime = microtime(true);
    
    $response = $this->drupalPost('/api/auth/login', [
      'json' => $authData,
      'headers' => [
        'Content-Type' => 'application/json',
      ],
    ]);
    
    $endTime = microtime(true);
    $responseTime = ($endTime - $startTime) * 1000; // 转换为毫秒

    $this->assertLessThan(
      $this->performanceBenchmarks['authentication'],
      $responseTime,
      sprintf('Authentication took %0.2fms, expected < %dms', $responseTime, $this->performanceBenchmarks['authentication'])
    );

    // 记录性能指标
    $this->recordPerformanceMetric('authentication', $responseTime);
  }

  /**
   * 测试简单GET请求性能。
   */
  public function testSimpleGetPerformance(): void
  {
    $tenantId = $this->testTenant['tenant_id'];
    
    $startTime = microtime(true);
    
    $response = $this->drupalGet("/api/v1/{$tenantId}/projects", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    
    $endTime = microtime(true);
    $responseTime = ($endTime - $startTime) * 1000;

    $this->assertSession()->statusCodeEquals(200);
    $this->assertLessThan(
      $this->performanceBenchmarks['simple_get'],
      $responseTime,
      sprintf('Simple GET took %0.2fms, expected < %dms', $responseTime, $this->performanceBenchmarks['simple_get'])
    );

    $this->recordPerformanceMetric('simple_get', $responseTime);
  }

  /**
   * 测试实体创建性能。
   */
  public function testEntityCreatePerformance(): void
  {
    $tenantId = $this->testTenant['tenant_id'];
    $projectId = $this->testProject['project_id'];
    
    $entityData = [
      'name' => 'Performance Test Entity',
      'description' => 'This is a performance test entity',
      'status' => true,
    ];

    $startTime = microtime(true);
    
    $response = $this->drupalPost("/api/v1/{$tenantId}/projects/{$projectId}/entities/performance_test_entity", [
      'json' => $entityData,
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
        'Content-Type' => 'application/json',
      ],
    ]);
    
    $endTime = microtime(true);
    $responseTime = ($endTime - $startTime) * 1000;

    $this->assertSession()->statusCodeEquals(201);
    $this->assertLessThan(
      $this->performanceBenchmarks['entity_create'],
      $responseTime,
      sprintf('Entity create took %0.2fms, expected < %dms', $responseTime, $this->performanceBenchmarks['entity_create'])
    );

    $this->recordPerformanceMetric('entity_create', $responseTime);

    // 返回创建的实体ID用于后续测试
    $data = json_decode($this->getSession()->getPage()->getContent(), true);
    return $data['data']['id'];
  }

  /**
   * 测试实体列表查询性能。
   */
  public function testEntityListPerformance(): void
  {
    $tenantId = $this->testTenant['tenant_id'];
    $projectId = $this->testProject['project_id'];

    // 先创建一些测试数据
    for ($i = 1; $i <= 10; $i++) {
      $this->drupalPost("/api/v1/{$tenantId}/projects/{$projectId}/entities/performance_test_entity", [
        'json' => [
          'name' => "Test Entity {$i}",
          'description' => "Test entity description {$i}",
          'status' => $i % 2 === 0,
        ],
        'headers' => [
          'Authorization' => 'Bearer ' . $this->jwtToken,
          'Content-Type' => 'application/json',
        ],
      ]);
    }

    $startTime = microtime(true);
    
    $response = $this->drupalGet("/api/v1/{$tenantId}/projects/{$projectId}/entities/performance_test_entity", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    
    $endTime = microtime(true);
    $responseTime = ($endTime - $startTime) * 1000;

    $this->assertSession()->statusCodeEquals(200);
    $this->assertLessThan(
      $this->performanceBenchmarks['entity_list'],
      $responseTime,
      sprintf('Entity list took %0.2fms, expected < %dms', $responseTime, $this->performanceBenchmarks['entity_list'])
    );

    $this->recordPerformanceMetric('entity_list', $responseTime);
  }

  /**
   * 测试实体更新性能。
   *
   * @depends testEntityCreatePerformance
   */
  public function testEntityUpdatePerformance(): void
  {
    $tenantId = $this->testTenant['tenant_id'];
    $projectId = $this->testProject['project_id'];
    
    // 先创建一个实体
    $entityId = $this->testEntityCreatePerformance();
    
    $updateData = [
      'name' => 'Updated Performance Test Entity',
      'description' => 'This entity has been updated for performance testing',
    ];

    $startTime = microtime(true);
    
    $response = $this->drupalPatch("/api/v1/{$tenantId}/projects/{$projectId}/entities/performance_test_entity/{$entityId}", [
      'json' => $updateData,
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
        'Content-Type' => 'application/json',
      ],
    ]);
    
    $endTime = microtime(true);
    $responseTime = ($endTime - $startTime) * 1000;

    $this->assertSession()->statusCodeEquals(200);
    $this->assertLessThan(
      $this->performanceBenchmarks['entity_update'],
      $responseTime,
      sprintf('Entity update took %0.2fms, expected < %dms', $responseTime, $this->performanceBenchmarks['entity_update'])
    );

    $this->recordPerformanceMetric('entity_update', $responseTime);
    
    return $entityId;
  }

  /**
   * 测试实体删除性能。
   *
   * @depends testEntityUpdatePerformance
   */
  public function testEntityDeletePerformance(): void
  {
    $tenantId = $this->testTenant['tenant_id'];
    $projectId = $this->testProject['project_id'];
    
    // 先创建一个实体
    $entityId = $this->testEntityUpdatePerformance();

    $startTime = microtime(true);
    
    $response = $this->drupalDelete("/api/v1/{$tenantId}/projects/{$projectId}/entities/performance_test_entity/{$entityId}", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    
    $endTime = microtime(true);
    $responseTime = ($endTime - $startTime) * 1000;

    $this->assertSession()->statusCodeEquals(200);
    $this->assertLessThan(
      $this->performanceBenchmarks['entity_delete'],
      $responseTime,
      sprintf('Entity delete took %0.2fms, expected < %dms', $responseTime, $this->performanceBenchmarks['entity_delete'])
    );

    $this->recordPerformanceMetric('entity_delete', $responseTime);
  }

  /**
   * 测试并发请求性能。
   */
  public function testConcurrentRequestsPerformance(): void
  {
    $tenantId = $this->testTenant['tenant_id'];
    $numRequests = 10;
    $startTime = microtime(true);

    // 模拟并发请求（在测试环境中串行执行）
    for ($i = 0; $i < $numRequests; $i++) {
      $this->drupalGet("/api/v1/{$tenantId}/projects", [
        'headers' => [
          'Authorization' => 'Bearer ' . $this->jwtToken,
        ],
      ]);
      
      $this->assertSession()->statusCodeEquals(200);
    }

    $endTime = microtime(true);
    $totalTime = ($endTime - $startTime) * 1000;
    $averageTime = $totalTime / $numRequests;

    // 每个请求平均时间应该在合理范围内
    $this->assertLessThan(
      $this->performanceBenchmarks['simple_get'] * 1.5, // 允许并发时有50%的性能下降
      $averageTime,
      sprintf('Average concurrent request time %0.2fms is too high', $averageTime)
    );

    $this->recordPerformanceMetric('concurrent_average', $averageTime);
    $this->recordPerformanceMetric('concurrent_total', $totalTime);
  }

  /**
   * 测试大数据集查询性能。
   */
  public function testLargeDatasetQueryPerformance(): void
  {
    $tenantId = $this->testTenant['tenant_id'];
    $projectId = $this->testProject['project_id'];

    // 创建大量测试数据
    $numEntities = 100;
    for ($i = 1; $i <= $numEntities; $i++) {
      $this->drupalPost("/api/v1/{$tenantId}/projects/{$projectId}/entities/performance_test_entity", [
        'json' => [
          'name' => "Large Dataset Entity {$i}",
          'description' => "Description for entity {$i} in large dataset performance test",
          'status' => $i % 3 === 0,
        ],
        'headers' => [
          'Authorization' => 'Bearer ' . $this->jwtToken,
          'Content-Type' => 'application/json',
        ],
      ]);
    }

    // 测试分页查询性能
    $startTime = microtime(true);
    
    $response = $this->drupalGet("/api/v1/{$tenantId}/projects/{$projectId}/entities/performance_test_entity?limit=20&page=1", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    
    $endTime = microtime(true);
    $responseTime = ($endTime - $startTime) * 1000;

    $this->assertSession()->statusCodeEquals(200);
    $this->assertLessThan(
      $this->performanceBenchmarks['entity_list'] * 2, // 允许大数据集查询时间翻倍
      $responseTime,
      sprintf('Large dataset query took %0.2fms, expected < %dms', $responseTime, $this->performanceBenchmarks['entity_list'] * 2)
    );

    $this->recordPerformanceMetric('large_dataset_query', $responseTime);

    // 验证分页功能正常工作
    $data = json_decode($this->getSession()->getPage()->getContent(), true);
    $this->assertTrue($data['success']);
    $this->assertArrayHasKey('pagination', $data);
    $this->assertEquals(20, count($data['data']));
  }

  /**
   * 记录性能指标。
   *
   * @param string $operation
   *   操作名称。
   * @param float $responseTime
   *   响应时间（毫秒）。
   */
  protected function recordPerformanceMetric(string $operation, float $responseTime): void
  {
    // 在实际应用中，这里可以将性能指标写入数据库或日志系统
    $timestamp = date('Y-m-d H:i:s');
    $message = sprintf(
      'Performance metric - %s: %0.2fms at %s',
      $operation,
      $responseTime,
      $timestamp
    );
    
    // 使用Drupal日志系统记录性能指标
    \Drupal::logger('baas_api_performance')->info($message, [
      'operation' => $operation,
      'response_time' => $responseTime,
      'timestamp' => $timestamp,
      'benchmark' => $this->performanceBenchmarks[$operation] ?? null,
      'passed' => isset($this->performanceBenchmarks[$operation]) 
        ? $responseTime < $this->performanceBenchmarks[$operation]
        : null,
    ]);
    
    // 输出到测试报告
    fwrite(STDOUT, "\n🏃 Performance: {$operation} = {$responseTime}ms\n");
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void
  {
    // 输出性能总结报告
    fwrite(STDOUT, "\n📊 Performance Benchmarks Summary:\n");
    foreach ($this->performanceBenchmarks as $operation => $benchmark) {
      fwrite(STDOUT, "  - {$operation}: < {$benchmark}ms\n");
    }
    
    parent::tearDown();
  }

}