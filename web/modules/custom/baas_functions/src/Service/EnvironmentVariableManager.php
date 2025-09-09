<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Service;

use Drupal\Core\Database\Connection;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\baas_functions\Exception\FunctionException;

/**
 * Environment Variable Manager - Manages project-level environment variables.
 */
class EnvironmentVariableManager {

  protected readonly \Drupal\Core\Logger\LoggerChannelInterface $logger;

  public function __construct(
    protected readonly Connection $database,
    protected readonly UnifiedPermissionCheckerInterface $permissionChecker,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $this->loggerFactory->get('baas_functions_env');
  }

  /**
   * Gets all environment variables for a project.
   *
   * @param string $project_id
   *   The project ID.
   * @param bool $include_values
   *   Whether to include decrypted values.
   *
   * @return array
   *   Array of environment variables.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  public function getProjectEnvVars(string $project_id, bool $include_values = FALSE): array {
    $current_user_id = (int) \Drupal::currentUser()->id();
    if (!$this->permissionChecker->checkProjectPermission($current_user_id, $project_id, 'view project env vars')) {
      throw FunctionException::accessDenied('Insufficient permissions to view environment variables');
    }

    $query = $this->database->select('baas_project_function_env_vars', 'e')
      ->fields('e')
      ->condition('e.project_id', $project_id)
      ->orderBy('e.var_name');

    $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    foreach ($results as &$env_var) {
      if ($include_values) {
        $env_var['value'] = $this->decryptValue($env_var['encrypted_value']);
      }
      else {
        $env_var['value'] = '***';
      }
      unset($env_var['encrypted_value']);
    }

    return $results;
  }

  /**
   * Gets a specific environment variable.
   *
   * @param string $project_id
   *   The project ID.
   * @param string $var_name
   *   The variable name.
   * @param bool $include_value
   *   Whether to include the decrypted value.
   *
   * @return array
   *   The environment variable data.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  public function getEnvVar(string $project_id, string $var_name, bool $include_value = FALSE): array {
    $current_user_id = (int) \Drupal::currentUser()->id();
    if (!$this->permissionChecker->checkProjectPermission($current_user_id, $project_id, 'view project env vars')) {
      throw FunctionException::accessDenied('Insufficient permissions to view environment variables');
    }

    $query = $this->database->select('baas_project_function_env_vars', 'e')
      ->fields('e')
      ->condition('e.project_id', $project_id)
      ->condition('e.var_name', $var_name);

    $result = $query->execute()->fetchAssoc();

    if (!$result) {
      throw FunctionException::functionNotFound("Environment variable '{$var_name}' not found");
    }

    if ($include_value) {
      $result['value'] = $this->decryptValue($result['encrypted_value']);
    }
    else {
      $result['value'] = '***';
    }
    unset($result['encrypted_value']);

    return $result;
  }

  /**
   * Creates a new environment variable.
   *
   * @param string $project_id
   *   The project ID.
   * @param string $var_name
   *   The variable name.
   * @param string $value
   *   The variable value.
   * @param string $description
   *   Optional description.
   * @param int $created_by
   *   User ID creating the variable.
   *
   * @return array
   *   The created environment variable data.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  public function createEnvVar(string $project_id, string $var_name, string $value, string $description, int $created_by): array {
    if (!$this->permissionChecker->checkProjectPermission($created_by, $project_id, 'manage project env vars')) {
      throw FunctionException::accessDenied('Insufficient permissions to create environment variables');
    }

    // Validate variable name
    if (!$this->isValidVarName($var_name)) {
      throw FunctionException::invalidInput('Environment variable name must contain only letters, numbers, and underscores');
    }

    // Check if variable already exists
    if ($this->envVarExists($project_id, $var_name)) {
      throw FunctionException::functionExists("Environment variable '{$var_name}' already exists");
    }

    $env_var_id = $this->generateEnvVarId($project_id, $var_name);
    $current_time = \Drupal::time()->getCurrentTime();

    $env_var_data = [
      'id' => $env_var_id,
      'project_id' => $project_id,
      'var_name' => $var_name,
      'encrypted_value' => $this->encryptValue($value),
      'description' => $description,
      'created_by' => $created_by,
      'created_at' => $current_time,
      'updated_at' => $current_time,
    ];

    try {
      $this->database->insert('baas_project_function_env_vars')
        ->fields($env_var_data)
        ->execute();

      $this->logger->info('Environment variable created', [
        'project_id' => $project_id,
        'var_name' => $var_name,
        'created_by' => $created_by,
      ]);

      // Return data without encrypted value
      $env_var_data['value'] = '***';
      unset($env_var_data['encrypted_value']);
      return $env_var_data;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create environment variable', [
        'project_id' => $project_id,
        'var_name' => $var_name,
        'error' => $e->getMessage(),
      ]);
      throw FunctionException::creationFailed($e->getMessage());
    }
  }

  /**
   * Updates an environment variable.
   *
   * @param string $project_id
   *   The project ID.
   * @param string $var_name
   *   The variable name.
   * @param array $update_data
   *   Data to update.
   *
   * @return array
   *   The updated environment variable data.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  public function updateEnvVar(string $project_id, string $var_name, array $update_data): array {
    $current_user_id = (int) \Drupal::currentUser()->id();
    if (!$this->permissionChecker->checkProjectPermission($current_user_id, $project_id, 'manage project env vars')) {
      throw FunctionException::accessDenied('Insufficient permissions to update environment variables');
    }

    // Verify the variable exists
    $existing = $this->getEnvVar($project_id, $var_name);

    $update_fields = ['updated_at' => \Drupal::time()->getCurrentTime()];

    if (isset($update_data['value'])) {
      $update_fields['encrypted_value'] = $this->encryptValue($update_data['value']);
    }

    if (isset($update_data['description'])) {
      $update_fields['description'] = $update_data['description'];
    }

    try {
      $this->database->update('baas_project_function_env_vars')
        ->fields($update_fields)
        ->condition('project_id', $project_id)
        ->condition('var_name', $var_name)
        ->execute();

      $this->logger->info('Environment variable updated', [
        'project_id' => $project_id,
        'var_name' => $var_name,
      ]);

      return $this->getEnvVar($project_id, $var_name);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to update environment variable', [
        'project_id' => $project_id,
        'var_name' => $var_name,
        'error' => $e->getMessage(),
      ]);
      throw FunctionException::updateFailed($e->getMessage());
    }
  }

  /**
   * Deletes an environment variable.
   *
   * @param string $project_id
   *   The project ID.
   * @param string $var_name
   *   The variable name.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  public function deleteEnvVar(string $project_id, string $var_name): void {
    $current_user_id = (int) \Drupal::currentUser()->id();
    if (!$this->permissionChecker->checkProjectPermission($current_user_id, $project_id, 'manage project env vars')) {
      throw FunctionException::accessDenied('Insufficient permissions to delete environment variables');
    }

    // Verify the variable exists
    $this->getEnvVar($project_id, $var_name);

    try {
      $deleted = $this->database->delete('baas_project_function_env_vars')
        ->condition('project_id', $project_id)
        ->condition('var_name', $var_name)
        ->execute();

      if ($deleted === 0) {
        throw FunctionException::functionNotFound("Environment variable '{$var_name}' not found");
      }

      $this->logger->info('Environment variable deleted', [
        'project_id' => $project_id,
        'var_name' => $var_name,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to delete environment variable', [
        'project_id' => $project_id,
        'var_name' => $var_name,
        'error' => $e->getMessage(),
      ]);
      throw FunctionException::deletionFailed($e->getMessage());
    }
  }

  /**
   * Gets environment variables as key-value pairs for function execution.
   *
   * @param string $project_id
   *   The project ID.
   *
   * @return array
   *   Associative array of environment variables.
   */
  public function getEnvVarsForExecution(string $project_id): array {
    $query = $this->database->select('baas_project_function_env_vars', 'e')
      ->fields('e', ['var_name', 'encrypted_value'])
      ->condition('e.project_id', $project_id);

    $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    $env_vars = [];

    foreach ($results as $row) {
      $env_vars[$row['var_name']] = $this->decryptValue($row['encrypted_value']);
    }

    return $env_vars;
  }

