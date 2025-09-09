<?php

declare(strict_types=1);

namespace Drupal\Tests\baas_api\Functional;

use Drupal\baas_tenant\Entity\Tenant;
use Drupal\baas_project\Entity\Project;
use GuzzleHttp\RequestOptions;

/**
 * API权限检查功能测试。
 *
 * 测试API端点的权限验证功能。
 *
 * @group baas_api
 * @group baas_auth
 */
class ApiPermissionTest extends ApiIntegrationTestBase
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
   * API密钥。
   *
   * @var string
   */
  protected string $apiKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    // 创建测试租户
    $tenantManager = $this->container->get('baas_tenant.manager');
    $this->testTenant = $tenantManager->createTenant(
      'Test Tenant',
      $this->testUser->id(),
      ['description' => 'API权限测试租户']
    );

    // 创建测试项目
    $projectManager = $this->container->get('baas_project.manager');
    $this->testProject = $projectManager->createProject(
      $this->testTenant['tenant_id'],
      [
        'name' => 'Test Project',
        'machine_name' => 'test_project',
        'description' => 'API权限测试项目',
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

    // 生成API密钥
    $apiKeyManager = $this->container->get('baas_auth.api_key_manager');
    $keyData = $apiKeyManager->createApiKey([
      'name' => 'Test API Key',
      'user_id' => $this->testUser->id(),
      'tenant_id' => $this->testTenant['tenant_id'],
      'permissions' => ['read_entities', 'create_entities'],
    ]);
    $this->apiKey = $keyData['key'];
  }

  /**
   * 测试无认证访问API端点被拒绝。
   */
  public function testUnauthenticatedAccessDenied(): void
  {
    $tenantId = $this->testTenant['tenant_id'];
    
    // 尝试无认证访问租户端点
    $response = $this->drupalGet("/api/v1/{$tenantId}/projects");
    $this->assertSession()->statusCodeEquals(401);

    // 验证错误响应格式
    $data = json_decode($this->getSession()->getPage()->getContent(), true);
    $this->assertFalse($data['success']);
    $this->assertArrayHasKey('error', $data);
    $this->assertEquals('AUTHENTICATION_REQUIRED', $data['error']['code']);
  }

  /**
   * 测试JWT认证访问API端点。
   */
  public function testJwtAuthenticatedAccess(): void
  {
    $tenantId = $this->testTenant['tenant_id'];
    
    // 使用JWT令牌访问项目列表
    $response = $this->drupalGet("/api/v1/{$tenantId}/projects", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    
    $this->assertSession()->statusCodeEquals(200);
    
    // 验证响应格式
    $data = json_decode($this->getSession()->getPage()->getContent(), true);
    $this->assertTrue($data['success']);
    $this->assertArrayHasKey('data', $data);
  }

  /**
   * 测试API密钥认证访问API端点。
   */
  public function testApiKeyAuthenticatedAccess(): void
  {
    $tenantId = $this->testTenant['tenant_id'];
    $projectId = $this->testProject['project_id'];
    
    // 使用API密钥访问项目实体模板
    $response = $this->drupalGet("/api/v1/{$tenantId}/projects/{$projectId}/templates", [
      'headers' => [
        'X-API-Key' => $this->apiKey,
      ],
    ]);
    
    $this->assertSession()->statusCodeEquals(200);
    
    // 验证响应格式
    $data = json_decode($this->getSession()->getPage()->getContent(), true);
    $this->assertTrue($data['success']);
    $this->assertArrayHasKey('data', $data);
  }

  /**
   * 测试无效JWT令牌被拒绝。
   */
  public function testInvalidJwtTokenRejected(): void
  {
    $tenantId = $this->testTenant['tenant_id'];
    
    // 使用无效的JWT令牌
    $response = $this->drupalGet("/api/v1/{$tenantId}/projects", [
      'headers' => [
        'Authorization' => 'Bearer invalid_jwt_token',
      ],
    ]);
    
    $this->assertSession()->statusCodeEquals(401);
    
    $data = json_decode($this->getSession()->getPage()->getContent(), true);
    $this->assertFalse($data['success']);
    $this->assertEquals('INVALID_TOKEN', $data['error']['code']);
  }

  /**
   * 测试无效API密钥被拒绝。
   */
  public function testInvalidApiKeyRejected(): void
  {
    $tenantId = $this->testTenant['tenant_id'];
    $projectId = $this->testProject['project_id'];
    
    // 使用无效的API密钥
    $response = $this->drupalGet("/api/v1/{$tenantId}/projects/{$projectId}/templates", [
      'headers' => [
        'X-API-Key' => 'invalid_api_key_123456',
      ],
    ]);
    
    $this->assertSession()->statusCodeEquals(401);
    
    $data = json_decode($this->getSession()->getPage()->getContent(), true);
    $this->assertFalse($data['success']);
    $this->assertEquals('INVALID_API_KEY', $data['error']['code']);
  }

  /**
   * 测试跨租户访问被拒绝。
   */
  public function testCrossTenantAccessDenied(): void
  {
    // 创建另一个租户
    $tenantManager = $this->container->get('baas_tenant.manager');
    $otherTenant = $tenantManager->createTenant(
      'Other Tenant',
      $this->testUser->id(),
      ['description' => '其他测试租户']
    );

    $otherTenantId = $otherTenant['tenant_id'];
    
    // 尝试使用当前租户的JWT访问其他租户的资源
    $response = $this->drupalGet("/api/v1/{$otherTenantId}/projects", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    
    $this->assertSession()->statusCodeEquals(403);
    
    $data = json_decode($this->getSession()->getPage()->getContent(), true);
    $this->assertFalse($data['success']);
    $this->assertEquals('ACCESS_DENIED', $data['error']['code']);
  }

  /**
   * 测试权限不足时访问被拒绝。
   */
  public function testInsufficientPermissionsDenied(): void
  {
    // 创建一个权限有限的用户
    $limitedUser = $this->createUser([], 'limited_user');
    
    // 添加用户到租户但不给管理权限
    $tenantManager = $this->container->get('baas_tenant.manager');
    $tenantManager->addUserToTenant(
      $this->testTenant['tenant_id'], 
      $limitedUser->id(), 
      'viewer'
    );

    // 为限制用户生成JWT令牌
    $jwtManager = $this->container->get('baas_auth.jwt_token_manager');
    $limitedJwt = $jwtManager->generateToken([
      'sub' => $limitedUser->id(),
      'tenant_id' => $this->testTenant['tenant_id'],
      'username' => $limitedUser->getAccountName(),
      'email' => $limitedUser->getEmail(),
      'roles' => ['tenant_viewer'],
    ]);

    $tenantId = $this->testTenant['tenant_id'];
    
    // 尝试创建新项目（需要管理员权限）
    $response = $this->drupalPost("/api/v1/{$tenantId}/projects", [
      'json' => [
        'name' => 'Unauthorized Project',
        'machine_name' => 'unauthorized_project',
      ],
      'headers' => [
        'Authorization' => 'Bearer ' . $limitedJwt,
        'Content-Type' => 'application/json',
      ],
    ]);
    
    $this->assertSession()->statusCodeEquals(403);
    
    $data = json_decode($this->getSession()->getPage()->getContent(), true);
    $this->assertFalse($data['success']);
    $this->assertEquals('INSUFFICIENT_PERMISSIONS', $data['error']['code']);
  }

  /**
   * 测试项目级权限检查。
   */
  public function testProjectLevelPermissionCheck(): void
  {
    $tenantId = $this->testTenant['tenant_id'];
    $projectId = $this->testProject['project_id'];
    
    // 测试有权限的操作：获取项目实体模板
    $response = $this->drupalGet("/api/v1/{$tenantId}/projects/{$projectId}/templates", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    
    $this->assertSession()->statusCodeEquals(200);
    
    // 创建限制项目成员
    $limitedUser = $this->createUser([], 'project_member');
    $projectManager = $this->container->get('baas_project.manager');
    $projectManager->addProjectMember(
      $projectId,
      $limitedUser->id(),
      'viewer' // 只能查看，不能修改
    );

    // 为项目成员生成JWT令牌
    $jwtManager = $this->container->get('baas_auth.jwt_token_manager');
    $memberJwt = $jwtManager->generateToken([
      'sub' => $limitedUser->id(),
      'tenant_id' => $tenantId,
      'project_id' => $projectId,
      'username' => $limitedUser->getAccountName(),
      'email' => $limitedUser->getEmail(),
      'roles' => ['project_viewer'],
    ]);

    // 项目查看者应该可以获取模板
    $response = $this->drupalGet("/api/v1/{$tenantId}/projects/{$projectId}/templates", [
      'headers' => [
        'Authorization' => 'Bearer ' . $memberJwt,
      ],
    ]);
    
    $this->assertSession()->statusCodeEquals(200);

    // 但不能创建新的实体模板
    $response = $this->drupalPost("/api/v1/{$tenantId}/projects/{$projectId}/templates", [
      'json' => [
        'name' => 'test_entity',
        'label' => 'Test Entity',
        'fields' => [],
      ],
      'headers' => [
        'Authorization' => 'Bearer ' . $memberJwt,
        'Content-Type' => 'application/json',
      ],
    ]);
    
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * 测试实体级权限检查。
   */
  public function testEntityLevelPermissionCheck(): void
  {
    $tenantId = $this->testTenant['tenant_id'];
    $projectId = $this->testProject['project_id'];
    
    // 首先创建一个实体模板
    $templateManager = $this->container->get('baas_entity.template_manager');
    $template = $templateManager->createTemplate([
      'tenant_id' => $tenantId,
      'project_id' => $projectId,
      'name' => 'users',
      'label' => 'Users',
      'fields' => [
        [
          'name' => 'name',
          'type' => 'string',
          'label' => 'Name',
          'required' => true,
        ],
        [
          'name' => 'email',
          'type' => 'email',
          'label' => 'Email',
          'required' => true,
        ],
      ],
    ]);

    // 测试创建实体数据
    $response = $this->drupalPost("/api/v1/{$tenantId}/projects/{$projectId}/entities/users", [
      'json' => [
        'name' => 'Test User',
        'email' => 'test@example.com',
      ],
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
        'Content-Type' => 'application/json',
      ],
    ]);
    
    $this->assertSession()->statusCodeEquals(201);
    
    $data = json_decode($this->getSession()->getPage()->getContent(), true);
    $this->assertTrue($data['success']);
    $this->assertArrayHasKey('data', $data);
    $entityId = $data['data']['id'];

    // 测试获取实体数据
    $response = $this->drupalGet("/api/v1/{$tenantId}/projects/{$projectId}/entities/users/{$entityId}", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    
    $this->assertSession()->statusCodeEquals(200);
    
    // 测试更新实体数据
    $response = $this->drupalPatch("/api/v1/{$tenantId}/projects/{$projectId}/entities/users/{$entityId}", [
      'json' => [
        'name' => 'Updated User Name',
      ],
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
        'Content-Type' => 'application/json',
      ],
    ]);
    
    $this->assertSession()->statusCodeEquals(200);
    
    // 测试删除实体数据
    $response = $this->drupalDelete("/api/v1/{$tenantId}/projects/{$projectId}/entities/users/{$entityId}", [
      'headers' => [
        'Authorization' => 'Bearer ' . $this->jwtToken,
      ],
    ]);
    
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * 测试API速率限制。
   */
  public function testApiRateLimit(): void
  {
    $tenantId = $this->testTenant['tenant_id'];
    
    // 快速连续发送多个请求以触发速率限制
    $rateLimitExceeded = false;
    for ($i = 0; $i < 100; $i++) {
      $response = $this->drupalGet("/api/v1/{$tenantId}/projects", [
        'headers' => [
          'Authorization' => 'Bearer ' . $this->jwtToken,
        ],
      ]);
      
      if ($this->getSession()->getStatusCode() === 429) {
        $rateLimitExceeded = true;
        break;
      }
    }
    
    // 应该触发速率限制
    $this->assertTrue($rateLimitExceeded, 'Rate limit should be triggered after multiple requests');
    
    if ($rateLimitExceeded) {
      $data = json_decode($this->getSession()->getPage()->getContent(), true);
      $this->assertFalse($data['success']);
      $this->assertEquals('RATE_LIMIT_EXCEEDED', $data['error']['code']);
    }
  }

}