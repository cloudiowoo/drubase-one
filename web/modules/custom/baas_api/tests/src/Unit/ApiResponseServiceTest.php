<?php

declare(strict_types=1);

namespace Drupal\Tests\baas_api\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\baas_api\Service\ApiResponseService;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * API响应服务单元测试。
 *
 * @coversDefaultClass \Drupal\baas_api\Service\ApiResponseService
 * @group baas_api
 */
class ApiResponseServiceTest extends UnitTestCase
{

  /**
   * API响应服务。
   *
   * @var \Drupal\baas_api\Service\ApiResponseService
   */
  protected ApiResponseService $responseService;

  /**
   * Mock日志工厂。
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * Mock配置工厂。
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * Mock日志器。
   *
   * @var \Psr\Log\LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    $this->logger = $this->createMock(LoggerInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->loggerFactory->method('get')->willReturn($this->logger);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);

    $this->responseService = new ApiResponseService(
      $this->loggerFactory,
      $this->configFactory
    );
  }

  /**
   * 测试创建成功响应。
   *
   * @covers ::createSuccessResponse
   */
  public function testCreateSuccessResponse(): void
  {
    $data = ['test' => 'data'];
    $message = 'Success message';
    $meta = ['custom' => 'meta'];

    $response = $this->responseService->createSuccessResponse($data, $message, $meta);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(200, $response->getStatusCode());

    $content = json_decode($response->getContent(), true);
    $this->assertTrue($content['success']);
    $this->assertEquals($data, $content['data']);
    $this->assertEquals($message, $content['message']);
    $this->assertArrayHasKey('meta', $content);
    $this->assertEquals('meta', $content['meta']['custom']);
  }

  /**
   * 测试创建错误响应。
   *
   * @covers ::createErrorResponse
   */
  public function testCreateErrorResponse(): void
  {
    $error = 'Error message';
    $code = 'ERROR_CODE';
    $status = 400;
    $context = ['field' => 'value'];

    $this->logger->expects($this->once())
      ->method('error')
      ->with($this->stringContains('API错误'));

    $response = $this->responseService->createErrorResponse($error, $code, $status, $context);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals($status, $response->getStatusCode());

    $content = json_decode($response->getContent(), true);
    $this->assertFalse($content['success']);
    $this->assertEquals($error, $content['error']['message']);
    $this->assertEquals($code, $content['error']['code']);
    $this->assertEquals($context, $content['error']['context']);
  }

  /**
   * 测试创建验证错误响应。
   *
   * @covers ::createValidationErrorResponse
   */
  public function testCreateValidationErrorResponse(): void
  {
    $errors = ['field1' => 'Required', 'field2' => 'Invalid'];
    $message = 'Validation failed';

    $this->logger->expects($this->once())
      ->method('error');

    $response = $this->responseService->createValidationErrorResponse($errors, $message);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(422, $response->getStatusCode());

    $content = json_decode($response->getContent(), true);
    $this->assertFalse($content['success']);
    $this->assertEquals($message, $content['error']['message']);
    $this->assertEquals('VALIDATION_ERROR', $content['error']['code']);
    $this->assertEquals(['validation_errors' => $errors], $content['error']['context']);
  }

  /**
   * 测试创建分页响应。
   *
   * @covers ::createPaginatedResponse
   */
  public function testCreatePaginatedResponse(): void
  {
    $items = [['id' => 1], ['id' => 2]];
    $total = 100;
    $page = 2;
    $limit = 10;
    $message = 'Paginated data';

    $response = $this->responseService->createPaginatedResponse($items, $total, $page, $limit, $message);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(200, $response->getStatusCode());

    $content = json_decode($response->getContent(), true);
    $this->assertTrue($content['success']);
    $this->assertEquals($items, $content['data']);
    $this->assertEquals($message, $content['message']);
    
    $pagination = $content['pagination'];
    $this->assertEquals($page, $pagination['page']);
    $this->assertEquals($limit, $pagination['limit']);
    $this->assertEquals($total, $pagination['total']);
    $this->assertEquals(10, $pagination['pages']);
    $this->assertTrue($pagination['has_prev']);
    $this->assertTrue($pagination['has_next']);
  }

  /**
   * 测试创建无内容响应。
   *
   * @covers ::createNoContentResponse
   */
  public function testCreateNoContentResponse(): void
  {
    $message = 'Operation completed';
    $meta = ['operation' => 'delete'];

    $response = $this->responseService->createNoContentResponse($message, $meta);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(204, $response->getStatusCode());

    $content = json_decode($response->getContent(), true);
    $this->assertTrue($content['success']);
    $this->assertNull($content['data']);
    $this->assertEquals($message, $content['message']);
    $this->assertEquals('delete', $content['meta']['operation']);
  }

