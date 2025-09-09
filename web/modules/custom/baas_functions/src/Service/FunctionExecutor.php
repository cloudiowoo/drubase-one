<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Service;

use GuzzleHttp\ClientInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\baas_functions\Exception\FunctionException;

/**
 * Function Executor - Handles communication with Node.js service for function execution.
 */
class FunctionExecutor {

  protected readonly \Drupal\Core\Logger\LoggerChannelInterface $logger;

  public function __construct(
    protected readonly ProjectFunctionManager $functionManager,
    protected readonly ClientInterface $httpClient,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly Connection $database,
    protected readonly EnvironmentVariableManager $environmentVariableManager,
  ) {
    $this->logger = $this->loggerFactory->get('baas_functions_executor');
  }

  /**
   * Executes a function in the Node.js runtime.
   *
   * @param string $function_id
   *   The function ID to execute.
   * @param array $request_data
   *   The request data to pass to the function.
   * @param array $context_data
   *   Additional context data (user, project, etc.).
   *
   * @return array
   *   The execution result.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  public function executeFunction(string $function_id, array $request_data, array $context_data): array {
    $function = $this->functionManager->getFunctionById($function_id);

    // Check if function is deployable
    if (!in_array($function['status'], ['testing', 'online'])) {
      throw FunctionException::executionFailed("Function status '{$function['status']}' is not executable");
    }

    $execution_id = $this->generateExecutionId();
    $start_time = microtime(TRUE);

    try {
      // Get project environment variables
      $project_id = $context_data['project_id'] ?? '';
      $env_vars = [];
      if ($project_id && $this->environmentVariableManager) {
        try {
          $env_vars = $this->environmentVariableManager->getEnvVarsForExecution($project_id);
        } catch (\Exception $e) {
          $this->logger->warning('Failed to load environment variables', [
            'project_id' => $project_id,
            'error' => $e->getMessage(),
          ]);
        }
      }

      // Prepare execution payload
      $payload = [
        'execution_id' => $execution_id,
        'function_id' => $function_id,
        'function_name' => $function['function_name'],
        'code' => $function['code'],
        'config' => $function['config'],
        'request' => $request_data,
        'context' => $context_data,
        'env' => $env_vars,
      ];

      $this->logger->info('Executing function', [
        'function_id' => $function_id,
        'execution_id' => $execution_id,
        'function_name' => $function['function_name'],
      ]);

      // Send request to Node.js service
      $nodejs_url = $this->getNodejsServiceUrl();
      $response = $this->httpClient->post($nodejs_url . '/execute', [
        'json' => $payload,
        'timeout' => $this->getExecutionTimeout($function['config']),
        'headers' => [
          'Content-Type' => 'application/json',
          'X-BaaS-Project-ID' => $context_data['project_id'] ?? '',
          'X-BaaS-Execution-ID' => $execution_id,
        ],
      ]);

      $execution_time = (microtime(TRUE) - $start_time) * 1000; // Convert to milliseconds
      $response_body = json_decode($response->getBody()->getContents(), TRUE);

      if (empty($response_body)) {
        throw FunctionException::executionFailed('Invalid response from Node.js service');
      }

      $result = [
        'execution_id' => $execution_id,
        'status' => $response_body['status'] ?? 'error',
        'data' => $response_body['data'] ?? NULL,
        'error' => $response_body['error'] ?? NULL,
        'execution_time_ms' => (int) $execution_time,
        'memory_used_mb' => $response_body['memory_used_mb'] ?? 0,
        'logs' => $response_body['logs'] ?? [],
      ];

      // Log execution result
      $this->logExecution($function_id, $execution_id, $request_data, $result, $context_data);

      // Update function statistics
      $this->updateFunctionStats($function_id, $result);

      $this->logger->info('Function executed successfully', [
        'function_id' => $function_id,
        'execution_id' => $execution_id,
        'status' => $result['status'],
        'execution_time_ms' => $result['execution_time_ms'],
      ]);

      return $result;
    }
    catch (\GuzzleHttp\Exception\RequestException $e) {
      $execution_time = (microtime(TRUE) - $start_time) * 1000;
      
      $error_result = [
        'execution_id' => $execution_id,
        'status' => 'error',
        'data' => NULL,
        'error' => 'Service communication error: ' . $e->getMessage(),
        'execution_time_ms' => (int) $execution_time,
        'memory_used_mb' => 0,
        'logs' => [],
      ];

      // Log the failed execution
      $this->logExecution($function_id, $execution_id, $request_data, $error_result, $context_data);
      $this->updateFunctionStats($function_id, $error_result);

      $this->logger->error('Function execution failed - service error', [
        'function_id' => $function_id,
        'execution_id' => $execution_id,
        'error' => $e->getMessage(),
      ]);

      throw FunctionException::executionFailed($e->getMessage(), [
        'execution_id' => $execution_id,
        'function_id' => $function_id,
      ]);
    }
    catch (\Exception $e) {
      $execution_time = (microtime(TRUE) - $start_time) * 1000;
      
      $error_result = [
        'execution_id' => $execution_id,
        'status' => 'error',
        'data' => NULL,
        'error' => 'Execution error: ' . $e->getMessage(),
        'execution_time_ms' => (int) $execution_time,
        'memory_used_mb' => 0,
        'logs' => [],
      ];

      $this->logExecution($function_id, $execution_id, $request_data, $error_result, $context_data);
      $this->updateFunctionStats($function_id, $error_result);

      $this->logger->error('Function execution failed - general error', [
        'function_id' => $function_id,
        'execution_id' => $execution_id,
        'error' => $e->getMessage(),
      ]);

      throw FunctionException::executionFailed($e->getMessage(), [
        'execution_id' => $execution_id,
        'function_id' => $function_id,
      ]);
    }
  }

  /**
   * Tests a function without logging the execution.
   *
   * @param string $code
   *   The function code to test.
   * @param array $test_data
   *   Test data to pass to the function.
   * @param array $config
   *   Function configuration.
   * @param array $context_data
   *   Context data for testing.
   *
   * @return array
   *   The test result.
   */
  public function testFunction(string $code, array $test_data, array $config, array $context_data): array {
    $execution_id = $this->generateExecutionId();
    $start_time = microtime(TRUE);

    try {
      $payload = [
        'execution_id' => $execution_id,
        'function_id' => 'test_' . $execution_id,
        'function_name' => 'test_function',
        'code' => $code,
        'config' => $config,
        'request' => $test_data,
        'context' => $context_data,
        'test_mode' => TRUE,
      ];

      $nodejs_url = $this->getNodejsServiceUrl();
      $response = $this->httpClient->post($nodejs_url . '/test', [
        'json' => $payload,
        'timeout' => $this->getExecutionTimeout($config),
        'headers' => [
          'Content-Type' => 'application/json',
          'X-BaaS-Project-ID' => $context_data['project_id'] ?? '',
          'X-BaaS-Execution-ID' => $execution_id,
        ],
      ]);

      $execution_time = (microtime(TRUE) - $start_time) * 1000;
      $response_body = json_decode($response->getBody()->getContents(), TRUE);

      return [
        'execution_id' => $execution_id,
        'status' => $response_body['status'] ?? 'error',
        'data' => $response_body['data'] ?? NULL,
        'error' => $response_body['error'] ?? NULL,
        'execution_time_ms' => (int) $execution_time,
        'memory_used_mb' => $response_body['memory_used_mb'] ?? 0,
        'logs' => $response_body['logs'] ?? [],
        'validation' => $response_body['validation'] ?? [],
      ];
    }
    catch (\Exception $e) {
      $execution_time = (microtime(TRUE) - $start_time) * 1000;
      
      return [
        'execution_id' => $execution_id,
        'status' => 'error',
        'data' => NULL,
        'error' => 'Test execution failed: ' . $e->getMessage(),
        'execution_time_ms' => (int) $execution_time,
        'memory_used_mb' => 0,
        'logs' => [],
        'validation' => [],
      ];
    }
  }

