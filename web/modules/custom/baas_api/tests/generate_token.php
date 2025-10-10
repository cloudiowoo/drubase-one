<?php

/**
 * @file
 * 用于生成API令牌的测试脚本
 */

use Drupal\Core\Datetime\DrupalDateTime;

// 检查是否在命令行环境运行
if (PHP_SAPI !== 'cli') {
  echo "此脚本只能在命令行环境中运行\n";
  exit(1);
}

// 从Drush $extra变量获取参数
if (!isset($extra) || empty($extra)) {
  $this->output()->writeln("用法: drush php:script generate_token.php tenant_id [token_name]");
  $this->output()->writeln("示例: drush php:script generate_token.php test1_tenant \"测试令牌\"");
  exit(1);
}

$tenant_id = $extra[0];
$token_name = isset($extra[1]) ? $extra[1] : 'API测试令牌';

// 获取所需服务
$token_manager = \Drupal::service('baas_api.token_manager');
$tenant_manager = \Drupal::service('baas_tenant.manager');

// 验证租户是否存在
$tenant = $tenant_manager->getTenant($tenant_id);
if (!$tenant) {
  $this->output()->writeln("错误: 租户 '{$tenant_id}' 不存在");
  exit(1);
}

// 生成永久API令牌（不过期）
$scopes = ['*']; // 设置为所有权限
$expires = 0;    // 设置为永不过期

try {
  // 创建令牌
  $token_data = $token_manager->generateToken($tenant_id, $scopes, $expires, $token_name);

  if ($token_data) {
    // 格式化输出
    $this->output()->writeln("API令牌已成功生成!");
    $this->output()->writeln("===============================");
    $this->output()->writeln("租户ID: {$tenant_id}");
    $this->output()->writeln("令牌名称: {$token_data['name']}");
    $this->output()->writeln("令牌值: {$token_data['token']}");
    $this->output()->writeln("权限范围: " . implode(', ', json_decode($token_data['scopes'], true)));
    $this->output()->writeln("创建时间: " . date('Y-m-d H:i:s', $token_data['created']));
    $this->output()->writeln("过期时间: " . ($token_data['expires'] ? date('Y-m-d H:i:s', $token_data['expires']) : '永不过期'));
    $this->output()->writeln("===============================");
    $this->output()->writeln("\n使用示例:");
    $this->output()->writeln("curl -H \"Authorization: Bearer {$token_data['token']}\" http://localhost/api/v1/{$tenant_id}/templates");
  } else {
    $this->output()->writeln("错误: 令牌创建失败");
    exit(1);
  }
} catch (\Exception $e) {
  $this->output()->writeln("错误: " . $e->getMessage());
  exit(1);
}
