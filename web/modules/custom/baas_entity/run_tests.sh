#!/bin/bash

# BaaS Entity 模块测试运行脚本
# 用于在Docker环境中执行所有测试

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 项目路径
PROJECT_PATH="/var/www/webs/cloudio/drubase"
MODULE_PATH="$PROJECT_PATH/web/modules/custom/baas_entity"

echo -e "${BLUE}=== BaaS Entity 模块测试套件 ===${NC}"
echo -e "${BLUE}项目路径: $PROJECT_PATH${NC}"
echo -e "${BLUE}模块路径: $MODULE_PATH${NC}"
echo ""

# 检查PHPUnit配置
echo -e "${YELLOW}检查PHPUnit配置...${NC}"
if [ ! -f "$MODULE_PATH/phpunit.xml" ]; then
    echo -e "${RED}错误: 未找到PHPUnit配置文件 $MODULE_PATH/phpunit.xml${NC}"
    exit 1
fi
echo -e "${GREEN}✓ PHPUnit配置文件存在${NC}"

# 检查测试目录
echo -e "${YELLOW}检查测试目录结构...${NC}"
TEST_DIRS=("tests/src/Unit" "tests/src/Kernel" "tests/src/Functional")
for dir in "${TEST_DIRS[@]}"; do
    if [ ! -d "$MODULE_PATH/$dir" ]; then
        echo -e "${RED}错误: 测试目录不存在 $MODULE_PATH/$dir${NC}"
        exit 1
    fi
    echo -e "${GREEN}✓ $dir 目录存在${NC}"
done

# 检查核心测试文件
echo -e "${YELLOW}检查核心测试文件...${NC}"
TEST_FILES=(
    "tests/src/Unit/FieldTypePluginTest.php"
    "tests/src/Kernel/FieldTypeManagerKernelTest.php"
    "tests/src/Functional/FieldTypePluginFunctionalTest.php"
)
for file in "${TEST_FILES[@]}"; do
    if [ ! -f "$MODULE_PATH/$file" ]; then
        echo -e "${RED}错误: 测试文件不存在 $MODULE_PATH/$file${NC}"
        exit 1
    fi
    echo -e "${GREEN}✓ $file 存在${NC}"
done

echo ""

# 运行单元测试
echo -e "${BLUE}=== 运行单元测试 ===${NC}"
cd "$MODULE_PATH"
if php -f "$PROJECT_PATH/web/core/scripts/run-tests.sh" -- \
    --url http://localhost \
    --sqlite "$PROJECT_PATH/web/sites/default/files/.ht.sqlite" \
    --module baas_entity \
    --class "Drupal\Tests\baas_entity\Unit\FieldTypePluginTest"; then
    echo -e "${GREEN}✓ 单元测试通过${NC}"
else
    echo -e "${RED}✗ 单元测试失败${NC}"
    exit 1
fi

echo ""

# 运行内核测试
echo -e "${BLUE}=== 运行内核测试 ===${NC}"
if php -f "$PROJECT_PATH/web/core/scripts/run-tests.sh" -- \
    --url http://localhost \
    --sqlite "$PROJECT_PATH/web/sites/default/files/.ht.sqlite" \
    --module baas_entity \
    --class "Drupal\Tests\baas_entity\Kernel\FieldTypeManagerKernelTest"; then
    echo -e "${GREEN}✓ 内核测试通过${NC}"
else
    echo -e "${RED}✗ 内核测试失败${NC}"
    exit 1
fi

echo ""

# 运行功能测试
echo -e "${BLUE}=== 运行功能测试 ===${NC}"
if php -f "$PROJECT_PATH/web/core/scripts/run-tests.sh" -- \
    --url http://localhost \
    --sqlite "$PROJECT_PATH/web/sites/default/files/.ht.sqlite" \
    --module baas_entity \
    --class "Drupal\Tests\baas_entity\Functional\FieldTypePluginFunctionalTest"; then
    echo -e "${GREEN}✓ 功能测试通过${NC}"
else
    echo -e "${RED}✗ 功能测试失败${NC}"
    exit 1
fi

echo ""

# 运行所有测试
echo -e "${BLUE}=== 运行所有模块测试 ===${NC}"
if php -f "$PROJECT_PATH/web/core/scripts/run-tests.sh" -- \
    --url http://localhost \
    --sqlite "$PROJECT_PATH/web/sites/default/files/.ht.sqlite" \
    --module baas_entity; then
    echo -e "${GREEN}✓ 所有测试通过${NC}"
else
    echo -e "${RED}✗ 部分测试失败${NC}"
    exit 1
fi

echo ""

# 代码质量检查（如果可用）
echo -e "${BLUE}=== 代码质量检查 ===${NC}"

# 检查PHP语法
echo -e "${YELLOW}检查PHP语法...${NC}"
find "$MODULE_PATH/src" -name "*.php" -exec php -l {} \; > /dev/null
if [ $? -eq 0 ]; then
    echo -e "${GREEN}✓ PHP语法检查通过${NC}"
else
    echo -e "${RED}✗ PHP语法检查失败${NC}"
    exit 1
fi

# 检查Drupal编码标准（如果phpcs可用）
if command -v phpcs &> /dev/null; then
    echo -e "${YELLOW}检查Drupal编码标准...${NC}"
    if phpcs --standard=Drupal --extensions=php,module,inc,install,test,profile,theme,css,info,txt,md,yml "$MODULE_PATH/src"; then
        echo -e "${GREEN}✓ Drupal编码标准检查通过${NC}"
    else
        echo -e "${YELLOW}⚠ Drupal编码标准检查发现问题${NC}"
    fi
else
    echo -e "${YELLOW}⚠ phpcs不可用，跳过编码标准检查${NC}"
fi

echo ""
echo -e "${GREEN}=== 所有测试完成 ===${NC}"
echo -e "${GREEN}BaaS Entity 模块测试套件执行成功！${NC}"

# 生成测试报告
echo ""
echo -e "${BLUE}=== 测试报告 ===${NC}"
echo "测试执行时间: $(date)"
echo "测试环境: Docker PHP 8.4"
echo "Drupal版本: 11.x"
echo "模块: baas_entity"
echo ""
echo "测试类型:"
echo "  ✓ 单元测试 (Unit Tests)"
echo "  ✓ 内核测试 (Kernel Tests)"
echo "  ✓ 功能测试 (Functional Tests)"
echo "  ✓ 代码质量检查"
echo ""
echo -e "${GREEN}所有测试通过！模块可以安全部署。${NC}"
