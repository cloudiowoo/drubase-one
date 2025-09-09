<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\baas_functions\Exception\FunctionException;

/**
 * Log Manager - Manages function execution logs.
 */
class LogManager {

  protected readonly \Drupal\Core\Logger\LoggerChannelInterface $logger;

  public function __construct(
    protected readonly Connection $database,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $this->loggerFactory->get('baas_functions_logs');
  }

  /**
   * Gets execution logs for a function.
   *
   * @param string $function_id
   *   The function ID.
   * @param array $filters
   *   Optional filters (status, date range, etc.).
   * @param int $limit
   *   Results limit.
   * @param int $offset
   *   Results offset.
   *
   * @return array
   *   Array of logs with pagination info.
   */
  public function getFunctionLogs(string $function_id, array $filters = [], int $limit = 50, int $offset = 0): array {
    $query = $this->database->select('baas_project_function_logs', 'l')
      ->fields('l')
      ->condition('l.function_id', $function_id)
      ->orderBy('l.created_at', 'DESC')
      ->range($offset, $limit);

    // Apply filters
    if (!empty($filters['status'])) {
      $query->condition('l.status', $filters['status']);
    }

    if (!empty($filters['from_date'])) {
      $from_timestamp = strtotime($filters['from_date']);
      if ($from_timestamp !== FALSE) {
        $query->condition('l.created_at', $from_timestamp, '>=');
      }
    }

    if (!empty($filters['to_date'])) {
      $to_timestamp = strtotime($filters['to_date'] . ' 23:59:59');
      if ($to_timestamp !== FALSE) {
        $query->condition('l.created_at', $to_timestamp, '<=');
      }
    }

    if (!empty($filters['user_id'])) {
      $query->condition('l.user_id', $filters['user_id']);
    }

    if (!empty($filters['execution_time_min'])) {
      $query->condition('l.execution_time_ms', $filters['execution_time_min'], '>=');
    }

    if (!empty($filters['execution_time_max'])) {
      $query->condition('l.execution_time_ms', $filters['execution_time_max'], '<=');
    }

    $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    // Decode JSON fields
    foreach ($results as &$log) {
      $log['input_data'] = json_decode($log['input_data'] ?? '{}', TRUE);
      $log['output_data'] = json_decode($log['output_data'] ?? '{}', TRUE);
      $log['error_stack'] = json_decode($log['error_stack'] ?? 'null', TRUE);
    }

    // Get total count
    $count_query = $this->database->select('baas_project_function_logs', 'l')
      ->condition('l.function_id', $function_id);
    
    // Apply same filters to count query
    if (!empty($filters['status'])) {
      $count_query->condition('l.status', $filters['status']);
    }
    if (!empty($filters['from_date'])) {
      $from_timestamp = strtotime($filters['from_date']);
      if ($from_timestamp !== FALSE) {
        $count_query->condition('l.created_at', $from_timestamp, '>=');
      }
    }
    if (!empty($filters['to_date'])) {
      $to_timestamp = strtotime($filters['to_date'] . ' 23:59:59');
      if ($to_timestamp !== FALSE) {
        $count_query->condition('l.created_at', $to_timestamp, '<=');
      }
    }
    if (!empty($filters['user_id'])) {
      $count_query->condition('l.user_id', $filters['user_id']);
    }

    $total = $count_query->countQuery()->execute()->fetchField();

    return [
      'logs' => $results,
      'pagination' => [
        'total' => (int) $total,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $total,
      ],
    ];
  }

  /**
   * Gets logs for a project (all functions).
   *
   * @param string $project_id
   *   The project ID.
   * @param array $filters
   *   Optional filters.
   * @param int $limit
   *   Results limit.
   * @param int $offset
   *   Results offset.
   *
   * @return array
   *   Array of logs with pagination info.
   */
  public function getProjectLogs(string $project_id, array $filters = [], int $limit = 50, int $offset = 0): array {
    $query = $this->database->select('baas_project_function_logs', 'l')
      ->fields('l')
      ->condition('l.project_id', $project_id)
      ->orderBy('l.created_at', 'DESC')
      ->range($offset, $limit);

    // Join with functions table to get function names
    $query->leftJoin('baas_project_functions', 'f', 'l.function_id = f.id');
    $query->addField('f', 'function_name');
    $query->addField('f', 'display_name', 'function_display_name');

    // Apply filters
    $this->applyLogFilters($query, $filters);

    $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    // Decode JSON fields
    foreach ($results as &$log) {
      $log['input_data'] = json_decode($log['input_data'] ?? '{}', TRUE);
      $log['output_data'] = json_decode($log['output_data'] ?? '{}', TRUE);
      $log['error_stack'] = json_decode($log['error_stack'] ?? 'null', TRUE);
    }

    // Get total count
    $count_query = $this->database->select('baas_project_function_logs', 'l')
      ->condition('l.project_id', $project_id);
    $this->applyLogFilters($count_query, $filters);
    $total = $count_query->countQuery()->execute()->fetchField();

    return [
      'logs' => $results,
      'pagination' => [
        'total' => (int) $total,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $total,
      ],
    ];
  }

