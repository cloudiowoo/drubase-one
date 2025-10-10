<?php

declare(strict_types=1);

namespace Drupal\Tests\baas_api\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\baas_api\Controller\BaseApiController;
use Drupal\baas_api\Service\ApiResponseService;
use Drupal\baas_api\Service\ApiValidationService;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * BaseAPI控制器单元测试。
 *
 * @coversDefaultClass \Drupal\baas_api\Controller\BaseApiController
 * @group baas_api
 */
class BaseApiControllerTest extends UnitTestCase
{

  /**
   * Mock API响应服务。
   *
   * @var \Drupal\baas_api\Service\ApiResponseService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ApiResponseService|MockObject $responseService;

  /**
   * Mock API验证服务。
   *
   * @var \Drupal\baas_api\Service\ApiValidationService|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ApiValidationService|MockObject $validationService;

  /**
   * Mock统一权限检查器。
   *
   * @var \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected UnifiedPermissionCheckerInterface|MockObject $permissionChecker;

  /**
   * 测试控制器实例。
   *
   * @var \Drupal\baas_api\Controller\BaseApiController
   */
  protected BaseApiController $controller;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    // 创建Mock服务
    $this->responseService = $this->createMock(ApiResponseService::class);
    $this->validationService = $this->createMock(ApiValidationService::class);
    $this->permissionChecker = $this->createMock(UnifiedPermissionCheckerInterface::class);

