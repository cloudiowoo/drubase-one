<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\baas_functions\Service\EnvironmentVariableManager;
use Drupal\baas_functions\Exception\FunctionException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * Environment Variable API Controller - Manages project environment variables.
 */
class EnvironmentVariableApiController extends ControllerBase {

  public function __construct(
    protected readonly EnvironmentVariableManager $envManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_functions.env_manager')
    );
  }

  /**
   * Lists environment variables for a project.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with environment variables list.
   */
  public function listEnvVars(Request $request, string $tenant_id, string $project_id): JsonResponse {
    try {
      $include_values = $request->query->getBoolean('include_values', FALSE);
      $env_vars = $this->envManager->getProjectEnvVars($project_id, $include_values);

      return new JsonResponse([
        'success' => TRUE,
        'data' => $env_vars,
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
   * Creates a new environment variable.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with created environment variable.
   */
  public function createEnvVar(Request $request, string $tenant_id, string $project_id): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);
      if (!$data) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Invalid JSON data',
          'code' => 'INVALID_JSON',
        ], Response::HTTP_BAD_REQUEST);
      }

      if (empty($data['var_name'])) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Variable name is required',
          'code' => 'MISSING_VAR_NAME',
        ], Response::HTTP_BAD_REQUEST);
      }

      if (!isset($data['value'])) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Variable value is required',
          'code' => 'MISSING_VALUE',
        ], Response::HTTP_BAD_REQUEST);
      }

      $current_user = $this->currentUser();
      $env_var = $this->envManager->createEnvVar(
        $project_id,
        $data['var_name'],
        $data['value'],
        $data['description'] ?? '',
        (int) $current_user->id()
      );

      return new JsonResponse([
        'success' => TRUE,
        'data' => $env_var,
        'message' => 'Environment variable created successfully',
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
   * Updates an environment variable.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   * @param string $var_name
   *   The variable name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with updated environment variable.
   */
  public function updateEnvVar(Request $request, string $tenant_id, string $project_id, string $var_name): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);
      if (!$data) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Invalid JSON data',
          'code' => 'INVALID_JSON',
        ], Response::HTTP_BAD_REQUEST);
      }

      $env_var = $this->envManager->updateEnvVar($project_id, $var_name, $data);

      return new JsonResponse([
        'success' => TRUE,
        'data' => $env_var,
        'message' => 'Environment variable updated successfully',
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
   * Deletes an environment variable.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   * @param string $var_name
   *   The variable name.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response confirming deletion.
   */
  public function deleteEnvVar(Request $request, string $tenant_id, string $project_id, string $var_name): JsonResponse {
    try {
      $this->envManager->deleteEnvVar($project_id, $var_name);

      return new JsonResponse([
        'success' => TRUE,
        'message' => 'Environment variable deleted successfully',
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
   * Access callback for managing environment variables.
   */
  public function accessManageEnvVars(string $tenant_id, string $project_id, AccountInterface $account): AccessResult {
    return AccessResult::allowedIf($account->isAuthenticated());
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