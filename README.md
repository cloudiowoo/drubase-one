# BaaS Platform

企业级Backend-as-a-Service平台，基于Drupal 11构建的现代化后端服务。

## ✨ 核心特性

- 🏢 **多租户架构** - 完全隔离的多租户数据管理
- 🔐 **统一认证** - JWT、API Key、Session多种认证方式
- 📊 **动态实体** - 无需编程即可创建和管理数据模型
- 🚀 **项目管理** - 灵活的项目组织和权限控制系统
- ⚡ **函数服务** - 支持无服务器函数和自定义业务逻辑
- 📱 **实时功能** - WebSocket实时通信支持
- 🎯 **API网关** - 统一的API管理、监控和限流
- 📁 **文件管理** - 安全的文件上传、存储和管理

## 🚀 快速开始

### 系统要求

- Docker >= 20.0
- Docker Compose >= 2.0
- 4GB+ 内存
- 10GB+ 磁盘空间

### 一键安装

```bash
# 1. 克隆项目
git clone https://github.com/your-username/baas-platform.git
cd baas-platform

# 2. 运行安装脚本
./install.sh

# 3. 访问平台
open http://localhost
```

### 默认登录信息

- **管理员账号**: admin
- **默认密码**: admin123
- **管理界面**: http://localhost/admin
- **API文档**: http://localhost/api/docs

## 🏗️ 架构概述

BaaS Platform采用现代化微服务架构：

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   Web应用       │    │   API网关        │    │   函数服务       │
│   (Drupal 11)   │    │   (统一入口)     │    │   (Node.js)     │
└─────────────────┘    └─────────────────┘    └─────────────────┘
         │                       │                       │
         └───────────────────────┼───────────────────────┘
                                 │
         ┌───────────────────────┼───────────────────────┐
         │                       │                       │
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   PostgreSQL    │    │     Redis       │    │   文件存储       │
│   (主数据库)     │    │   (缓存)        │    │   (本地/云)     │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

## 📚 核心概念

### 租户 (Tenants)
- 数据完全隔离的组织单位
- 每个租户有独立的数据空间和配置
- 支持子租户和层级管理

### 项目 (Projects)  
- 租户内的业务项目组织单位
- 灵活的成员权限和角色管理
- 资源使用监控和限制

### 实体 (Entities)
- 动态创建的数据模型
- 支持字段类型：文本、数字、日期、文件、引用等
- RESTful API自动生成

### 函数 (Functions)
- 无服务器函数支持
- 事件驱动的业务逻辑处理
- 支持多种触发器：HTTP、数据变更、定时任务

## 🔧 配置管理

### 环境变量

```env
# 数据库配置
DB_HOST=localhost
DB_NAME=baas_platform
DB_USER=baas_user
DB_PASSWORD=your_secure_password

# Redis缓存
REDIS_HOST=localhost
REDIS_PORT=6379

# 应用配置
APP_ENV=production
APP_SECRET=your-app-secret
SITE_NAME=BaaS Platform

# API配置
API_RATE_LIMIT_USER=60
API_RATE_LIMIT_IP=30
```

### 管理配置

访问 `/admin/config/baas` 进行系统配置：
- 租户管理和配置
- API限流和监控设置
- 实体模板管理
- 函数服务配置

## 🌐 API 使用

### 认证

```bash
# 获取访问令牌
curl -X POST http://localhost/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username": "admin", "password": "admin123"}'
```

### 数据操作

```bash
# 获取实体列表
curl -X GET "http://localhost/api/v1/tenant_id/projects/project_id/entities/users" \
  -H "Authorization: Bearer YOUR_TOKEN"

# 创建数据
curl -X POST "http://localhost/api/v1/tenant_id/projects/project_id/entities/users" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "John Doe", "email": "john@example.com"}'
```

## 🛠️ 开发指南

### 自定义模块开发

```php
<?php
// web/modules/custom/my_baas_module/my_baas_module.module

/**
 * 实现hook_baas_entity_presave()
 */
function my_baas_module_baas_entity_presave($entity_data, $context) {
  // 自定义业务逻辑
  $entity_data['created_time'] = time();
  return $entity_data;
}
```

### 函数服务开发

```javascript
// services/functions/my-function/index.js
exports.handler = async (event, context) => {
  // 处理业务逻辑
  return {
    statusCode: 200,
    body: JSON.stringify({
      message: 'Function executed successfully',
      data: event.data
    })
  };
};
```

## 🔍 监控和维护

### 系统监控

```bash
# 查看服务状态
docker-compose ps

# 查看日志
docker-compose logs -f [service-name]

# 数据库状态检查
docker-compose exec db pg_isready
```

### 性能优化

- 启用Redis缓存
- 配置适当的API限流
- 定期清理日志和临时文件
- 监控数据库性能

## 🤝 社区支持

- **文档**: 详细使用文档请参考安装后的系统帮助
- **问题报告**: [GitHub Issues](https://github.com/your-username/baas-platform/issues)
- **功能请求**: [GitHub Discussions](https://github.com/your-username/baas-platform/discussions)

## 📄 许可证

本项目采用 MIT 许可证。详情请参考 [LICENSE](LICENSE) 文件。

## 🙏 贡献

欢迎贡献代码、报告问题或提出改进建议！

---

**版本**: 1.1.0-beta.1  
**更新时间**: 2025-09-25 12:39:29 UTC  
**官方网站**: https://baas-platform.com