<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\baas_functions\Exception\FunctionException;

/**
 * Version Manager - Manages function versions and history.
 */
class VersionManager {

  protected readonly \Drupal\Core\Logger\LoggerChannelInterface $logger;

  public function __construct(
    protected readonly Connection $database,
    protected readonly ProjectFunctionManager $functionManager,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $this->loggerFactory->get('baas_functions_version');
  }

  /**
   * Gets all versions for a function.
   *
   * @param string $function_id
   *   The function ID.
   *
   * @return array
   *   Array of function versions.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  public function getFunctionVersions(string $function_id): array {
    // Verify function exists
    $this->functionManager->getFunctionById($function_id);

    $query = $this->database->select('baas_project_function_versions', 'v')
      ->fields('v')
      ->condition('v.function_id', $function_id)
      ->orderBy('v.version', 'DESC');

    $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    // Decode JSON config field
    foreach ($results as &$version) {
      $version['config'] = json_decode($version['config'] ?? '{}', TRUE);
    }

    return $results;
  }

  /**
   * Gets a specific version of a function.
   *
   * @param string $function_id
   *   The function ID.
   * @param int $version_number
   *   The version number.
   *
   * @return array
   *   The version data.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  public function getFunctionVersion(string $function_id, int $version_number): array {
    $query = $this->database->select('baas_project_function_versions', 'v')
      ->fields('v')
      ->condition('v.function_id', $function_id)
      ->condition('v.version', $version_number);

    $result = $query->execute()->fetchAssoc();

    if (!$result) {
      throw FunctionException::functionNotFound("Version {$version_number} of function {$function_id}");
    }

    $result['config'] = json_decode($result['config'] ?? '{}', TRUE);
    return $result;
  }

  /**
   * Creates a new version of a function.
   *
   * @param string $function_id
   *   The function ID.
   * @param string $code
   *   The function code.
   * @param array $config
   *   The function configuration.
   * @param int $deployed_by
   *   User ID who deployed this version.
   *
   * @return array
   *   The created version data.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  public function createVersion(string $function_id, string $code, array $config, int $deployed_by): array {
    $function = $this->functionManager->getFunctionById($function_id);
    $new_version = $function['version'] + 1;

    $version_id = $function_id . '_v' . $new_version;
    $current_time = \Drupal::time()->getCurrentTime();

    $version_data = [
      'id' => $version_id,
      'function_id' => $function_id,
      'version' => $new_version,
      'code' => $code,
      'config' => json_encode($config),
      'deployed_at' => $current_time,
      'deployed_by' => $deployed_by,
    ];

    $transaction = $this->database->startTransaction();
    try {
      // Insert new version
      $this->database->insert('baas_project_function_versions')
        ->fields($version_data)
        ->execute();

      // Update main function record
      $this->database->update('baas_project_functions')
        ->fields([
          'version' => $new_version,
          'code' => $code,
          'config' => json_encode($config),
          'updated_at' => $current_time,
          'last_deployed_at' => $current_time,
        ])
        ->condition('id', $function_id)
        ->execute();

      $this->logger->info('Function version created', [
        'function_id' => $function_id,
        'version' => $new_version,
        'deployed_by' => $deployed_by,
      ]);

      $version_data['config'] = $config;
      return $version_data;
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->logger->error('Failed to create function version', [
        'function_id' => $function_id,
        'version' => $new_version,
        'error' => $e->getMessage(),
      ]);
      throw FunctionException::creationFailed($e->getMessage());
    }
  }

  /**
   * Rolls back a function to a previous version.
   *
   * @param string $function_id
   *   The function ID.
   * @param int $target_version
   *   The version to rollback to.
   * @param int $rolled_back_by
   *   User ID performing the rollback.
   *
   * @return array
   *   The updated function data.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  public function rollbackFunction(string $function_id, int $target_version, int $rolled_back_by): array {
    // Get the target version data
    $version_data = $this->getFunctionVersion($function_id, $target_version);
    
    // Get current function data
    $function = $this->functionManager->getFunctionById($function_id);
    $current_version = $function['version'];

    if ($target_version >= $current_version) {
      throw FunctionException::invalidInput("Cannot rollback to version {$target_version} - current version is {$current_version}");
    }

    $transaction = $this->database->startTransaction();
    try {
      // Create a new version entry for the rollback
      $new_version = $current_version + 1;
      $rollback_version_id = $function_id . '_v' . $new_version;
      $current_time = \Drupal::time()->getCurrentTime();

      $this->database->insert('baas_project_function_versions')
        ->fields([
          'id' => $rollback_version_id,
          'function_id' => $function_id,
          'version' => $new_version,
          'code' => $version_data['code'],
          'config' => json_encode($version_data['config']),
          'deployed_at' => $current_time,
          'deployed_by' => $rolled_back_by,
        ])
        ->execute();

      // Update main function record
      $this->database->update('baas_project_functions')
        ->fields([
          'version' => $new_version,
          'code' => $version_data['code'],
          'config' => json_encode($version_data['config']),
          'updated_at' => $current_time,
          'last_deployed_at' => $current_time,
        ])
        ->condition('id', $function_id)
        ->execute();

      $this->logger->info('Function rolled back', [
        'function_id' => $function_id,
        'from_version' => $current_version,
        'to_version' => $target_version,
        'new_version' => $new_version,
        'rolled_back_by' => $rolled_back_by,
      ]);

      return $this->functionManager->getFunctionById($function_id);
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->logger->error('Failed to rollback function', [
        'function_id' => $function_id,
        'target_version' => $target_version,
        'error' => $e->getMessage(),
      ]);
      throw FunctionException::updateFailed($e->getMessage());
    }
  }

  /**
   * Compares two function versions.
   *
   * @param string $function_id
   *   The function ID.
   * @param int $version1
   *   First version to compare.
   * @param int $version2
   *   Second version to compare.
   *
   * @return array
   *   Comparison result with differences.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  public function compareVersions(string $function_id, int $version1, int $version2): array {
    $v1_data = $this->getFunctionVersion($function_id, $version1);
    $v2_data = $this->getFunctionVersion($function_id, $version2);

    $comparison = [
      'version1' => $version1,
      'version2' => $version2,
      'differences' => [],
      'code_diff' => $this->calculateCodeDiff($v1_data['code'], $v2_data['code']),
      'config_diff' => $this->calculateConfigDiff($v1_data['config'], $v2_data['config']),
    ];

    // Check for differences in key fields
    $fields_to_compare = ['code', 'config', 'deployed_at', 'deployed_by'];
    foreach ($fields_to_compare as $field) {
      if ($v1_data[$field] !== $v2_data[$field]) {
        $comparison['differences'][$field] = [
          'version1_value' => $v1_data[$field],
          'version2_value' => $v2_data[$field],
        ];
      }
    }

    return $comparison;
  }

  /**
   * Gets version statistics for a function.
   *
   * @param string $function_id
   *   The function ID.
   *
   * @return array
   *   Version statistics.
   */
  public function getVersionStatistics(string $function_id): array {
    $query = $this->database->select('baas_project_function_versions', 'v')
      ->condition('v.function_id', $function_id);

    $query->addExpression('COUNT(*)', 'total_versions');
    $query->addExpression('MIN(deployed_at)', 'first_deployed');
    $query->addExpression('MAX(deployed_at)', 'last_deployed');

    $stats = $query->execute()->fetchAssoc();

    // Get deployment frequency (versions per day)
    $time_span = $stats['last_deployed'] - $stats['first_deployed'];
    $days = max(1, $time_span / 86400); // Convert to days
    $deployment_frequency = $stats['total_versions'] / $days;

    // Get recent activity (versions in last 30 days)
    $thirty_days_ago = \Drupal::time()->getCurrentTime() - (30 * 86400);
    $recent_versions = $this->database->select('baas_project_function_versions', 'v')
      ->condition('v.function_id', $function_id)
      ->condition('v.deployed_at', $thirty_days_ago, '>=')
      ->countQuery()
      ->execute()
      ->fetchField();

    return [
      'total_versions' => (int) $stats['total_versions'],
      'first_deployed' => (int) $stats['first_deployed'],
      'last_deployed' => (int) $stats['last_deployed'],
      'deployment_frequency_per_day' => round($deployment_frequency, 2),
      'recent_versions_30_days' => (int) $recent_versions,
      'time_span_days' => round($days, 1),
    ];
  }

