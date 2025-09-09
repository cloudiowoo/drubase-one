<?php

declare(strict_types=1);

namespace Drupal\Tests\baas_api\Functional;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\RequestOptions;

/**
 * API认证集成测试。
 *
 * @group baas_api
 */
class ApiAuthenticationTest extends ApiIntegrationTestBase
{

  /**
   * 测试JWT认证成功。
   */
  public function testJwtAuthenticationSuccess(): void
  {
    $response = $this->apiRequest('GET', '/api/health');
    
    $this->assertApiSuccess($response);
    $this->assertRateLimitHeaders($response);
    $this->assertCorsHeaders($response);
    $this->assertSecurityHeaders($response);
  }

  /**
   * 测试API Key认证成功。
   */
  public function testApiKeyAuthenticationSuccess(): void
  {
    $response = $this->apiKeyRequest('GET', '/api/health');
    
    $this->assertApiSuccess($response);
    $this->assertRateLimitHeaders($response);
  }

  /**
   * 测试缺少认证信息。
   */
  public function testMissingAuthentication(): void
  {
    try {
      $this->anonymousRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/users');
      $this->fail('应该抛出认证错误');
    } catch (ClientException $e) {
      $this->assertEquals(401, $e->getResponse()->getStatusCode());
      $this->assertApiError($e->getResponse(), 401, 'AUTHENTICATION_REQUIRED');
    }
  }

  /**
   * 测试无效的JWT Token。
   */
  public function testInvalidJwtToken(): void
  {
    try {
      $response = $this->getHttpClient()->request('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/users', [
        RequestOptions::HEADERS => [
          'Authorization' => 'Bearer invalid_token',
          'Content-Type' => 'application/json',
        ],
      ]);
      $this->fail('应该抛出认证错误');
    } catch (ClientException $e) {
      $this->assertEquals(401, $e->getResponse()->getStatusCode());
      $this->assertApiError($e->getResponse(), 401, 'INVALID_JWT_TOKEN');
    }
  }

  /**
   * 测试过期的JWT Token。
   */
  public function testExpiredJwtToken(): void
  {
    // 创建过期的JWT Token
    $expired_payload = [
      'sub' => $this->testUser->id(),
      'tenant_id' => $this->testTenant['tenant_id'],
      'username' => $this->testUser->getAccountName(),
      'type' => 'access',
      'exp' => time() - 3600, // 1小时前过期
    ];

    $expired_token = $this->jwtTokenManager->generateToken($expired_payload);

    try {
      $response = $this->getHttpClient()->request('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/users', [
        RequestOptions::HEADERS => [
          'Authorization' => 'Bearer ' . $expired_token,
          'Content-Type' => 'application/json',
        ],
      ]);
      $this->fail('应该抛出认证错误');
    } catch (ClientException $e) {
      $this->assertEquals(401, $e->getResponse()->getStatusCode());
      $this->assertApiError($e->getResponse(), 401, 'JWT_TOKEN_EXPIRED');
    }
  }

  /**
   * 测试被撤销的JWT Token。
   */
  public function testRevokedJwtToken(): void
  {
    // 将当前token加入黑名单
    $payload = $this->jwtTokenManager->parseToken($this->testJwtToken);
    $blacklist_service = $this->container->get('baas_auth.jwt_blacklist_service');
    $blacklist_service->addToBlacklist($payload['jti'], $payload['exp']);

    try {
      $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/users');
      $this->fail('应该抛出认证错误');
    } catch (ClientException $e) {
      $this->assertEquals(401, $e->getResponse()->getStatusCode());
      $this->assertApiError($e->getResponse(), 401, 'JWT_TOKEN_REVOKED');
    }
  }

  /**
   * 测试无效的API Key。
   */
  public function testInvalidApiKey(): void
  {
    try {
      $response = $this->getHttpClient()->request('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/users', [
        RequestOptions::HEADERS => [
          'X-API-Key' => 'invalid_api_key',
          'Content-Type' => 'application/json',
        ],
      ]);
      $this->fail('应该抛出认证错误');
    } catch (ClientException $e) {
      $this->assertEquals(401, $e->getResponse()->getStatusCode());
      $this->assertApiError($e->getResponse(), 401, 'INVALID_API_KEY');
    }
  }

  /**
   * 测试禁用的API Key。
   */
  public function testDisabledApiKey(): void
  {
    // 禁用API Key
    $api_key_manager = $this->container->get('baas_auth.api_key_manager');
    $api_key_manager->disableApiKey($this->testApiKey);

    try {
      $this->apiKeyRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/users');
      $this->fail('应该抛出认证错误');
    } catch (ClientException $e) {
      $this->assertEquals(401, $e->getResponse()->getStatusCode());
      $this->assertApiError($e->getResponse(), 401, 'API_KEY_DISABLED');
    }
  }

