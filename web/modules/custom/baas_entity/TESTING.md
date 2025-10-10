# BaaS Entity 模块测试指南

## 测试概述

BaaS Entity 模块的测试套件确保字段类型插件系统的可靠性和稳定性。测试覆盖了插件注册、类型管理、值验证、处理和格式化等核心功能。

## 当前测试状态

✅ **单元测试**: 完全实现并通过
- 字段类型插件注册测试
- 可用类型获取测试
- 类型信息获取测试
- 存储Schema测试
- 值验证测试
- 值处理测试
- 值格式化测试
- 错误处理测试

✅ **PHPUnit配置**: 完整配置文件已创建
- 测试套件定义
- 环境变量配置
- 代码覆盖率设置
- 日志和报告配置

⚠️ **内核测试**: 已创建但需要Drupal环境配置
⚠️ **功能测试**: 已创建但需要完整Drupal安装和数据库配置

## 推荐的测试执行策略

### 日常开发测试（推荐）

```bash
# 运行快速单元测试（无需Drupal环境）
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && web/modules/custom/baas_entity/run_unit_tests.sh"
```

### 内核测试（需要数据库配置）

```bash
# 运行内核测试（需要正确的数据库配置）
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && web/modules/custom/baas_entity/run_kernel_tests.sh"
```

### 完整测试套件（需要完整环境）

```bash
# 运行所有测试（需要完整Drupal环境）
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && web/modules/custom/baas_entity/run_tests.sh"
```

## 测试类型详解

### 单元测试 (Unit Tests) ✅
- **目的**: 测试独立的类和方法
- **位置**: `tests/src/Unit/`
- **环境**: 不需要Drupal环境
- **执行速度**: 快速（< 5秒）
- **状态**: 完全可用，推荐日常使用

**包含的测试文件**:
- `FieldTypePluginTest.php` - 核心插件功能测试（8个测试方法）
- `FieldTypeManagerUnitTest.php` - 管理器服务测试（10个测试方法）
- `SimpleUnitTest.php` - 基础功能验证测试（2个测试方法）

**总计**: 3个测试文件，20个测试方法

### 内核测试 (Kernel Tests) ⚠️
- **目的**: 测试服务集成和依赖注入
- **位置**: `tests/src/Kernel/`
- **环境**: 需要Drupal内核和数据库
- **执行速度**: 中等（10-30秒）
- **状态**: 已实现，需要数据库配置

**包含的测试文件**:
- `FieldTypeManagerKernelTest.php` - 服务集成测试

**内核测试要求**:
1. 完整的Drupal环境
2. 正确的数据库连接配置
3. 模块依赖已安装
4. 适当的文件权限

**内核测试故障排除**:

如果内核测试失败，请按以下步骤检查：

1. **检查数据库连接**:
   ```bash
   docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && vendor/bin/drush sql:connect"
   ```

2. **检查模块状态**:
   ```bash
   docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && vendor/bin/drush pm:list | grep baas"
   ```

3. **手动运行内核测试**:
   ```bash
   # 使用SQLite（推荐用于测试）
   docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase/web/core && SIMPLETEST_BASE_URL=http://localhost SIMPLETEST_DB=sqlite://localhost/:memory: ../../vendor/bin/phpunit ../modules/custom/baas_entity/tests/src/Kernel/FieldTypeManagerKernelTest.php"

   # 或使用PostgreSQL（需要正确的密码）
   docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase/web/core && SIMPLETEST_BASE_URL=http://localhost SIMPLETEST_DB='pgsql://postgres:YOUR_PASSWORD@pg17:5432/db_drubase' ../../vendor/bin/phpunit ../modules/custom/baas_entity/tests/src/Kernel/FieldTypeManagerKernelTest.php"
   ```

4. **常见问题解决**:
   - **数据库连接失败**: 检查`web/sites/default/settings.php`中的数据库配置
   - **SQLite版本过低**: 使用PostgreSQL或MySQL数据库
   - **权限问题**: 确保`sites/simpletest/browser_output`目录可写
   - **模块未安装**: 运行`drush en baas_entity`启用模块

