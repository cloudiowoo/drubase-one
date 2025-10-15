#!/bin/bash

# BaaS API 集成测试运行脚本

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 项目根目录
PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"

# 测试目录
TEST_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# 函数：打印带颜色的消息
print_message() {
    local color=$1
    local message=$2
    echo -e "${color}${message}${NC}"
}

# 函数：运行命令并检查结果
run_command() {
    local description=$1
    local command=$2

    print_message $BLUE "🔄 $description"

    if eval "$command"; then
        print_message $GREEN "✅ $description - 成功"
        return 0
    else
        print_message $RED "❌ $description - 失败"
        return 1
    fi
}

# 函数：检查前置条件
check_prerequisites() {
    print_message $YELLOW "🔍 检查前置条件..."

    # 检查Docker是否运行
    if ! docker info > /dev/null 2>&1; then
        print_message $RED "❌ Docker 未运行，请启动Docker"
        exit 1
    fi

    # 检查PHP容器是否运行
    if ! docker exec php8-4-fpm-official php --version > /dev/null 2>&1; then
        print_message $RED "❌ PHP容器未运行，请启动开发环境"
        exit 1
    fi

    # 检查数据库连接
    if ! docker exec php8-4-fpm-official sh -c "cd /var/www/html/cloudio/drubase && vendor/bin/drush sql:query 'SELECT 1'" > /dev/null 2>&1; then
        print_message $RED "❌ 数据库连接失败"
        exit 1
    fi

    print_message $GREEN "✅ 前置条件检查通过"
}

# 函数：准备测试环境
setup_test_environment() {
    print_message $YELLOW "🔧 准备测试环境..."

    # 清理缓存
    run_command "清理Drupal缓存" "docker exec php8-4-fpm-official sh -c 'cd /var/www/html/cloudio/drubase && vendor/bin/drush cr'"

    # 启用测试模块
    run_command "启用测试模块" "docker exec php8-4-fpm-official sh -c 'cd /var/www/html/cloudio/drubase && vendor/bin/drush en baas_api baas_auth baas_tenant baas_entity baas_project -y'"

    # 更新数据库
    run_command "更新数据库" "docker exec php8-4-fpm-official sh -c 'cd /var/www/html/cloudio/drubase && vendor/bin/drush updb -y'"

    # 创建测试目录
    run_command "创建测试输出目录" "docker exec php8-4-fpm-official mkdir -p /tmp/browsertest"

    print_message $GREEN "✅ 测试环境准备完成"
}

# 函数：运行单元测试
run_unit_tests() {
    print_message $YELLOW "🧪 运行单元测试..."

    run_command "运行单元测试" "docker exec php8-4-fpm-official sh -c 'cd /var/www/html/cloudio/drubase && vendor/bin/phpunit --configuration web/modules/custom/baas_api/tests/phpunit.xml --testsuite unit --verbose'"
}

# 函数：运行内核测试
run_kernel_tests() {
    print_message $YELLOW "🔬 运行内核测试..."

    run_command "运行内核测试" "docker exec php8-4-fpm-official sh -c 'cd /var/www/html/cloudio/drubase && vendor/bin/phpunit --configuration web/modules/custom/baas_api/tests/phpunit.xml --testsuite kernel --verbose'"
}

# 函数：运行功能测试
run_functional_tests() {
    print_message $YELLOW "🌐 运行功能测试..."

    run_command "运行功能测试" "docker exec php8-4-fpm-official sh -c 'cd /var/www/html/cloudio/drubase && vendor/bin/phpunit --configuration web/modules/custom/baas_api/tests/phpunit.xml --testsuite functional --verbose'"
}

# 函数：运行所有测试
run_all_tests() {
    print_message $YELLOW "🚀 运行所有测试..."

    run_command "运行所有测试" "docker exec php8-4-fpm-official sh -c 'cd /var/www/html/cloudio/drubase && vendor/bin/phpunit --configuration web/modules/custom/baas_api/tests/phpunit.xml --verbose'"
}

# 函数：运行覆盖率测试
run_coverage_tests() {
    print_message $YELLOW "📊 运行覆盖率测试..."

    run_command "运行覆盖率测试" "docker exec php8-4-fpm-official sh -c 'cd /var/www/html/cloudio/drubase && vendor/bin/phpunit --configuration web/modules/custom/baas_api/tests/phpunit.xml --coverage-html /tmp/coverage-report --verbose'"

    print_message $GREEN "📊 覆盖率报告已生成到 /tmp/coverage-report"
}

