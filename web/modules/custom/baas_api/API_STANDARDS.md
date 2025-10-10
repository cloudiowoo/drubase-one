# BaaS API 标准化指南

## 概述

本文档定义了BaaS平台所有API模块的标准化规范，确保一致的开发体验和API质量。

## 核心原则

### 1. 统一响应格式

所有API响应必须遵循以下标准格式：

#### 成功响应
```json
{
  "success": true,
  "data": <响应数据>,
  "meta": {
    "timestamp": "2025-01-18T12:00:00+00:00",
    "api_version": "v1",
    "request_id": "req_abcd1234_xyz789",
    "server_time": 1642507200.123
  },
  "message": "操作成功消息（可选）",
  "pagination": {
    "page": 1,
    "limit": 20,
    "total": 100,
    "pages": 5,
    "has_prev": false,
    "has_next": true
  }
}
```

#### 错误响应
```json
{
  "success": false,
  "error": {
    "message": "错误描述",
    "code": "ERROR_CODE"
  },
  "meta": {
    "timestamp": "2025-01-18T12:00:00+00:00",
    "api_version": "v1",
    "request_id": "req_abcd1234_xyz789",
    "server_time": 1642507200.123
  }
}
```

#### 验证错误响应
```json
{
  "success": false,
  "error": {
    "message": "Validation failed",
    "code": "VALIDATION_ERROR",
    "context": {
      "validation_errors": {
        "field1": ["错误消息1", "错误消息2"],
        "field2": ["错误消息3"]
      }
    }
  },
  "meta": { ... }
}
```

### 2. HTTP状态码标准

| 状态码 | 使用场景 | 示例 |
|--------|----------|------|
| 200 | 成功获取数据 | GET /api/v1/users |
| 201 | 成功创建资源 | POST /api/v1/users |
| 204 | 成功但无内容返回 | DELETE /api/v1/users/123 |
| 400 | 请求参数错误 | 缺少必需参数 |
| 401 | 未认证 | 缺少或无效的认证令牌 |
| 403 | 权限不足 | 无权访问特定资源 |
| 404 | 资源不存在 | 用户ID不存在 |
| 422 | 验证失败 | 数据格式不正确 |
| 429 | 请求过于频繁 | 超出API调用限制 |
| 500 | 服务器内部错误 | 系统异常 |

### 3. 错误代码标准

#### 通用错误代码
- `INVALID_JSON`: JSON格式错误
- `INVALID_REQUEST_FORMAT`: 请求格式错误
- `MISSING_REQUIRED_FIELDS`: 缺少必需字段
- `INVALID_FIELDS`: 包含无效字段
- `VALIDATION_ERROR`: 数据验证失败
- `ACCESS_DENIED`: 访问被拒绝
- `RESOURCE_NOT_FOUND`: 资源不存在
- `DUPLICATE_RESOURCE`: 资源已存在

#### 租户相关错误代码
- `TENANT_NOT_FOUND`: 租户不存在
- `INVALID_TENANT_ID`: 租户ID格式无效
- `TENANT_ACCESS_DENIED`: 租户访问被拒绝

#### 项目相关错误代码
- `PROJECT_NOT_FOUND`: 项目不存在
- `INVALID_PROJECT_ID`: 项目ID格式无效
- `PROJECT_ACCESS_DENIED`: 项目访问被拒绝

#### 实体相关错误代码
- `ENTITY_NOT_FOUND`: 实体不存在
- `INVALID_ENTITY_NAME`: 实体名称格式无效
- `ENTITY_TEMPLATE_NOT_FOUND`: 实体模板不存在

## 实现标准

### 1. 控制器要求

所有API控制器必须：

1. **继承BaseApiController**
   ```php
   class MyApiController extends BaseApiController
   {
     // 自动获得标准化响应方法
   }
   ```

2. **使用标准化响应方法**
   ```php
   // 成功响应
   return $this->createSuccessResponse($data, '操作成功');
   
   // 错误响应
   return $this->createErrorResponse('错误消息', 'ERROR_CODE', 400);
   
   // 分页响应
   return $this->createPaginatedResponse($items, $total, $page, $limit);
   
   // 创建成功响应
   return $this->createCreatedResponse($data, '创建成功');
   
   // 验证错误响应
   return $this->createValidationErrorResponse($errors);
   ```

3. **使用标准化验证**
   ```php
   // 验证JSON请求
   $validation = $this->validateJsonRequest($request->getContent(), ['name', 'email']);
   if (!$validation['valid']) {
     return $this->createErrorResponse($validation['error'], $validation['code']);
   }
   
   // 验证数据
   $result = $this->validateData($data, [
     'name' => ['required' => true, 'min_length' => 2],
     'email' => ['required' => true, 'pattern' => 'email']
   ]);
   ```

### 2. 服务依赖注入

使用标准化服务：

```php
public function __construct(
  ApiResponseService $response_service,
  ApiValidationService $validation_service
) {
  parent::__construct($response_service, $validation_service);
  // 添加其他依赖
}

public static function create(ContainerInterface $container): static
{
  return new static(
    $container->get('baas_api.response'),
    $container->get('baas_api.validation')
    // 其他服务
  );
}
```

### 3. 分页参数处理

```php
// 解析分页参数
$pagination = $this->parsePaginationParams($request, 20, 100);

// 解析排序参数
$sort = $this->parseSortParams($request, ['id', 'name', 'created'], 'created');

// 解析筛选参数
$filters = $this->parseFilterParams($request, ['status', 'type']);
```

### 4. 错误处理模式

