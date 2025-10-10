<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Service;

use Drupal\Core\Database\Connection;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\baas_functions\Exception\FunctionException;

/**
 * Project Function Manager - Core business logic for function management.
 */
class ProjectFunctionManager {

  protected readonly \Drupal\Core\Logger\LoggerChannelInterface $logger;

  public function __construct(
    protected readonly Connection $database,
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly UnifiedPermissionCheckerInterface $permissionChecker,
    protected readonly ClientInterface $httpClient,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $this->loggerFactory->get('baas_functions');
  }

  /**
   * Creates a new project function.
   *
   * @param string $project_id
   *   The project ID.
   * @param array $function_data
   *   Function data including name, code, config, etc.
   * @param int $created_by
   *   User ID creating the function.
   *
   * @return array
   *   The created function data.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  public function createFunction(string $project_id, array $function_data, int $created_by): array {
    // Validate project access
    if (!$this->permissionChecker->canAccessProject($created_by, $project_id)) {
      throw FunctionException::accessDenied('Insufficient permissions to create function');
    }

    // Validate required fields
    if (empty($function_data['function_name'])) {
      throw FunctionException::invalidInput('Function name is required');
    }

    if (empty($function_data['code'])) {
      throw FunctionException::invalidInput('Function code is required');
    }

    // Check for function name uniqueness within project
    if ($this->functionExists($project_id, $function_data['function_name'])) {
      throw FunctionException::functionExists($function_data['function_name']);
    }

    $function_id = $this->generateFunctionId($project_id, $function_data['function_name']);
    $current_time = \Drupal::time()->getCurrentTime();

    $function_record = [
      'id' => $function_id,
      'project_id' => $project_id,
      'function_name' => $function_data['function_name'],
      'display_name' => $function_data['display_name'] ?? $function_data['function_name'],
      'description' => $function_data['description'] ?? '',
      'code' => $function_data['code'],
      'config' => json_encode($function_data['config'] ?? []),
      'status' => 'draft',
      'version' => 1,
      'call_count' => 0,
      'success_count' => 0,
      'error_count' => 0,
      'avg_response_time' => 0,
      'created_by' => $created_by,
      'created_at' => $current_time,
      'updated_at' => $current_time,
    ];

    $transaction = $this->database->startTransaction();
    try {
      $this->database->insert('baas_project_functions')
        ->fields($function_record)
        ->execute();

      // Create initial version record
      $this->createFunctionVersion($function_id, 1, $function_data['code'], $function_data['config'] ?? [], $created_by);

      $this->logger->info('Function created successfully', [
        'function_id' => $function_id,
        'project_id' => $project_id,
        'function_name' => $function_data['function_name'],
        'created_by' => $created_by,
      ]);

      return $this->getFunctionById($function_id);
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->logger->error('Failed to create function', [
        'error' => $e->getMessage(),
        'project_id' => $project_id,
        'function_name' => $function_data['function_name'],
      ]);
      throw FunctionException::creationFailed($e->getMessage());
    }
  }

  /**
   * Updates an existing project function.
   *
   * @param string $function_id
   *   The function ID.
   * @param array $update_data
   *   Data to update.
   * @param int $updated_by
   *   User ID updating the function.
   *
   * @return array
   *   The updated function data.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  public function updateFunction(string $function_id, array $update_data, int $updated_by): array {
    $function = $this->getFunctionById($function_id);
    
    if (!$this->permissionChecker->canAccessProject($updated_by, $function['project_id'])) {
      throw FunctionException::accessDenied('Insufficient permissions to update function');
    }

    $current_time = \Drupal::time()->getCurrentTime();
    $update_fields = ['updated_at' => $current_time];

    // Handle code updates (create new version)
    $code_changed = false;
    if (isset($update_data['code']) && $update_data['code'] !== $function['code']) {
      $code_changed = true;
      $new_version = $function['version'] + 1;
      $update_fields['code'] = $update_data['code'];
      $update_fields['version'] = $new_version;
    }

    // Handle other field updates
    $allowed_fields = ['display_name', 'description', 'config', 'status'];
    foreach ($allowed_fields as $field) {
      if (isset($update_data[$field])) {
        if ($field === 'config') {
          $update_fields[$field] = json_encode($update_data[$field]);
        }
        else {
          $update_fields[$field] = $update_data[$field];
        }
      }
    }

    $transaction = $this->database->startTransaction();
    try {
      $this->database->update('baas_project_functions')
        ->fields($update_fields)
        ->condition('id', $function_id)
        ->execute();

      // Create version record if code changed
      if ($code_changed) {
        $this->createFunctionVersion(
          $function_id,
          $new_version,
          $update_data['code'],
          $update_data['config'] ?? json_decode($function['config'], TRUE),
          $updated_by
        );
      }

      $this->logger->info('Function updated successfully', [
        'function_id' => $function_id,
        'updated_by' => $updated_by,
        'code_changed' => $code_changed,
      ]);

      return $this->getFunctionById($function_id);
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->logger->error('Failed to update function', [
        'error' => $e->getMessage(),
        'function_id' => $function_id,
      ]);
      throw FunctionException::updateFailed($e->getMessage());
    }
  }

  /**
   * Retrieves a function by ID.
   *
   * @param string $function_id
   *   The function ID.
   *
   * @return array
   *   The function data.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  public function getFunctionById(string $function_id): array {
    $query = $this->database->select('baas_project_functions', 'f')
      ->fields('f')
      ->condition('f.id', $function_id);

    $result = $query->execute()->fetchAssoc();

    if (!$result) {
      throw FunctionException::functionNotFound($function_id);
    }

    // Decode JSON fields
    $result['config'] = json_decode($result['config'] ?? '{}', TRUE);

    return $result;
  }

  /**
   * Retrieves functions for a project.
   *
   * @param string $project_id
   *   The project ID.
   * @param array $filters
   *   Optional filters (status, etc.).
   * @param int $limit
   *   Results limit.
   * @param int $offset
   *   Results offset.
   *
   * @return array
   *   Array of functions with pagination info.
   */
  public function getProjectFunctions(string $project_id, array $filters = [], int $limit = 20, int $offset = 0): array {
    // Note: Permission checking should be done at the controller level
    // This service method focuses on data retrieval

    $query = $this->database->select('baas_project_functions', 'f')
      ->fields('f')
      ->condition('f.project_id', $project_id)
      ->orderBy('f.created_at', 'DESC')
      ->range($offset, $limit);

    // Apply filters
    if (!empty($filters['status'])) {
      $query->condition('f.status', $filters['status']);
    }

    if (!empty($filters['search'])) {
      $or = $query->orConditionGroup()
        ->condition('f.function_name', '%' . $filters['search'] . '%', 'LIKE')
        ->condition('f.display_name', '%' . $filters['search'] . '%', 'LIKE')
        ->condition('f.description', '%' . $filters['search'] . '%', 'LIKE');
      $query->condition($or);
    }

    $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    // Decode JSON fields
    foreach ($results as &$function) {
      $function['config'] = json_decode($function['config'] ?? '{}', TRUE);
    }

    // Get total count
    $count_query = $this->database->select('baas_project_functions', 'f')
      ->condition('f.project_id', $project_id);
    if (!empty($filters['status'])) {
      $count_query->condition('f.status', $filters['status']);
    }
    if (!empty($filters['search'])) {
      $or = $count_query->orConditionGroup()
        ->condition('f.function_name', '%' . $filters['search'] . '%', 'LIKE')
        ->condition('f.display_name', '%' . $filters['search'] . '%', 'LIKE')
        ->condition('f.description', '%' . $filters['search'] . '%', 'LIKE');
      $count_query->condition($or);
    }
    $total = $count_query->countQuery()->execute()->fetchField();

    return [
      'functions' => $results,
      'pagination' => [
        'total' => (int) $total,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $total,
      ],
    ];
  }

