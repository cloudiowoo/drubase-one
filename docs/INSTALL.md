# Drubase One 安装指南

## 系统要求

- **Docker**: >= 20.0
- **Docker Compose**: >= 2.0
- **内存**: 4GB+
- **磁盘**: 10GB+
- **操作系统**: Linux, macOS, Windows (with WSL2)

## 快速安装

### 1. 下载项目

```bash
git clone https://github.com/cloudiowoo/drubase-one.git
cd drubase-one
```

### 2. 环境配置

复制并编辑环境配置文件：

```bash
cp .env.example .env
```

编辑 `.env` 文件，设置数据库密码等配置：

```env
DB_PASSWORD=your_secure_password
DB_NAME=baas_platform
DB_USER=baas_user
APP_SECRET=your-app-secret-key
```

### 3. 运行安装脚本

```bash
./install.sh
```

### 4. 访问系统

- **前端**: http://localhost
- **管理后台**: http://localhost/admin
- **API文档**: http://localhost/api/docs

### 5. 默认登录信息

- **用户名**: admin
- **密码**: admin123

## 高级配置

### 自定义端口

编辑 `docker-compose.yml`：

```yaml
ports:
  - "8080:80"  # 修改为自定义端口
```

### 生产环境配置

1. 修改默认密码
2. 设置强密码策略
3. 配置HTTPS
4. 设置备份策略

## 故障排除

### 端口冲突

```bash
# 检查端口占用
lsof -i :80
lsof -i :5432

# 停止冲突服务或修改端口配置
```

### 权限问题

```bash
# 修复文件权限
sudo chown -R $(whoami):$(whoami) .
chmod +x install.sh
```

### 容器启动失败

```bash
# 查看日志
docker-compose logs

# 重新构建容器
docker-compose down
docker-compose up --build
```

## 卸载

```bash
# 停止所有服务
docker-compose down

# 删除数据卷（⚠️ 会删除所有数据）
docker-compose down -v

# 删除镜像
docker-compose down --rmi all
```
