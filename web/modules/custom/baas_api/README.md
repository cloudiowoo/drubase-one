# BaaS API 模块

## 概述

BaaS API 模块是 BaaS 平台的 API 层，提供标准化的 REST 和 GraphQL 接口，用于与租户数据和服务进行交互。该模块实现了速率限制、认证授权、API 文档和使用统计等核心功能。

## 功能特性

- **标准化 API 响应格式**：提供统一的 JSON 响应结构
- **RESTful API 支持**：符合 REST 设计规范的端点
- **GraphQL API 支持**：灵活查询的 GraphQL 接口
- **API 文档生成**：自动生成 OpenAPI 和 GraphQL Schema 文档
- **API 认证授权**：支持 JWT 令牌和 API 密钥认证方式
- **速率限制**：基于租户和路径的 API 请求限流
- **使用统计**：API 调用记录和使用分析
- **多租户隔离**：确保租户数据安全隔离
- **Drupal 命令行工具**：用于管理 API 文档和缓存的 Drush 命令

## 依赖

- baas_tenant：租户管理模块
- baas_entity：实体管理模块
- drupal:rest：Drupal REST 模块
- drupal:serialization：Drupal 序列化模块

## 安装

模块安装通过 Composer 和 Drush：

```bash
composer require drupal/baas_api
drush en baas_api
```

## 模块结构

```
baas_api/
├── src/
│   ├── Controller/          # API 控制器
│   ├── Form/                # 管理表单
│   ├── Service/             # 核心服务
│   ├── Commands/            # Drush 命令
│   ├── EventSubscriber/     # 事件订阅者
│   ├── Plugin/              # 插件
│   └── Access/              # 访问检查器
├── templates/               # 模板文件
├── js/                      # JavaScript 文件
├── css/                     # CSS 文件
├── baas_api.info.yml        # 模块信息
├── baas_api.routing.yml     # 路由定义
├── baas_api.services.yml    # 服务定义
└── baas_api.module          # 模块钩子
```

## API 端点

### RESTful API

BaaS API 提供以下 RESTful 端点模式：

```
/api/v1/{tenant_id}/{resource}[/{id}]
```

支持的 HTTP 方法：

| 方法   | 路径                         | 功能                 |
|-------|------------------------------|---------------------|
| GET   | /api/v1/{tenant_id}/{resource} | 获取资源列表         |
| POST  | /api/v1/{tenant_id}/{resource} | 创建新资源           |
| GET   | /api/v1/{tenant_id}/{resource}/{id} | 获取单个资源 |
| PUT   | /api/v1/{tenant_id}/{resource}/{id} | 更新资源（完全替换）|
| PATCH | /api/v1/{tenant_id}/{resource}/{id} | 更新资源（部分更新）|
| DELETE| /api/v1/{tenant_id}/{resource}/{id} | 删除资源         |

### GraphQL API

GraphQL API 通过单一端点提供服务：

```
/api/graphql/{tenant_id}
```

### 文档端点

```
/api/docs                         # 全局 API 文档
/api/tenant/{tenant_id}/docs      # 租户特定 API 文档
/api/tenant/{tenant_id}/graphql/schema  # 租户 GraphQL Schema
```

## 认证方式

BaaS API 支持以下认证方式：

### Bearer Token 认证

```
Authorization: Bearer {JWT_TOKEN}
```

### API 密钥认证

```
X-API-Key: {API_KEY}
```

## 速率限制

API 设置了基于租户和路径的速率限制，超过限制时返回 429 状态码。响应头中包含限制信息：

```
X-RateLimit-Limit: 100
X-RateLimit-Remaining: 98
X-RateLimit-Reset: 58
Retry-After: 58
```

## API 响应格式

### 成功响应

```json
{
  "success": true,
  "data": {
    // 响应数据
  }
}
```

### 分页响应

```json
{
  "success": true,
  "data": [
    // 数据项数组
  ],
  "pagination": {
    "total": 100,
    "page": 1,
    "limit": 10,
    "pages": 10
  }
}
```

### 错误响应

```json
{
  "success": false,
  "message": "错误描述",
  "errors": {
    // 详细错误信息
  }
}
```

## 使用示例

### API 令牌生成

```php
// 创建 API 令牌
$token_manager = \Drupal::service('baas_api.token_manager');
$token_data = $token_manager->generateToken(
  'tenant123',
  ['read', 'write'],
  time() + 86400,
  'Web App Token'
);
```

### API 请求示例

```php
// 使用 Bearer 认证发送请求
$client = new \GuzzleHttp\Client();
$response = $client->request('GET', 'https://example.com/api/v1/tenant123/users', [
  'headers' => [
    'Authorization' => 'Bearer ' . $token,
    'Accept' => 'application/json',
  ],
]);
$data = json_decode($response->getBody(), true);
```

### GraphQL 查询示例

```graphql
query {
  users(first: 5) {
    edges {
      node {
        id
        name
        email
      }
    }
    pageInfo {
      hasNextPage
    }
  }
}
```

## 配置

API 模块配置位于 `/admin/config/baas/api/settings` 路径下，可配置：

- API 文档信息（标题、描述、版本等）
- 联系人信息
- 服务器 URL
- 速率限制设置
- 日志和统计保留期
- CORS 设置

## Drush 命令

API 模块提供以下 Drush 命令：

```bash
# 清除 API 缓存
drush baas:api-cache-clear

# 导出 API 文档
drush baas:api-docs-export [tenant_id] --format=json|yaml --path=/path/to/output
```

## 权限

API 模块定义了以下权限：

- `administer baas api`：管理 API 设置
- `access baas api docs`：访问 API 文档
- `view baas api stats`：查看 API 使用统计
- `use baas api advanced features`：使用高级 API 功能

## 故障排除

### 速率限制问题

如果遇到意外的 429 响应：
- 检查速率限制设置是否合理
- 使用 Drush 命令清除速率限制缓存：`drush baas:api-cache-clear`

### API 文档生成问题

如果 API 文档不包含最新实体：
- 确保实体模板已正确创建
- 清除缓存：`drush cr`
- 手动导出文档：`drush baas:api-docs-export`

## 开发扩展

### 添加自定义端点

在 `baas_api.routing.yml` 中添加新路由：

```yaml
baas_api.custom_endpoint:
  path: '/api/v1/{tenant_id}/custom'
  defaults:
    _controller: '\Drupal\baas_api\Controller\CustomController::handleRequest'
  methods: [GET]
  requirements:
    _permission: 'access baas api'
```

### 添加自定义文档

在 `ApiDocGenerator` 服务中扩展文档生成：

```php
// 添加自定义端点到 OpenAPI 文档
$openapi['paths']['/api/v1/{tenant_id}/custom'] = [
  'get' => [
    'summary' => '获取自定义数据',
    'description' => '自定义端点描述',
    'parameters' => [...],
    'responses' => [...],
  ],
];
```

## 参与开发

欢迎参与 BaaS API 模块的开发：

1. Fork 项目仓库
2. 创建特性分支
3. 提交变更
4. 推送到您的分支
5. 创建 Pull Request

## 许可证

该模块基于 Drupal 授权的 GPL v2+ 许可证发布。
