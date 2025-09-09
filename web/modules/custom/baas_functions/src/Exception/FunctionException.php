<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Exception;

/**
 * Exception class for BaaS Functions operations.
 */
class FunctionException extends \Exception {

  public const FUNCTION_NOT_FOUND = 1001;
  public const FUNCTION_EXISTS = 1002;
  public const ACCESS_DENIED = 1003;
  public const INVALID_INPUT = 1004;
  public const CREATION_FAILED = 1005;
  public const UPDATE_FAILED = 1006;
  public const DELETION_FAILED = 1007;
  public const EXECUTION_FAILED = 1008;
  public const VALIDATION_FAILED = 1009;
  public const DEPLOYMENT_FAILED = 1010;

  public function __construct(
    string $message = '',
    int $code = 0,
    ?\Throwable $previous = NULL,
    protected readonly array $context = [],
  ) {
    parent::__construct($message, $code, $previous);
  }

  /**
   * Gets the exception context data.
   *
   * @return array
   *   The context data.
   */
  public function getContext(): array {
    return $this->context;
  }

  /**
   * Creates a function not found exception.
   *
   * @param string $function_id
   *   The function ID.
   *
   * @return static
   *   The exception instance.
   */
  public static function functionNotFound(string $function_id): static {
    return new static(
      "Function not found: {$function_id}",
      self::FUNCTION_NOT_FOUND,
      NULL,
      ['function_id' => $function_id]
    );
  }

  /**
   * Creates a function exists exception.
   *
   * @param string $function_name
   *   The function name.
   *
   * @return static
   *   The exception instance.
   */
  public static function functionExists(string $function_name): static {
    return new static(
      "Function already exists: {$function_name}",
      self::FUNCTION_EXISTS,
      NULL,
      ['function_name' => $function_name]
    );
  }

  /**
   * Creates an access denied exception.
   *
   * @param string $message
   *   The error message.
   *
   * @return static
   *   The exception instance.
   */
  public static function accessDenied(string $message): static {
    return new static($message, self::ACCESS_DENIED);
  }

  /**
   * Creates an invalid input exception.
   *
   * @param string $message
   *   The error message.
   *
   * @return static
   *   The exception instance.
   */
  public static function invalidInput(string $message): static {
    return new static($message, self::INVALID_INPUT);
  }

  /**
   * Creates a creation failed exception.
   *
   * @param string $message
   *   The error message.
   *
   * @return static
   *   The exception instance.
   */
  public static function creationFailed(string $message): static {
    return new static("Function creation failed: {$message}", self::CREATION_FAILED);
  }

  /**
   * Creates an update failed exception.
   *
   * @param string $message
   *   The error message.
   *
   * @return static
   *   The exception instance.
   */
  public static function updateFailed(string $message): static {
    return new static("Function update failed: {$message}", self::UPDATE_FAILED);
  }

  /**
   * Creates a deletion failed exception.
   *
   * @param string $message
   *   The error message.
   *
   * @return static
   *   The exception instance.
   */
  public static function deletionFailed(string $message): static {
    return new static("Function deletion failed: {$message}", self::DELETION_FAILED);
  }

  /**
   * Creates an execution failed exception.
   *
   * @param string $message
   *   The error message.
   * @param array $context
   *   Additional context data.
   *
   * @return static
   *   The exception instance.
   */
  public static function executionFailed(string $message, array $context = []): static {
    return new static("Function execution failed: {$message}", self::EXECUTION_FAILED, NULL, $context);
  }

  /**
   * Creates a validation failed exception.
   *
   * @param string $message
   *   The error message.
   * @param array $context
   *   Validation context data.
   *
   * @return static
   *   The exception instance.
   */
  public static function validationFailed(string $message, array $context = []): static {
    return new static("Function validation failed: {$message}", self::VALIDATION_FAILED, NULL, $context);
  }

  /**
   * Creates a deployment failed exception.
   *
   * @param string $message
   *   The error message.
   * @param array $context
   *   Deployment context data.
   *
   * @return static
   *   The exception instance.
   */
  public static function deploymentFailed(string $message, array $context = []): static {
    return new static("Function deployment failed: {$message}", self::DEPLOYMENT_FAILED, NULL, $context);
  }

}