# BaaS实体管理模块 (baas_entity)

BaaS实体管理模块是Drupal 11 Supabase风格BaaS平台的核心功能模块，提供动态实体和字段管理能力。该模块允许租户在不编写代码的情况下定义自己的数据模型，并通过API进行CRUD操作。

## 核心功能

- 创建、更新、删除、查询实体模板
- 动态定义字段及字段类型
- 租户数据隔离
- 自动生成实体类型和数据库表
- 事件驱动架构，支持与其他模块集成
- 字段类型映射与验证
- RESTful API访问实体数据

## 管理界面

### 实体模板列表
- 路径: `/admin/baas/entity/templates`
- 功能: 显示所有实体模板列表，包括ID、租户、名称、标签、字段数等信息
- 操作: 编辑、管理字段、删除

### 添加实体模板
- 路径: `/admin/baas/entity/templates/add`
- 功能: 创建新实体模板，设置租户、名称、标签、描述和高级设置

### 编辑实体模板
- 路径: `/admin/baas/entity/templates/{template_id}/edit`
- 功能: 更新实体模板的标签、描述和设置

### 删除实体模板
- 路径: `/admin/baas/entity/templates/{template_id}/delete`
- 功能: 确认并删除指定实体模板及其所有字段和数据

### 字段列表
- 路径: `/admin/baas/entity/templates/{template_id}/fields`
- 功能: 显示指定实体模板的所有字段
- 操作: 编辑、删除字段

### 添加字段
- 路径: `/admin/baas/entity/templates/{template_id}/fields/add`
- 功能: 为实体模板添加新字段，设置标签、名称、类型和字段特定设置

### 编辑字段
- 路径: `/admin/baas/entity/templates/{template_id}/fields/{field_id}/edit`
- 功能: 更新字段的标签、描述和设置

### 删除字段
- 路径: `/admin/baas/entity/templates/{template_id}/fields/{field_id}/delete`
- 功能: 确认并删除指定字段

## API端点

所有API端点默认返回JSON格式响应，支持租户识别和访问控制。

### 获取实体模板列表
- 路径: `/api/tenant/{tenant_id}/templates`
- 方法: `GET`
- 描述: 获取指定租户的所有实体模板
- 权限: `access tenant entity templates`
- 响应示例:
  ```json
  {
    "success": true,
    "data": [
      {
        "id": 1,
        "tenant_id": "test1_3d21ec0b",
        "name": "client",
        "label": "客户",
        "description": "存储客户基本信息",
        "status": true,
        "field_count": 3,
        "created": 1747021533
      },
      {
        "id": 2,
        "tenant_id": "test1_3d21ec0b",
        "name": "profile",
        "label": "档案",
        "description": "用户档案记录",
        "status": true,
        "field_count": 5,
        "created": 1747022097
      }
    ]
  }
  ```

### 获取实体模板详情
- 路径: `/api/tenant/{tenant_id}/templates/{template_name}`
- 方法: `GET`
- 描述: 获取指定实体模板的详细信息和字段定义
- 权限: `access tenant entity templates`
- 响应示例:
  ```json
  {
    "success": true,
    "data": {
      "id": 1,
      "tenant_id": "test1_3d21ec0b",
      "name": "client",
      "label": "客户",
      "description": "存储客户基本信息",
      "status": true,
      "created": 1747021533,
      "updated": 1747021567,
      "fields": [
        {
          "id": 9,
          "name": "uuid",
          "label": "UUID",
          "type": "uuid",
          "required": true,
          "settings": {}
        },
        {
          "id": 10,
          "name": "title",
          "label": "TITLE",
          "type": "string",
          "required": true,
          "settings": {}
        },
        {
          "id": 11,
          "name": "content",
          "label": "Content",
          "type": "text",
          "required": false,
          "settings": {}
        }
      ]
    }
  }
  ```

### 获取实体数据
- 路径: `/api/tenant/{tenant_id}/{entity_name}`
- 方法: `GET`
- 描述: 获取指定实体的数据列表，支持分页、过滤和排序
- 权限: `access tenant entity data`
- 参数:
  - `page`: 页码，默认为1
  - `limit`: 每页记录数，默认为20
  - `sort`: 排序字段，默认为id
  - `direction`: 排序方向(asc/desc)，默认为asc
  - `filter`: 字段过滤条件
- 响应示例:
  ```json
  {
    "success": true,
    "data": {
      "items": [
        {
          "id": 1,
          "uuid": "550e8400-e29b-41d4-a716-446655440000",
          "title": "客户A",
          "content": "这是客户A的详细信息"
        },
        {
          "id": 2,
          "uuid": "6ba7b810-9dad-11d1-80b4-00c04fd430c8",
          "title": "客户B",
          "content": "这是客户B的详细信息"
        }
      ],
      "total": 2,
      "page": 1,
      "limit": 20,
      "pages": 1
    }
  }
  ```