  /**
   * 测试创建创建成功响应。
   *
   * @covers ::createCreatedResponse
   */
  public function testCreateCreatedResponse(): void
  {
    $data = ['id' => 123, 'name' => 'Created Item'];
    $message = 'Resource created';

    $response = $this->responseService->createCreatedResponse($data, $message);

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertEquals(201, $response->getStatusCode());

    $content = json_decode($response->getContent(), true);
    $this->assertTrue($content['success']);
    $this->assertEquals($data, $content['data']);
    $this->assertEquals($message, $content['message']);
  }

  /**
   * 测试验证JSON请求。
   *
   * @covers ::validateJsonRequest
   */
  public function testValidateJsonRequest(): void
  {
    // 测试有效JSON
    $valid_json = '{"name": "test", "email": "test@example.com"}';
    $required_fields = ['name'];
    $allowed_fields = ['name', 'email'];

    $result = $this->responseService->validateJsonRequest($valid_json, $required_fields, $allowed_fields);

    $this->assertTrue($result['valid']);
    $this->assertEquals(['name' => 'test', 'email' => 'test@example.com'], $result['data']);
  }

  /**
   * 测试验证无效JSON请求。
   *
   * @covers ::validateJsonRequest
   */
  public function testValidateInvalidJsonRequest(): void
  {
    // 测试无效JSON
    $invalid_json = '{"name": "test",}';

    $result = $this->responseService->validateJsonRequest($invalid_json);

    $this->assertFalse($result['valid']);
    $this->assertEquals('INVALID_JSON', $result['code']);
    $this->assertStringContainsString('Invalid JSON format', $result['error']);
  }

  /**
   * 测试验证缺少必需字段。
   *
   * @covers ::validateJsonRequest
   */
  public function testValidateMissingRequiredFields(): void
  {
    $json = '{"email": "test@example.com"}';
    $required_fields = ['name', 'email'];

    $result = $this->responseService->validateJsonRequest($json, $required_fields);

    $this->assertFalse($result['valid']);
    $this->assertEquals('MISSING_REQUIRED_FIELDS', $result['code']);
    $this->assertStringContainsString('Missing required fields: name', $result['error']);
    $this->assertEquals(['name'], $result['context']['missing_fields']);
  }

  /**
   * 测试验证不允许的字段。
   *
   * @covers ::validateJsonRequest
   */
  public function testValidateInvalidFields(): void
  {
    $json = '{"name": "test", "invalid_field": "value"}';
    $allowed_fields = ['name'];

    $result = $this->responseService->validateJsonRequest($json, [], $allowed_fields);

    $this->assertFalse($result['valid']);
    $this->assertEquals('INVALID_FIELDS', $result['code']);
    $this->assertStringContainsString('Invalid fields: invalid_field', $result['error']);
    $this->assertEquals(['invalid_field'], $result['context']['invalid_fields']);
  }

  /**
   * 测试构建元数据。
   *
   * @covers ::buildMeta
   */
  public function testBuildMeta(): void
  {
    $additional_meta = ['custom' => 'value'];

    $reflection = new \ReflectionClass($this->responseService);
    $method = $reflection->getMethod('buildMeta');
    $method->setAccessible(true);

    $meta = $method->invoke($this->responseService, $additional_meta);

    $this->assertArrayHasKey('timestamp', $meta);
    $this->assertArrayHasKey('api_version', $meta);
    $this->assertArrayHasKey('request_id', $meta);
    $this->assertArrayHasKey('server_time', $meta);
    $this->assertEquals('value', $meta['custom']);
    $this->assertEquals('v1', $meta['api_version']);
  }

  /**
   * 测试规范化分页信息。
   *
   * @covers ::normalizePagination
   */
  public function testNormalizePagination(): void
  {
    $pagination = [
      'page' => 2,
      'limit' => 10,
      'total' => 25,
    ];

    $reflection = new \ReflectionClass($this->responseService);
    $method = $reflection->getMethod('normalizePagination');
    $method->setAccessible(true);

    $normalized = $method->invoke($this->responseService, $pagination);

    $this->assertEquals(2, $normalized['page']);
    $this->assertEquals(10, $normalized['limit']);
    $this->assertEquals(25, $normalized['total']);
    $this->assertEquals(3, $normalized['pages']);
    $this->assertTrue($normalized['has_prev']);
    $this->assertTrue($normalized['has_next']);
  }

  /**
   * 测试生成请求ID。
   *
   * @covers ::generateRequestId
   */
  public function testGenerateRequestId(): void
  {
    $reflection = new \ReflectionClass($this->responseService);
    $method = $reflection->getMethod('generateRequestId');
    $method->setAccessible(true);

    $request_id1 = $method->invoke($this->responseService);
    $request_id2 = $method->invoke($this->responseService);

    $this->assertStringStartsWith('req_', $request_id1);
    $this->assertStringStartsWith('req_', $request_id2);
    $this->assertNotEquals($request_id1, $request_id2);
  }

}