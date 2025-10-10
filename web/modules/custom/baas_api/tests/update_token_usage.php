<?php

/**
 * @file
 * 用于更新API令牌最后使用时间的测试脚本
 */

// 检查是否在命令行环境运行
if (PHP_SAPI !== 'cli') {
  $this->output()->writeln("此脚本只能在命令行环境中运行");
  exit(1);
}

// 从Drush $extra变量获取参数
if (!isset($extra) || empty($extra)) {
  $this->output()->writeln("用法: drush php:script update_token_usage.php [tenant_id|all] [token_hash]");
  $this->output()->writeln("示例: drush php:script update_token_usage.php test1_3d21ec0b");
  $this->output()->writeln("      drush php:script update_token_usage.php all");
  exit(1);
}

$tenant_id = $extra[0];
$token_hash = isset($extra[1]) ? $extra[1] : NULL;

// 获取数据库连接
$database = \Drupal::database();

try {
  // 构建查询
  $query = $database->update('baas_api_tokens')
    ->fields(['last_used' => time()]);

  // 应用过滤条件
  if ($tenant_id !== 'all') {
    $query->condition('tenant_id', $tenant_id);
  }

  if ($token_hash) {
    $query->condition('token_hash', $token_hash);
  }

  // 只更新活跃的令牌
  $query->condition('status', 1);

  // 执行更新
  $count = $query->execute();

  $this->output()->writeln("已更新 {$count} 个API令牌的最后使用时间");

  // 查询更新后的令牌列表
  $select_query = $database->select('baas_api_tokens', 't')
    ->fields('t', ['id', 'tenant_id', 'name', 'created', 'expires', 'last_used', 'status']);

  if ($tenant_id !== 'all') {
    $select_query->condition('tenant_id', $tenant_id);
  }

  if ($token_hash) {
    $select_query->condition('token_hash', $token_hash);
  }

  $tokens = $select_query->execute()->fetchAll(\PDO::FETCH_ASSOC);

  if (empty($tokens)) {
    $this->output()->writeln("未找到符合条件的令牌");
    exit(0);
  }

  // 显示更新后的令牌信息
  $this->output()->writeln("\n更新后的令牌信息:");
  $this->output()->writeln("===============================");

  foreach ($tokens as $token) {
    $this->output()->writeln("ID: {$token['id']}");
    $this->output()->writeln("租户ID: {$token['tenant_id']}");
    $this->output()->writeln("名称: {$token['name']}");
    $this->output()->writeln("创建时间: " . date('Y-m-d H:i:s', $token['created']));
    $this->output()->writeln("过期时间: " . ($token['expires'] ? date('Y-m-d H:i:s', $token['expires']) : '永不过期'));
    $this->output()->writeln("最后使用: " . date('Y-m-d H:i:s', $token['last_used']));
    $this->output()->writeln("状态: " . ($token['status'] ? '活跃' : '已撤销'));
    $this->output()->writeln("-------------------------------");
  }
} catch (\Exception $e) {
  $this->output()->writeln("错误: " . $e->getMessage());
  exit(1);
}
