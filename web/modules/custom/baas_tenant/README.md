# BaaS租户管理模块 (baas_tenant)

BaaS租户管理模块是Drupal 11 Supabase风格BaaS平台的核心模块，提供多租户隔离和管理功能。该模块是整个BaaS平台的基础，其他模块都依赖于它来实现租户级别的功能。

## 核心功能

- 创建、更新、删除、查询租户
- 租户资源限制和用量统计
- 租户特定数据库表结构管理
- 事件驱动架构，支持其他模块订阅租户生命周期事件
- 租户数据的缓存和性能优化
- 租户识别与API密钥管理
- 租户域名绑定与解析

## 管理界面

### 租户管理列表
- 路径: `/admin/config/baas/tenants`
- 功能: 显示所有租户列表，包括ID、名称、状态、创建时间等信息
- 操作: 编辑、删除、API密钥管理

### 租户详情
- 路径: `/admin/config/baas/tenants/{tenant_id}/view`
- 功能: 显示租户详细信息，包括基本信息、资源配置等
- 操作: 编辑、删除、管理API密钥、返回列表

### 添加租户
- 路径: `/admin/config/baas/tenants/add`
- 功能: 创建新租户，设置名称、状态和资源限制

### 编辑租户
- 路径: `/admin/config/baas/tenants/{tenant_id}/edit`
- 功能: 更新租户信息和资源限制配置

### 删除租户
- 路径: `/admin/config/baas/tenants/{tenant_id}/delete`
- 功能: 确认并删除指定租户及其所有数据

### API密钥管理
- 路径: `/admin/config/baas/tenants/{tenant_id}/api-key`
- 功能: 查看、生成、重新生成、移除API密钥

## API端点

所有API端点默认返回JSON格式响应，支持租户识别和访问控制。

### 当前租户信息
- 路径: `/api/tenant/current`
- 方法: `GET`
- 描述: 获取当前请求的租户信息
- 权限: `_tenant_access: 'TRUE'`
- 响应示例:
  ```json
  {
    "success": true,
    "data": {
      "tenant_id": "test1_3d21ec0b",
      "name": "test1",
      "status": true,
      "created": 1746944055
    }
  }
  ```

### 租户详情
- 路径: `/api/tenant/{tenant_id}`
- 方法: `GET`
- 描述: 获取指定租户的详情信息
- 权限: `_tenant_access: 'TRUE'`
- 响应示例:
  ```json
  {
    "success": true,
    "data": {
      "tenant_id": "test1_3d21ec0b",
      "name": "test1",
      "status": true,
      "created": 1746944055
    }
  }
  ```

### 租户使用统计
- 路径: `/api/tenant/{tenant_id}/usage`
- 方法: `GET`
- 描述: 获取指定租户的资源使用统计
- 权限: `_tenant_access: 'TRUE'`
- 响应示例:
  ```json
  {
    "success": true,
    "data": {
      "tenant_id": "test1_3d21ec0b",
      "usage": {
        "api_calls": 10,
        "storage": 250,
        "entities": 15,
        "functions": 2
      },
      "period": "30天"
    }
  }
  ```

## 租户识别机制

系统通过以下三种方式识别当前租户:

1. **域名识别**
   - 完全匹配: 通过`settings`中的`domain`字段匹配
   - 通配符匹配: 通过`settings`中的`wildcard_domain`和`allowed_subdomains`字段匹配

2. **API密钥识别**
   - 请求头: `X-API-Key: {api_key_value}`

3. **URL路径识别**
   - URL格式: `/api/{tenant_id}/...`

## Drush命令

### 租户列表
- 命令: `baas-tenant:list`
- 别名: `baas-tenants`
- 用法: `drush baas-tenant:list`
- 描述: 列出所有租户信息，包括ID、名称、状态、创建时间和API密钥状态

### 查看/管理API密钥
- 命令: `baas-tenant:api-key`
- 别名: `baas-api-key`
- 选项:
  - `--generate`: 生成新的API密钥
  - `--remove`: 移除现有API密钥
