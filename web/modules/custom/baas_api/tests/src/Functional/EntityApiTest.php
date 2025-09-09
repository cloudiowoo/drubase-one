<?php

declare(strict_types=1);

namespace Drupal\Tests\baas_api\Functional;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\RequestOptions;

/**
 * 实体API集成测试。
 *
 * @group baas_api
 */
class EntityApiTest extends ApiIntegrationTestBase
{

  /**
   * 测试实体模板。
   *
   * @var mixed
   */
  protected $testTemplate;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void
  {
    parent::setUp();

    // 创建测试实体模板
    $this->testTemplate = $this->createTestEntityTemplate('test_entity');
  }

  /**
   * 测试获取实体模板列表。
   */
  public function testGetEntityTemplates(): void
  {
    $response = $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/templates');
    
    $this->assertApiSuccess($response);
    
    $body = $this->getApiResponseBody($response);
    $this->assertIsArray($body['data']);
    $this->assertNotEmpty($body['data']);
    
    // 验证模板数据结构
    $template = $body['data'][0];
    $this->assertArrayHasKey('id', $template);
    $this->assertArrayHasKey('name', $template);
    $this->assertArrayHasKey('label', $template);
    $this->assertArrayHasKey('fields', $template);
  }

  /**
   * 测试创建实体模板。
   */
  public function testCreateEntityTemplate(): void
  {
    $template_data = [
      'name' => 'new_entity',
      'label' => 'New Entity',
      'description' => 'A new test entity',
      'fields' => [
        [
          'name' => 'name',
          'type' => 'string',
          'label' => 'Name',
          'required' => true,
          'settings' => ['max_length' => 100],
        ],
        [
          'name' => 'status',
          'type' => 'boolean',
          'label' => 'Status',
          'required' => false,
          'settings' => [],
        ],
      ],
    ];

    $response = $this->apiRequest('POST', '/api/v1/' . $this->testTenant['tenant_id'] . '/templates', [
      RequestOptions::JSON => $template_data,
    ]);
    
    $this->assertApiSuccess($response, 201);
    
    $body = $this->getApiResponseBody($response);
    $this->assertArrayHasKey('id', $body['data']);
    $this->assertEquals('new_entity', $body['data']['name']);
    $this->assertEquals('New Entity', $body['data']['label']);
    $this->assertCount(2, $body['data']['fields']);
  }

  /**
   * 测试获取单个实体模板。
   */
  public function testGetSingleEntityTemplate(): void
  {
    $response = $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/templates/' . $this->testTemplate['id']);
    
    $this->assertApiSuccess($response);
    
    $body = $this->getApiResponseBody($response);
    $this->assertEquals($this->testTemplate['id'], $body['data']['id']);
    $this->assertEquals('test_entity', $body['data']['name']);
    $this->assertArrayHasKey('fields', $body['data']);
  }

  /**
   * 测试更新实体模板。
   */
  public function testUpdateEntityTemplate(): void
  {
    $update_data = [
      'label' => 'Updated Test Entity',
      'description' => 'Updated description',
    ];

    $response = $this->apiRequest('PUT', '/api/v1/' . $this->testTenant['tenant_id'] . '/templates/' . $this->testTemplate['id'], [
      RequestOptions::JSON => $update_data,
    ]);
    
    $this->assertApiSuccess($response);
    
    $body = $this->getApiResponseBody($response);
    $this->assertEquals('Updated Test Entity', $body['data']['label']);
    $this->assertEquals('Updated description', $body['data']['description']);
  }

  /**
   * 测试删除实体模板。
   */
  public function testDeleteEntityTemplate(): void
  {
    // 先创建一个临时模板
    $temp_template = $this->createTestEntityTemplate('temp_entity');

    $response = $this->apiRequest('DELETE', '/api/v1/' . $this->testTenant['tenant_id'] . '/templates/' . $temp_template['id']);
    
    $this->assertEquals(204, $response->getStatusCode());

    // 验证模板已被删除
    try {
      $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/templates/' . $temp_template['id']);
      $this->fail('应该抛出404错误');
    } catch (ClientException $e) {
      $this->assertEquals(404, $e->getResponse()->getStatusCode());
    }
  }