### 获取单个实体数据
- 路径: `/api/tenant/{tenant_id}/{entity_name}/{id}`
- 方法: `GET`
- 描述: 获取特定实体记录
- 权限: `access tenant entity data`
- 响应示例:
  ```json
  {
    "success": true,
    "data": {
      "id": 1,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "title": "客户A",
      "content": "这是客户A的详细信息"
    }
  }
  ```

### 创建实体记录
- 路径: `/api/tenant/{tenant_id}/{entity_name}`
- 方法: `POST`
- 描述: 创建新的实体记录
- 权限: `create tenant entity data`
- 请求示例:
  ```json
  {
    "title": "客户C",
    "content": "这是客户C的详细信息"
  }
  ```
- 响应示例:
  ```json
  {
    "success": true,
    "data": {
      "id": 3,
      "uuid": "f47ac10b-58cc-4372-a567-0e02b2c3d479",
      "title": "客户C",
      "content": "这是客户C的详细信息"
    }
  }
  ```

### 更新实体记录
- 路径: `/api/tenant/{tenant_id}/{entity_name}/{id}`
- 方法: `PUT`
- 描述: 更新现有的实体记录
- 权限: `update tenant entity data`
- 请求示例:
  ```json
  {
    "content": "这是客户A的更新信息"
  }
  ```
- 响应示例:
  ```json
  {
    "success": true,
    "data": {
      "id": 1,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "title": "客户A",
      "content": "这是客户A的更新信息"
    }
  }
  ```

### 删除实体记录
- 路径: `/api/tenant/{tenant_id}/{entity_name}/{id}`
- 方法: `DELETE`
- 描述: 删除实体记录
- 权限: `delete tenant entity data`
- 响应示例:
  ```json
  {
    "success": true,
    "message": "记录已成功删除"
  }
  ```

## 字段类型支持

模块支持以下字段类型及其特定设置：

| 字段类型 | 描述 | 特定设置 |
|---------|------|---------|
| string | 字符串 | max_length: 最大长度 |
| text | 长文本 | rows: 行数 |
| integer | 整数 | min: 最小值, max: 最大值 |
| decimal | 小数 | precision: 精度, scale: 小数位 |
| boolean | 布尔值 | on_label: 开启标签, off_label: 关闭标签 |
| date | 日期 | date_type: 日期类型(date/datetime) |
| reference | 引用其他实体 | reference_type: 引用的实体类型 |
| file | 文件 | file_extensions: 允许的文件扩展名, max_size: 最大文件大小 |
| image | 图片 | file_extensions: 允许的图片扩展名, max_size: 最大文件大小, max_width: 最大宽度, max_height: 最大高度 |
| uuid | UUID | 自动生成唯一标识符 |
| json | JSON数据 | schema: JSON模式定义(可选) |

## 服务

模块提供以下核心服务:

### 模板管理器
- 服务ID: `baas_entity.template_manager`
- 类: `Drupal\baas_entity\Service\TemplateManager`
- 主要方法:
  - `createTemplate($tenant_id, $name, $label, $description, $settings)`: 创建实体模板
  - `updateTemplate($template_id, $values)`: 更新实体模板
  - `deleteTemplate($template_id)`: 删除实体模板
  - `getTemplate($template_id)`: 获取模板详情
  - `getTemplateByName($tenant_id, $name)`: 通过名称获取模板
  - `getTemplatesByTenant($tenant_id, $active_only)`: 获取租户的所有模板
  - `addField($template_id, $name, $label, $type, $description, $required, $weight, $settings)`: 添加字段
  - `updateField($field_id, $values)`: 更新字段
  - `deleteField($field_id)`: 删除字段
  - `getField($field_id)`: 获取字段详情
  - `getFields($template_id)`: 获取模板的所有字段

### 字段映射器
- 服务ID: `baas_entity.field_mapper`
- 类: `Drupal\baas_entity\Service\FieldMapper`
- 主要方法:
  - `getSupportedFieldTypes()`: 获取支持的字段类型
  - `mapFieldToDatabaseColumn($field)`: 将字段映射到数据库列
  - `validateFieldValue($field, $value)`: 验证字段值
  - `formatFieldValue($field, $value)`: 格式化字段值
  - `getFieldTypeSettings($type)`: 获取字段类型的默认设置

### 实体生成器
- 服务ID: `baas_entity.entity_generator`
- 类: `Drupal\baas_entity\Service\EntityGenerator`
- 主要方法:
  - `createEntityType($tenant_id, $template)`: 创建实体类型
  - `generateEntityClass($template, $fields)`: 生成实体类
  - `createEntityTable($template)`: 创建实体表
  - `updateEntityTable($template, $fields)`: 更新实体表结构
  - `deleteEntityType($tenant_id, $name)`: 删除实体类型