- 用法:
  - `drush baas-tenant:api-key {tenant_id}`: 查看指定租户的API密钥
  - `drush baas-tenant:api-key {tenant_id} --generate`: 为指定租户生成新的API密钥
  - `drush baas-tenant:api-key {tenant_id} --remove`: 移除指定租户的API密钥

### 设置自定义API密钥
- 命令: `baas-tenant:set-api-key`
- 别名: `baas-set-api-key`
- 用法: `drush baas-tenant:set-api-key {tenant_id} {api_key}`
- 描述: 为指定租户设置自定义的API密钥

## 租户配置

租户支持以下配置项:

- `max_entities`: 最大实体数量限制
- `max_storage`: 最大存储空间限制 (MB)
- `max_requests`: 每日最大API请求数限制
- `max_edge_functions`: 最大边缘函数数量限制（可选）
- `domain`: 租户专属域名（可选）
- `wildcard_domain`: 通配符域名（可选）
- `allowed_subdomains`: 允许的子域名列表（可选）
- `api_key`: API密钥（可选）

## 服务

模块提供以下核心服务:

### 租户管理器
- 服务ID: `baas_tenant.manager`
- 类: `Drupal\baas_tenant\TenantManager`
- 主要方法:
  - `createTenant($name, $settings)`: 创建新租户
  - `getTenant($tenant_id)`: 获取租户信息
  - `updateTenant($tenant_id, $data)`: 更新租户信息
  - `deleteTenant($tenant_id)`: 删除租户
  - `listTenants($conditions)`: 列出租户
  - `checkResourceLimits($tenant_id, $resource_type, $count)`: 检查资源限制
  - `recordUsage($tenant_id, $resource_type, $count)`: 记录资源使用
  - `getUsage($tenant_id, $resource_type, $period)`: 获取资源使用统计
  - `loadTenantByDomain($domain)`: 通过域名加载租户
  - `loadTenantByApiKey($api_key)`: 通过API密钥加载租户
  - `generateApiKey($tenant_id)`: 生成API密钥
  - `removeApiKey($tenant_id)`: 移除API密钥
  - `getApiKey($tenant_id)`: 获取API密钥

### 租户解析器
- 服务ID: `baas_tenant.resolver`
- 类: `Drupal\baas_tenant\TenantResolver`
- 主要方法:
  - `resolveTenant()`: 从当前请求解析租户
  - `getCurrentTenant()`: 获取当前租户信息
  - `getCurrentTenantId()`: 获取当前租户ID

## 代码示例

### 创建租户

```php
$tenant_id = \Drupal::service('baas_tenant.manager')
  ->createTenant('示例租户', [
    'max_entities' => 200,
    'max_storage' => 1024,
    'max_requests' => 20000,
  ]);
```

### 检查资源限制并记录使用

```php
$tenant_manager = \Drupal::service('baas_tenant.manager');
if ($tenant_manager->checkResourceLimits($tenant_id, 'api_calls', 1)) {
  // 允许API调用并记录
  $tenant_manager->recordUsage($tenant_id, 'api_calls');
  // 执行API操作...
} else {
  // 返回限制错误
}
```

### 管理API密钥

```php
// 生成新API密钥
$api_key = \Drupal::service('baas_tenant.manager')->generateApiKey($tenant_id);

// 获取API密钥
$api_key = \Drupal::service('baas_tenant.manager')->getApiKey($tenant_id);

// 移除API密钥
\Drupal::service('baas_tenant.manager')->removeApiKey($tenant_id);
```

### 识别当前租户

```php
// 获取当前租户
$tenant = \Drupal::service('baas_tenant.resolver')->getCurrentTenant();
if ($tenant) {
  $tenant_id = $tenant['tenant_id'];
  // 使用租户信息...
}
```

### 订阅租户事件