  /**
   * 测试权限不足。
   */
  public function testInsufficientPermissions(): void
  {
    // 创建权限受限的JWT Token
    $limited_payload = [
      'sub' => $this->testUser->id(),
      'tenant_id' => $this->testTenant['tenant_id'],
      'username' => $this->testUser->getAccountName(),
      'permissions' => ['access tenant entity data'], // 没有删除权限
      'type' => 'access',
    ];

    $limited_token = $this->jwtTokenManager->generateToken($limited_payload);

    try {
      $response = $this->getHttpClient()->request('DELETE', '/api/v1/' . $this->testTenant['tenant_id'] . '/users/1', [
        RequestOptions::HEADERS => [
          'Authorization' => 'Bearer ' . $limited_token,
          'Content-Type' => 'application/json',
        ],
      ]);
      $this->fail('应该抛出权限错误');
    } catch (ClientException $e) {
      $this->assertEquals(403, $e->getResponse()->getStatusCode());
      $this->assertApiError($e->getResponse(), 403, 'INSUFFICIENT_PERMISSIONS');
    }
  }

  /**
   * 测试跨租户访问权限。
   */
  public function testCrossTenantAccess(): void
  {
    // 创建另一个租户
    $tenant_manager = $this->container->get('baas_tenant.manager');
    $other_tenant = $tenant_manager->createTenant(
      'other_tenant',
      $this->testUser->id(),
      ['name' => 'Other Tenant']
    );

    try {
      // 尝试访问其他租户的数据
      $this->apiRequest('GET', '/api/v1/' . $other_tenant['tenant_id'] . '/users');
      $this->fail('应该抛出权限错误');
    } catch (ClientException $e) {
      $this->assertEquals(403, $e->getResponse()->getStatusCode());
      $this->assertApiError($e->getResponse(), 403, 'ACCESS_DENIED');
    }
  }

  /**
   * 测试公开端点访问。
   */
  public function testPublicEndpointAccess(): void
  {
    // 健康检查端点
    $response = $this->anonymousRequest('GET', '/api/health');
    $this->assertApiSuccess($response);

    // API文档端点
    $response = $this->anonymousRequest('GET', '/api/docs');
    $this->assertEquals(200, $response->getStatusCode());

    // 登录端点
    $response = $this->anonymousRequest('POST', '/api/auth/login', [
      RequestOptions::JSON => [
        'username' => 'nonexistent',
        'password' => 'wrong',
      ],
    ]);
    // 应该返回认证失败，而不是认证要求
    $this->assertEquals(401, $response->getStatusCode());
  }

  /**
   * 测试CORS预检请求。
   */
  public function testCorsPreflightRequest(): void
  {
    $response = $this->getHttpClient()->request('OPTIONS', '/api/v1/' . $this->testTenant['tenant_id'] . '/users', [
      RequestOptions::HEADERS => [
        'Origin' => 'http://localhost:3000',
        'Access-Control-Request-Method' => 'GET',
        'Access-Control-Request-Headers' => 'Authorization',
      ],
    ]);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertCorsHeaders($response);
    $this->assertEquals('', $response->getBody()->getContents());
  }

  /**
   * 测试速率限制。
   */
  public function testRateLimit(): void
  {
    // 这个测试需要实际的速率限制配置
    // 由于测试环境的限制，这里只验证头部信息
    $response = $this->apiRequest('GET', '/api/health');
    
    $this->assertApiSuccess($response);
    $this->assertRateLimitHeaders($response);
    
    // 验证速率限制头部的值
    $limit = $response->getHeader('X-RateLimit-Limit')[0];
    $remaining = $response->getHeader('X-RateLimit-Remaining')[0];
    $reset = $response->getHeader('X-RateLimit-Reset')[0];
    
    $this->assertIsNumeric($limit);
    $this->assertIsNumeric($remaining);
    $this->assertIsNumeric($reset);
    $this->assertLessThanOrEqual($limit, $remaining);
  }

  /**
   * 测试请求ID追踪。
   */
  public function testRequestIdTracking(): void
  {
    $custom_request_id = 'test_request_123';
    
    $response = $this->apiRequest('GET', '/api/health', [
      RequestOptions::HEADERS => [
        'X-Request-ID' => $custom_request_id,
      ],
    ]);
    
    $this->assertApiSuccess($response);
    $this->assertTrue($response->hasHeader('X-Request-ID'));
    $this->assertEquals($custom_request_id, $response->getHeader('X-Request-ID')[0]);
  }

  /**
   * 测试API版本头部。
   */
  public function testApiVersionHeaders(): void
  {
    $response = $this->apiRequest('GET', '/api/health');
    
    $this->assertApiSuccess($response);
    $this->assertTrue($response->hasHeader('X-API-Version'));
    $this->assertTrue($response->hasHeader('X-API-Server'));
    $this->assertEquals('v1', $response->getHeader('X-API-Version')[0]);
    $this->assertEquals('BaaS-Platform', $response->getHeader('X-API-Server')[0]);
  }

}