  /**
   * 测试获取实体数据列表。
   */
  public function testGetEntityDataList(): void
  {
    // 创建一些测试数据
    $this->createTestEntityData('test_entity', ['title' => 'Entity 1']);
    $this->createTestEntityData('test_entity', ['title' => 'Entity 2']);

    $response = $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/test_entity');
    
    $this->assertApiSuccess($response);
    $this->assertApiPagination($response);
    
    $body = $this->getApiResponseBody($response);
    $this->assertIsArray($body['data']);
    $this->assertCount(2, $body['data']);
    
    // 验证数据结构
    $entity = $body['data'][0];
    $this->assertArrayHasKey('id', $entity);
    $this->assertArrayHasKey('title', $entity);
    $this->assertArrayHasKey('description', $entity);
  }

  /**
   * 测试实体数据分页。
   */
  public function testEntityDataPagination(): void
  {
    // 创建多个测试数据
    for ($i = 1; $i <= 25; $i++) {
      $this->createTestEntityData('test_entity', ['title' => "Entity {$i}"]);
    }

    // 测试第一页
    $response = $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/test_entity?page=1&limit=10');
    
    $this->assertApiSuccess($response);
    $this->assertApiPagination($response);
    
    $body = $this->getApiResponseBody($response);
    $this->assertCount(10, $body['data']);
    
    $pagination = $body['pagination'];
    $this->assertEquals(1, $pagination['page']);
    $this->assertEquals(10, $pagination['limit']);
    $this->assertEquals(25, $pagination['total']);
    $this->assertEquals(3, $pagination['pages']);
    $this->assertFalse($pagination['has_prev']);
    $this->assertTrue($pagination['has_next']);
  }

  /**
   * 测试实体数据排序。
   */
  public function testEntityDataSorting(): void
  {
    // 创建测试数据
    $this->createTestEntityData('test_entity', ['title' => 'Beta Entity']);
    $this->createTestEntityData('test_entity', ['title' => 'Alpha Entity']);

    // 按标题升序排序
    $response = $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/test_entity?sort_field=title&sort_direction=ASC');
    
    $this->assertApiSuccess($response);
    
    $body = $this->getApiResponseBody($response);
    $this->assertEquals('Alpha Entity', $body['data'][0]['title']);
    $this->assertEquals('Beta Entity', $body['data'][1]['title']);
  }

  /**
   * 测试实体数据过滤。
   */
  public function testEntityDataFiltering(): void
  {
    // 创建测试数据
    $this->createTestEntityData('test_entity', ['title' => 'Active Entity', 'status' => 1]);
    $this->createTestEntityData('test_entity', ['title' => 'Inactive Entity', 'status' => 0]);

    // 过滤激活状态的实体
    $response = $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/test_entity?status=1');
    
    $this->assertApiSuccess($response);
    
    $body = $this->getApiResponseBody($response);
    $this->assertCount(1, $body['data']);
    $this->assertEquals('Active Entity', $body['data'][0]['title']);
  }

  /**
   * 测试创建实体数据。
   */
  public function testCreateEntityData(): void
  {
    $entity_data = [
      'title' => 'New Entity',
      'description' => 'A new test entity',
    ];

    $response = $this->apiRequest('POST', '/api/v1/' . $this->testTenant['tenant_id'] . '/test_entity', [
      RequestOptions::JSON => $entity_data,
    ]);
    
    $this->assertApiSuccess($response, 201);
    
    $body = $this->getApiResponseBody($response);
    $this->assertArrayHasKey('id', $body['data']);
    $this->assertEquals('New Entity', $body['data']['title']);
    $this->assertEquals('A new test entity', $body['data']['description']);
  }

  /**
   * 测试获取单个实体数据。
   */
  public function testGetSingleEntityData(): void
  {
    $entity = $this->createTestEntityData('test_entity', ['title' => 'Single Entity']);

    $response = $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/test_entity/' . $entity->id());
    
    $this->assertApiSuccess($response);
    
    $body = $this->getApiResponseBody($response);
    $this->assertEquals($entity->id(), $body['data']['id']);
    $this->assertEquals('Single Entity', $body['data']['title']);
  }

  /**
   * 测试更新实体数据。
   */
  public function testUpdateEntityData(): void
  {
    $entity = $this->createTestEntityData('test_entity', ['title' => 'Original Title']);

    $update_data = [
      'title' => 'Updated Title',
      'description' => 'Updated description',
    ];

    $response = $this->apiRequest('PUT', '/api/v1/' . $this->testTenant['tenant_id'] . '/test_entity/' . $entity->id(), [
      RequestOptions::JSON => $update_data,
    ]);
    
    $this->assertApiSuccess($response);
    
    $body = $this->getApiResponseBody($response);
    $this->assertEquals('Updated Title', $body['data']['title']);
    $this->assertEquals('Updated description', $body['data']['description']);
  }

