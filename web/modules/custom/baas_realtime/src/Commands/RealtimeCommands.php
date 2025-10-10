<?php

declare(strict_types=1);

namespace Drupal\baas_realtime\Commands;

use Drush\Commands\DrushCommands;
use Drush\Attributes as CLI;

/**
 * BaaS Realtime Drush commands.
 */
class RealtimeCommands extends DrushCommands
{

  /**
   * 创建实时触发器函数。
   */
  #[CLI\Command(name: 'baas:realtime:create-triggers', aliases: ['brc-triggers'])]
  #[CLI\Help(description: 'Create PostgreSQL trigger function for realtime notifications')]
  public function createTriggers(): void {
    try {
      /** @var \Drupal\baas_realtime\Service\DatabaseTriggerManager $trigger_manager */
      $trigger_manager = \Drupal::service('baas_realtime.database_trigger_manager');
      
      if ($trigger_manager->createRealtimeTriggerFunction()) {
        $this->output()->writeln('<info>✓ Successfully created notify_realtime_change() function</info>');
      } else {
        $this->output()->writeln('<error>✗ Failed to create trigger function. Check logs for details.</error>');
      }
      
    } catch (\Exception $e) {
      $this->output()->writeln('<error>Error: ' . $e->getMessage() . '</error>');
    }
  }

  /**
   * 检查实时触发器函数状态。
   */
  #[CLI\Command(name: 'baas:realtime:check-triggers', aliases: ['brc-check'])]
  #[CLI\Help(description: 'Check if PostgreSQL trigger function exists')]
  public function checkTriggers(): void {
    try {
      /** @var \Drupal\baas_realtime\Service\DatabaseTriggerManager $trigger_manager */
      $trigger_manager = \Drupal::service('baas_realtime.database_trigger_manager');
      
      if ($trigger_manager->triggerFunctionExists()) {
        $this->output()->writeln('<info>✓ notify_realtime_change() function exists</info>');
      } else {
        $this->output()->writeln('<error>✗ notify_realtime_change() function does not exist</error>');
        $this->output()->writeln('<comment>Run "drush baas:realtime:create-triggers" to create it</comment>');
      }
      
    } catch (\Exception $e) {
      $this->output()->writeln('<error>Error checking function: ' . $e->getMessage() . '</error>');
    }
  }

}