<?php

declare(strict_types=1);

namespace Drupal\Tests\baas_api\Functional;

/**
 * API缓存集成测试。
 *
 * 测试API缓存功能的完整流程。
 *
 * @group baas_api
 * @group cache
 */
class ApiCacheIntegrationTest extends ApiIntegrationTestBase
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
   * API缓存服务。
   *
   * @var \Drupal\baas_api\Service\ApiCacheServiceInterface
   */
  protected $cacheService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    // 获取缓存服务
    $this->cacheService = $this->container->get('baas_api.cache');

    // 创建测试租户
    $tenantManager = $this->container->get('baas_tenant.manager');
    $this->testTenant = $tenantManager->createTenant(
      'Cache Test Tenant',
      $this->testUser->id(),
      ['description' => 'API缓存测试租户']
    );

    // 创建测试项目
    $projectManager = $this->container->get('baas_project.manager');
    $this->testProject = $projectManager->createProject(
      $this->testTenant['tenant_id'],
      [
        'name' => 'Cache Test Project',
        'machine_name' => 'cache_test_project',
        'description' => 'API缓存测试项目',
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
  }

  /**
   * 测试API响应缓存功能。
   */
  public function testApiResponseCaching(): void
  {
    $tenantId = $this->testTenant['tenant_id'];

    // 清除所有缓存确保测试开始时没有缓存
    $this->cacheService->invalidateCache();

    // 第一次请求 - 应该是缓存未命中
    $response1 = $this->drupalGet("/api/v1/{$tenantId}/projects", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderExists('X-Cache');
    $this->assertSession()->responseHeaderEquals('X-Cache', 'MISS');

    // 第二次相同请求 - 应该是缓存命中
    $response2 = $this->drupalGet("/api/v1/{$tenantId}/projects", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderExists('X-Cache');
    $this->assertSession()->responseHeaderEquals('X-Cache', 'HIT');
    $this->assertSession()->responseHeaderExists('X-Cache-Key');
    $this->assertSession()->responseHeaderExists('X-Cache-TTL');

    // 验证响应内容相同
    $data1 = json_decode($response1, true);
    $data2 = json_decode($this->getSession()->getPage()->getContent(), true);
    $this->assertEquals($data1, $data2);
  }

  /**
   * 测试缓存无效化功能。
   */
  public function testCacheInvalidation(): void
  {
    $tenantId = $this->testTenant['tenant_id'];

    // 第一次请求建立缓存
    $this->drupalGet("/api/v1/{$tenantId}/projects", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    $this->assertSession()->responseHeaderEquals('X-Cache', 'MISS');

    // 第二次请求验证缓存命中
    $this->drupalGet("/api/v1/{$tenantId}/projects", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    $this->assertSession()->responseHeaderEquals('X-Cache', 'HIT');

    // 清除租户缓存
    $this->cacheService->invalidateTenantCache($tenantId);

    // 第三次请求应该是缓存未命中（因为缓存已清除）
    $this->drupalGet("/api/v1/{$tenantId}/projects", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    $this->assertSession()->responseHeaderEquals('X-Cache', 'MISS');
  }

  /**
   * 测试不同HTTP方法的缓存策略。
   */
  public function testHttpMethodCachingStrategy(): void
  {
    $tenantId = $this->testTenant['tenant_id'];

    // GET请求应该被缓存
    $this->drupalGet("/api/v1/{$tenantId}/projects", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderExists('X-Cache');

    // POST请求不应该被缓存
    $response = $this->drupalPost("/api/v1/{$tenantId}/projects", [
      'json' => [
        'name' => 'Test Project for Cache',
        'machine_name' => 'test_project_cache',
        'description' => 'Testing cache behavior with POST',
      ],
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
        'Content-Type' => 'application/json',
      ],
    ]);

    // POST响应不应该有缓存头
    $this->assertSession()->statusCodeEquals(201);
    $this->assertSession()->responseHeaderNotExists('X-Cache');
  }

  /**
   * 测试查询参数对缓存键的影响。
   */
  public function testQueryParameterCacheKeys(): void
  {
    $tenantId = $this->testTenant['tenant_id'];

    // 请求1：无查询参数
    $this->drupalGet("/api/v1/{$tenantId}/projects", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    $this->assertSession()->responseHeaderEquals('X-Cache', 'MISS');

    // 请求2：相同路径，无查询参数 - 应该缓存命中
    $this->drupalGet("/api/v1/{$tenantId}/projects", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    $this->assertSession()->responseHeaderEquals('X-Cache', 'HIT');

    // 请求3：相同路径，有查询参数 - 应该缓存未命中（不同的缓存键）
    $this->drupalGet("/api/v1/{$tenantId}/projects?limit=10", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    $this->assertSession()->responseHeaderEquals('X-Cache', 'MISS');

    // 请求4：相同路径和查询参数 - 应该缓存命中
    $this->drupalGet("/api/v1/{$tenantId}/projects?limit=10", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    $this->assertSession()->responseHeaderEquals('X-Cache', 'HIT');
  }

  /**
   * 测试缓存绕过机制。
   */
  public function testCacheBypass(): void
  {
    $tenantId = $this->testTenant['tenant_id'];

    // 建立缓存
    $this->drupalGet("/api/v1/{$tenantId}/projects", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    $this->assertSession()->responseHeaderEquals('X-Cache', 'MISS');

    // 正常请求应该命中缓存
    $this->drupalGet("/api/v1/{$tenantId}/projects", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    $this->assertSession()->responseHeaderEquals('X-Cache', 'HIT');

    // 使用no_cache参数绕过缓存
    $this->drupalGet("/api/v1/{$tenantId}/projects?no_cache=1", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    $this->assertSession()->responseHeaderNotExists('X-Cache');

    // 使用Cache-Control: no-cache头绕过缓存
    $this->drupalGet("/api/v1/{$tenantId}/projects", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
        'Cache-Control' => 'no-cache',
      ],
    ]);
    $this->assertSession()->responseHeaderNotExists('X-Cache');
  }

  /**
   * 测试项目级缓存无效化。
   */
  public function testProjectCacheInvalidation(): void
  {
    $tenantId = $this->testTenant['tenant_id'];
    $projectId = $this->testProject['project_id'];

    // 建立项目级缓存
    $this->drupalGet("/api/v1/{$tenantId}/projects/{$projectId}", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    $this->assertSession()->responseHeaderEquals('X-Cache', 'MISS');

    // 验证缓存命中
    $this->drupalGet("/api/v1/{$tenantId}/projects/{$projectId}", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    $this->assertSession()->responseHeaderEquals('X-Cache', 'HIT');

    // 清除项目缓存
    $this->cacheService->invalidateProjectCache($projectId);

    // 验证缓存已被清除
    $this->drupalGet("/api/v1/{$tenantId}/projects/{$projectId}", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    $this->assertSession()->responseHeaderEquals('X-Cache', 'MISS');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void
  {
    // 清理测试缓存
    if (isset($this->cacheService)) {
      $this->cacheService->invalidateCache();
    }

    parent::tearDown();
  }

}