```php
// 在EventSubscriber类中:
public static function getSubscribedEvents(): array {
  return [
    TenantEvent::TENANT_CREATE => 'onTenantCreate',
    TenantEvent::TENANT_UPDATE => 'onTenantUpdate',
    TenantEvent::TENANT_DELETE => 'onTenantDelete',
  ];
}

public function onTenantCreate(TenantEvent $event): void {
  $tenant_id = $event->getTenantId();
  // 处理租户创建事件...
}
```

## API响应格式

模块提供了统一的API响应格式工具类:

```php
use Drupal\baas_tenant\ApiResponse;

// 成功响应
$response = ApiResponse::success($data, [
  'version' => '1.0',
]);

// 错误响应
$response = ApiResponse::error(
  '已达到资源限制',
  404,
  ['resource' => 'api_calls']
);
```

## 数据表结构

### 系统表

- `baas_tenant_config`: 租户配置信息表
  - `tenant_id`: 租户ID (主键)
  - `name`: 租户名称
  - `settings`: JSON格式的租户设置
  - `status`: 租户状态 (1=启用, 0=禁用)
  - `created`: 创建时间戳
  - `updated`: 更新时间戳

- `baas_tenant_usage`: 租户资源使用统计表
  - `id`: 自增ID (主键)
  - `tenant_id`: 租户ID
  - `resource_type`: 资源类型
  - `date`: 使用日期
  - `count`: 使用计数
  - `timestamp`: 记录时间戳

### 租户专用表 (每个租户独立)

- `tenant_{tenant_id}_entity_data`: 租户实体数据表
  - `id`: 自增ID (主键)
  - `entity_type`: 实体类型
  - `data`: JSON格式的实体数据
  - `created`: 创建时间戳
  - `updated`: 更新时间戳

- `tenant_{tenant_id}_functions`: 租户自定义函数表
  - `id`: 自增ID (主键)
  - `name`: 函数名称
  - `code`: 函数代码
  - `status`: 函数状态
  - `created`: 创建时间戳
  - `updated`: 更新时间戳

## 测试指南

本部分提供了测试baas_tenant模块功能的方法和步骤。

### 管理界面测试

1. **创建租户**
   - 访问 `/admin/config/baas/tenants/add`
   - 填写必要信息（名称、资源限制等）
   - 点击"保存"，确认新租户出现在租户列表中

2. **编辑租户**
   - 访问 `/admin/config/baas/tenants`
   - 点击要编辑的租户旁边的"编辑"链接
   - 修改信息，点击"保存"，确认更改已应用

3. **API密钥管理**
   - 访问 `/admin/config/baas/tenants/{tenant_id}/api-key`
   - 测试生成、查看和删除API密钥功能
   - 确认API密钥状态在租户列表中正确显示

4. **删除租户**
   - 访问 `/admin/config/baas/tenants/{tenant_id}/delete`
   - 确认删除，验证租户及其所有数据是否已移除

### Drush命令测试

1. **租户列表命令**
   ```bash
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush baas-tenant:list"
   ```
   确认命令正确显示所有租户及其信息

2. **API密钥管理命令**
   ```bash
   # 查看API密钥
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush baas-tenant:api-key {tenant_id}"

   # 生成新的API密钥
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush baas-tenant:api-key {tenant_id} --generate"

   # 移除API密钥
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush baas-tenant:api-key {tenant_id} --remove"
   ```
   验证每个命令都能按预期工作

3. **设置自定义API密钥**
   ```bash
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush baas-tenant:set-api-key {tenant_id} test_api_key_123"
   ```
   确认API密钥已正确设置

### API端点测试

1. **获取租户信息**
   ```bash
   # 使用API密钥获取租户信息
   docker exec -it php8-4-fpm-official curl -X GET "http://nginx/api/tenant/{tenant_id}" -H "X-API-Key: {api_key}" -H "Accept: application/json" -v
   ```
   确认返回正确的租户信息JSON

