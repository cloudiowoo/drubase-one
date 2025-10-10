# 自定义域名配置指南

本文档说明如何在 Drubase One 中配置自定义域名，以替代默认的 `local.drubase.com`。

## 概述

Drubase One 使用域名进行容器间通信和外部访问。默认配置使用 `local.drubase.com` 作为内部通信域名。如果您需要使用自己的域名（如 `api.example.com` 或 `my-baas.app`），请按照以下步骤配置。

## 配置步骤

### 1. 修改环境变量文件 (.env)

**重要**：`.env` 文件位于 `docker/` 目录（与 docker-compose.yml 同级）。

编辑 `docker/.env` 文件，修改 `BAAS_DOMAIN` 变量：

```bash
# 文件位置：/path/to/drubase_one/docker/.env
# 说明：install.sh 会自动创建在 docker/ 目录

# BaaS平台内部域名（用于容器间通信）
BAAS_DOMAIN=api.example.com

# 外部访问域名（用于浏览器访问）
DOMAIN=api.example.com
```

**docker-compose 工作原理**：
- docker-compose 从**当前工作目录**读取 `.env` 文件
- `.env` 文件与 `docker-compose.yml` 放在同一目录（docker/）
- 执行命令时需要先进入 docker/ 目录：`cd docker && docker-compose up -d`
- 这是 docker-compose 的标准配置方式

**注意**:
- `BAAS_DOMAIN`: 用于Docker容器间的内部通信、WebSocket连接和CORS配置
- `DOMAIN`: 用于外部浏览器访问（可以与BAAS_DOMAIN相同或不同）

**重要**: 修改 `BAAS_DOMAIN` 后，以下配置会自动更新：
- PHP-FPM和BaaS Functions的 `extra_hosts` DNS映射
- BaaS Functions的 `DRUPAL_API_URL` API端点
- BaaS Functions的 `ALLOWED_ORIGINS` CORS白名单（解决WebSocket 1008错误）

### 2. 更新Nginx虚拟主机配置

编辑 `docker/nginx/vhosts/drubase.conf`，修改 `server_name` 指令：

```nginx
server {
    listen 80;

    # 将 local.drubase.com 替换为您的域名
    server_name api.example.com ~^(?<subdomain>.+)\.api\.example\.com$;

    root /var/www/html/web;
    # ... 其他配置保持不变
}
```

**子域名支持**: 上述配置也支持子域名（如 `admin.api.example.com`）。如果不需要，可以简化为：

```nginx
server_name api.example.com;
```

### 3. 更新php8-4-fpm服务的extra_hosts

编辑 `docker/docker-compose.yml`，找到 `php8-4-fpm` 服务，更新 `extra_hosts`：

```yaml
php8-4-fpm:
  # ... 其他配置
  extra_hosts:
    - "api.example.com:172.20.1.100"  # 替换为您的域名
```

### 4. 重启服务

应用配置更改：

```bash
# ⚠️ 重要：必须在 docker/ 目录中运行 docker-compose
cd /path/to/drubase_one/docker  # 进入 docker/ 目录（.env 所在位置）

# 停止所有服务
docker-compose down

# 重新启动服务
docker-compose up -d

# 验证环境变量已正确加载
docker-compose exec baas-functions-service env | grep DRUPAL_API_URL
# 应该显示：DRUPAL_API_URL=http://api.example.com

docker-compose exec baas-functions-service env | grep ALLOWED_ORIGINS
# 应该包含：http://api.example.com
```

**关键要点**：
- ✅ **正确做法**：在 docker/ 目录（`.env` 和 `docker-compose.yml` 所在位置）执行 docker-compose
- ❌ **常见错误**：在其他目录执行，导致 `.env` 无法被读取
- docker-compose 从**当前工作目录**读取 `.env`，因此必须 cd 到 docker/ 目录

### 5. 配置DNS解析（生产环境）

#### 本地开发环境

编辑本地 hosts 文件：

**Linux/macOS**: `/etc/hosts`
```
127.0.0.1  api.example.com
```

**Windows**: `C:\Windows\System32\drivers\etc\hosts`
```
127.0.0.1  api.example.com
```

#### 生产环境

在您的DNS服务商（如Cloudflare、阿里云DNS）添加A记录：

```
类型: A
主机记录: api
记录值: 您的服务器IP地址
TTL: 600
```

## 配置验证

### 1. 测试Nginx配置

```bash
docker exec nginx nginx -t
```

应该看到：`nginx: configuration file /etc/nginx/nginx.conf test is successful`

### 2. 测试域名解析

```bash
# 从宿主机测试
curl http://api.example.com/user/login

# 从Functions容器内部测试
docker exec baas-functions-service curl -s http://api.example.com/user/login
```

### 3. 检查WebSocket连接

打开浏览器开发者工具 (F12) → 网络 → WS，访问您的应用，应该能看到WebSocket成功连接到 `ws://api.example.com:4000`。