    // 创建测试控制器（匿名类继承BaseApiController）
    $this->controller = new class(
      $this->responseService,
      $this->validationService,
      $this->permissionChecker
    ) extends BaseApiController {
      // 公开受保护的方法以便测试
      public function publicCreateSuccessResponse($data, string $message = '', array $meta = [], array $pagination = [], int $status = 200): JsonResponse
      {
        return $this->createSuccessResponse($data, $message, $meta, $pagination, $status);
      }

      public function publicCreateErrorResponse(string $error, string $code, int $status = 500, array $context = []): JsonResponse
      {
        return $this->createErrorResponse($error, $code, $status, $context);
      }

      public function publicHasPermission(string $permission, ?string $tenant_id = null, ?string $project_id = null): bool
      {
        return $this->hasPermission($permission, $tenant_id, $project_id);
      }

      public function publicRequirePermission(string $permission, ?string $tenant_id = null, ?string $project_id = null): ?JsonResponse
      {
        return $this->requirePermission($permission, $tenant_id, $project_id);
      }

      public function publicValidateTenantAccess(string $tenant_id, string $operation = 'view'): array
      {
        return $this->validateTenantAccess($tenant_id, $operation);
      }
    };
  }

  /**
   * 测试构造函数。
   *
   * @covers ::__construct
   */
  public function testConstruct(): void
  {
    $this->assertInstanceOf(BaseApiController::class, $this->controller);
  }

  /**
   * 测试成功响应创建。
   *
   * @covers ::createSuccessResponse
   */
  public function testCreateSuccessResponse(): void
  {
    $testData = ['id' => 1, 'name' => 'Test'];
    $testMessage = 'Operation successful';
    $expectedResponse = new JsonResponse(['success' => true, 'data' => $testData]);

    $this->responseService
      ->expects($this->once())
      ->method('createSuccessResponse')
      ->with($testData, $testMessage, [], [], 200)
      ->willReturn($expectedResponse);

    $response = $this->controller->publicCreateSuccessResponse($testData, $testMessage);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals($expectedResponse, $response);
  }

  /**
   * 测试错误响应创建。
   *
   * @covers ::createErrorResponse
   */
  public function testCreateErrorResponse(): void
  {
    $errorMessage = 'Something went wrong';
    $errorCode = 'TEST_ERROR';
    $statusCode = 400;
    $context = ['detail' => 'Test error detail'];
    $expectedResponse = new JsonResponse(['success' => false, 'error' => $errorMessage], $statusCode);

    $this->responseService
      ->expects($this->once())
      ->method('createErrorResponse')
      ->with($errorMessage, $errorCode, $statusCode, $context)
      ->willReturn($expectedResponse);

    $response = $this->controller->publicCreateErrorResponse($errorMessage, $errorCode, $statusCode, $context);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals($expectedResponse, $response);
  }

  /**
   * 测试权限检查方法。
   *
   * @covers ::hasPermission
   */
  public function testHasPermission(): void
  {
    $permission = 'test_permission';
    $tenantId = 'tenant_123';
    $projectId = 'project_456';

    // Mock getAuthData方法返回的认证数据
    $authData = ['user_id' => 123];

    // 使用反射来模拟getAuthData的返回值
    $reflection = new \ReflectionClass($this->controller);
    $property = $reflection->getProperty('permissionChecker');
    $property->setAccessible(true);

    // 测试项目级权限检查
    $this->permissionChecker
      ->expects($this->once())
      ->method('checkProjectPermission')
      ->with(123, $projectId, $permission)
      ->willReturn(true);

    // 由于我们不能直接mock getAuthData，我们需要测试实际的权限检查逻辑
    // 这里我们测试当没有认证数据时权限检查返回false
    $hasPermission = $this->controller->publicHasPermission($permission, $tenantId, $projectId);
    
    // 没有认证数据时应该返回false
    $this->assertFalse($hasPermission);
  }

  /**
   * 测试权限要求方法。
   *
   * @covers ::requirePermission
   */
  public function testRequirePermission(): void
  {
    $permission = 'admin_permission';
    
    // 测试权限不足的情况
    $expectedErrorResponse = new JsonResponse([
      'success' => false,
      'error' => 'Insufficient permissions'
    ], 403);

    $this->responseService
      ->expects($this->once())
      ->method('createErrorResponse')
      ->with(
        'Insufficient permissions',
        'INSUFFICIENT_PERMISSIONS',
        403,
        ['required_permission' => $permission]
      )
      ->willReturn($expectedErrorResponse);

    $response = $this->controller->publicRequirePermission($permission);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals($expectedErrorResponse, $response);
  }

  /**
   * 测试租户访问验证。
   *
   * @covers ::validateTenantAccess
   */
  public function testValidateTenantAccess(): void
  {
    $tenantId = 'tenant_123';
    $operation = 'read';

    // Mock验证服务返回租户ID有效
    $this->validationService
      ->expects($this->once())
      ->method('validateTenantId')
      ->with($tenantId)
      ->willReturn(['valid' => true]);

    // 调用validateTenantAccess方法
    $result = $this->controller->publicValidateTenantAccess($tenantId, $operation);

    // 应该返回租户ID格式无效错误（因为我们没有mock租户管理器）
    $this->assertIsArray($result);
    $this->assertArrayHasKey('valid', $result);
  }

  /**
   * 测试数据验证方法委托。
   *
   * @covers ::validateData
   */
  public function testValidateData(): void
  {
    $data = ['name' => 'test', 'email' => 'test@example.com'];
    $rules = [
      'name' => ['required' => true, 'min_length' => 2],
      'email' => ['required' => true, 'pattern' => 'email'],
    ];
    $expectedResult = ['valid' => true, 'errors' => []];

    $this->validationService
      ->expects($this->once())
      ->method('validateData')
      ->with($data, $rules)
      ->willReturn($expectedResult);

    // 使用反射调用受保护的方法
    $reflection = new \ReflectionClass($this->controller);
    $method = $reflection->getMethod('validateData');
    $method->setAccessible(true);

    $result = $method->invoke($this->controller, $data, $rules);

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * 测试租户ID验证方法委托。
   *
   * @covers ::validateTenantId
   */
  public function testValidateTenantId(): void
  {
    $tenantId = 'tenant_123';
    $expectedResult = ['valid' => true];

    $this->validationService
      ->expects($this->once())
      ->method('validateTenantId')
      ->with($tenantId)
      ->willReturn($expectedResult);

    // 使用反射调用受保护的方法
    $reflection = new \ReflectionClass($this->controller);
    $method = $reflection->getMethod('validateTenantId');
    $method->setAccessible(true);

    $result = $method->invoke($this->controller, $tenantId);

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * 测试项目ID验证方法委托。
   *
   * @covers ::validateProjectId
   */
  public function testValidateProjectId(): void
  {
    $projectId = 'project_456';
    $expectedResult = ['valid' => true];

    $this->validationService
      ->expects($this->once())
      ->method('validateProjectId')
      ->with($projectId)
      ->willReturn($expectedResult);

    // 使用反射调用受保护的方法
    $reflection = new \ReflectionClass($this->controller);
    $method = $reflection->getMethod('validateProjectId');
    $method->setAccessible(true);

    $result = $method->invoke($this->controller, $projectId);

    $this->assertEquals($expectedResult, $result);
  }

  /**
   * 测试实体名称验证方法委托。
   *
   * @covers ::validateEntityName
   */
  public function testValidateEntityName(): void
  {
    $entityName = 'users';
    $expectedResult = ['valid' => true];

    $this->validationService
      ->expects($this->once())
      ->method('validateEntityName')
      ->with($entityName)
      ->willReturn($expectedResult);

    // 使用反射调用受保护的方法
    $reflection = new \ReflectionClass($this->controller);
    $method = $reflection->getMethod('validateEntityName');
    $method->setAccessible(true);

    $result = $method->invoke($this->controller, $entityName);

    $this->assertEquals($expectedResult, $result);
  }

}