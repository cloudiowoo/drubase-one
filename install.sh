#!/bin/bash
# Drubase One 一键安装脚本

set -e

echo "🚀 开始安装 Drubase One..."

# 检查Docker环境
if ! command -v docker &> /dev/null; then
    echo "❌ Docker 未安装，请先安装 Docker"
    exit 1
fi

if ! command -v docker-compose &> /dev/null; then
    echo "❌ Docker Compose 未安装，请先安装 Docker Compose"
    exit 1
fi

# 检查.env文件
if [ ! -f ".env" ]; then
    echo "📝 创建 .env 配置文件..."
    cat > .env << ENVEOF
# 数据库配置
DB_PASSWORD=baas_password
DB_NAME=baas_platform
DB_USER=baas_user

# Redis配置
REDIS_PASSWORD=

# 应用配置
APP_ENV=production
APP_SECRET=your-secret-key-change-this

# 域名配置
DOMAIN=localhost
ENVEOF
    echo "✅ .env 文件已创建，请根据需要修改配置"
fi

# 启动Docker服务
echo "🐳 启动 Docker 服务..."
docker-compose -f docker/docker-compose.yml up -d

# 等待数据库启动
echo "⏳ 等待数据库启动..."
sleep 10

# 安装依赖
echo "📦 安装 Composer 依赖..."
docker-compose -f docker/docker-compose.yml exec -T php8-4-fpm bash -c "cd /var/www/html && composer install --no-dev --optimize-autoloader"

# 安装Drupal
echo "⚙️  安装 Drupal..."
docker-compose -f docker/docker-compose.yml exec -T php8-4-fpm bash -c "cd /var/www/html && vendor/bin/drush si baas_platform -y \
    --db-url=\"pgsql://postgres:\${DB_PASSWORD:-baas_password}@pg17:5432/drubase\" \
    --account-name=admin \
    --account-pass=admin123 \
    --account-mail=admin@example.com \
    --site-name=\"Drubase One\""

# 清理缓存
echo "🧹 清理缓存..."
docker-compose -f docker/docker-compose.yml exec -T php8-4-fpm bash -c "cd /var/www/html && vendor/bin/drush cr"

echo ""
echo "🎉 Drubase One 安装完成！"
echo ""
echo "📍 访问地址: http://localhost"
echo "👤 管理员账号: admin"
echo "🔑 管理员密码: admin123"
echo ""
echo "📚 API文档: http://localhost/admin/config/baas/api/docs"
echo "⚡ 函数服务: http://localhost:3001"
echo ""
echo "🛠️  管理命令:"
echo "  docker-compose -f docker/docker-compose.yml logs -f    # 查看日志"
echo "  docker-compose -f docker/docker-compose.yml stop       # 停止服务"
echo "  docker-compose -f docker/docker-compose.yml restart    # 重启服务"
echo ""