  /**
   * Gets a specific log entry.
   *
   * @param string $log_id
   *   The log ID.
   *
   * @return array
   *   The log entry data.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  public function getLogEntry(string $log_id): array {
    $query = $this->database->select('baas_project_function_logs', 'l')
      ->fields('l')
      ->condition('l.id', $log_id);

    // Join with functions table
    $query->leftJoin('baas_project_functions', 'f', 'l.function_id = f.id');
    $query->addField('f', 'function_name');
    $query->addField('f', 'display_name', 'function_display_name');

    $result = $query->execute()->fetchAssoc();

    if (!$result) {
      throw FunctionException::functionNotFound("Log entry {$log_id}");
    }

    // Decode JSON fields
    $result['input_data'] = json_decode($result['input_data'] ?? '{}', TRUE);
    $result['output_data'] = json_decode($result['output_data'] ?? '{}', TRUE);
    $result['error_stack'] = json_decode($result['error_stack'] ?? 'null', TRUE);

    return $result;
  }

  /**
   * Gets log statistics for a function.
   *
   * @param string $function_id
   *   The function ID.
   * @param int $days
   *   Number of days to analyze (default 30).
   *
   * @return array
   *   Log statistics.
   */
  public function getFunctionLogStats(string $function_id, int $days = 30): array {
    $since_timestamp = \Drupal::time()->getCurrentTime() - ($days * 86400);

    // Basic counts
    $query = $this->database->select('baas_project_function_logs', 'l')
      ->condition('l.function_id', $function_id)
      ->condition('l.created_at', $since_timestamp, '>=');

    $query->addExpression('COUNT(*)', 'total_executions');
    $query->addExpression('SUM(CASE WHEN status = \'success\' THEN 1 ELSE 0 END)', 'successful_executions');
    $query->addExpression('SUM(CASE WHEN status = \'error\' THEN 1 ELSE 0 END)', 'failed_executions');
    $query->addExpression('AVG(execution_time_ms)', 'avg_execution_time');
    $query->addExpression('MIN(execution_time_ms)', 'min_execution_time');
    $query->addExpression('MAX(execution_time_ms)', 'max_execution_time');
    $query->addExpression('AVG(memory_used_mb)', 'avg_memory_usage');

    $stats = $query->execute()->fetchAssoc();

    // Calculate success rate
    $total = (int) $stats['total_executions'];
    $successful = (int) $stats['successful_executions'];
    $success_rate = $total > 0 ? ($successful / $total) * 100 : 0;

    // Get hourly distribution for the last 24 hours
    $hourly_stats = $this->getHourlyExecutionStats($function_id, 24);

    // Get error patterns
    $error_patterns = $this->getErrorPatterns($function_id, $days);

    return [
      'period_days' => $days,
      'total_executions' => $total,
      'successful_executions' => $successful,
      'failed_executions' => (int) $stats['failed_executions'],
      'success_rate_percent' => round($success_rate, 2),
      'avg_execution_time_ms' => round((float) $stats['avg_execution_time'], 2),
      'min_execution_time_ms' => (int) $stats['min_execution_time'],
      'max_execution_time_ms' => (int) $stats['max_execution_time'],
      'avg_memory_usage_mb' => round((float) $stats['avg_memory_usage'], 2),
      'hourly_distribution' => $hourly_stats,
      'error_patterns' => $error_patterns,
    ];
  }

  /**
   * Gets project-wide log statistics.
   *
   * @param string $project_id
   *   The project ID.
   * @param int $days
   *   Number of days to analyze.
   *
   * @return array
   *   Project log statistics.
   */
  public function getProjectLogStats(string $project_id, int $days = 30): array {
    $since_timestamp = \Drupal::time()->getCurrentTime() - ($days * 86400);

    // Overall stats
    $query = $this->database->select('baas_project_function_logs', 'l')
      ->condition('l.project_id', $project_id)
      ->condition('l.created_at', $since_timestamp, '>=');

    $query->addExpression('COUNT(*)', 'total_executions');
    $query->addExpression('COUNT(DISTINCT function_id)', 'active_functions');
    $query->addExpression('SUM(CASE WHEN status = \'success\' THEN 1 ELSE 0 END)', 'successful_executions');
    $query->addExpression('AVG(execution_time_ms)', 'avg_execution_time');

    $stats = $query->execute()->fetchAssoc();

    // Per-function breakdown
    $function_stats = $this->database->select('baas_project_function_logs', 'l')
      ->fields('l', ['function_id'])
      ->condition('l.project_id', $project_id)
      ->condition('l.created_at', $since_timestamp, '>=')
      ->groupBy('l.function_id');

    $function_stats->leftJoin('baas_project_functions', 'f', 'l.function_id = f.id');
    $function_stats->addField('f', 'function_name');
    $function_stats->addExpression('COUNT(*)', 'executions');
    $function_stats->addExpression('AVG(l.execution_time_ms)', 'avg_time');

    $function_breakdown = $function_stats->execute()->fetchAll(\PDO::FETCH_ASSOC);

    return [
      'period_days' => $days,
      'total_executions' => (int) $stats['total_executions'],
      'active_functions' => (int) $stats['active_functions'],
      'successful_executions' => (int) $stats['successful_executions'],
      'avg_execution_time_ms' => round((float) $stats['avg_execution_time'], 2),
      'function_breakdown' => $function_breakdown,
    ];
  }

