#!/bin/bash

# BaaS Entity 模块单元测试运行脚本
# 此脚本专门运行单元测试，确保核心功能正常工作

set -e

echo "=== BaaS Entity 模块单元测试 ==="
echo "项目路径: $(pwd)"
echo "模块路径: $(pwd)/web/modules/custom/baas_entity"
echo ""

# 检查必要文件
echo "检查测试环境..."
if [ ! -f "vendor/bin/phpunit" ]; then
    echo "✗ PHPUnit 未安装"
    echo "请运行: composer require --dev phpunit/phpunit"
    exit 1
fi
echo "✓ PHPUnit 已安装"

if [ ! -d "web/modules/custom/baas_entity/tests/src/Unit" ]; then
    echo "✗ 单元测试目录不存在"
    exit 1
fi
echo "✓ 单元测试目录存在"

if [ ! -f "web/modules/custom/baas_entity/tests/src/Unit/FieldTypePluginTest.php" ]; then
    echo "✗ 核心测试文件不存在"
    exit 1
fi
echo "✓ 核心测试文件存在"
echo ""

# 运行单元测试
echo "=== 运行单元测试 ==="
echo "测试目录: tests/src/Unit/"
echo ""

# 记录开始时间
start_time=$(date +%s)

# 运行所有单元测试
if vendor/bin/phpunit web/modules/custom/baas_entity/tests/src/Unit/; then
    echo ""
    echo "✓ 所有单元测试通过"
    test_result="PASSED"
else
    echo ""
    echo "✗ 单元测试失败"
    test_result="FAILED"
    exit 1
fi

# 计算执行时间
end_time=$(date +%s)
execution_time=$((end_time - start_time))

echo ""
echo "=== 代码质量检查 ==="

# PHP语法检查
echo "检查PHP语法..."
if find web/modules/custom/baas_entity/src -name "*.php" -exec php -l {} \; | grep -q "Errors parsing"; then
    echo "✗ PHP语法错误"
    exit 1
else
    echo "✓ PHP语法检查通过"
fi

# 检查是否有phpcs
if command -v phpcs >/dev/null 2>&1; then
    echo "运行代码标准检查..."
    if phpcs --standard=Drupal web/modules/custom/baas_entity/src/; then
        echo "✓ 代码标准检查通过"
    else
        echo "⚠ 代码标准检查发现问题（非致命错误）"
    fi
else
    echo "⚠ phpcs 未安装，跳过代码标准检查"
fi

echo ""
echo "=== 测试报告 ==="
echo "执行时间: ${execution_time}秒"
echo "环境信息: Docker PHP 8.4, Drupal 11.x"
echo "测试结果: $test_result"
echo ""

if [ "$test_result" = "PASSED" ]; then
    echo "🎉 所有测试通过！模块可以安全部署。"
    exit 0
else
    echo "❌ 测试失败，请检查错误信息。"
    exit 1
fi
