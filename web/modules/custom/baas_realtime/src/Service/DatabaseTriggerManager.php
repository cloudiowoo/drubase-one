<?php

declare(strict_types=1);

namespace Drupal\baas_realtime\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * PostgreSQL 触发器管理服务。
 */
class DatabaseTriggerManager
{

  /**
   * Logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('baas_realtime');
  }

  /**
   * 创建实时通知触发器函数。
   *
   * @return bool
   *   创建成功返回 TRUE，失败返回 FALSE。
   */
  public function createRealtimeTriggerFunction(): bool {
    try {
      // 方法1: 尝试使用原生 PostgreSQL 连接（如果可用）
      if ($this->tryCreateWithNativeConnection()) {
        return TRUE;
      }

      // 方法2: 尝试分段创建简化版本
      if ($this->tryCreateSimplifiedFunction()) {
        return TRUE;
      }

      // 方法3: 记录手动创建指令
      $this->logManualCreationInstructions();
      return FALSE;

    } catch (\Exception $e) {
      $this->logger->error('Failed to create realtime trigger function: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 尝试使用原生连接创建函数。
   */
  protected function tryCreateWithNativeConnection(): bool {
    try {
      // 获取数据库配置
      $db_config = $this->database->getConnectionOptions();
      $host = $db_config['host'] ?? 'localhost';
      $port = $db_config['port'] ?? 5432;
      $database = $db_config['database'];
      $username = $db_config['username'];
      $password = $db_config['password'] ?? '';
      
      // 尝试使用原生 PDO PostgreSQL 连接
      $dsn = "pgsql:host={$host};port={$port};dbname={$database}";
      $pdo = new \PDO($dsn, $username, $password, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
      ]);
      
      // 先删除现有函数
      $pdo->exec('DROP FUNCTION IF EXISTS notify_realtime_change() CASCADE');
      
      // 创建简化但功能完整的触发器函数
      $function_sql = "CREATE OR REPLACE FUNCTION notify_realtime_change() RETURNS TRIGGER AS \$func\$ BEGIN PERFORM pg_notify('realtime_changes', json_build_object('table', TG_TABLE_NAME, 'type', TG_OP, 'timestamp', extract(epoch from now()), 'record', CASE WHEN TG_OP = 'DELETE' THEN row_to_json(OLD) ELSE row_to_json(NEW) END)::text); RETURN COALESCE(NEW, OLD); END; \$func\$ LANGUAGE plpgsql";
      
      $pdo->exec($function_sql);
      
      $this->logger->info('Created realtime trigger function via native PDO connection');
      return TRUE;
      
    } catch (\Exception $e) {
      $this->logger->debug('Native PDO connection method failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 尝试创建简化版本的函数。
   */
  protected function tryCreateSimplifiedFunction(): bool {
    try {
      // 删除现有函数
      $this->database->query('DROP FUNCTION IF EXISTS notify_realtime_change() CASCADE');
      
      // 创建最简化的触发器函数（单行，无分号）
      $simple_function = "CREATE OR REPLACE FUNCTION notify_realtime_change() RETURNS TRIGGER AS \$func\$ BEGIN PERFORM pg_notify('realtime_changes', json_build_object('table', TG_TABLE_NAME, 'type', TG_OP, 'timestamp', extract(epoch from now()), 'record', CASE WHEN TG_OP = 'DELETE' THEN row_to_json(OLD) ELSE row_to_json(NEW) END)::text); RETURN COALESCE(NEW, OLD); END \$func\$ LANGUAGE plpgsql";
      
      $this->database->query($simple_function);
      
      $this->logger->info('Created simplified realtime trigger function');
      return TRUE;
      
    } catch (\Exception $e) {
      $this->logger->debug('Simplified function creation failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 记录手动创建指令。
   */
  protected function logManualCreationInstructions(): void {
    $manual_command = 'docker exec pg17 psql -U postgres -d db_drubase -c "CREATE OR REPLACE FUNCTION notify_realtime_change() RETURNS TRIGGER AS \$\$ BEGIN PERFORM pg_notify(\'realtime_changes\', json_build_object(\'table\', TG_TABLE_NAME, \'type\', TG_OP, \'timestamp\', extract(epoch from now()), \'record\', CASE WHEN TG_OP = \'DELETE\' THEN row_to_json(OLD) ELSE row_to_json(NEW) END)::text); RETURN COALESCE(NEW, OLD); END; \$\$ LANGUAGE plpgsql;"';
    
    $this->logger->warning('Automatic trigger function creation failed. Manual creation required.');
    $this->logger->info('To manually create the function, run: @command', [
      '@command' => $manual_command,
    ]);
    
    // 也记录到 Drupal 消息系统
    if (\Drupal::hasService('messenger')) {
      \Drupal::messenger()->addWarning('BaaS Realtime: PostgreSQL trigger function needs to be created manually.');
      \Drupal::messenger()->addMessage('Run this command: ' . $manual_command, 'status', FALSE);
    }
  }

  /**
   * 检查触发器函数是否存在。
   */
  public function triggerFunctionExists(): bool {
    try {
      $result = $this->database->query("SELECT proname FROM pg_proc WHERE proname = 'notify_realtime_change'")->fetchField();
      return !empty($result);
    } catch (\Exception $e) {
      $this->logger->error('Failed to check trigger function existence: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 删除触发器函数。
   */
  public function dropRealtimeTriggerFunction(): bool {
    try {
      $this->database->query('DROP FUNCTION IF EXISTS notify_realtime_change() CASCADE');
      $this->logger->info('Dropped realtime trigger function');
      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('Failed to drop trigger function: @error', [
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * 获取完整的触发器函数 SQL。
   */
  protected function getCompleteTriggerFunctionSQL(): string {
    return "
      CREATE OR REPLACE FUNCTION notify_realtime_change()
      RETURNS TRIGGER AS \$trigger_func\$
      DECLARE
          channel_name TEXT;
          payload JSONB;
          table_project_id TEXT;
          table_tenant_id TEXT;
      BEGIN
          -- 提取租户ID和项目ID
          IF TG_TABLE_NAME ~ '^baas_[a-f0-9]{6}_' THEN
              table_project_id := regexp_replace(TG_TABLE_NAME, '^baas_[a-f0-9]{6}_(.*)\$', '\\1');
              table_tenant_id := regexp_replace(TG_TABLE_NAME, '^baas_([a-f0-9]{6})_.*\$', '\\1');
          ELSE
              table_project_id := 'global';
              table_tenant_id := 'global';  
          END IF;
          
          -- 构建频道名
          channel_name := 'realtime:' || table_project_id || ':' || TG_TABLE_NAME;
          
          -- 构建payload
          payload := jsonb_build_object(
              'table', TG_TABLE_NAME,
              'type', TG_OP,
              'project_id', table_project_id,
              'tenant_id', table_tenant_id,
              'timestamp', extract(epoch from now())
          );
          
          -- 添加数据内容
          IF TG_OP = 'DELETE' THEN
              payload := payload || jsonb_build_object('old_record', row_to_json(OLD));
          ELSIF TG_OP = 'INSERT' THEN
              payload := payload || jsonb_build_object('record', row_to_json(NEW));
          ELSIF TG_OP = 'UPDATE' THEN
              payload := payload || jsonb_build_object(
                  'record', row_to_json(NEW),
                  'old_record', row_to_json(OLD)
              );
          END IF;
          
          -- 发送通知
          PERFORM pg_notify('realtime_changes', payload::text);
          
          RETURN COALESCE(NEW, OLD);
      END;
      \$trigger_func\$ LANGUAGE plpgsql
    ";
  }

}