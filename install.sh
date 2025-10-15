#!/bin/bash
# Drubase One 一键安装脚本

set -e

echo "🚀 开始安装 Drubase One..."

# 检查Docker环境
if ! command -v docker &> /dev/null; then
    echo "❌ Docker 未安装，请先安装 Docker"
    exit 1
fi

# 检测 Docker Compose 版本并设置命令
if docker compose version &> /dev/null; then
    DOCKER_COMPOSE="docker compose"
    echo "✅ 检测到 Docker Compose v2"
elif command -v docker-compose &> /dev/null; then
    DOCKER_COMPOSE="docker-compose"
    echo "✅ 检测到 Docker Compose v1"
else
    echo "❌ Docker Compose 未安装，请先安装 Docker Compose"
    echo "   安装方式: https://docs.docker.com/compose/install/"
    exit 1
fi

# 检查.env文件（与docker-compose.yml同级）
if [ ! -f "docker/.env" ]; then
    echo "📝 创建 .env 配置文件（docker/目录）..."
    cat > docker/.env << ENVEOF
# 数据库配置
DB_PASSWORD=baas_password
DB_NAME=drubase
DB_USER=postgres

# Redis配置
REDIS_PASSWORD=

# 应用配置
APP_ENV=production
APP_SECRET=your-secret-key-change-this

# 域名配置
# BaaS平台内部域名（用于容器间通信）
# 如果需要自定义域名，请修改此变量，同时需要更新nginx vhost配置中的server_name
BAAS_DOMAIN=local.drubase.com

# 外部访问域名（用于浏览器访问）
DOMAIN=localhost
ENVEOF
    echo "✅ .env 文件已创建在 docker/.env，请根据需要修改配置"
fi

# 创建必要的数据目录
echo "📁 创建数据目录..."

# 停止可能运行的容器
echo "🛑 停止现有容器..."
cd docker && $DOCKER_COMPOSE down 2>/dev/null || true && cd ..

# 彻底清理数据目录
echo "🧹 彻底清理数据目录..."
rm -rf docker/pg/v17/data docker/redis/data
rm -rf docker/nginx/logs docker/fpm/8.4/log docker/nodejs-services/baas-functions/logs

# 重新创建所需的目录
echo "📁 重新创建目录结构..."
mkdir -p docker/pg/v17/data docker/pg/v17/log
mkdir -p docker/redis/data
mkdir -p docker/nginx/logs
mkdir -p docker/fpm/8.4/log
mkdir -p docker/nodejs-services/baas-functions/logs

# 设置PostgreSQL数据目录权限
echo "🔐 设置目录权限..."
chmod 700 docker/pg/v17/data
chmod 755 docker/pg/v17/log

# 强制移除可能存在的PostgreSQL容器和卷
echo "🗑️  清理PostgreSQL容器和卷..."
docker rm -f pg17 2>/dev/null || true
docker volume rm drubase_one_pg_data 2>/dev/null || true

# 启动Docker服务
echo "🐳 启动 Docker 服务..."
cd docker && $DOCKER_COMPOSE up -d && cd ..

# 等待数据库启动
echo "⏳ 等待数据库启动..."
echo "   这可能需要几分钟来初始化PostgreSQL..."
sleep 5

# 检查数据库是否准备就绪
for i in {1..12}; do
    echo "   尝试连接数据库 ($i/12)..."
    if cd docker && $DOCKER_COMPOSE exec -T pg17 pg_isready -U postgres -d drubase >/dev/null 2>&1 && cd ..; then
        echo "✅ 数据库已准备就绪"
        break
    fi
    if [ $i -eq 12 ]; then
        echo "❌ 数据库启动超时，请检查日志: cd docker && $DOCKER_COMPOSE logs pg17"
        exit 1
    fi
    sleep 10
done

# 删除可能存在的composer.lock文件，让用户首次运行时生成新的
if [ -f "composer.lock" ]; then
    echo "🧹 移除过期的 composer.lock 文件..."
    rm "composer.lock"
fi

# 安装依赖
echo "📦 安装 Composer 依赖..."
echo "   首次安装将自动生成 composer.lock..."
cd docker && $DOCKER_COMPOSE exec -T php8-4-fpm bash -c "cd /var/www/html && composer install --no-dev --optimize-autoloader" && cd ..

# 准备 Drupal 安装
echo "⚙️  准备 Drupal 安装..."
echo "   设置文件权限..."
cd docker && $DOCKER_COMPOSE exec -T php8-4-fpm bash -c "cd /var/www/html && \
    mkdir -p web/sites/default/files && \
    chmod 755 web/sites/default/files && \
    cp web/sites/default/default.settings.php web/sites/default/settings.php 2>/dev/null || true && \
    chmod 666 web/sites/default/settings.php 2>/dev/null || true" && cd ..

echo ""
echo "🎉 Drubase One 环境准备完成！"
echo ""
echo "🌐 请打开浏览器访问: http://localhost"
echo ""
echo "📋 安装向导说明:"
echo "   1. 选择安装配置文件: 'Drubase One'"
echo "   2. 数据库配置:"
echo "      - 数据库类型: PostgreSQL"
echo "      - 数据库名: drubase"
echo "      - 用户名: postgres"
echo "      - 密码: \${DB_PASSWORD:-baas_password}"
echo "      - 高级选项 > 主机: pg17"
echo "      - 高级选项 > 端口: 5432"
echo "   3. 创建管理员账户"
echo "   4. 选择是否导入 Groups 演示数据（可选）"
echo ""
echo "📚 安装完成后的资源:"
echo "   • API 文档: http://localhost/admin/config/baas/api/docs"
echo "   • 函数服务: http://localhost:3001"
echo "   • 演示应用: http://localhost:3000 (如果启用)"
echo ""
echo "🛠️  管理命令:"
echo "   cd docker && \$DOCKER_COMPOSE logs -f    # 查看日志"
echo "   cd docker && \$DOCKER_COMPOSE stop       # 停止服务"
echo "   cd docker && \$DOCKER_COMPOSE restart    # 重启服务"
echo ""
echo "💡 提示:"
echo "   • 如果遇到权限问题，请手动设置 docker/pg/v17/log 目录权限"
echo "   • .env 配置文件位于 docker/.env（与 docker-compose.yml 同级）"
echo "   • 修改域名配置后需要在 docker/ 目录中重启服务"
echo ""