  /**
   * 测试删除实体数据。
   */
  public function testDeleteEntityData(): void
  {
    $entity = $this->createTestEntityData('test_entity', ['title' => 'To Delete']);

    $response = $this->apiRequest('DELETE', '/api/v1/' . $this->testTenant['tenant_id'] . '/test_entity/' . $entity->id());
    
    $this->assertEquals(204, $response->getStatusCode());

    // 验证实体已被删除
    try {
      $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/test_entity/' . $entity->id());
      $this->fail('应该抛出404错误');
    } catch (ClientException $e) {
      $this->assertEquals(404, $e->getResponse()->getStatusCode());
    }
  }

  /**
   * 测试无效的实体名称。
   */
  public function testInvalidEntityName(): void
  {
    try {
      $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/invalid-entity');
      $this->fail('应该抛出404错误');
    } catch (ClientException $e) {
      $this->assertEquals(404, $e->getResponse()->getStatusCode());
      $this->assertApiError($e->getResponse(), 404, 'ENTITY_TYPE_NOT_FOUND');
    }
  }

  /**
   * 测试无效的实体ID。
   */
  public function testInvalidEntityId(): void
  {
    try {
      $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/test_entity/999999');
      $this->fail('应该抛出404错误');
    } catch (ClientException $e) {
      $this->assertEquals(404, $e->getResponse()->getStatusCode());
      $this->assertApiError($e->getResponse(), 404, 'ENTITY_NOT_FOUND');
    }
  }

  /**
   * 测试无效的租户ID。
   */
  public function testInvalidTenantId(): void
  {
    try {
      $this->apiRequest('GET', '/api/v1/invalid_tenant/test_entity');
      $this->fail('应该抛出404错误');
    } catch (ClientException $e) {
      $this->assertEquals(404, $e->getResponse()->getStatusCode());
      $this->assertApiError($e->getResponse(), 404, 'TENANT_NOT_FOUND');
    }
  }

  /**
   * 测试创建实体数据时的验证错误。
   */
  public function testCreateEntityDataValidationError(): void
  {
    try {
      // 发送无效的JSON数据
      $this->apiRequest('POST', '/api/v1/' . $this->testTenant['tenant_id'] . '/test_entity', [
        RequestOptions::JSON => [
          'title' => '', // 必填字段为空
        ],
      ]);
      $this->fail('应该抛出验证错误');
    } catch (ClientException $e) {
      $this->assertEquals(422, $e->getResponse()->getStatusCode());
      $this->assertApiError($e->getResponse(), 422, 'VALIDATION_ERROR');
    }
  }

  /**
   * 测试批量操作。
   */
  public function testBatchOperations(): void
  {
    // 创建多个实体
    $entities = [];
    for ($i = 1; $i <= 5; $i++) {
      $entities[] = $this->createTestEntityData('test_entity', ['title' => "Batch Entity {$i}"]);
    }

    // 测试批量获取
    $ids = array_map(function ($entity) {
      return $entity->id();
    }, $entities);

    $response = $this->apiRequest('GET', '/api/v1/' . $this->testTenant['tenant_id'] . '/test_entity?ids=' . implode(',', $ids));
    
    $this->assertApiSuccess($response);
    
    $body = $this->getApiResponseBody($response);
    $this->assertCount(5, $body['data']);
  }

  /**
   * 测试实体关联。
   */
  public function testEntityReferences(): void
  {
    // 创建带有引用字段的实体模板
    $this->createTestEntityTemplate('referenced_entity', [
      [
        'name' => 'ref_field',
        'type' => 'reference',
        'label' => 'Reference Field',
        'required' => false,
        'settings' => [
          'target_type' => 'test_entity',
        ],
      ],
    ]);

    // 创建被引用的实体
    $target_entity = $this->createTestEntityData('test_entity', ['title' => 'Target Entity']);

    // 创建引用实体
    $reference_data = [
      'title' => 'Reference Entity',
      'ref_field' => $target_entity->id(),
    ];

    $response = $this->apiRequest('POST', '/api/v1/' . $this->testTenant['tenant_id'] . '/referenced_entity', [
      RequestOptions::JSON => $reference_data,
    ]);
    
    $this->assertApiSuccess($response, 201);
    
    $body = $this->getApiResponseBody($response);
    $this->assertEquals($target_entity->id(), $body['data']['ref_field']);
  }

}