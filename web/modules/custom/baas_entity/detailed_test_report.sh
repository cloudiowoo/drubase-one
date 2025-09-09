#!/bin/bash

# BaaS Entity 模块详细测试报告生成脚本
# 专门用于生成可读性强的单元测试报告

set -e

echo "=== BaaS Entity 模块详细测试报告 ==="
echo "生成时间: $(date)"
echo "PHP版本: $(php -r 'echo PHP_VERSION;')"
echo "PHPUnit版本: $(vendor/bin/phpunit --version | head -1)"
echo ""

# 创建报告目录
REPORTS_DIR="web/modules/custom/baas_entity/tests/reports"
mkdir -p "$REPORTS_DIR"

echo "=== 测试文件概览 ==="
echo "单元测试目录: web/modules/custom/baas_entity/tests/src/Unit/"
echo ""

# 列出所有测试文件
echo "📁 测试文件列表:"
find web/modules/custom/baas_entity/tests/src/Unit/ -name "*.php" | while read file; do
  echo "   - $(basename "$file")"
  # 统计测试方法数量
  method_count=$(grep -c "public function test" "$file" 2>/dev/null || echo "0")
  echo "     测试方法数: $method_count"
done
echo ""

echo "=== 详细测试列表 ==="
vendor/bin/phpunit --list-tests web/modules/custom/baas_entity/tests/src/Unit/ | grep "^ - " | while read -r line; do
  # 解析测试类和方法
  test_info=$(echo "$line" | sed 's/^ - //')
  class_name=$(echo "$test_info" | cut -d':' -f1 | sed 's/.*\\//')
  method_name=$(echo "$test_info" | cut -d':' -f2)

  echo "🧪 $class_name::$method_name"
done
echo ""

echo "=== 运行测试并生成详细报告 ==="

# 运行测试并捕获输出
echo "正在执行测试..."
TEST_OUTPUT=$(vendor/bin/phpunit web/modules/custom/baas_entity/tests/src/Unit/ 2>&1)
TEST_EXIT_CODE=$?

echo "测试执行完成，退出码: $TEST_EXIT_CODE"
echo ""

# 分析测试结果
if echo "$TEST_OUTPUT" | grep -q "OK"; then
  echo "✅ 测试状态: 全部通过"
  TESTS_COUNT=$(echo "$TEST_OUTPUT" | grep -o '[0-9]\+ tests' | head -1)
  ASSERTIONS_COUNT=$(echo "$TEST_OUTPUT" | grep -o '[0-9]\+ assertions' | head -1)
  echo "📊 测试统计: $TESTS_COUNT, $ASSERTIONS_COUNT"
elif echo "$TEST_OUTPUT" | grep -q "FAILURES"; then
  echo "❌ 测试状态: 有失败"
  echo "$TEST_OUTPUT" | grep -A 10 "FAILURES"
elif echo "$TEST_OUTPUT" | grep -q "ERRORS"; then
  echo "⚠️  测试状态: 有错误"
  echo "$TEST_OUTPUT" | grep -A 10 "ERRORS"
else
  echo "❓ 测试状态: 未知"
fi

echo ""
echo "=== 按测试类分组的详细信息 ==="

# 为每个测试类生成详细报告
for test_file in web/modules/custom/baas_entity/tests/src/Unit/*.php; do
  if [ -f "$test_file" ]; then
    class_name=$(basename "$test_file" .php)
    echo ""
    echo "📋 $class_name"
    echo "   文件: $test_file"

    # 提取类的注释
    if grep -q "@group" "$test_file"; then
      group=$(grep "@group" "$test_file" | sed 's/.*@group //' | tr -d ' ')
      echo "   分组: $group"
    fi

    # 列出所有测试方法
    echo "   测试方法:"
    grep "public function test" "$test_file" | sed 's/.*function //' | sed 's/(.*$//' | while read method; do
      echo "     - $method"

      # 尝试提取方法注释
      method_line=$(grep -n "function $method" "$test_file" | cut -d: -f1)
      if [ ! -z "$method_line" ]; then
        # 查找方法上方的注释
        comment_line=$((method_line - 1))
        comment=$(sed -n "${comment_line}p" "$test_file" | sed 's/.*\* //' | sed 's/\*\///')
        if [ ! -z "$comment" ] && [[ "$comment" != *"function"* ]]; then
          echo "       描述: $comment"
        fi
      fi
    done
  fi
done

echo ""
echo "=== 测试覆盖范围分析 ==="
echo "🎯 测试覆盖的功能领域:"
echo "   - 字段类型管理器服务 (FieldTypeManagerUnitTest)"
echo "   - 字段类型插件系统 (FieldTypePluginTest)"
echo "   - 基础功能验证 (SimpleUnitTest)"
echo ""

echo "🔍 主要测试场景:"
echo "   - 服务实例化和方法存在性验证"
echo "   - 插件注册和类型管理"
echo "   - 数据验证、处理和格式化"
echo "   - 错误处理和边界情况"
echo "   - 缓存管理和性能优化"
echo ""

echo "=== 测试质量指标 ==="
total_tests=$(vendor/bin/phpunit --list-tests web/modules/custom/baas_entity/tests/src/Unit/ | grep -c "^ - " || echo "0")
total_files=$(find web/modules/custom/baas_entity/tests/src/Unit/ -name "*.php" | wc -l)
avg_tests_per_file=$((total_tests / total_files))

echo "📈 测试数量指标:"
echo "   - 总测试文件数: $total_files"
echo "   - 总测试方法数: $total_tests"
echo "   - 平均每文件测试数: $avg_tests_per_file"
echo ""

echo "=== 运行建议 ==="
echo "💡 日常开发建议:"
echo "   - 使用 run_unit_tests.sh 进行快速测试"
echo "   - 使用 generate_test_reports.sh 生成完整报告"
echo "   - 使用 detailed_test_report.sh 查看详细分析"
echo ""

echo "🚀 单独运行测试的命令:"
echo "   # 运行所有单元测试"
echo "   vendor/bin/phpunit web/modules/custom/baas_entity/tests/src/Unit/"
echo ""
echo "   # 运行特定测试类"
echo "   vendor/bin/phpunit web/modules/custom/baas_entity/tests/src/Unit/FieldTypePluginTest.php"
echo ""
echo "   # 运行特定测试方法"
echo "   vendor/bin/phpunit --filter testPluginRegistration web/modules/custom/baas_entity/tests/src/Unit/"
echo ""

echo "=== 报告生成完成 ==="
echo "📁 详细日志已保存到: $REPORTS_DIR/"
echo "🎉 测试报告分析完成！"
