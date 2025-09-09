# BaaS 认证模块 (baas_auth)

BaaS认证模块为后端即服务（Backend as a Service）平台提供完整的认证和授权解决方案。本模块基于Drupal 11和Symfony 7构建，支持JWT令牌认证、API密钥管理、会话管理、权限控制等功能。

## 📋 目录

- [功能特性](#功能特性)
- [系统要求](#系统要求)
- [安装配置](#安装配置)
- [服务架构](#服务架构)
- [API 接口](#api-接口)
- [使用指南](#使用指南)
- [配置说明](#配置说明)
- [权限系统](#权限系统)
- [安全特性](#安全特性)
- [故障排除](#故障排除)
- [开发指南](#开发指南)

## 🚀 功能特性

### 核心认证功能
- **JWT令牌认证** - 基于JSON Web Token的无状态认证
- **API密钥管理** - 支持多种类型的API密钥认证
- **会话管理** - 用户会话生命周期管理
- **密码服务** - 安全的密码哈希和验证
- **令牌黑名单** - 支持令牌吊销和黑名单管理

### 权限控制系统
- **角色权限管理** - 灵活的角色和权限分配
- **租户隔离** - 多租户环境下的权限隔离
- **资源级权限** - 细粒度的资源访问控制
- **权限缓存** - 高性能的权限检查缓存机制

### REST API接口
- **标准化API** - 遵循RESTful设计规范
- **多种认证方式** - 支持Bearer Token和API Key认证
- **统一响应格式** - 标准化的JSON响应格式
- **错误处理** - 完善的错误响应和状态码

## 🔧 系统要求

- **PHP**: 8.3+
- **Drupal**: 11.x
- **PostgreSQL**: 16+
- **扩展依赖**:
  - firebase/php-jwt: JWT令牌处理
  - guzzlehttp/guzzle: HTTP客户端（可选）

## 📦 安装配置

### 1. 启用模块

```bash
# 使用Drush启用模块
drush en baas_auth

# 或通过Drupal管理界面启用
# 管理 → 扩展 → 启用 "BaaS Authentication"
```

### 2. 数据库迁移

模块启用时会自动创建必要的数据库表：

```sql
-- JWT黑名单表
baas_jwt_blacklist

-- API密钥表
baas_api_keys

-- 用户会话表
baas_user_sessions

-- 租户角色表
baas_user_tenant_roles

-- 权限表
baas_permissions

-- 安全日志表
baas_security_logs
```

### 3. 基本配置

访问配置页面：`/admin/config/baas/auth`

```php
// 主要配置项
$config = [
  'jwt_secret' => 'your-secret-key',           // JWT签名密钥
  'jwt_expire' => 3600,                        // 令牌过期时间(秒)
  'jwt_refresh_expire' => 2592000,             // 刷新令牌过期时间(秒)
  'api_key_length' => 32,                      // API密钥长度
  'session_timeout' => 86400,                  // 会话超时时间(秒)
  'max_login_attempts' => 5,                   // 最大登录尝试次数
  'lockout_duration' => 900,                   // 账户锁定时长(秒)
];
```

## 🏗️ 服务架构

### 核心服务组件

```php
// 服务依赖关系图
baas_auth.jwt_token_manager          // JWT令牌管理
├── baas_auth.authentication_service // 认证服务
├── baas_auth.permission_checker     // 权限检查
├── baas_auth.jwt_blacklist_service  // JWT黑名单
├── baas_auth.api_key_manager        // API密钥管理
├── baas_auth.session_manager        // 会话管理
└── baas_auth.password_service       // 密码服务
```

### 服务使用示例

```php
// 在控制器中使用认证服务
class MyController extends ControllerBase {

  protected $authService;

  public function __construct(AuthenticationService $auth_service) {
    $this->authService = $auth_service;
  }

  public function secureAction() {
    // 验证JWT令牌
    $token = $request->headers->get('Authorization');
    $payload = $this->authService->verifyToken($token);

    if (!$payload) {
      throw new AccessDeniedHttpException('无效的认证令牌');
    }

    // 继续处理业务逻辑...
  }
}
```

## 🔌 API 接口

### 认证端点

| 方法 | 端点 | 描述 | 认证要求 |
|------|------|------|----------|
| POST | `/api/auth/login` | 用户登录 | 无 |
| POST | `/api/auth/refresh` | 刷新令牌 | 无 |
| POST | `/api/auth/logout` | 用户登出 | Bearer Token |
| GET | `/api/auth/me` | 获取当前用户信息 | Bearer Token |
| POST | `/api/auth/verify` | 验证令牌 | Bearer Token |

### 权限管理端点

| 方法 | 端点 | 描述 | 认证要求 |
|------|------|------|----------|
| GET | `/api/auth/permissions` | 获取用户权限 | Bearer Token |
| GET | `/api/auth/roles` | 获取用户角色 | Bearer Token |
| POST | `/api/auth/assign-role` | 分配角色 | Admin权限 |
| POST | `/api/auth/revoke-role` | 撤销角色 | Admin权限 |

### API密钥管理端点

| 方法 | 端点 | 描述 | 认证要求 |
|------|------|------|----------|
| GET | `/api/auth/api-keys` | 获取API密钥列表 | Bearer Token |
| POST | `/api/auth/api-keys` | 创建API密钥 | Admin权限 |
| PUT | `/api/auth/api-keys/{id}` | 更新API密钥 | Admin权限 |
| DELETE | `/api/auth/api-keys/{id}` | 删除API密钥 | Admin权限 |

## 📖 使用指南

### 1. 用户登录

```bash
# 请求示例
curl -X POST http://your-site.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "testuser",
    "password": "password123",
    "tenant_id": "tenant_001"
  }'
```

```json
// 成功响应
{
  "data": {
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "refresh_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
    "token_type": "Bearer",
    "expires_in": 3600,
    "user": {
      "id": 123,
      "username": "testuser",
      "email": "test@example.com"
    }
  },
  "meta": {
    "timestamp": "2023-08-15T12:00:00Z",
    "version": "1.0"
  },
  "error": null
}
```

### 2. 使用认证令牌

```bash
# 在API请求中使用Bearer令牌
curl -X GET http://your-site.com/api/auth/me \
  -H "Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
```

### 3. 权限检查

```php
// 在自定义服务中检查权限
class MyService {

  protected $permissionChecker;

  public function secureOperation($user_id, $tenant_id) {
    // 检查用户权限
    if (!$this->permissionChecker->hasPermission($user_id, $tenant_id, 'admin')) {
      throw new AccessDeniedHttpException('权限不足');
    }

    // 检查资源权限
    if (!$this->permissionChecker->hasResourcePermission($user_id, $tenant_id, 'user', 'create')) {
      throw new AccessDeniedHttpException('无法创建用户');
    }

    // 执行操作...
  }
}
```

### 4. API密钥认证

```bash
# 使用API密钥进行认证
curl -X GET http://your-site.com/api/data/entities \
  -H "X-API-Key: abc123def456ghi789jkl012mno345pq"
```

### 5. 会话管理

```php
// 获取用户会话
$sessions = $this->sessionManager->getUserSessions($user_id, $tenant_id);

// 删除特定会话
$this->sessionManager->deleteSession($session_id);

// 清理过期会话
$cleaned = $this->sessionManager->cleanupExpiredSessions();
```

## ⚙️ 配置说明

### JWT配置

```yaml
# config/baas_auth.settings.yml
jwt:
  secret: 'your-secret-key-here'
  algorithm: 'HS256'
  expire: 3600              # 1小时
  refresh_expire: 2592000   # 30天
  issuer: 'your-domain.com'
  audience: 'baas-clients'
```

### 安全配置

```yaml
security:
  max_login_attempts: 5
  lockout_duration: 900     # 15分钟
  password_min_length: 8
  require_special_chars: true
  session_timeout: 86400    # 24小时
  api_key_length: 32
```

### 权限配置

```yaml
permissions:
  default_roles: ['authenticated']
  admin_roles: ['administrator', 'super_admin']
  cache_ttl: 3600          # 权限缓存1小时
  tenant_isolation: true   # 启用租户隔离
```

## 🔐 权限系统

### 权限层级

```
租户级权限
├── admin                 # 租户管理员
├── manager              # 租户管理者
├── editor               # 内容编辑者
├── viewer               # 只读访问者
└── authenticated        # 已认证用户（默认）
```

### 资源权限

```php
// 资源权限格式：{resource}.{operation}
$permissions = [
  'user.create',    // 创建用户
  'user.read',      // 读取用户
  'user.update',    // 更新用户
  'user.delete',    // 删除用户
  'entity.create',  // 创建实体
  'entity.read',    // 读取实体
  'entity.update',  // 更新实体
  'entity.delete',  // 删除实体
];
```

### 权限分配示例

```php
// 角色权限映射
$role_permissions = [
  'admin' => ['*'],  // 所有权限
  'manager' => [
    'user.create', 'user.read', 'user.update',
    'entity.create', 'entity.read', 'entity.update'
  ],
  'editor' => [
    'entity.create', 'entity.read', 'entity.update'
  ],
  'viewer' => [
    'user.read', 'entity.read'
  ],
];
```

## 🛡️ 安全特性

### 1. JWT安全
- **签名验证** - 防止令牌篡改
- **过期检查** - 自动过期无效令牌
- **黑名单机制** - 支持令牌主动吊销
- **刷新令牌** - 减少长期令牌暴露风险

### 2. API密钥安全
- **哈希存储** - 密钥使用单向哈希存储
- **权限限制** - 密钥关联特定权限范围
- **自动轮换** - 支持密钥定期轮换
- **使用监控** - 记录密钥使用日志

### 3. 会话安全
- **超时机制** - 自动清理过期会话
- **并发限制** - 限制用户同时会话数
- **IP绑定** - 可选的IP地址绑定
- **异常检测** - 监控异常登录行为

### 4. 密码安全
- **强度验证** - 密码复杂度检查
- **哈希算法** - 使用Drupal内置哈希算法
- **盐值加密** - 每个密码使用唯一盐值
- **重哈希检查** - 支持算法升级重哈希

## 🔧 故障排除

### 常见问题

#### 1. JWT令牌验证失败

**问题**: 返回"无效的令牌"错误

**解决方案**:
```bash
# 检查JWT配置
drush config:get baas_auth.settings jwt.secret

# 验证令牌格式
# 确保请求头格式：Authorization: Bearer {token}

# 检查令牌是否在黑名单
drush ev "echo \Drupal::service('baas_auth.jwt_blacklist_service')->isBlacklisted('token_jti') ? 'YES' : 'NO';"
```

#### 2. 权限检查失败

**问题**: 权限检查返回false

**解决方案**:
```bash
# 清除权限缓存
drush cache:clear

# 检查用户角色
drush ev "\$roles = \Drupal::service('baas_auth.permission_checker')->getUserRoles(1, 'tenant_id'); print_r(\$roles);"

# 验证权限配置
drush config:export --destination=/tmp/config
```

#### 3. 数据库连接错误

**问题**: 无法连接数据库

**解决方案**:
```bash
# 检查数据库连接
drush status

# 验证表结构
drush sql:query "SELECT table_name FROM information_schema.tables WHERE table_name LIKE 'baas_%';"

# 重新运行安装钩子
drush php:eval "\Drupal::moduleHandler()->loadInclude('baas_auth', 'install'); baas_auth_install();"
```

### 调试工具

#### 1. 启用详细日志

```php
// 在settings.php中启用调试
$config['system.logging']['error_level'] = 'verbose';

// 查看认证相关日志
drush watchdog:show --filter=baas_auth
```

#### 2. 测试API端点

```bash
# 测试登录端点
curl -v -X POST http://your-site.com/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin","tenant_id":"default"}'

# 测试令牌验证
curl -v -X POST http://your-site.com/api/auth/verify \
  -H "Authorization: Bearer YOUR_TOKEN"
```

## 🛠️ 开发指南

### 扩展认证服务

```php
// 创建自定义认证提供者
namespace Drupal\my_module\Service;

class CustomAuthProvider implements AuthProviderInterface {

  public function authenticate($credentials) {
    // 实现自定义认证逻辑
    return $user_info;
  }
}

// 注册服务
# my_module.services.yml
services:
  my_module.custom_auth_provider:
    class: Drupal\my_module\Service\CustomAuthProvider
    tags:
      - { name: baas_auth.auth_provider, priority: 10 }
```

### 添加自定义权限

```php
// 实现权限提供者接口
class CustomPermissionProvider implements PermissionProviderInterface {

  public function getPermissions($user_id, $tenant_id) {
    // 返回自定义权限数组
    return ['custom.permission'];
  }
}

// 监听权限事件
class PermissionEventSubscriber implements EventSubscriberInterface {

  public function onPermissionCheck(PermissionEvent $event) {
    // 添加额外的权限检查逻辑
  }
}
```

### 自定义API端点

```php
// 创建自定义API控制器
namespace Drupal\my_module\Controller;

class MyApiController extends ControllerBase {

  use AuthApiTrait; // 使用认证特性

  public function customEndpoint(Request $request) {
    // 自动进行JWT验证
    $payload = $this->validateToken($request);

    // 检查权限
    $this->checkPermission($payload['sub'], $payload['tenant_id'], 'custom.action');

    // 处理业务逻辑
    return $this->jsonResponse($data);
  }
}
```

### 数据库扩展

```php
// 扩展认证相关表结构
function my_module_schema() {
  $schema['baas_user_profiles'] = [
    'description' => '用户扩展资料表',
    'fields' => [
      'user_id' => [
        'type' => 'int',
        'not null' => TRUE,
      ],
      'tenant_id' => [
        'type' => 'varchar',
        'length' => 255,
        'not null' => TRUE,
      ],
      'profile_data' => [
        'type' => 'text',
        'size' => 'big',
      ],
    ],
    'primary key' => ['user_id', 'tenant_id'],
  ];

  return $schema;
}
```

## 📞 支持与贡献

### 问题报告
如发现问题，请提交至项目的Issue跟踪器，并包含：
- Drupal版本
- PHP版本
- 错误日志
- 重现步骤

### 开发贡献
欢迎提交Pull Request：
1. Fork项目仓库
2. 创建功能分支
3. 提交代码变更
4. 编写测试用例
5. 提交Pull Request

### 开发环境
```bash
# 克隆项目
git clone [repository-url]

# 安装依赖
composer install

# 运行测试
vendor/bin/phpunit

# 代码风格检查
vendor/bin/phpcs --standard=Drupal
```

---

## 📄 许可证

本模块遵循GPL-2.0+许可证。详见LICENSE文件。

## 🔗 相关链接

- [Drupal 11文档](https://www.drupal.org/docs/11)
- [JWT PHP库](https://github.com/firebase/php-jwt)
- [Symfony 7文档](https://symfony.com/doc/7.0/index.html)
- [BaaS平台架构文档](link-to-architecture-docs)

---

**版本**: 1.0.0
**更新时间**: 2024-01-15
**维护者**: BaaS Development Team
