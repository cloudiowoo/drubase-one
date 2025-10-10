# BaaS API 统一认证中间件

## 概述

BaaS API 统一认证中间件提供企业级的API认证、授权和安全管理功能，支持多种认证方式和细粒度权限控制。

## 架构设计

### 中间件组件

1. **ApiAuthenticationSubscriber** - 认证订阅器
   - 处理JWT和API Key认证
   - 提取和验证认证信息
   - 设置认证上下文

2. **ApiPermissionSubscriber** - 权限订阅器
   - 基于角色的访问控制(RBAC)
   - 多级权限检查（全局、租户、项目）
   - 动态权限映射

3. **ApiRateLimitSubscriber** - 速率限制订阅器
   - 基于令牌桶算法的速率限制
   - 按用户、IP、API端点分级限制
   - 动态速率限制配置

4. **ApiResponseSubscriber** - 响应处理订阅器
   - 统一响应头管理
   - CORS支持
   - 安全头部注入

## 认证方式

### 1. JWT认证

**请求头格式:**
```
Authorization: Bearer <jwt_token>
```

**JWT载荷结构:**
```json
{
  "sub": "用户ID",
  "tenant_id": "租户ID",
  "project_id": "项目ID", 
  "username": "用户名",
  "email": "邮箱",
  "roles": ["role1", "role2"],
  "permissions": ["perm1", "perm2"],
  "type": "access|refresh",
  "jti": "令牌唯一标识",
  "exp": "过期时间戳",
  "iat": "签发时间戳"
}
```

**认证流程:**
1. 提取Authorization头部的Bearer token
2. 验证JWT签名和格式
3. 检查token是否在黑名单中
4. 验证token过期时间
5. 构建认证上下文

### 2. API Key认证

**请求头格式:**
```
X-API-Key: <api_key>
```

**查询参数格式:**
```
?api_key=<api_key>
```

**认证流程:**
1. 从请求头或查询参数获取API Key
2. 验证API Key有效性
3. 检查API Key状态和权限
4. 构建认证上下文

## 权限控制

### 权限层级

1. **全局权限** - 跨租户的系统级权限
2. **租户权限** - 租户内的管理权限
3. **项目权限** - 项目内的操作权限

### 权限检查逻辑

```php
// 1. 检查项目级权限
if ($project_id && $permissionChecker->checkProjectPermission($user_id, $project_id, $permission)) {
    return true;
}

// 2. 检查租户级权限
if ($tenant_id && $permissionChecker->checkTenantPermission($user_id, $tenant_id, $permission)) {
    return true;
}

// 3. 检查全局权限
return $permissionChecker->checkGlobalPermission($user_id, $permission);
```

### API端点权限映射

| API端点 | 权限要求 |
|---------|----------|
| `GET /api/v1/{tenant_id}/entities` | `access tenant entity data` |
| `POST /api/v1/{tenant_id}/entities` | `create tenant entity data` |
| `PUT /api/v1/{tenant_id}/entities/{id}` | `update tenant entity data` |
| `DELETE /api/v1/{tenant_id}/entities/{id}` | `delete tenant entity data` |
| `GET /api/v1/{tenant_id}/templates` | `access tenant entity templates` |
| `POST /api/v1/{tenant_id}/templates` | `administer baas entity templates` |

## 速率限制

### 限制策略

1. **认证用户限制:**
   - 一般API: 1000次/小时
   - 创建操作: 100次/小时
   - 修改操作: 200次/小时

2. **匿名用户限制:**
   - 一般API: 100次/小时
   - 认证端点: 10次/分钟

3. **特殊端点:**
   - 健康检查: 无限制
   - API文档: 无限制

### 速率限制头部

```
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 999
X-RateLimit-Reset: 1640995200
Retry-After: 60
```

## 公开端点

以下端点无需认证：

- `/api/health` - 健康检查
- `/api/docs` - API文档
- `/api/auth/login` - 用户登录
- `/api/auth/register` - 用户注册
- `/api/auth/refresh` - 刷新Token
- `/api/auth/reset-password` - 重置密码
- `/api/auth/verify-email` - 邮箱验证

## 错误响应

### 认证错误

**401 Unauthorized - 缺少认证信息:**
```json
{
  "success": false,
  "error": {
    "message": "Authentication required",
    "code": "AUTHENTICATION_REQUIRED"
  },
  "meta": {
    "timestamp": "2024-01-01T00:00:00Z",
    "request_id": "req_123456"
  }
}
```

**401 Unauthorized - 无效JWT:**
```json
{
  "success": false,
  "error": {
    "message": "Invalid JWT token",
    "code": "INVALID_JWT_TOKEN"
  },
  "meta": {
    "timestamp": "2024-01-01T00:00:00Z",
    "request_id": "req_123456"
  }
}
```

**401 Unauthorized - JWT已撤销:**
```json
{
  "success": false,
  "error": {
    "message": "JWT token has been revoked",
    "code": "JWT_TOKEN_REVOKED"
  },
  "meta": {
    "timestamp": "2024-01-01T00:00:00Z",
    "request_id": "req_123456"
  }
}
```

### 权限错误