### 实体注册表
- 服务ID: `baas_entity.entity_registry`
- 类: `Drupal\baas_entity\Service\EntityRegistry`
- 主要方法:
  - `registerEntityTypes(&$entity_types)`: 注册动态实体类型
  - `getRegisteredEntityTypes()`: 获取已注册的实体类型
  - `getEntityTypeDefinition($tenant_id, $name)`: 获取实体类型定义

## 代码示例

### 创建实体模板和字段

```php
// 获取模板管理器服务
$template_manager = \Drupal::service('baas_entity.template_manager');

// 创建实体模板
$template_id = $template_manager->createTemplate(
  'tenant_a',
  'client',
  '客户',
  '存储客户基本信息',
  [
    'translatable' => TRUE,
    'revisionable' => FALSE,
    'publishable' => TRUE,
  ]
);

// 添加字段
$template_manager->addField(
  $template_id,
  'uuid',
  'UUID',
  'uuid',
  '客户唯一标识',
  TRUE,
  0,
  []
);

$template_manager->addField(
  $template_id,
  'title',
  '名称',
  'string',
  '客户名称',
  TRUE,
  1,
  ['max_length' => 50]
);

$template_manager->addField(
  $template_id,
  'content',
  '详情',
  'text',
  '客户详细信息',
  FALSE,
  2,
  ['rows' => 3]
);
```

### 动态生成实体类型

```php
// 获取实体生成器服务
$entity_generator = \Drupal::service('baas_entity.entity_generator');

// 创建实体类型
$template = $template_manager->getTemplate($template_id);
$fields = $template_manager->getFields($template_id);
$entity_generator->createEntityType('tenant_a', $template);

// 创建实体表
$entity_generator->createEntityTable($template);
```

### 通过API访问实体数据

```php
// 获取实体数据
$tenant_id = 'tenant_a';
$entity_name = 'client';
$url = "/api/tenant/{$tenant_id}/{$entity_name}";
$response = \Drupal::httpClient()->request('GET', $url, [
  'headers' => [
    'X-API-Key' => 'tenant_api_key',
    'Accept' => 'application/json',
  ],
]);
$data = json_decode($response->getBody(), TRUE);

// 创建实体记录
$new_client = [
  'title' => '客户A',
  'content' => '这是客户A的详细信息',
];
$response = \Drupal::httpClient()->request('POST', $url, [
  'headers' => [
    'X-API-Key' => 'tenant_api_key',
    'Content-Type' => 'application/json',
    'Accept' => 'application/json',
  ],
  'body' => json_encode($new_client),
]);
$result = json_decode($response->getBody(), TRUE);
```

## 数据表结构

### 系统表

- `baas_entity_template`: 实体模板表
  - `id`: 自增ID (主键)
  - `tenant_id`: 租户ID
  - `name`: 实体名称
  - `label`: 实体标签
  - `description`: 实体描述
  - `settings`: JSON格式的实体设置
  - `status`: 实体状态 (1=启用, 0=禁用)
  - `created`: 创建时间戳
  - `updated`: 更新时间戳

- `baas_entity_field`: 实体字段表
  - `id`: 自增ID (主键)
  - `template_id`: 模板ID (外键)
  - `name`: 字段名称
  - `label`: 字段标签
  - `type`: 字段类型
  - `description`: 字段描述
  - `required`: 是否必填 (1=是, 0=否)
  - `multiple`: 是否多值 (1=是, 0=否)
  - `settings`: JSON格式的字段设置
  - `weight`: 字段权重
  - `created`: 创建时间戳
  - `updated`: 更新时间戳

### 租户实体表 (动态生成)

为每个租户的每个实体模板自动生成的表：

- `tenant_{tenant_id}_{entity_name}`: 实体数据表
  - `id`: 自增ID (主键)
  - `{field_name}`: 动态生成的字段列
  - `created`: 创建时间戳
  - `updated`: 更新时间戳
  - `status`: 状态 (1=发布, 0=草稿)
  - `uuid`: 通用唯一标识符

## 测试指南

本部分提供了测试baas_entity模块功能的方法和步骤。

### 实体模板管理测试

1. **创建实体模板**
   ```bash
   # 创建测试租户
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush baas-tenant:set-api-key tenant_a test_api_key_123"

   # 通过API创建实体模板
   docker exec -it php8-4-fpm-official curl -X POST "http://nginx/api/tenant/tenant_a/templates" \
     -H "X-API-Key: test_api_key_123" \
     -H "Content-Type: application/json" \
     -H "Accept: application/json" \
     -d '{
       "name": "client",
       "label": "客户",
       "description": "存储客户基本信息",
       "settings": {
         "translatable": true,
         "revisionable": false,
         "publishable": true
       }
     }'
   ```

