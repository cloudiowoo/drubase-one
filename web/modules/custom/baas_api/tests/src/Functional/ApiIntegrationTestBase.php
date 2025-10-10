<?php

declare(strict_types=1);

namespace Drupal\Tests\baas_api\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;
use Drupal\baas_tenant\Entity\Tenant;
use Drupal\baas_auth\Service\JwtTokenManagerInterface;
use GuzzleHttp\RequestOptions;

/**
 * API集成测试基类。
 *
 * 提供API测试的基础功能和工具方法。
 */
abstract class ApiIntegrationTestBase extends BrowserTestBase
{

  /**
   * 默认主题。
   *
   * @var string
   */
  protected $defaultTheme = 'stark';

  /**
   * 测试模块。
   *
   * @var array
   */
  protected static $modules = [
    'baas_api',
    'baas_auth',
    'baas_tenant',
    'baas_entity',
    'baas_project',
    'user',
    'system',
  ];

  /**
   * 测试用户。
   *
   * @var \Drupal\user\Entity\User
   */
  protected User $testUser;

  /**
   * 测试租户。
   *
   * @var \Drupal\baas_tenant\Entity\Tenant
   */
  protected $testTenant;

  /**
   * JWT Token管理器。
   *
   * @var \Drupal\baas_auth\Service\JwtTokenManagerInterface
   */
  protected JwtTokenManagerInterface $jwtTokenManager;

  /**
   * 测试JWT Token。
   *
   * @var string
   */
  protected string $testJwtToken;

  /**
   * 测试API Key。
   *
   * @var string
   */
  protected string $testApiKey;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    // 获取服务
    $this->jwtTokenManager = $this->container->get('baas_auth.jwt_token_manager');

    // 创建测试用户
    $this->testUser = $this->createTestUser();

    // 创建测试租户
    $this->testTenant = $this->createTestTenant();