### 功能测试 (Functional Tests) ⚠️
- **目的**: 测试完整的用户工作流程
- **位置**: `tests/src/Functional/`
- **环境**: 需要完整Drupal环境
- **执行速度**: 较慢（30-60秒）
- **状态**: 已实现，需要完整环境配置

**包含的测试文件**:
- `FieldTypePluginFunctionalTest.php` - 端到端功能测试

## 环境要求

- Docker容器: `php8-4-fpm-xdebug`
- Drupal版本: 11.x
- PHP版本: 8.4+
- PHPUnit: 10.5+
- 数据库: PostgreSQL 16+ / MySQL 8.0+ / SQLite 3.45+

## 测试配置文件

### phpunit.xml ✅
简化的PHPUnit配置文件，专注于单元测试：
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="../../../../vendor/autoload.php"
         colors="true">
  <testsuites>
    <testsuite name="unit">
      <directory>tests/src/Unit</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

### 环境变量
内核和功能测试需要以下环境变量：
```bash
export SIMPLETEST_BASE_URL="http://localhost"
export SIMPLETEST_DB="sqlite://localhost/:memory:"  # 或其他数据库连接字符串
export BROWSERTEST_OUTPUT_DIRECTORY="sites/simpletest/browser_output"
export SYMFONY_DEPRECATIONS_HELPER="disabled"
```

## 快速开始

### 1. 运行单元测试（推荐用于日常开发）

```bash
# 使用专用脚本
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && web/modules/custom/baas_entity/run_unit_tests.sh"

# 或直接使用PHPUnit
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && vendor/bin/phpunit --testsuite unit web/modules/custom/baas_entity/"
```

### 2. 运行内核测试

```bash
# 使用专用脚本（自动检测数据库配置）
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && web/modules/custom/baas_entity/run_kernel_tests.sh"

# 或手动指定数据库
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase/web/core && SIMPLETEST_BASE_URL=http://localhost SIMPLETEST_DB=sqlite://localhost/:memory: ../../vendor/bin/phpunit ../modules/custom/baas_entity/tests/src/Kernel/"
```

### 3. 运行特定测试文件

```bash
# 运行核心插件测试
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && vendor/bin/phpunit web/modules/custom/baas_entity/tests/src/Unit/FieldTypePluginTest.php"

# 运行管理器测试
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && vendor/bin/phpunit web/modules/custom/baas_entity/tests/src/Unit/FieldTypeManagerUnitTest.php"
```

### 4. 生成测试报告

```bash
# 生成代码覆盖率报告
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && vendor/bin/phpunit --coverage-html web/modules/custom/baas_entity/tests/reports/coverage web/modules/custom/baas_entity/tests/src/Unit/"
```

## 测试文件结构

```
tests/
├── src/
│   ├── Unit/                                 # 单元测试 ✅
│   │   ├── FieldTypePluginTest.php          # 核心插件测试 ✅
│   │   ├── FieldTypeManagerUnitTest.php     # 管理器测试 ✅
│   │   └── SimpleUnitTest.php               # 基础测试 ✅
│   ├── Kernel/                              # 内核测试 ⚠️
│   │   └── FieldTypeManagerKernelTest.php   # 服务集成测试 ⚠️
│   └── Functional/                          # 功能测试 ⚠️
│       └── FieldTypePluginFunctionalTest.php # 端到端测试 ⚠️
└── reports/                                 # 测试报告目录 ✅
    ├── coverage/                            # 代码覆盖率报告
    ├── junit.xml                            # JUnit格式报告
    └── testdox.html                         # 测试文档报告
```

## 测试执行结果示例

### 成功的单元测试执行
```
=== BaaS Entity 模块单元测试 ===
✓ PHPUnit 已安装
✓ 单元测试目录存在
✓ 核心测试文件存在

=== 运行单元测试 ===
PHPUnit 10.5.46 by Sebastian Bergmann and contributors.
Runtime: PHP 8.4.2
.
✓ 单元测试通过

=== 代码质量检查 ===
✓ PHP语法检查通过

=== 测试报告 ===
执行时间: 0秒
环境信息: Docker PHP 8.4, Drupal 11.x
测试结果: PASSED
🎉 所有测试通过！模块可以安全部署。
```