  /**
   * Bulk imports environment variables from array.
   *
   * @param string $project_id
   *   The project ID.
   * @param array $env_vars
   *   Associative array of variable names and values.
   * @param int $created_by
   *   User ID performing the import.
   * @param bool $overwrite
   *   Whether to overwrite existing variables.
   *
   * @return array
   *   Import results with success/failure counts.
   */
  public function importEnvVars(string $project_id, array $env_vars, int $created_by, bool $overwrite = FALSE): array {
    if (!$this->permissionChecker->checkProjectPermission($created_by, $project_id, 'manage project env vars')) {
      throw FunctionException::accessDenied('Insufficient permissions to import environment variables');
    }

    $results = [
      'success_count' => 0,
      'error_count' => 0,
      'skipped_count' => 0,
      'errors' => [],
    ];

    foreach ($env_vars as $var_name => $value) {
      try {
        if (!$this->isValidVarName($var_name)) {
          $results['errors'][] = "Invalid variable name: {$var_name}";
          $results['error_count']++;
          continue;
        }

        $exists = $this->envVarExists($project_id, $var_name);

        if ($exists && !$overwrite) {
          $results['skipped_count']++;
          continue;
        }

        if ($exists) {
          $this->updateEnvVar($project_id, $var_name, ['value' => $value]);
        }
        else {
          $this->createEnvVar($project_id, $var_name, $value, '', $created_by);
        }

        $results['success_count']++;
      }
      catch (\Exception $e) {
        $results['errors'][] = "Error with {$var_name}: " . $e->getMessage();
        $results['error_count']++;
      }
    }

    $this->logger->info('Environment variables imported', [
      'project_id' => $project_id,
      'success_count' => $results['success_count'],
      'error_count' => $results['error_count'],
      'skipped_count' => $results['skipped_count'],
    ]);

    return $results;
  }