    // 生成测试Token
    $this->testJwtToken = $this->generateTestJwtToken();
    $this->testApiKey = $this->generateTestApiKey();
  }

  /**
   * 创建测试用户。
   *
   * @return \Drupal\user\Entity\User
   *   测试用户。
   */
  protected function createTestUser(): User
  {
    $user = User::create([
      'name' => 'test_api_user',
      'mail' => 'test@example.com',
      'pass' => 'test_password',
      'status' => 1,
      'roles' => ['api_user'],
    ]);
    $user->save();

    return $user;
  }

  /**
   * 创建测试租户。
   *
   * @return mixed
   *   测试租户。
   */
  protected function createTestTenant()
  {
    $tenant_manager = $this->container->get('baas_tenant.manager');
    
    return $tenant_manager->createTenant(
      'test_tenant',
      $this->testUser->id(),
      [
        'name' => 'Test Tenant',
        'description' => 'Test tenant for API integration testing',
        'status' => 1,
      ]
    );
  }

  /**
   * 生成测试JWT Token。
   *
   * @return string
   *   JWT Token。
   */
  protected function generateTestJwtToken(): string
  {
    $payload = [
      'sub' => $this->testUser->id(),
      'tenant_id' => $this->testTenant['tenant_id'],
      'username' => $this->testUser->getAccountName(),
      'email' => $this->testUser->getEmail(),
      'roles' => ['api_user'],
      'permissions' => [
        'access tenant entity data',
        'create tenant entity data',
        'update tenant entity data',
        'delete tenant entity data',
        'access tenant entity templates',
      ],
      'type' => 'access',
    ];

    return $this->jwtTokenManager->generateToken($payload);
  }

  /**
   * 生成测试API Key。
   *
   * @return string
   *   API Key。
   */
  protected function generateTestApiKey(): string
  {
    $api_key_manager = $this->container->get('baas_auth.api_key_manager');
    
    return $api_key_manager->createApiKey(
      $this->testUser->id(),
      'test_api_key',
      [
        'tenant_id' => $this->testTenant['tenant_id'],
        'permissions' => [
          'access tenant entity data',
          'create tenant entity data',
          'update tenant entity data',
          'delete tenant entity data',
        ],
        'expires_at' => time() + 3600, // 1小时后过期
      ]
    );
  }

  /**
   * 发送API请求（使用JWT认证）。
   *
   * @param string $method
   *   HTTP方法。
   * @param string $url
   *   API URL。
   * @param array $options
   *   请求选项。
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   HTTP响应。
   */
  protected function apiRequest(string $method, string $url, array $options = []): \Psr\Http\Message\ResponseInterface
  {
    $options = array_merge_recursive([
      RequestOptions::HEADERS => [
        'Authorization' => 'Bearer ' . $this->testJwtToken,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ], $options);

    return $this->getHttpClient()->request($method, $url, $options);
  }

  /**
   * 发送API请求（使用API Key认证）。
   *
   * @param string $method
   *   HTTP方法。
   * @param string $url
   *   API URL。
   * @param array $options
   *   请求选项。
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   HTTP响应。
   */
  protected function apiKeyRequest(string $method, string $url, array $options = []): \Psr\Http\Message\ResponseInterface
  {
    $options = array_merge_recursive([
      RequestOptions::HEADERS => [
        'X-API-Key' => $this->testApiKey,
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ], $options);

    return $this->getHttpClient()->request($method, $url, $options);
  }

  /**
   * 发送匿名API请求。
   *
   * @param string $method
   *   HTTP方法。
   * @param string $url
   *   API URL。
   * @param array $options
   *   请求选项。
   *
   * @return \Psr\Http\Message\ResponseInterface
   *   HTTP响应。
   */
  protected function anonymousRequest(string $method, string $url, array $options = []): \Psr\Http\Message\ResponseInterface
  {
    $options = array_merge_recursive([
      RequestOptions::HEADERS => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
    ], $options);

    return $this->getHttpClient()->request($method, $url, $options);
  }

  /**
   * 断言API响应成功。
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   HTTP响应。
   * @param int $expected_status
   *   期望的HTTP状态码。
   */
  protected function assertApiSuccess(\Psr\Http\Message\ResponseInterface $response, int $expected_status = 200): void
  {
    $this->assertEquals($expected_status, $response->getStatusCode());
    
    $body = json_decode($response->getBody()->getContents(), true);
    $this->assertIsArray($body);
    $this->assertTrue($body['success'] ?? false, 'API响应应该标记为成功');
    $this->assertArrayHasKey('data', $body);
    $this->assertArrayHasKey('meta', $body);
  }

  /**
   * 断言API响应失败。
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   HTTP响应。
   * @param int $expected_status
   *   期望的HTTP状态码。
   * @param string $expected_code
   *   期望的错误代码。
   */
  protected function assertApiError(\Psr\Http\Message\ResponseInterface $response, int $expected_status, string $expected_code = null): void
  {
    $this->assertEquals($expected_status, $response->getStatusCode());
    
    $body = json_decode($response->getBody()->getContents(), true);
    $this->assertIsArray($body);
    $this->assertFalse($body['success'] ?? true, 'API响应应该标记为失败');
    $this->assertArrayHasKey('error', $body);
    
    if ($expected_code) {
      $this->assertEquals($expected_code, $body['error']['code']);
    }
  }

  /**
   * 断言API响应包含分页信息。
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   HTTP响应。
   */
  protected function assertApiPagination(\Psr\Http\Message\ResponseInterface $response): void
  {
    $body = json_decode($response->getBody()->getContents(), true);
    $this->assertArrayHasKey('pagination', $body);
    
    $pagination = $body['pagination'];
    $this->assertArrayHasKey('page', $pagination);
    $this->assertArrayHasKey('limit', $pagination);
    $this->assertArrayHasKey('total', $pagination);
    $this->assertArrayHasKey('pages', $pagination);
    $this->assertArrayHasKey('has_prev', $pagination);
    $this->assertArrayHasKey('has_next', $pagination);
  }

  /**
   * 断言API响应包含速率限制头部。
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   HTTP响应。
   */
  protected function assertRateLimitHeaders(\Psr\Http\Message\ResponseInterface $response): void
  {
    $this->assertTrue($response->hasHeader('X-RateLimit-Limit'));
    $this->assertTrue($response->hasHeader('X-RateLimit-Remaining'));
    $this->assertTrue($response->hasHeader('X-RateLimit-Reset'));
  }

  /**
   * 断言API响应包含CORS头部。
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   HTTP响应。
   */
  protected function assertCorsHeaders(\Psr\Http\Message\ResponseInterface $response): void
  {
    $this->assertTrue($response->hasHeader('Access-Control-Allow-Origin'));
    $this->assertTrue($response->hasHeader('Access-Control-Allow-Methods'));
    $this->assertTrue($response->hasHeader('Access-Control-Allow-Headers'));
  }

  /**
   * 断言API响应包含安全头部。
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   HTTP响应。
   */
  protected function assertSecurityHeaders(\Psr\Http\Message\ResponseInterface $response): void
  {
    $this->assertTrue($response->hasHeader('X-Content-Type-Options'));
    $this->assertTrue($response->hasHeader('X-Frame-Options'));
    $this->assertTrue($response->hasHeader('X-XSS-Protection'));
    $this->assertTrue($response->hasHeader('Content-Security-Policy'));
  }

  /**
   * 获取API响应体。
   *
   * @param \Psr\Http\Message\ResponseInterface $response
   *   HTTP响应。
   *
   * @return array
   *   解析后的响应数据。
   */
  protected function getApiResponseBody(\Psr\Http\Message\ResponseInterface $response): array
  {
    return json_decode($response->getBody()->getContents(), true);
  }

  /**
   * 创建测试实体模板。
   *
   * @param string $entity_name
   *   实体名称。
   * @param array $fields
   *   字段定义。
   *
   * @return mixed
   *   创建的模板。
   */
  protected function createTestEntityTemplate(string $entity_name, array $fields = [])
  {
    $default_fields = [
      [
        'name' => 'title',
        'type' => 'string',
        'label' => 'Title',
        'required' => true,
        'settings' => ['max_length' => 255],
      ],
      [
        'name' => 'description',
        'type' => 'text',
        'label' => 'Description',
        'required' => false,
        'settings' => [],
      ],
    ];

    $fields = array_merge($default_fields, $fields);

    $template_manager = $this->container->get('baas_entity.template_manager');
    
    return $template_manager->createTemplate(
      $this->testTenant['tenant_id'],
      $entity_name,
      [
        'label' => ucfirst($entity_name),
        'description' => "Test entity template for {$entity_name}",
        'fields' => $fields,
      ]
    );
  }

  /**
   * 创建测试实体数据。
   *
   * @param string $entity_name
   *   实体名称。
   * @param array $data
   *   实体数据。
   *
   * @return mixed
   *   创建的实体。
   */
  protected function createTestEntityData(string $entity_name, array $data = [])
  {
    $default_data = [
      'title' => 'Test Entity',
      'description' => 'Test entity for API integration testing',
    ];

    $data = array_merge($default_data, $data);

    $entity_type_id = $this->testTenant['tenant_id'] . '_' . $entity_name;
    $entity_storage = $this->container->get('entity_type.manager')->getStorage($entity_type_id);
    
    $entity = $entity_storage->create($data);
    $entity->save();

    return $entity;
  }

  /**
   * 清理测试数据。
   */
  protected function cleanupTestData(): void
  {
    // 清理测试实体
    $entity_registry = $this->container->get('baas_entity.entity_registry');
    $registered_types = $entity_registry->getRegisteredEntityTypes();
    
    foreach ($registered_types as $entity_type_id) {
      if (strpos($entity_type_id, $this->testTenant['tenant_id']) === 0) {
        try {
          $entity_storage = $this->container->get('entity_type.manager')->getStorage($entity_type_id);
          $entities = $entity_storage->loadMultiple();
          foreach ($entities as $entity) {
            $entity->delete();
          }
        } catch (\Exception $e) {
          // 忽略清理错误
        }
      }
    }

    // 清理测试租户
    try {
      $tenant_manager = $this->container->get('baas_tenant.manager');
      $tenant_manager->deleteTenant($this->testTenant['tenant_id']);
    } catch (\Exception $e) {
      // 忽略清理错误
    }

    // 清理测试用户
    try {
      $this->testUser->delete();
    } catch (\Exception $e) {
      // 忽略清理错误
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void
  {
    $this->cleanupTestData();
    parent::tearDown();
  }

}