  /**
   * Checks the status of the Node.js service.
   *
   * @return array
   *   Service status information.
   */
  public function checkServiceStatus(): array {
    try {
      $start_time = microtime(TRUE);
      $nodejs_url = $this->getNodejsServiceUrl();
      
      $response = $this->httpClient->get($nodejs_url . '/health', [
        'timeout' => 5,
      ]);

      $response_time = (microtime(TRUE) - $start_time) * 1000; // Convert to milliseconds
      $response_body = json_decode($response->getBody()->getContents(), TRUE);
      
      if (empty($response_body)) {
        throw new \Exception('Invalid response from Node.js service');
      }
      
      return [
        'status' => 'online',
        'service_url' => $nodejs_url,
        'response_time_ms' => (int) $response_time,
        'version' => $response_body['version'] ?? 'unknown',
        'uptime' => $response_body['uptime'] ?? 0,
        'service_status' => $response_body['status'] ?? 'unknown',
        'checks' => $response_body['checks'] ?? [],
        'system' => $response_body['system'] ?? [],
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Node.js service status check failed', [
        'service_url' => $this->getNodejsServiceUrl(),
        'error' => $e->getMessage(),
      ]);
      
      return [
        'status' => 'offline',
        'service_url' => $this->getNodejsServiceUrl(),
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Gets the Node.js service URL from configuration.
   *
   * @return string
   *   The service URL.
   */
  protected function getNodejsServiceUrl(): string {
    $config = $this->configFactory->get('baas_functions.settings');
    return $config->get('nodejs_service_url') ?: 'http://localhost:3001';
  }

  /**
   * Gets the execution timeout from function config.
   *
   * @param array $config
   *   Function configuration.
   *
   * @return int
   *   Timeout in seconds.
   */
  protected function getExecutionTimeout(array $config): int {
    return $config['timeout'] ?? 30;
  }

  /**
   * Generates a unique execution ID.
   *
   * @return string
   *   The execution ID.
   */
  protected function generateExecutionId(): string {
    return 'exec_' . uniqid() . '_' . time();
  }

  /**
   * Logs function execution to the database.
   *
   * @param string $function_id
   *   The function ID.
   * @param string $execution_id
   *   The execution ID.
   * @param array $input_data
   *   Input data.
   * @param array $result
   *   Execution result.
   * @param array $context_data
   *   Context data.
   */
  protected function logExecution(string $function_id, string $execution_id, array $input_data, array $result, array $context_data): void {
    try {
      $function = $this->functionManager->getFunctionById($function_id);
      
      $log_id = 'log_' . $execution_id;
      $current_time = \Drupal::time()->getCurrentTime();

      $this->database->insert('baas_project_function_logs')
        ->fields([
          'id' => $log_id,
          'function_id' => $function_id,
          'project_id' => $function['project_id'],
          'execution_id' => $execution_id,
          'input_data' => json_encode($input_data),
          'output_data' => json_encode($result['data']),
          'execution_time_ms' => $result['execution_time_ms'],
          'memory_used_mb' => $result['memory_used_mb'],
          'status' => $result['status'],
          'error_message' => $result['error'],
          'error_stack' => $result['error'] ? json_encode($result) : NULL,
          'user_id' => $context_data['user_id'] ?? NULL,
          'ip_address' => $context_data['ip_address'] ?? NULL,
          'user_agent' => $context_data['user_agent'] ?? NULL,
          'created_at' => $current_time,
        ])
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to log function execution', [
        'execution_id' => $execution_id,
        'error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Updates function execution statistics.
   *
   * @param string $function_id
   *   The function ID.
   * @param array $result
   *   Execution result.
   */
  protected function updateFunctionStats(string $function_id, array $result): void {
    try {
      $function = $this->functionManager->getFunctionById($function_id);
      
      $new_call_count = $function['call_count'] + 1;
      $new_success_count = $function['success_count'] + ($result['status'] === 'success' ? 1 : 0);
      $new_error_count = $function['error_count'] + ($result['status'] === 'error' ? 1 : 0);
      
      // Calculate new average response time
      $total_time = ($function['avg_response_time'] * $function['call_count']) + $result['execution_time_ms'];
      $new_avg_response_time = (int) ($total_time / $new_call_count);

      $this->database->update('baas_project_functions')
        ->fields([
          'call_count' => $new_call_count,
          'success_count' => $new_success_count,
          'error_count' => $new_error_count,
          'avg_response_time' => $new_avg_response_time,
          'updated_at' => \Drupal::time()->getCurrentTime(),
        ])
        ->condition('id', $function_id)
        ->execute();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to update function statistics', [
        'function_id' => $function_id,
        'error' => $e->getMessage(),
      ]);
    }
  }

}