## 常见问题

### Q: 修改域名后WebSocket连接失败（1008错误或认证超时）？

**A**: WebSocket 1008错误或认证超时，通常是因为 `.env` 文件未被正确读取。

#### 症状
- 浏览器显示：`📴 BaaS多表实时连接已关闭, 代码: 1008`
- baas-functions日志显示：`error: Connection authentication failed: timeout of 5000ms exceeded`
- 执行 `docker exec baas-functions-service env | grep DRUPAL_API_URL` 显示旧域名

#### 诊断步骤

1. **检查 `.env` 文件位置和内容**：
   ```bash
   # .env应该在 docker/ 目录（与 docker-compose.yml 同级）
   cat /path/to/drubase_one/docker/.env | grep BAAS_DOMAIN
   # 应该显示：BAAS_DOMAIN=local.drubase-one.com
   ```

2. **检查当前工作目录**：
   ```bash
   pwd
   # 必须显示 docker 目录：/path/to/drubase_one/docker
   # docker-compose 会从当前目录读取 .env 文件
   ```

3. **验证容器中的环境变量**：
   ```bash
   # ⚠️ 确保在 docker/ 目录执行（.env 所在位置）
   cd /path/to/drubase_one/docker
   docker-compose exec baas-functions-service env | grep DRUPAL_API_URL

   # ✅ 如果正确：DRUPAL_API_URL=http://local.drubase-one.com
   # ❌ 如果错误：DRUPAL_API_URL=http://local.drubase.com（说明.env未被读取）
   ```

#### 解决方案

**唯一正确的方法：在 docker/ 目录运行 docker-compose**

```bash
# 1. 切换到 docker/ 目录（.env 文件所在位置）
cd /path/to/drubase_one/docker

# 2. 验证 .env 文件存在且内容正确
cat .env | grep BAAS_DOMAIN

# 3. 完全重启服务
docker-compose down
docker-compose up -d

# 4. 验证环境变量已生效
docker-compose exec baas-functions-service env | grep DRUPAL_API_URL
# 应该显示：DRUPAL_API_URL=http://local.drubase-one.com
```

**为什么必须在 docker/ 目录？**
- docker-compose 从**当前工作目录**读取 `.env` 文件
- `.env` 和 `docker-compose.yml` 都在 docker/ 目录
- install.sh 将 `.env` 创建在 docker/ 目录
- 这是 docker-compose 的标准配置方式

4. **检查baas-functions日志**：
   ```bash
   docker logs baas-functions-service --tail 20

   # 应该看到：
   # info: Realtime server initialized successfully {"drupalApiUrl":"http://local.drubase-one.com","port":4000}

   # 而不是旧域名：
   # info: Realtime server initialized successfully {"drupalApiUrl":"http://local.drubase.com","port":4000}
   ```

5. **测试WebSocket连接**：
   刷新浏览器页面，应该能看到：
   - ✅ WebSocket认证成功
   - ✅ WebSocket连接已建立
   - 没有1008错误

### Q: 502 Bad Gateway 错误？

**A**: 这通常是因为：

1. Nginx vhost 配置的 `server_name` 未更新
2. DNS解析未正确配置（容器内无法解析域名）
3. PHP-FPM服务未运行

检查命令：
```bash
# 检查PHP-FPM状态
docker exec php8-4-fpm-xdebug ps aux | grep php-fpm

# 检查容器DNS解析
docker exec baas-functions-service getent hosts api.example.com
```

### Q: 需要HTTPS支持怎么办？

**A**: 参考 `HTTPS_CONFIGURATION.md`（待创建）或手动配置：

1. 获取SSL证书（Let's Encrypt推荐）
2. 更新 `docker-compose.yml` 中nginx的端口映射（启用443端口）
3. 更新nginx配置添加SSL证置
4. 修改应用配置使用 `https://` 协议

## 配置示例

### 示例1：单域名配置

```bash
# .env
BAAS_DOMAIN=baas.mycompany.com
DOMAIN=baas.mycompany.com
```

```nginx
# docker/nginx/vhosts/drubase.conf
server_name baas.mycompany.com;
```

```yaml
# docker/docker-compose.yml (php8-4-fpm)
extra_hosts:
  - "baas.mycompany.com:172.20.1.100"
```

### 示例2：多环境配置

**开发环境 (.env.dev)**:
```bash
BAAS_DOMAIN=dev.baas.mycompany.com
DOMAIN=localhost
```

**生产环境 (.env.prod)**:
```bash
BAAS_DOMAIN=api.mycompany.com
DOMAIN=api.mycompany.com
```

## 相关文档

- [安装指南](README.md)
- [Docker配置说明](DOCKER_CONFIGURATION.md)
- [网络架构](NETWORK_ARCHITECTURE.md)

## 技术支持

如遇问题，请提交Issue: https://github.com/cloudiowoo/drubase-one/issues