### 内核测试执行（需要环境配置）
```
=== BaaS Entity 模块内核测试 ===
✓ 检查环境...
✓ PHPUnit 已安装
✓ 内核测试目录存在
✓ 检查数据库连接...
✓ 数据库连接正常
✓ 使用PostgreSQL数据库: db_drubase

=== 运行内核测试 ===
数据库: pgsql://postgres:***@pg17:5432/db_drubase
基础URL: http://localhost

开始运行内核测试...
PHPUnit 10.5.46 by Sebastian Bergmann and contributors.
...........                                              11 / 11 (100%)
✓ 内核测试通过

=== 测试报告 ===
测试类型: 内核测试 (Kernel Tests)
测试文件: FieldTypeManagerKernelTest.php
执行时间: Sun Jun  1 15:14:24 UTC 2025
环境信息: Docker PHP 8.4.2, Drupal 11.x
数据库: pgsql
测试结果: PASSED
🎉 内核测试通过！服务集成正常工作。
```

## 编写新测试

### 单元测试示例

```php
<?php

namespace Drupal\Tests\baas_entity\Unit;

use PHPUnit\Framework\TestCase;

class MyNewTest extends TestCase {

  protected function setUp(): void {
    parent::setUp();
    // 手动加载必要的类
    require_once __DIR__ . '/../../../src/MyClass.php';
  }

  public function testMyFunction(): void {
    $result = MyClass::myFunction();
    $this->assertEquals('expected', $result);
  }
}
```

### 内核测试示例（需要Drupal环境）

```php
<?php

namespace Drupal\Tests\baas_entity\Kernel;

use Drupal\KernelTests\KernelTestBase;

class MyKernelTest extends KernelTestBase {

  protected static $modules = ['baas_entity'];

  public function testServiceIntegration(): void {
    $service = \Drupal::service('baas_entity.field_type_manager');
    $this->assertInstanceOf(FieldTypeManager::class, $service);
  }
}
```

## 最佳实践

### 测试命名
- 测试类: `{ClassName}Test`
- 测试方法: `test{FunctionName}`
- 描述性方法名: `testValidateValueWithEmptyString`

### 测试结构
1. **Arrange**: 设置测试数据
2. **Act**: 执行被测试的操作
3. **Assert**: 验证结果

### 断言使用
- `assertTrue()` / `assertFalse()`: 布尔值
- `assertEquals()` / `assertNotEquals()`: 值比较
- `assertInstanceOf()`: 类型检查
- `assertEmpty()` / `assertNotEmpty()`: 空值检查

### Mock和Stub
- 使用PHPUnit的mock功能模拟依赖
- 避免在单元测试中使用真实的外部服务

## 持续集成

### 何时运行测试
- 每次代码提交前运行单元测试
- Pull Request创建时运行完整测试套件
- 部署到生产环境前运行所有测试

### 覆盖率目标
- 单元测试覆盖率: > 90%
- 关键路径覆盖率: 100%

## 故障排除

### 常见问题

1. **类未找到错误**
   ```
   解决方案: 在setUp()方法中手动require_once相关类文件
   ```

2. **PHPUnit未安装**
   ```bash
   composer require --dev phpunit/phpunit
   ```

3. **Prophecy错误**
   ```bash
   composer require --dev phpspec/prophecy-phpunit
   ```

4. **权限错误**
   ```bash
   chmod +x run_unit_tests.sh
   chmod +x run_kernel_tests.sh
   chmod +x run_tests.sh
   ```

5. **数据库连接错误（内核/功能测试）**
   ```
   确保SIMPLETEST_DB环境变量正确配置
   检查SQLite文件权限
   验证PostgreSQL/MySQL连接信息
   ```

6. **内核测试特定问题**
   ```
   - 检查模块依赖是否已安装
   - 确保数据库用户有创建临时表的权限
   - 验证Drupal bootstrap过程正常
   - 检查sites/simpletest目录权限
   ```

### 调试技巧

1. **使用var_dump()输出调试信息**
2. **运行单个测试方法**:
   ```bash
   vendor/bin/phpunit --filter testMethodName
   ```
3. **查看详细错误信息**: 检查PHPUnit输出
4. **使用Xdebug调试**: 在Docker环境中已配置Xdebug

### 内核测试调试步骤

1. **验证基础环境**:
   ```bash
   docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && vendor/bin/drush status"
   ```

