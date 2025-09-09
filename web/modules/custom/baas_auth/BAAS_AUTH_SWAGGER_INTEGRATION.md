# BaaS Auth API 与 Swagger 集成完整指南

## 概述

`baas_auth` 模块的API已成功集成到 `baas_api` 模块的Swagger API文档管理系统中，实现了统一的API文档管理和测试功能。

## 集成功能

### 1. API文档生成

- **服务名称**: `baas_api.docs_generator`
- **生成方法**: `generateApiDocs()`
- **输出格式**: OpenAPI 3.0.0 规范

### 2. 认证API端点

已集成的认证相关API端点共 **11个**：

#### 基础认证
- `POST /auth/login` - 用户登录
- `POST /auth/refresh` - 刷新令牌
- `POST /auth/logout` - 用户注销
- `GET /auth/me` - 获取当前用户信息
- `POST /auth/verify` - 验证令牌

#### 权限管理
- `GET /auth/permissions` - 获取用户权限
- `GET /auth/roles` - 获取用户角色
- `POST /auth/change-password` - 修改密码

#### API密钥管理
- `GET /auth/api-keys` - 获取API密钥列表
- `POST /auth/api-keys` - 创建新API密钥
- `DELETE /auth/api-keys/{id}` - 删除API密钥

#### 会话和安全
- `GET /auth/sessions` - 获取用户会话列表
- `GET /auth/security-logs` - 获取安全日志

### 3. 安全方案

支持两种认证方式：

#### Bearer Token (JWT)
```yaml
bearerAuth:
  type: http
  scheme: bearer
  bearerFormat: JWT
  description: 使用JWT令牌进行身份验证
```

#### API Key
```yaml
apiKeyAuth:
  type: apiKey
  in: header
  name: X-API-Key
  description: 使用API密钥进行身份验证
```

### 4. 数据模型

定义了完整的请求/响应模型：

- `LoginRequest` - 登录请求
- `LoginResponse` - 登录响应
- `TokenVerifyRequest` - 令牌验证请求
- `SuccessResponse` - 成功响应
- `Error` - 错误响应

## 使用方法

### 1. 生成API文档

使用Drush命令生成API文档：

```bash
# 生成JSON格式文档
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && vendor/bin/drush baas-api:docs-export --format=json"

# 生成YAML格式文档
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && vendor/bin/drush baas-api:docs-export --format=yaml"
```

### 2. 访问API文档

- **Swagger UI页面**: `http://local.drubase.com/api/docs`
- **JSON文档**: `http://local.drubase.com/api-docs.json`
- **测试页面**: `http://local.drubase.com/test-swagger.html`

### 3. API测试

#### 使用Swagger UI测试

1. 访问 `http://local.drubase.com/test-swagger.html`
2. 点击"设置认证令牌"按钮
3. 输入有效的JWT令牌
4. 测试各种API端点

#### 使用curl测试

```bash
# 登录获取令牌
curl -X POST http://local.drubase.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"password"}'

# 使用令牌访问受保护的端点
curl -X GET http://local.drubase.com/api/auth/me \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

## 技术实现

### 1. 代码结构

```
web/modules/custom/baas_api/src/Service/ApiDocGenerator.php
├── generateApiDocs()           # 主文档生成方法
├── generateAuthApiPaths()      # 认证API路径生成
├── generateAuthComponents()    # 认证组件定义
└── 其他辅助方法
```

### 2. 集成点

- **路由集成**: 通过 `baas_auth.routing.yml` 获取端点定义
- **权限集成**: 自动识别端点权限要求
- **安全集成**: 支持JWT和API Key两种认证方式

### 3. 扩展性

- 支持租户特定的API文档生成
- 支持GraphQL模式导出
- 支持多种输出格式（JSON、YAML）

## 配置选项

### API文档配置

在 `baas_api.settings.yml` 中可配置：

```yaml
api_docs:
  title: "BaaS Platform API"
  description: "Backend as a Service 平台API文档"
  version: "1.0.0"
  contact:
    name: "API支持"
    email: "support@example.com"
```

### 认证配置

在 `baas_auth.settings.yml` 中可配置：

```yaml
jwt:
  secret: "your-secret-key"
  algorithm: "HS256"
  expire: 3600
security:
  max_login_attempts: 5
```

## 测试验证

### 1. 功能测试

- ✅ API文档成功生成
- ✅ 包含11个认证端点
- ✅ 安全方案正确定义
- ✅ 数据模型完整

### 2. 集成测试

- ✅ Drush命令正常工作
- ✅ JSON/YAML格式输出正确
- ✅ Swagger UI正常加载
- ✅ 认证功能正常

## 维护和更新

### 1. 添加新端点

1. 在 `baas_auth.routing.yml` 中定义新路由
2. 在 `generateAuthApiPaths()` 方法中添加端点定义
3. 重新生成API文档

### 2. 更新数据模型

1. 在 `generateAuthComponents()` 方法中更新模型定义
2. 确保与实际API响应格式一致

### 3. 缓存清理

```bash
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && vendor/bin/drush cr"
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && vendor/bin/drush baas-api:cache-clear"
```

## 总结

`baas_auth` API已完全集成到Swagger文档管理系统中，提供了：

- **完整的API文档**: 包含所有认证相关端点
- **交互式测试**: 通过Swagger UI进行API测试
- **多种认证方式**: 支持JWT和API Key
- **灵活的配置**: 支持多种输出格式和配置选项
- **易于维护**: 通过Drush命令管理文档生成

这个集成为开发者提供了一个统一、完整的API管理和测试平台。
