# Drubase One API 文档

## 概述

Drubase One 提供完整的RESTful API，支持多租户数据管理、认证授权、动态实体等功能。

## 认证方式

### JWT Token 认证

```bash
# 登录获取token
curl -X POST http://localhost/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username": "admin", "password": "admin123"}'

# 使用token访问API
curl -X GET http://localhost/api/v1/tenants \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

### API Key 认证

```bash
curl -X GET http://localhost/api/v1/tenants \
  -H "X-API-Key: YOUR_API_KEY"
```

## 核心 API 端点

### 租户管理

```bash
# 获取租户列表
GET /api/v1/tenants

# 创建租户
POST /api/v1/tenants
{
  "name": "My Tenant",
  "description": "租户描述"
}
```

### 项目管理

```bash
# 获取项目列表
GET /api/v1/projects

# 创建项目
POST /api/v1/projects
{
  "name": "My Project",
  "tenant_id": "tenant_123"
}
```

### 实体数据

```bash
# 获取实体数据
GET /api/v1/{tenant_id}/projects/{project_id}/entities/{entity_name}

# 创建数据
POST /api/v1/{tenant_id}/projects/{project_id}/entities/{entity_name}
{
  "field1": "value1",
  "field2": "value2"
}
```

## 响应格式

### 成功响应

```json
{
  "success": true,
  "data": {...},
  "message": "操作成功",
  "meta": {
    "timestamp": "2025-01-01T00:00:00Z",
    "api_version": "v1"
  }
}
```

### 错误响应

```json
{
  "success": false,
  "error": "错误描述",
  "code": "ERROR_CODE",
  "meta": {
    "timestamp": "2025-01-01T00:00:00Z",
    "api_version": "v1"
  }
}
```

## 限流政策

- **认证用户**: 每分钟60次请求
- **匿名用户**: 每分钟30次请求
- **超限响应**: HTTP 429 Too Many Requests

## 完整文档

安装完成后，访问 http://localhost/api/docs 查看完整的API文档。