2. **检查模块状态**:
   ```bash
   docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && vendor/bin/drush pm:list | grep baas"
   ```

3. **测试数据库连接**:
   ```bash
   docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && vendor/bin/drush sql:query 'SELECT 1;'"
   ```

4. **使用简化的数据库配置**:
   ```bash
   # 使用内存SQLite（最简单）
   export SIMPLETEST_DB="sqlite://localhost/:memory:"

   # 或使用文件SQLite
   export SIMPLETEST_DB="sqlite://localhost/tmp/test.sqlite"
   ```

## 测试脚本详解

### run_unit_tests.sh ✅
专门运行单元测试的简化脚本，包含：
- 环境检查
- 单元测试执行
- 代码质量检查
- 测试报告生成
- **推荐用于日常开发**

### run_kernel_tests.sh ⚠️
专门运行内核测试的脚本（需要数据库配置）：
- 自动检测数据库配置
- 环境变量设置
- 内核测试执行
- 错误诊断和建议
- **适用于集成测试**

### run_tests.sh ⚠️
完整的测试脚本（需要完整Drupal环境）：
- 所有类型测试（单元、内核、功能）
- 更复杂的环境要求
- 完整的集成测试
- **适用于CI/CD和发布前测试**

## 贡献指南

### 添加新测试

1. 在适当的目录创建测试文件
2. 继承正确的基类
3. 添加必要的setUp()和tearDown()方法
4. 编写描述性的测试方法
5. 运行测试确保通过

### 代码审查清单

- [ ] 测试覆盖新功能
- [ ] 测试方法命名清晰
- [ ] 包含边界情况测试
- [ ] 错误处理测试
- [ ] 性能考虑
- [ ] 文档更新

## 参考资源

- [Drupal测试文档](https://www.drupal.org/docs/automated-testing)
- [PHPUnit文档](https://phpunit.de/documentation.html)
- [Drupal编码标准](https://www.drupal.org/docs/develop/standards)
- [Drupal内核测试指南](https://www.drupal.org/docs/automated-testing/phpunit-in-drupal/kernel-tests)

## 总结

BaaS Entity 模块的测试体系已经**完整建立**：

### ✅ 已完成
- 单元测试套件（完全可用）
- PHPUnit配置文件
- 测试脚本和文档
- 测试报告目录
- 代码质量检查

### ⚠️ 需要环境配置
- 内核测试（需要Drupal内核和数据库）
- 功能测试（需要完整Drupal环境）

### 🎯 推荐使用方式
- **日常开发**: 使用 `run_unit_tests.sh` 进行快速测试
- **集成测试**: 配置数据库后使用 `run_kernel_tests.sh` 进行服务集成测试
- **发布前**: 配置完整环境后使用 `run_tests.sh` 进行全面测试
- **持续集成**: 集成单元测试到CI/CD流程

### 📋 内核测试运行指南

**方法1: 使用自动化脚本（推荐）**
```bash
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase && web/modules/custom/baas_entity/run_kernel_tests.sh"
```

**方法2: 手动运行（使用SQLite）**
```bash
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase/web/core && SIMPLETEST_BASE_URL=http://localhost SIMPLETEST_DB=sqlite://localhost/:memory: ../../vendor/bin/phpunit ../modules/custom/baas_entity/tests/src/Kernel/FieldTypeManagerKernelTest.php"
```

**方法3: 手动运行（使用PostgreSQL）**
```bash
# 需要替换YOUR_PASSWORD为实际密码
docker exec -it php8-4-fpm-xdebug sh -c "cd /var/www/webs/cloudio/drubase/web/core && SIMPLETEST_BASE_URL=http://localhost SIMPLETEST_DB='pgsql://postgres:YOUR_PASSWORD@pg17:5432/db_drubase' ../../vendor/bin/phpunit ../modules/custom/baas_entity/tests/src/Kernel/FieldTypeManagerKernelTest.php"
```

---

**注意**:
- 单元测试可以随时运行，无需特殊配置
- 内核测试需要正确的数据库配置，建议使用SQLite进行测试
- 功能测试需要完整的Drupal环境和浏览器支持
- 所有测试脚本都包含详细的错误诊断和解决建议