# 函数：运行性能测试
run_performance_tests() {
    print_message $YELLOW "⚡ 运行性能测试..."

    run_command "运行性能测试" "docker exec php8-4-fpm-official sh -c 'cd /var/www/html/cloudio/drubase && vendor/bin/phpunit --configuration web/modules/custom/baas_api/tests/phpunit.xml --group performance --verbose'"
}

# 函数：运行缓存测试
run_cache_tests() {
    print_message $YELLOW "💰 运行缓存测试..."

    run_command "运行缓存测试" "docker exec php8-4-fpm-official sh -c 'cd /var/www/html/cloudio/drubase && vendor/bin/phpunit --configuration web/modules/custom/baas_api/tests/phpunit.xml --group cache --verbose'"
}

# 函数：运行API端点测试
run_api_endpoint_tests() {
    print_message $YELLOW "🔗 运行API端点测试..."

    # 健康检查
    run_command "测试健康检查端点" "docker exec php8-4-fpm-official curl -f http://localhost/api/health"

    # API文档
    run_command "测试API文档端点" "docker exec php8-4-fpm-official curl -f http://localhost/api/docs"

    # 认证端点
    run_command "测试认证端点" "docker exec php8-4-fpm-official curl -f -X POST -H 'Content-Type: application/json' -d '{\"username\":\"test\",\"password\":\"test\"}' http://localhost/api/auth/login"
}

# 函数：清理测试环境
cleanup_test_environment() {
    print_message $YELLOW "🧹 清理测试环境..."

    # 清理测试数据
    run_command "清理测试数据" "docker exec php8-4-fpm-official sh -c 'cd /var/www/html/cloudio/drubase && vendor/bin/drush sql:query \"DELETE FROM baas_tenant_config WHERE tenant_id LIKE \\'test_%\\'\"'"

    # 清理缓存
    run_command "清理缓存" "docker exec php8-4-fpm-official sh -c 'cd /var/www/html/cloudio/drubase && vendor/bin/drush cr'"

    print_message $GREEN "✅ 测试环境清理完成"
}

# 函数：显示帮助信息
show_help() {
    echo "BaaS API 集成测试运行脚本"
    echo ""
    echo "用法: $0 [选项]"
    echo ""
    echo "选项:"
    echo "  unit            运行单元测试"
    echo "  kernel          运行内核测试"
    echo "  functional      运行功能测试"
    echo "  performance     运行性能测试"
    echo "  cache           运行缓存测试"
    echo "  coverage        运行覆盖率测试"
    echo "  endpoints       运行API端点测试"
    echo "  all             运行所有测试"
    echo "  setup           只准备测试环境"
    echo "  cleanup         只清理测试环境"
    echo "  help            显示帮助信息"
    echo ""
    echo "示例:"
    echo "  $0 all          # 运行所有测试"
    echo "  $0 functional   # 只运行功能测试"
    echo "  $0 coverage     # 运行覆盖率测试"
}

# 主函数
main() {
    local command=${1:-help}

    case $command in
        unit)
            check_prerequisites
            setup_test_environment
            run_unit_tests
            cleanup_test_environment
            ;;
        kernel)
            check_prerequisites
            setup_test_environment
            run_kernel_tests
            cleanup_test_environment
            ;;
        functional)
            check_prerequisites
            setup_test_environment
            run_functional_tests
            cleanup_test_environment
            ;;
        performance)
            check_prerequisites
            setup_test_environment
            run_performance_tests
            cleanup_test_environment
            ;;
        cache)
            check_prerequisites
            setup_test_environment
            run_cache_tests
            cleanup_test_environment
            ;;
        coverage)
            check_prerequisites
            setup_test_environment
            run_coverage_tests
            cleanup_test_environment
            ;;
        endpoints)
            check_prerequisites
            setup_test_environment
            run_api_endpoint_tests
            cleanup_test_environment
            ;;
        all)
            check_prerequisites
            setup_test_environment
            run_all_tests
            cleanup_test_environment
            ;;
        setup)
            check_prerequisites
            setup_test_environment
            ;;
        cleanup)
            cleanup_test_environment
            ;;
        help)
            show_help
            ;;
        *)
            print_message $RED "❌ 未知命令: $command"
            show_help
            exit 1
            ;;
    esac
}

# 运行主函数
main "$@"