  /**
   * Deletes old versions beyond retention limit.
   *
   * @param string $function_id
   *   The function ID.
   * @param int $keep_versions
   *   Number of versions to keep.
   *
   * @return int
   *   Number of versions deleted.
   */
  public function cleanupOldVersions(string $function_id, int $keep_versions = 10): int {
    // Get versions ordered by version number (newest first)
    $query = $this->database->select('baas_project_function_versions', 'v')
      ->fields('v', ['id', 'version'])
      ->condition('v.function_id', $function_id)
      ->orderBy('v.version', 'DESC')
      ->range($keep_versions, PHP_INT_MAX); // Skip the first N versions

    $versions_to_delete = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($versions_to_delete)) {
      return 0;
    }

    $ids_to_delete = array_column($versions_to_delete, 'id');
    $deleted_count = $this->database->delete('baas_project_function_versions')
      ->condition('id', $ids_to_delete, 'IN')
      ->execute();

    $this->logger->info('Cleaned up old function versions', [
      'function_id' => $function_id,
      'deleted_count' => $deleted_count,
      'kept_versions' => $keep_versions,
    ]);

    return $deleted_count;
  }

  /**
   * Calculates code differences between two versions.
   *
   * @param string $code1
   *   First code version.
   * @param string $code2
   *   Second code version.
   *
   * @return array
   *   Code difference information.
   */
  protected function calculateCodeDiff(string $code1, string $code2): array {
    $lines1 = explode("\n", $code1);
    $lines2 = explode("\n", $code2);

    $diff = [
      'lines_added' => count($lines2) - count($lines1),
      'total_changes' => 0,
      'has_changes' => $code1 !== $code2,
    ];

    // Simple line-by-line comparison
    $max_lines = max(count($lines1), count($lines2));
    for ($i = 0; $i < $max_lines; $i++) {
      $line1 = $lines1[$i] ?? '';
      $line2 = $lines2[$i] ?? '';
      
      if ($line1 !== $line2) {
        $diff['total_changes']++;
      }
    }

    return $diff;
  }

  /**
   * Calculates configuration differences between two versions.
   *
   * @param array $config1
   *   First configuration.
   * @param array $config2
   *   Second configuration.
   *
   * @return array
   *   Configuration difference information.
   */
  protected function calculateConfigDiff(array $config1, array $config2): array {
    $diff = [
      'has_changes' => $config1 !== $config2,
      'changed_keys' => [],
      'added_keys' => [],
      'removed_keys' => [],
    ];

    $all_keys = array_unique(array_merge(array_keys($config1), array_keys($config2)));

    foreach ($all_keys as $key) {
      if (!isset($config1[$key])) {
        $diff['added_keys'][] = $key;
      }
      elseif (!isset($config2[$key])) {
        $diff['removed_keys'][] = $key;
      }
      elseif ($config1[$key] !== $config2[$key]) {
        $diff['changed_keys'][] = $key;
      }
    }

    return $diff;
  }

}