  /**
   * Deletes a function and all related data.
   *
   * @param string $function_id
   *   The function ID.
   * @param int $deleted_by
   *   User ID performing the deletion.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  public function deleteFunction(string $function_id, int $deleted_by): void {
    $function = $this->getFunctionById($function_id);
    
    if (!$this->permissionChecker->canAccessProject($deleted_by, $function['project_id'])) {
      throw FunctionException::accessDenied('Insufficient permissions to delete function');
    }

    $transaction = $this->database->startTransaction();
    try {
      // Delete logs (cascade will handle this, but explicit for clarity)
      $this->database->delete('baas_project_function_logs')
        ->condition('function_id', $function_id)
        ->execute();

      // Delete versions (cascade will handle this)
      $this->database->delete('baas_project_function_versions')
        ->condition('function_id', $function_id)
        ->execute();

      // Delete main function record
      $this->database->delete('baas_project_functions')
        ->condition('id', $function_id)
        ->execute();

      $this->logger->info('Function deleted successfully', [
        'function_id' => $function_id,
        'function_name' => $function['function_name'],
        'deleted_by' => $deleted_by,
      ]);
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->logger->error('Failed to delete function', [
        'error' => $e->getMessage(),
        'function_id' => $function_id,
      ]);
      throw FunctionException::deletionFailed($e->getMessage());
    }
  }

  /**
   * Checks if a function exists in the project.
   *
   * @param string $project_id
   *   The project ID.
   * @param string $function_name
   *   The function name.
   *
   * @return bool
   *   TRUE if function exists.
   */
  public function functionExists(string $project_id, string $function_name): bool {
    $result = $this->database->select('baas_project_functions', 'f')
      ->fields('f', ['id'])
      ->condition('f.project_id', $project_id)
      ->condition('f.function_name', $function_name)
      ->execute()
      ->fetchField();

    return !empty($result);
  }

  /**
   * Generates a unique function ID.
   *
   * @param string $project_id
   *   The project ID.
   * @param string $function_name
   *   The function name.
   *
   * @return string
   *   The generated function ID.
   */
  protected function generateFunctionId(string $project_id, string $function_name): string {
    return 'func_' . $project_id . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $function_name) . '_' . uniqid();
  }

  /**
   * Creates a function version record.
   *
   * @param string $function_id
   *   The function ID.
   * @param int $version
   *   The version number.
   * @param string $code
   *   The function code.
   * @param array $config
   *   The function configuration.
   * @param int $deployed_by
   *   User ID who deployed this version.
   */
  protected function createFunctionVersion(string $function_id, int $version, string $code, array $config, int $deployed_by): void {
    $version_id = $function_id . '_v' . $version;
    $current_time = \Drupal::time()->getCurrentTime();

    $this->database->insert('baas_project_function_versions')
      ->fields([
        'id' => $version_id,
        'function_id' => $function_id,
        'version' => $version,
        'code' => $code,
        'config' => json_encode($config),
        'deployed_at' => $current_time,
        'deployed_by' => $deployed_by,
      ])
      ->execute();
  }

  /**
   * Gets functions by project ID (alias for getProjectFunctions).
   *
   * @param string $project_id
   *   The project ID.
   *
   * @return array
   *   Array of functions for the project.
   */
  public function getFunctionsByProject(string $project_id): array {
    $result = $this->getProjectFunctions($project_id);
    return $result['functions'] ?? [];
  }

}