```php
try {
  // 业务逻辑
  $result = $this->doSomething();
  return $this->createSuccessResponse($result);
} catch (ValidationException $e) {
  return $this->createValidationErrorResponse($e->getErrors());
} catch (NotFoundException $e) {
  return $this->createErrorResponse($e->getMessage(), 'RESOURCE_NOT_FOUND', 404);
} catch (\Exception $e) {
  $this->getLogger('baas_api')->error('API错误: @error', ['@error' => $e->getMessage()]);
  return $this->createErrorResponse('Internal server error', 'INTERNAL_ERROR', 500);
}
```

## 数据验证标准

### 1. 验证规则定义

```php
$rules = [
  'tenant_id' => [
    'required' => true,
    'pattern' => 'tenant_id'
  ],
  'name' => [
    'required' => true,
    'min_length' => 2,
    'max_length' => 100
  ],
  'email' => [
    'required' => true,
    'pattern' => 'email'
  ],
  'age' => [
    'type' => 'integer',
    'min' => 0,
    'max' => 150
  ]
];
```

### 2. 内置验证模式

- `tenant_id`: 租户ID格式 `/^[a-zA-Z0-9_-]{3,32}$/`
- `project_id`: 项目ID格式 `/^[a-zA-Z0-9_-]{3,32}$/`
- `entity_name`: 实体名称格式 `/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`
- `field_name`: 字段名称格式 `/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/`
- `email`: 邮箱格式 `/^[^\s@]+@[^\s@]+\.[^\s@]+$/`
- `uuid`: UUID格式 `/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i`

## API文档标准

### 1. OpenAPI规范

所有API必须在OpenAPI文档中定义：

```yaml
paths:
  /api/v1/users:
    get:
      summary: 获取用户列表
      parameters:
        - name: page
          in: query
          schema:
            type: integer
            minimum: 1
            default: 1
        - name: limit
          in: query
          schema:
            type: integer
            minimum: 1
            maximum: 100
            default: 20
      responses:
        200:
          description: 成功获取用户列表
          content:
            application/json:
              schema:
                $ref: '#/components/schemas/StandardSuccessResponse'
```

### 2. 响应模式定义

```yaml
components:
  schemas:
    StandardSuccessResponse:
      type: object
      properties:
        success:
          type: boolean
          example: true
        data:
          type: object
        meta:
          $ref: '#/components/schemas/ResponseMeta'
        pagination:
          $ref: '#/components/schemas/Pagination'
```

## 迁移指南

### 现有控制器标准化步骤

1. **更新控制器继承**
   ```php
   // 旧代码
   class MyController extends ControllerBase
   
   // 新代码
   class MyController extends BaseApiController
   ```

2. **更新构造函数**
   ```php
   public function __construct(
     ApiResponseService $response_service,
     ApiValidationService $validation_service,
     // 其他服务...
   ) {
     parent::__construct($response_service, $validation_service);
     // ...
   }
   ```

3. **替换响应创建**
   ```php
   // 旧代码
   return new JsonResponse(['success' => true, 'data' => $data]);
   
   // 新代码
   return $this->createSuccessResponse($data);
   ```

4. **使用标准化验证**
   ```php
   // 旧代码
   $data = json_decode($request->getContent(), true);
   if (!$data || !isset($data['name'])) {
     return new JsonResponse(['error' => 'Invalid data'], 400);
   }
   
   // 新代码
   $validation = $this->validateJsonRequest($request->getContent(), ['name']);
   if (!$validation['valid']) {
     return $this->createErrorResponse($validation['error'], $validation['code']);
   }
   $data = $validation['data'];
   ```

## 测试标准

### 1. 响应格式测试

确保所有API响应都包含必需的字段：

```php
$response = $this->makeApiRequest('GET', '/api/v1/users');
$data = json_decode($response->getContent(), true);

$this->assertArrayHasKey('success', $data);
$this->assertArrayHasKey('meta', $data);
$this->assertArrayHasKey('timestamp', $data['meta']);
$this->assertArrayHasKey('request_id', $data['meta']);
```

### 2. 错误处理测试

验证错误响应格式：

```php
$response = $this->makeApiRequest('POST', '/api/v1/users', []);
$data = json_decode($response->getContent(), true);

$this->assertEquals(400, $response->getStatusCode());
$this->assertFalse($data['success']);
$this->assertArrayHasKey('error', $data);
$this->assertArrayHasKey('code', $data['error']);
```

## 性能考虑

1. **响应缓存**: 对于不经常变化的数据，使用适当的缓存策略
2. **分页优化**: 大数据集合必须支持分页，默认限制为20条记录
3. **字段选择**: 支持`fields`参数来限制返回的字段
4. **压缩**: 启用gzip压缩以减少网络传输

## 安全要求

1. **输入验证**: 所有输入必须经过验证
2. **权限检查**: 每个API端点都必须进行适当的权限检查
3. **日志记录**: 记录所有API调用和错误
4. **速率限制**: 实施适当的速率限制策略

## 版本控制

1. **URL版本控制**: 使用`/api/v1/`前缀
2. **向后兼容**: 新版本必须保持向后兼容性
3. **弃用策略**: 提供至少6个月的弃用通知期

## 监控和指标

1. **响应时间**: 监控API响应时间
2. **错误率**: 跟踪错误响应的百分比
3. **使用统计**: 记录API端点的使用频率
4. **用户行为**: 分析API使用模式

---

**更新日期**: 2025-01-18  
**版本**: 1.0  
**维护者**: BaaS开发团队