2. **获取当前租户**
   ```bash
   # 使用API密钥获取当前租户
   docker exec -it php8-4-fpm-official curl -X GET "http://nginx/api/tenant/current" -H "X-API-Key: {api_key}" -H "Accept: application/json" -v
   ```
   验证系统能正确识别当前租户

3. **租户使用统计**
   ```bash
   # 获取租户使用统计
   docker exec -it php8-4-fpm-official curl -X GET "http://nginx/api/tenant/{tenant_id}/usage" -H "X-API-Key: {api_key}" -H "Accept: application/json" -v
   ```
   确认返回租户的资源使用情况

### 资源限制测试

1. **测试资源限制检查**
   ```bash
   # 检查实体数量限制（假设限制为200）
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush php:eval '\$result = \Drupal::service(\"baas_tenant.manager\")->checkResourceLimits(\"{tenant_id}\", \"entities\", 150); var_dump(\$result);'"
   ```
   应返回`true`，表示未超出限制

   ```bash
   # 检查超出实体数量限制
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush php:eval '\$result = \Drupal::service(\"baas_tenant.manager\")->checkResourceLimits(\"{tenant_id}\", \"entities\", 250); var_dump(\$result);'"
   ```
   应返回`false`，表示已超出限制

2. **记录资源使用**
   ```bash
   # 记录API调用
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush php:eval '\Drupal::service(\"baas_tenant.manager\")->recordUsage(\"{tenant_id}\", \"api_calls\", 5);'"
   ```

   查询数据库验证使用记录：
   ```bash
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush sql:query 'SELECT * FROM baas_tenant_usage WHERE tenant_id = \"{tenant_id}\" ORDER BY timestamp DESC LIMIT 5;'"
   ```

### 租户识别测试

1. **API密钥识别**
   - 设置API密钥后，使用上述API端点测试中的curl命令验证系统能通过API密钥识别租户

2. **域名识别**
   - 为租户设置域名：
     ```bash
     docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush php:eval '\$tenant = \Drupal::service(\"baas_tenant.manager\")->getTenant(\"{tenant_id}\"); \$settings = \$tenant[\"settings\"] ?? []; \$settings[\"domain\"] = \"test.example.com\"; \Drupal::service(\"baas_tenant.manager\")->updateTenant(\"{tenant_id}\", [\"settings\" => \$settings]);'"
     ```

   - 模拟发送请求到该域名：
     ```bash
     docker exec -it php8-4-fpm-official curl -X GET "http://nginx/api/tenant/current" -H "Host: test.example.com" -H "Accept: application/json" -v
     ```

### 数据库测试

1. **查看租户配置表**
   ```bash
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush sql:query 'SELECT * FROM baas_tenant_config;'"
   ```

2. **查看租户专用表结构**
   ```bash
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush sql:query 'SELECT table_name FROM information_schema.tables WHERE table_name LIKE \"tenant_{tenant_id}%\";'"
   ```

3. **查看租户使用统计**
   ```bash
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush sql:query 'SELECT * FROM baas_tenant_usage WHERE tenant_id = \"{tenant_id}\";'"
   ```

### 故障排除

若测试过程中遇到问题，可使用以下方法进行故障排除：

1. **查看Drupal日志**
   ```bash
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush watchdog:show --tail"
   ```

2. **清除缓存**
   ```bash
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush cr"
   ```

3. **检查权限**
   确保当前用户拥有`administer baas tenants`权限

4. **启用SQL日志**
   在settings.php中启用SQL日志，查看具体的数据库操作

## 依赖

- Drupal 11.x
- PHP 8.3+
- Symfony 7.x
- PostgreSQL 16+

## 权限

- `administer baas tenants`: 管理BaaS租户
- `view baas tenants`: 查看BaaS租户信息
- `administer tenant`: 管理所有租户
- `access any tenant`: 访问任何租户
- `access own tenant`: 访问自己的租户