  /**
   * Checks if an environment variable exists.
   *
   * @param string $project_id
   *   The project ID.
   * @param string $var_name
   *   The variable name.
   *
   * @return bool
   *   TRUE if variable exists.
   */
  protected function envVarExists(string $project_id, string $var_name): bool {
    $result = $this->database->select('baas_project_function_env_vars', 'e')
      ->fields('e', ['id'])
      ->condition('e.project_id', $project_id)
      ->condition('e.var_name', $var_name)
      ->execute()
      ->fetchField();

    return !empty($result);
  }

  /**
   * Validates environment variable name.
   *
   * @param string $var_name
   *   The variable name to validate.
   *
   * @return bool
   *   TRUE if valid.
   */
  protected function isValidVarName(string $var_name): bool {
    // Allow letters, numbers, and underscores only
    return preg_match('/^[A-Z_][A-Z0-9_]*$/i', $var_name) === 1;
  }

  /**
   * Generates unique environment variable ID.
   *
   * @param string $project_id
   *   The project ID.
   * @param string $var_name
   *   The variable name.
   *
   * @return string
   *   The generated ID.
   */
  protected function generateEnvVarId(string $project_id, string $var_name): string {
    return 'env_' . $project_id . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $var_name) . '_' . uniqid();
  }

  /**
   * Encrypts a value for storage.
   *
   * @param string $value
   *   The value to encrypt.
   *
   * @return string
   *   The encrypted value.
   */
  protected function encryptValue(string $value): string {
    // Simple base64 encoding for now - in production, use proper encryption
    // TODO: Implement proper encryption with Drupal's encryption module
    return base64_encode($value);
  }

  /**
   * Decrypts a stored value.
   *
   * @param string $encrypted_value
   *   The encrypted value.
   *
   * @return string
   *   The decrypted value.
   */
  protected function decryptValue(string $encrypted_value): string {
    // Simple base64 decoding for now - in production, use proper decryption
    // TODO: Implement proper decryption with Drupal's encryption module
    return base64_decode($encrypted_value);
  }

}