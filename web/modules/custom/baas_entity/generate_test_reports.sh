#!/bin/bash

# BaaS Entity 模块测试报告生成脚本
# 生成多种格式的详细测试报告

set -e

echo "=== BaaS Entity 模块测试报告生成 ==="
echo "项目路径: $(pwd)"
echo ""

# 创建报告目录
REPORTS_DIR="web/modules/custom/baas_entity/tests/reports"
mkdir -p "$REPORTS_DIR"
echo "✓ 创建报告目录: $REPORTS_DIR"

# 清理旧报告
rm -f "$REPORTS_DIR"/*
echo "✓ 清理旧报告文件"

echo ""
echo "=== 生成测试报告 ==="

# 1. 生成JUnit XML报告（适用于CI/CD）
echo "1. 生成JUnit XML报告..."
vendor/bin/phpunit \
  --log-junit "$REPORTS_DIR/junit.xml" \
  web/modules/custom/baas_entity/tests/src/Unit/ \
  > /dev/null 2>&1

if [ -f "$REPORTS_DIR/junit.xml" ] && [ -s "$REPORTS_DIR/junit.xml" ]; then
  echo "   ✓ JUnit XML报告: $REPORTS_DIR/junit.xml"
else
  echo "   ✗ JUnit XML报告生成失败"
fi

# 2. 生成TestDox HTML报告（可读性强）
echo "2. 生成TestDox HTML报告..."
vendor/bin/phpunit \
  --testdox-html "$REPORTS_DIR/testdox.html" \
  web/modules/custom/baas_entity/tests/src/Unit/ \
  > /dev/null 2>&1

if [ -f "$REPORTS_DIR/testdox.html" ]; then
  echo "   ✓ TestDox HTML报告: $REPORTS_DIR/testdox.html"
else
  echo "   ✗ TestDox HTML报告生成失败"
fi

# 3. 生成TestDox文本报告
echo "3. 生成TestDox文本报告..."
vendor/bin/phpunit \
  --testdox-text "$REPORTS_DIR/testdox.txt" \
  web/modules/custom/baas_entity/tests/src/Unit/ \
  > /dev/null 2>&1

if [ -f "$REPORTS_DIR/testdox.txt" ]; then
  echo "   ✓ TestDox文本报告: $REPORTS_DIR/testdox.txt"
else
  echo "   ✗ TestDox文本报告生成失败"
fi

# 4. 生成详细的测试执行日志
echo "4. 生成详细执行日志..."
vendor/bin/phpunit \
  --debug \
  web/modules/custom/baas_entity/tests/src/Unit/ \
  > "$REPORTS_DIR/debug.log" 2>&1

if [ -f "$REPORTS_DIR/debug.log" ]; then
  echo "   ✓ 详细执行日志: $REPORTS_DIR/debug.log"
else
  echo "   ✗ 详细执行日志生成失败"
fi

# 5. 生成测试列表
echo "5. 生成测试列表..."
vendor/bin/phpunit \
  --list-tests \
  web/modules/custom/baas_entity/tests/src/Unit/ \
  > "$REPORTS_DIR/test_list.txt" 2>&1

if [ -f "$REPORTS_DIR/test_list.txt" ]; then
  echo "   ✓ 测试列表: $REPORTS_DIR/test_list.txt"
else
  echo "   ✗ 测试列表生成失败"
fi

# 6. 生成简洁的测试摘要
echo "6. 生成测试摘要..."
{
  echo "=== BaaS Entity 模块测试摘要 ==="
  echo "生成时间: $(date)"
  echo "PHP版本: $(php -r 'echo PHP_VERSION;')"
  echo "PHPUnit版本: $(vendor/bin/phpunit --version | head -1)"
  echo ""

  echo "=== 测试统计 ==="
  if [ -f "$REPORTS_DIR/test_list.txt" ]; then
    TOTAL_TESTS=$(grep -c "^ - " "$REPORTS_DIR/test_list.txt" 2>/dev/null || echo "0")
    echo "总测试数: $TOTAL_TESTS"

    # 按测试类分组统计
    echo ""
    echo "=== 按测试类分组 ==="
    grep "^ - " "$REPORTS_DIR/test_list.txt" | sed 's/^ - //' | cut -d':' -f1 | sort | uniq -c | while read count class; do
      echo "  $class: $count 个测试"
    done
  fi

  echo ""
  echo "=== 测试执行结果 ==="
  # 运行测试并获取结果
  if vendor/bin/phpunit web/modules/custom/baas_entity/tests/src/Unit/ 2>&1 | grep -E "(OK|FAILURES|ERRORS)" | tail -1; then
    echo "测试执行完成"
  else
    echo "测试执行状态未知"
  fi

} > "$REPORTS_DIR/summary.txt"

echo "   ✓ 测试摘要: $REPORTS_DIR/summary.txt"

echo ""
echo "=== 报告文件列表 ==="
ls -la "$REPORTS_DIR"

echo ""
echo "=== 快速查看测试摘要 ==="
if [ -f "$REPORTS_DIR/summary.txt" ]; then
  cat "$REPORTS_DIR/summary.txt"
fi

echo ""
echo "=== 查看TestDox报告 ==="
if [ -f "$REPORTS_DIR/testdox.txt" ]; then
  echo "TestDox格式的测试描述:"
  cat "$REPORTS_DIR/testdox.txt"
fi

echo ""
echo "🎉 测试报告生成完成！"
echo ""
echo "📁 报告文件位置: $REPORTS_DIR"
echo "📊 主要报告文件:"
echo "   - summary.txt     : 测试摘要"
echo "   - testdox.html    : HTML格式的可读报告"
echo "   - testdox.txt     : 文本格式的可读报告"
echo "   - junit.xml       : JUnit XML格式（CI/CD用）"
echo "   - debug.log       : 详细执行日志"
echo "   - test_list.txt   : 所有测试列表"
