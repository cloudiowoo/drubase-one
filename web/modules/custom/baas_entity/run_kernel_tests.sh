#!/bin/bash

# BaaS Entity 模块内核测试运行脚本
# 此脚本专门用于运行内核测试，需要完整的Drupal环境

set -e

echo "=== BaaS Entity 模块内核测试 ==="

# 检查是否在Docker容器内
if [ ! -f /.dockerenv ]; then
    echo "❌ 此脚本需要在Docker容器内运行"
    echo "请使用: docker exec -it php8-4-fpm-xdebug sh -c 'cd /var/www/webs/cloudio/drubase && web/modules/custom/baas_entity/run_kernel_tests.sh'"
    exit 1
fi

# 设置基础路径
BASE_DIR="/var/www/webs/cloudio/drubase"
MODULE_DIR="$BASE_DIR/web/modules/custom/baas_entity"
CORE_DIR="$BASE_DIR/web/core"

# 检查必要文件
echo "✓ 检查环境..."
if [ ! -f "$BASE_DIR/vendor/bin/phpunit" ]; then
    echo "❌ PHPUnit 未安装"
    exit 1
fi

if [ ! -d "$MODULE_DIR/tests/src/Kernel" ]; then
    echo "❌ 内核测试目录不存在"
    exit 1
fi

echo "✓ PHPUnit 已安装"
echo "✓ 内核测试目录存在"

# 检查数据库连接
echo "✓ 检查数据库连接..."
cd "$BASE_DIR"
if ! vendor/bin/drush sql:connect > /dev/null 2>&1; then
    echo "❌ 数据库连接失败"
    echo "请检查Drupal数据库配置"
    exit 1
fi
echo "✓ 数据库连接正常"

# 获取数据库配置
DB_INFO=$(vendor/bin/drush sql:connect 2>/dev/null | head -1)
if [[ $DB_INFO == *"psql"* ]]; then
    # PostgreSQL
    DB_HOST=$(vendor/bin/drush status --field=db-hostname 2>/dev/null)
    DB_PORT=$(vendor/bin/drush status --field=db-port 2>/dev/null)
    DB_NAME=$(vendor/bin/drush status --field=db-name 2>/dev/null)
    DB_USER=$(vendor/bin/drush status --field=db-username 2>/dev/null)

    # 从settings.php获取密码
    DB_PASS=$(grep -A 10 "databases\[" web/sites/default/settings.php | grep "password" | sed "s/.*=> '//" | sed "s/',.*//")

    SIMPLETEST_DB="pgsql://${DB_USER}:${DB_PASS}@${DB_HOST}:${DB_PORT}/${DB_NAME}"
    echo "✓ 使用PostgreSQL数据库: $DB_NAME"
elif [[ $DB_INFO == *"mysql"* ]]; then
    # MySQL
    DB_HOST=$(vendor/bin/drush status --field=db-hostname 2>/dev/null)
    DB_PORT=$(vendor/bin/drush status --field=db-port 2>/dev/null)
    DB_NAME=$(vendor/bin/drush status --field=db-name 2>/dev/null)
    DB_USER=$(vendor/bin/drush status --field=db-username 2>/dev/null)

    # 从settings.php获取密码
    DB_PASS=$(grep -A 10 "databases\[" web/sites/default/settings.php | grep "password" | sed "s/.*=> '//" | sed "s/',.*//")

    SIMPLETEST_DB="mysql://${DB_USER}:${DB_PASS}@${DB_HOST}:${DB_PORT}/${DB_NAME}"
    echo "✓ 使用MySQL数据库: $DB_NAME"
else
    echo "⚠️  无法识别数据库类型，使用SQLite作为备选"
    # 创建临时SQLite数据库
    TEMP_DB="/tmp/drupal_test_$(date +%s).sqlite"
    SIMPLETEST_DB="sqlite://localhost${TEMP_DB}"
    echo "✓ 使用临时SQLite数据库: $TEMP_DB"
fi

# 设置环境变量
export SIMPLETEST_BASE_URL="http://localhost"
export SIMPLETEST_DB="$SIMPLETEST_DB"
export BROWSERTEST_OUTPUT_DIRECTORY="$BASE_DIR/web/sites/simpletest/browser_output"
export SYMFONY_DEPRECATIONS_HELPER="disabled"

# 创建输出目录
mkdir -p "$BASE_DIR/web/sites/simpletest/browser_output" 2>/dev/null || true
chmod 777 "$BASE_DIR/web/sites/simpletest/browser_output" 2>/dev/null || true

echo "=== 运行内核测试 ==="
echo "数据库: $SIMPLETEST_DB"
echo "基础URL: $SIMPLETEST_BASE_URL"
echo ""

# 切换到core目录运行测试
cd "$CORE_DIR"

# 运行内核测试
echo "开始运行内核测试..."
if ../../vendor/bin/phpunit ../modules/custom/baas_entity/tests/src/Kernel/FieldTypeManagerKernelTest.php; then
    echo ""
    echo "✓ 内核测试通过"
    TEST_RESULT="PASSED"
else
    echo ""
    echo "❌ 内核测试失败"
    TEST_RESULT="FAILED"
fi

# 清理临时文件
if [[ $SIMPLETEST_DB == *"sqlite"* ]] && [[ $SIMPLETEST_DB == *"/tmp/"* ]]; then
    TEMP_FILE=$(echo $SIMPLETEST_DB | sed 's/sqlite:\/\/localhost//')
    rm -f "$TEMP_FILE" 2>/dev/null || true
    echo "✓ 清理临时数据库文件"
fi

echo ""
echo "=== 测试报告 ==="
echo "测试类型: 内核测试 (Kernel Tests)"
echo "测试文件: FieldTypeManagerKernelTest.php"
echo "执行时间: $(date)"
echo "环境信息: Docker PHP $(php -r 'echo PHP_VERSION;'), Drupal $(cd $BASE_DIR && vendor/bin/drush status --field=drupal-version 2>/dev/null)"
echo "数据库: $(echo $SIMPLETEST_DB | cut -d: -f1)"
echo "测试结果: $TEST_RESULT"

if [ "$TEST_RESULT" = "PASSED" ]; then
    echo ""
    echo "🎉 内核测试通过！服务集成正常工作。"
    exit 0
else
    echo ""
    echo "⚠️  内核测试失败。请检查："
    echo "   1. 数据库配置是否正确"
    echo "   2. 模块依赖是否已安装"
    echo "   3. Drupal环境是否完整"
    echo ""
    echo "建议先运行单元测试确保基础功能正常："
    echo "   ./web/modules/custom/baas_entity/run_unit_tests.sh"
    exit 1
fi