2. **添加字段**
   ```bash
   # 添加UUID字段
   docker exec -it php8-4-fpm-official curl -X POST "http://nginx/api/tenant/tenant_a/templates/client/fields" \
     -H "X-API-Key: test_api_key_123" \
     -H "Content-Type: application/json" \
     -H "Accept: application/json" \
     -d '{
       "name": "uuid",
       "label": "UUID",
       "type": "uuid",
       "description": "客户唯一标识",
       "required": true,
       "settings": {}
     }'

   # 添加标题字段
   docker exec -it php8-4-fpm-official curl -X POST "http://nginx/api/tenant/tenant_a/templates/client/fields" \
     -H "X-API-Key: test_api_key_123" \
     -H "Content-Type: application/json" \
     -H "Accept: application/json" \
     -d '{
       "name": "title",
       "label": "名称",
       "type": "string",
       "description": "客户名称",
       "required": true,
       "settings": {
         "max_length": 50
       }
     }'
   ```

3. **获取实体模板列表**
   ```bash
   docker exec -it php8-4-fpm-official curl -X GET "http://nginx/api/tenant/tenant_a/templates" \
     -H "X-API-Key: test_api_key_123" \
     -H "Accept: application/json"
   ```

### 实体数据管理测试

1. **创建实体数据**
   ```bash
   docker exec -it php8-4-fpm-official curl -X POST "http://nginx/api/tenant/tenant_a/client" \
     -H "X-API-Key: test_api_key_123" \
     -H "Content-Type: application/json" \
     -H "Accept: application/json" \
     -d '{
       "title": "客户A",
       "content": "这是客户A的详细信息"
     }'
   ```

2. **查询实体数据**
   ```bash
   # 获取所有客户
   docker exec -it php8-4-fpm-official curl -X GET "http://nginx/api/tenant/tenant_a/client" \
     -H "X-API-Key: test_api_key_123" \
     -H "Accept: application/json"

   # 获取特定客户
   docker exec -it php8-4-fpm-official curl -X GET "http://nginx/api/tenant/tenant_a/client/1" \
     -H "X-API-Key: test_api_key_123" \
     -H "Accept: application/json"
   ```

### 数据库检查

1. **查看生成的数据表**
   ```bash
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush sql:query 'SELECT table_name FROM information_schema.tables WHERE table_name LIKE \"tenant_a_%\";'"
   ```

2. **检查模板和字段表**
   ```bash
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush sql:query 'SELECT * FROM baas_entity_template;'"
   docker exec -it php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush sql:query 'SELECT * FROM baas_entity_field;'"
   ```

## 租户案例

本模块支持不同租户实现各种业务需求，以下是两个简化的案例示例：

### 案例一：企业客户管理系统

企业租户可以创建客户管理系统，包含客户信息、合同记录和服务记录等数据模型。

1. **主要实体模型**:
   - 客户档案：存储客户基本信息、联系方式、地址等
   - 合同记录：记录与客户签订的合同信息
   - 服务记录：跟踪提供给客户的服务历史

2. **实现特点**:
   - 客户档案与合同、服务记录之间建立引用关系
   - 自动生成合同编号和服务工单号
   - 合同到期提醒和服务定期回访提示

### 案例二：教育课程平台

教育机构可以建立在线课程管理平台，包含课程分类、课程信息和教师管理等模型。

1. **主要实体模型**:
   - 课程分类：对课程进行分类管理
   - 课程信息：存储课程详情、价格、教师等
   - 学生选课：记录学生的选课和学习情况

2. **实现特点**:
   - 支持课程分类的多级结构
   - 课程可设置先修课程要求
   - 学生选课时自动检查课程容量限制

## 最新开发进展

### 已完成功能

- 核心实体模板和字段管理功能
- RESTful API基础端点
- 租户数据隔离实现
- 动态实体类型生成器
- 基础字段类型支持（string、text、integer、boolean、uuid等）
- 数据库表动态创建和更新

### 正在开发

- 高级字段类型（关系型字段、多值字段）
- 批量导入导出功能
- 性能优化与缓存处理
- 实体模板版本控制
- 字段验证和业务规则引擎
- WebSocket实时数据更新通知

### 规划中功能

- GraphQL API支持
- 实体模板迁移工具
- 动态权限管理
- 自定义索引创建
- 统计分析功能
- 自动化文档生成

## 总结

baas_entity模块为多租户SaaS平台提供了强大的后端支持，使不同租户可以在同一套系统上构建自己的业务应用，同时保持数据隔离和安全。通过灵活的实体模板和字段定义，租户可以根据业务需求创建各种数据模型，而无需编写代码，大大降低了应用开发和维护的复杂度。
