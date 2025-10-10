<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\baas_functions\Service\ProjectFunctionManager;
use Drupal\baas_functions\Service\FunctionExecutor;
use Drupal\baas_functions\Service\VersionManager;
use Drupal\baas_functions\Service\LogManager;
use Drupal\baas_functions\Service\CodeValidator;
use Drupal\baas_functions\Exception\FunctionException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Project Function API Controller - Handles all function-related API endpoints.
 */
class ProjectFunctionApiController extends ControllerBase {

  public function __construct(
    protected readonly ProjectFunctionManager $functionManager,
    protected readonly FunctionExecutor $functionExecutor,
    protected readonly VersionManager $versionManager,
    protected readonly LogManager $logManager,
    protected readonly CodeValidator $codeValidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_functions.manager'),
      $container->get('baas_functions.executor'),
      $container->get('baas_functions.version_manager'),
      $container->get('baas_functions.log_manager'),
      $container->get('baas_functions.validator')
    );
  }

  /**
   * Lists functions for a project.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with function list.
   */
  public function listFunctions(Request $request, string $tenant_id, string $project_id): JsonResponse {
    try {
      $page = max(1, (int) $request->query->get('page', 1));
      $limit = max(1, min(100, (int) $request->query->get('limit', 20)));
      $offset = ($page - 1) * $limit;
      
      $filters = [];
      if ($status = $request->query->get('status')) {
        $filters['status'] = $status;
      }
      if ($search = $request->query->get('search')) {
        $filters['search'] = $search;
      }

      $result = $this->functionManager->getProjectFunctions($project_id, $filters, $limit, $offset);

      return new JsonResponse([
        'success' => TRUE,
        'data' => $result['functions'],
        'pagination' => [
          'page' => $page,
          'limit' => $limit,
          'total' => $result['pagination']['total'],
          'pages' => ceil($result['pagination']['total'] / $limit),
          'has_more' => $result['pagination']['has_more'],
        ],
      ]);
    }
    catch (FunctionException $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'context' => $e->getContext(),
      ], $this->getHttpStatusFromException($e));
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Creates a new function.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with created function data.
   */
  public function createFunction(Request $request, string $tenant_id, string $project_id): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);
      if (!$data) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Invalid JSON data',
          'code' => 'INVALID_JSON',
        ], Response::HTTP_BAD_REQUEST);
      }

      // Validate function code
      if (!empty($data['code'])) {
        $validation_result = $this->codeValidator->validateCode($data['code']);
        if (!$validation_result['is_valid']) {
          return new JsonResponse([
            'success' => FALSE,
            'error' => 'Code validation failed',
            'code' => 'VALIDATION_FAILED',
            'validation_errors' => $validation_result['errors'],
          ], Response::HTTP_BAD_REQUEST);
        }
      }

      $current_user = $this->currentUser();
      $function = $this->functionManager->createFunction($project_id, $data, (int) $current_user->id());

      return new JsonResponse([
        'success' => TRUE,
        'data' => $function,
        'message' => 'Function created successfully',
      ], Response::HTTP_CREATED);
    }
    catch (FunctionException $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'context' => $e->getContext(),
      ], $this->getHttpStatusFromException($e));
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Gets a specific function.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   * @param string $function_name
   *   The function name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with function data.
   */
  public function getFunction(Request $request, string $tenant_id, string $project_id, string $function_name): JsonResponse {
    try {
      $function_id = $this->getFunctionIdByName($project_id, $function_name);
      $function = $this->functionManager->getFunctionById($function_id);

      return new JsonResponse([
        'success' => TRUE,
        'data' => $function,
      ]);
    }
    catch (FunctionException $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'context' => $e->getContext(),
      ], $this->getHttpStatusFromException($e));
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Updates a function.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   * @param string $function_name
   *   The function name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with updated function data.
   */
  public function updateFunction(Request $request, string $tenant_id, string $project_id, string $function_name): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);
      if (!$data) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Invalid JSON data',
          'code' => 'INVALID_JSON',
        ], Response::HTTP_BAD_REQUEST);
      }

      // Validate function code if provided
      if (!empty($data['code'])) {
        $validation_result = $this->codeValidator->validateCode($data['code']);
        if (!$validation_result['is_valid']) {
          return new JsonResponse([
            'success' => FALSE,
            'error' => 'Code validation failed',
            'code' => 'VALIDATION_FAILED',
            'validation_errors' => $validation_result['errors'],
          ], Response::HTTP_BAD_REQUEST);
        }
      }

      $function_id = $this->getFunctionIdByName($project_id, $function_name);
      $current_user = $this->currentUser();
      $function = $this->functionManager->updateFunction($function_id, $data, (int) $current_user->id());

      return new JsonResponse([
        'success' => TRUE,
        'data' => $function,
        'message' => 'Function updated successfully',
      ]);
    }
    catch (FunctionException $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'context' => $e->getContext(),
      ], $this->getHttpStatusFromException($e));
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Deletes a function.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   * @param string $function_name
   *   The function name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response confirming deletion.
   */
  public function deleteFunction(Request $request, string $tenant_id, string $project_id, string $function_name): JsonResponse {
    try {
      $function_id = $this->getFunctionIdByName($project_id, $function_name);
      $current_user = $this->currentUser();
      $this->functionManager->deleteFunction($function_id, (int) $current_user->id());

      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Function deleted successfully',
      ]);
    }
    catch (FunctionException $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'context' => $e->getContext(),
      ], $this->getHttpStatusFromException($e));
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Executes a function.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   * @param string $function_name
   *   The function name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with execution result.
   */
  public function executeFunction(Request $request, string $tenant_id, string $project_id, string $function_name): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE) ?: [];
      $function_id = $this->getFunctionIdByName($project_id, $function_name);

      $context_data = [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
        'user_id' => $this->currentUser()->id(),
        'ip_address' => $request->getClientIp(),
        'user_agent' => $request->headers->get('User-Agent'),
      ];

      $result = $this->functionExecutor->executeFunction($function_id, $data, $context_data);

      return new JsonResponse([
        'success' => TRUE,
        'data' => $result,
      ]);
    }
    catch (FunctionException $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'context' => $e->getContext(),
      ], $this->getHttpStatusFromException($e));
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Tests a function.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   * @param string $function_name
   *   The function name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with test result.
   */
  public function testFunction(Request $request, string $tenant_id, string $project_id, string $function_name): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE) ?: [];
      $function_id = $this->getFunctionIdByName($project_id, $function_name);
      $function = $this->functionManager->getFunctionById($function_id);

      $test_data = $data['test_data'] ?? [];
      $context_data = [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
        'user_id' => $this->currentUser()->id(),
        'test_mode' => TRUE,
      ];

      $result = $this->functionExecutor->testFunction(
        $function['code'],
        $test_data,
        $function['config'],
        $context_data
      );

      return new JsonResponse([
        'success' => TRUE,
        'data' => $result,
      ]);
    }
    catch (FunctionException $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'context' => $e->getContext(),
      ], $this->getHttpStatusFromException($e));
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Changes function status.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   * @param string $function_name
   *   The function name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with updated function.
   */
  public function changeStatus(Request $request, string $tenant_id, string $project_id, string $function_name): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);
      if (!$data || !isset($data['status'])) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Status is required',
          'code' => 'MISSING_STATUS',
        ], Response::HTTP_BAD_REQUEST);
      }

      $allowed_statuses = ['draft', 'testing', 'online', 'offline'];
      if (!in_array($data['status'], $allowed_statuses)) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Invalid status',
          'code' => 'INVALID_STATUS',
          'allowed_statuses' => $allowed_statuses,
        ], Response::HTTP_BAD_REQUEST);
      }

      $function_id = $this->getFunctionIdByName($project_id, $function_name);
      $current_user = $this->currentUser();
      $function = $this->functionManager->updateFunction($function_id, ['status' => $data['status']], (int) $current_user->id());

      return new JsonResponse([
        'success' => TRUE,
        'data' => $function,
        'message' => "Function status changed to {$data['status']}",
      ]);
    }
    catch (FunctionException $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'context' => $e->getContext(),
      ], $this->getHttpStatusFromException($e));
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Gets function execution logs.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   * @param string $function_name
   *   The function name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with logs.
   */
  public function getFunctionLogs(Request $request, string $tenant_id, string $project_id, string $function_name): JsonResponse {
    try {
      $function_id = $this->getFunctionIdByName($project_id, $function_name);
      
      $page = max(1, (int) $request->query->get('page', 1));
      $limit = max(1, min(100, (int) $request->query->get('limit', 50)));
      $offset = ($page - 1) * $limit;

      $filters = [];
      if ($status = $request->query->get('status')) {
        $filters['status'] = $status;
      }
      if ($from_date = $request->query->get('from_date')) {
        $filters['from_date'] = $from_date;
      }
      if ($to_date = $request->query->get('to_date')) {
        $filters['to_date'] = $to_date;
      }

      $result = $this->logManager->getFunctionLogs($function_id, $filters, $limit, $offset);

      return new JsonResponse([
        'success' => TRUE,
        'data' => $result['logs'],
        'pagination' => $result['pagination'],
      ]);
    }
    catch (FunctionException $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'context' => $e->getContext(),
      ], $this->getHttpStatusFromException($e));
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Gets function versions.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   * @param string $function_name
   *   The function name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with versions.
   */
  public function getFunctionVersions(Request $request, string $tenant_id, string $project_id, string $function_name): JsonResponse {
    try {
      $function_id = $this->getFunctionIdByName($project_id, $function_name);
      $versions = $this->versionManager->getFunctionVersions($function_id);

      return new JsonResponse([
        'success' => TRUE,
        'data' => $versions,
      ]);
    }
    catch (FunctionException $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'context' => $e->getContext(),
      ], $this->getHttpStatusFromException($e));
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Rolls back function to a previous version.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   * @param string $function_name
   *   The function name.
   * @param int $version
   *   The version to rollback to.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with updated function.
   */
  public function rollbackFunction(Request $request, string $tenant_id, string $project_id, string $function_name, int $version): JsonResponse {
    try {
      $function_id = $this->getFunctionIdByName($project_id, $function_name);
      $current_user = $this->currentUser();
      
      $function = $this->versionManager->rollbackFunction($function_id, $version, (int) $current_user->id());

      return new JsonResponse([
        'success' => TRUE,
        'data' => $function,
        'message' => "Function rolled back to version {$version}",
      ]);
    }
    catch (FunctionException $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'context' => $e->getContext(),
      ], $this->getHttpStatusFromException($e));
    }
    catch (\Exception $e) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * Access callback for viewing functions.
   */
  public function accessViewFunctions(string $tenant_id, string $project_id, AccountInterface $account): AccessResult {
    try {
      $project_access_checker = \Drupal::service('baas_project.access_checker');
      return $project_access_checker->access($account, $project_id, 'view');
    } catch (\Exception $e) {
      return AccessResult::forbidden('Access check failed: ' . $e->getMessage());
    }
  }

  /**
   * Access callback for creating functions.
   */
  public function accessCreateFunction(string $tenant_id, string $project_id, AccountInterface $account): AccessResult {
    try {
      $project_access_checker = \Drupal::service('baas_project.access_checker');
      return $project_access_checker->access($account, $project_id, 'edit');
    } catch (\Exception $e) {
      return AccessResult::forbidden('Access check failed: ' . $e->getMessage());
    }
  }

  /**
   * Access callback for editing functions.
   */
  public function accessEditFunction(string $tenant_id, string $project_id, string $function_name, AccountInterface $account): AccessResult {
    try {
      $project_access_checker = \Drupal::service('baas_project.access_checker');
      return $project_access_checker->access($account, $project_id, 'edit');
    } catch (\Exception $e) {
      return AccessResult::forbidden('Access check failed: ' . $e->getMessage());
    }
  }

  /**
   * Access callback for deleting functions.
   */
  public function accessDeleteFunction(string $tenant_id, string $project_id, string $function_name, AccountInterface $account): AccessResult {
    try {
      $project_access_checker = \Drupal::service('baas_project.access_checker');
      return $project_access_checker->access($account, $project_id, 'delete');
    } catch (\Exception $e) {
      return AccessResult::forbidden('Access check failed: ' . $e->getMessage());
    }
  }

  /**
   * Access callback for executing functions.
   */
  public function accessExecuteFunction(string $tenant_id, string $project_id, string $function_name, AccountInterface $account): AccessResult {
    $logger = \Drupal::logger('baas_functions_access');
    
    $logger->info('Function access check started', [
      'user_id' => $account->id(),
      'tenant_id' => $tenant_id,
      'project_id' => $project_id,
      'function_name' => $function_name,
    ]);
    
    try {
      // Use the same access pattern as other project-level APIs
      $project_access_checker = \Drupal::service('baas_project.access_checker');
      
      // Check if user can access the project (requires 'view' permission for function execution)
      $access_result = $project_access_checker->access($account, $project_id, 'view');
      
      if (!$access_result->isAllowed()) {
        $logger->error('Function access denied: Project access denied', [
          'user_id' => $account->id(),
          'project_id' => $project_id,
          'reason' => $access_result->getReason(),
        ]);
        return $access_result;
      }
      
      // Check if function exists and is executable
      $function_id = $this->getFunctionIdByName($project_id, $function_name);
      $logger->info('Function ID resolved', [
        'function_name' => $function_name,
        'function_id' => $function_id,
      ]);
      
      $function = $this->functionManager->getFunctionById($function_id);
      $logger->info('Function details loaded', [
        'function_id' => $function_id,
        'status' => $function['status'],
      ]);
      
      if (!in_array($function['status'], ['testing', 'online'])) {
        $logger->error('Function access denied: Function status not executable', [
          'function_id' => $function_id,
          'status' => $function['status'],
        ]);
        return AccessResult::forbidden("Function status '{$function['status']}' is not executable");
      }
      
      $logger->info('Function access granted', [
        'user_id' => $account->id(),
        'function_id' => $function_id,
      ]);
      return AccessResult::allowed()
        ->addCacheContexts(['user', 'route'])
        ->addCacheTags(["baas_project:{$project_id}", "baas_function:{$function_id}"]);
      
    } catch (\Exception $e) {
      $logger->error('Function access check failed with exception', [
        'user_id' => $account->id(),
        'project_id' => $project_id,
        'function_name' => $function_name,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ]);
      return AccessResult::forbidden('Access check failed: ' . $e->getMessage());
    }
  }

  /**
   * Gets function ID by project and function name.
   *
   * @param string $project_id
   *   The project ID.
   * @param string $function_name
   *   The function name.
   *
   * @return string
   *   The function ID.
   *
   * @throws \Drupal\baas_functions\Exception\FunctionException
   */
  protected function getFunctionIdByName(string $project_id, string $function_name): string {
    try {
      // Get all functions for the project and find by name
      $functions = $this->functionManager->getFunctionsByProject($project_id);
      
      foreach ($functions as $function) {
        if ($function['function_name'] === $function_name) {
          return $function['id'];
        }
      }
      
      throw FunctionException::functionNotFound($function_name);
    } catch (\Exception $e) {
      throw FunctionException::functionNotFound($function_name);
    }
  }

  /**
   * Maps FunctionException to HTTP status code.
   *
   * @param \Drupal\baas_functions\Exception\FunctionException $e
   *   The exception.
   *
   * @return int
   *   HTTP status code.
   */
  protected function getHttpStatusFromException(FunctionException $e): int {
    return match ($e->getCode()) {
      FunctionException::FUNCTION_NOT_FOUND => Response::HTTP_NOT_FOUND,
      FunctionException::ACCESS_DENIED => Response::HTTP_FORBIDDEN,
      FunctionException::INVALID_INPUT,
      FunctionException::VALIDATION_FAILED => Response::HTTP_BAD_REQUEST,
      FunctionException::FUNCTION_EXISTS => Response::HTTP_CONFLICT,
      default => Response::HTTP_INTERNAL_SERVER_ERROR,
    };
  }

}