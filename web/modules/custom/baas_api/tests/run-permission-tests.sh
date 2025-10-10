#!/bin/bash

# BaaS API 权限检查测试运行脚本

echo "🧪 运行BaaS API权限检查测试..."

# 设置颜色输出
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 获取脚本目录
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" &> /dev/null && pwd )"
MODULE_DIR="$(dirname "$SCRIPT_DIR")"

echo "📁 模块目录: $MODULE_DIR"
echo "📁 测试目录: $SCRIPT_DIR"

# 检查PHPUnit配置
if [ ! -f "$SCRIPT_DIR/phpunit.xml" ]; then
    echo -e "${RED}❌ 找不到PHPUnit配置文件: $SCRIPT_DIR/phpunit.xml${NC}"
    exit 1
fi

echo -e "${GREEN}✅ 找到PHPUnit配置文件${NC}"

# 运行单元测试
echo -e "${YELLOW}🔍 运行单元测试...${NC}"

echo "📋 测试用例："
echo "  - BaasAuthenticatedUser权限检查测试"
echo "  - BaseApiController功能测试"
echo "  - ApiResponseService测试"

# 运行单元测试
cd "$SCRIPT_DIR"
../../../../../../vendor/bin/phpunit --configuration phpunit.xml --testsuite unit --testdox

if [ $? -eq 0 ]; then
    echo -e "${GREEN}✅ 单元测试通过${NC}"
else
    echo -e "${RED}❌ 单元测试失败${NC}"
    exit 1
fi

# 运行功能测试（如果环境支持）
echo -e "${YELLOW}🌐 检查功能测试环境...${NC}"

if [ -n "$SIMPLETEST_BASE_URL" ] && [ -n "$SIMPLETEST_DB" ]; then
    echo -e "${GREEN}✅ 功能测试环境已配置${NC}"
    echo "  - Base URL: $SIMPLETEST_BASE_URL"
    echo "  - Database: $SIMPLETEST_DB"
    
    echo -e "${YELLOW}🧪 运行功能测试...${NC}"
    ../../../../../../vendor/bin/phpunit --configuration phpunit.xml --testsuite functional --testdox
    
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✅ 功能测试通过${NC}"
    else
        echo -e "${RED}❌ 功能测试失败${NC}"
        exit 1
    fi
else
    echo -e "${YELLOW}⚠️  功能测试环境未配置，跳过功能测试${NC}"
    echo "要运行功能测试，请设置以下环境变量："
    echo "  export SIMPLETEST_BASE_URL=http://localhost"
    echo "  export SIMPLETEST_DB=pgsql://drupal:drupal@postgres/drupal"
fi

# 生成测试报告
echo -e "${YELLOW}📊 生成测试覆盖报告...${NC}"

if command -v xdebug &> /dev/null; then
    ../../../../../../vendor/bin/phpunit --configuration phpunit.xml --testsuite unit --coverage-text --coverage-html coverage-html
    echo -e "${GREEN}✅ 测试覆盖报告已生成：coverage-html/index.html${NC}"
else
    echo -e "${YELLOW}⚠️  Xdebug未安装，跳过覆盖报告生成${NC}"
fi

echo -e "${GREEN}🎉 权限检查测试完成！${NC}"

# 显示测试摘要
echo ""
echo "📋 测试摘要："
echo "✅ 单元测试：BaasAuthenticatedUser权限检查逻辑"
echo "✅ 单元测试：BaseApiController权限验证方法"
echo "✅ 单元测试：ApiResponseService标准化响应"
if [ -n "$SIMPLETEST_BASE_URL" ] && [ -n "$SIMPLETEST_DB" ]; then
    echo "✅ 功能测试：API端点权限验证"
    echo "✅ 功能测试：跨租户访问控制"
    echo "✅ 功能测试：项目级权限检查"
else
    echo "⏭️  功能测试：需要配置测试环境"
fi

echo ""
echo "📚 相关文档："
echo "  - API权限检查架构：../docs/authentication-middleware.md"
echo "  - API标准化指南：../API_STANDARDS.md"
echo "  - 项目状态报告：../../../../docs/project-status-report.md"

exit 0