**403 Forbidden - 权限不足:**
```json
{
  "success": false,
  "error": {
    "message": "Insufficient permissions",
    "code": "INSUFFICIENT_PERMISSIONS",
    "context": {
      "required_permissions": ["access tenant entity data"],
      "missing_permissions": ["access tenant entity data"]
    }
  },
  "meta": {
    "timestamp": "2024-01-01T00:00:00Z",
    "request_id": "req_123456"
  }
}
```

### 速率限制错误

**429 Too Many Requests:**
```json
{
  "success": false,
  "error": {
    "message": "Rate limit exceeded",
    "code": "RATE_LIMIT_EXCEEDED",
    "context": {
      "limit": 1000,
      "remaining": 0,
      "reset_time": 1640995200,
      "retry_after": 60
    }
  },
  "meta": {
    "timestamp": "2024-01-01T00:00:00Z",
    "request_id": "req_123456"
  }
}
```

## 使用示例

### 1. JWT认证请求

```bash
curl -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..." \
     -X GET "http://localhost/api/v1/tenant1/users"
```

### 2. API Key认证请求

```bash
curl -H "X-API-Key: ak_1234567890abcdef" \
     -X GET "http://localhost/api/v1/tenant1/users"
```

### 3. 权限验证

```php
// 在控制器中使用BaseApiController的便捷方法
public function myAction(Request $request, string $tenant_id): JsonResponse {
    // 检查权限
    $permission_error = $this->requirePermission('access tenant entity data', $tenant_id);
    if ($permission_error) {
        return $permission_error;
    }
    
    // 获取当前用户信息
    $user_id = $this->getCurrentUserId();
    $current_tenant = $this->getCurrentTenantId();
    
    // 执行业务逻辑
    // ...
}
```

## 日志记录

### 认证日志

```
[INFO] API认证成功: GET /api/v1/tenant1/users - 用户ID: 123, 租户ID: tenant1
[WARNING] API认证失败: Invalid JWT token (INVALID_JWT_TOKEN)
```

### 权限日志

```
[DEBUG] API权限验证成功: GET /api/v1/tenant1/users - 用户ID: 123, 权限: access tenant entity data
[WARNING] API权限验证失败: Insufficient permissions (INSUFFICIENT_PERMISSIONS)
```

### 速率限制日志

```
[DEBUG] API速率限制检查通过: user_123_tenant_tenant1 - 剩余: 999/1000
[WARNING] API速率限制超出: 限制=1000, 重置时间=2024-01-01 12:00:00
```

## 配置选项

### 认证配置

```yaml
# config/baas_api.settings.yml
authentication:
  jwt:
    secret_key: 'your-secret-key'
    algorithm: 'HS256'
    expiration: 3600  # 1小时
  
  api_key:
    header_name: 'X-API-Key'
    query_param: 'api_key'
    
cors:
  allowed_origins:
    - 'http://localhost:3000'
    - 'https://example.com'
  allowed_methods:
    - 'GET'
    - 'POST'
    - 'PUT'
    - 'DELETE'
    - 'OPTIONS'
```

### 速率限制配置

```yaml
rate_limits:
  authenticated_user:
    general: 1000/hour
    create: 100/hour
    modify: 200/hour
  
  anonymous_user:
    general: 100/hour
    auth: 10/minute
```

## 安全最佳实践

1. **JWT安全:**
   - 使用强密钥签名
   - 设置合理的过期时间
   - 实现token黑名单机制

2. **API Key安全:**
   - 定期轮换API Key
   - 设置适当的权限范围
   - 监控API Key使用情况

3. **传输安全:**
   - 强制使用HTTPS
   - 设置安全响应头
   - 实现CORS策略

4. **监控和审计:**
   - 记录所有认证尝试
   - 监控异常访问模式
   - 定期审查权限配置

## 性能优化

1. **权限缓存:**
   - 权限检查结果缓存30分钟
   - 使用多级缓存策略
   - 支持缓存预热

2. **速率限制优化:**
   - 使用内存缓存存储计数器
   - 实现分布式速率限制
   - 支持动态限制调整

3. **中间件优化:**
   - 优化事件订阅器执行顺序
   - 减少不必要的数据库查询
   - 使用连接池管理

## 故障排除

### 常见问题

1. **JWT验证失败:**
   - 检查密钥配置
   - 验证token格式
   - 确认时间同步

2. **权限检查失败:**
   - 验证用户角色分配
   - 检查权限配置
   - 清理权限缓存

3. **速率限制异常:**
   - 检查缓存后端状态
   - 验证限制配置
   - 监控系统负载

### 调试工具

```bash
# 检查JWT token
curl -H "Authorization: Bearer <token>" \
     -X GET "http://localhost/api/debug/auth"

# 检查用户权限
curl -H "Authorization: Bearer <token>" \
     -X GET "http://localhost/api/debug/permissions"

# 检查速率限制状态
curl -H "Authorization: Bearer <token>" \
     -X GET "http://localhost/api/debug/rate-limits"
```

## 结论

BaaS API 统一认证中间件提供了完整的企业级API安全解决方案，支持多种认证方式、细粒度权限控制和灵活的速率限制策略。通过标准化的架构设计和丰富的配置选项，能够满足各种业务场景的安全需求。