  /**
   * Deletes old log entries.
   *
   * @param int $older_than_days
   *   Delete logs older than this many days.
   * @param int $batch_size
   *   Number of records to delete per batch.
   *
   * @return int
   *   Number of log entries deleted.
   */
  public function cleanupOldLogs(int $older_than_days = 90, int $batch_size = 1000): int {
    $cutoff_timestamp = \Drupal::time()->getCurrentTime() - ($older_than_days * 86400);
    
    $total_deleted = 0;
    
    do {
      $deleted = $this->database->delete('baas_project_function_logs')
        ->condition('created_at', $cutoff_timestamp, '<')
        ->range(0, $batch_size)
        ->execute();
      
      $total_deleted += $deleted;
      
      // Sleep briefly to avoid overwhelming the database
      if ($deleted > 0) {
        usleep(100000); // 100ms
      }
    } while ($deleted > 0);

    if ($total_deleted > 0) {
      $this->logger->info('Cleaned up old function logs', [
        'deleted_count' => $total_deleted,
        'older_than_days' => $older_than_days,
      ]);
    }

    return $total_deleted;
  }

  /**
   * Applies common log filters to a query.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   The database query.
   * @param array $filters
   *   Array of filters to apply.
   */
  protected function applyLogFilters($query, array $filters): void {
    if (!empty($filters['status'])) {
      $query->condition('l.status', $filters['status']);
    }

    if (!empty($filters['from_date'])) {
      $from_timestamp = strtotime($filters['from_date']);
      if ($from_timestamp !== FALSE) {
        $query->condition('l.created_at', $from_timestamp, '>=');
      }
    }

    if (!empty($filters['to_date'])) {
      $to_timestamp = strtotime($filters['to_date'] . ' 23:59:59');
      if ($to_timestamp !== FALSE) {
        $query->condition('l.created_at', $to_timestamp, '<=');
      }
    }

    if (!empty($filters['function_id'])) {
      $query->condition('l.function_id', $filters['function_id']);
    }

    if (!empty($filters['user_id'])) {
      $query->condition('l.user_id', $filters['user_id']);
    }
  }

  /**
   * Gets hourly execution statistics.
   *
   * @param string $function_id
   *   The function ID.
   * @param int $hours
   *   Number of hours to analyze.
   *
   * @return array
   *   Hourly execution counts.
   */
  protected function getHourlyExecutionStats(string $function_id, int $hours): array {
    $since_timestamp = \Drupal::time()->getCurrentTime() - ($hours * 3600);

    $query = $this->database->select('baas_project_function_logs', 'l')
      ->condition('l.function_id', $function_id)
      ->condition('l.created_at', $since_timestamp, '>=');

    // Group by hour
    $query->addExpression('FROM_UNIXTIME(created_at, \'%Y-%m-%d %H:00:00\')', 'hour');
    $query->addExpression('COUNT(*)', 'executions');
    $query->groupBy('hour');
    $query->orderBy('hour');

    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Gets common error patterns.
   *
   * @param string $function_id
   *   The function ID.
   * @param int $days
   *   Number of days to analyze.
   *
   * @return array
   *   Common error patterns.
   */
  protected function getErrorPatterns(string $function_id, int $days): array {
    $since_timestamp = \Drupal::time()->getCurrentTime() - ($days * 86400);

    $query = $this->database->select('baas_project_function_logs', 'l')
      ->fields('l', ['error_message'])
      ->condition('l.function_id', $function_id)
      ->condition('l.status', 'error')
      ->condition('l.created_at', $since_timestamp, '>=')
      ->isNotNull('l.error_message');

    $query->addExpression('COUNT(*)', 'error_count');
    $query->groupBy('l.error_message');
    $query->orderBy('error_count', 'DESC');
    $query->range(0, 10); // Top 10 error patterns

    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

}