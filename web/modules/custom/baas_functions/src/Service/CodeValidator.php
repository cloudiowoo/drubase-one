<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Code Validator - Validates function code for security and standards compliance.
 */
class CodeValidator {

  protected readonly \Drupal\Core\Logger\LoggerChannelInterface $logger;

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $this->loggerFactory->get('baas_functions_validator');
  }

  /**
   * Validates function code for security and standards.
   *
   * @param string $code
   *   The function code to validate.
   *
   * @return array
   *   Validation result with is_valid flag and errors array.
   */
  public function validateCode(string $code): array {
    $errors = [];
    $warnings = [];

    // Basic syntax validation
    $syntax_errors = $this->validateSyntax($code);
    if (!empty($syntax_errors)) {
      $errors = array_merge($errors, $syntax_errors);
    }

    // Security validation
    $security_errors = $this->validateSecurity($code);
    if (!empty($security_errors)) {
      $errors = array_merge($errors, $security_errors);
    }

    // Standards validation
    $standards_warnings = $this->validateStandards($code);
    if (!empty($standards_warnings)) {
      $warnings = array_merge($warnings, $standards_warnings);
    }

    // Structure validation
    $structure_errors = $this->validateStructure($code);
    if (!empty($structure_errors)) {
      $errors = array_merge($errors, $structure_errors);
    }

    return [
      'is_valid' => empty($errors),
      'errors' => $errors,
      'warnings' => $warnings,
      'score' => $this->calculateCodeScore($code, $errors, $warnings),
    ];
  }

  /**
   * Validates basic JavaScript syntax.
   *
   * @param string $code
   *   The code to validate.
   *
   * @return array
   *   Array of syntax errors.
   */
  protected function validateSyntax(string $code): array {
    $errors = [];

    // Check for basic syntax issues
    if (empty(trim($code))) {
      $errors[] = [
        'type' => 'syntax',
        'message' => 'Function code cannot be empty',
        'severity' => 'error',
      ];
      return $errors;
    }

    // Check for balanced brackets
    $brackets = ['(' => ')', '[' => ']', '{' => '}'];
    $stack = [];
    $chars = str_split($code);
    
    for ($i = 0; $i < count($chars); $i++) {
      $char = $chars[$i];
      
      if (in_array($char, array_keys($brackets))) {
        $stack[] = $char;
      }
      elseif (in_array($char, array_values($brackets))) {
        if (empty($stack)) {
          $errors[] = [
            'type' => 'syntax',
            'message' => "Unmatched closing bracket '{$char}' at position {$i}",
            'severity' => 'error',
            'position' => $i,
          ];
        }
        else {
          $last = array_pop($stack);
          if ($brackets[$last] !== $char) {
            $errors[] = [
              'type' => 'syntax', 
              'message' => "Mismatched brackets: expected '{$brackets[$last]}' but found '{$char}' at position {$i}",
              'severity' => 'error',
              'position' => $i,
            ];
          }
        }
      }
    }

    if (!empty($stack)) {
      $errors[] = [
        'type' => 'syntax',
        'message' => 'Unclosed brackets: ' . implode(', ', $stack),
        'severity' => 'error',
      ];
    }

    return $errors;
  }

  /**
   * Validates code for security issues.
   *
   * @param string $code
   *   The code to validate.
   *
   * @return array
   *   Array of security errors.
   */
  protected function validateSecurity(string $code): array {
    $errors = [];

    // Dangerous patterns to check for
    $dangerous_patterns = [
      '/require\s*\(/i' => 'Use of require() is not allowed',
      '/import\s+.*from\s+[\'"](?!@baas\/)/i' => 'External imports are restricted, use BaaS Context API',
      '/eval\s*\(/i' => 'Use of eval() is prohibited for security reasons',
      '/Function\s*\(/i' => 'Dynamic function creation is not allowed',
      '/process\.exit/i' => 'Process manipulation is not allowed',
      '/child_process/i' => 'Child process execution is prohibited',
      '/fs\./i' => 'Direct file system access is not allowed',
      '/\$\{.*\}/s' => 'Template literals with expressions should be carefully reviewed',
    ];

    foreach ($dangerous_patterns as $pattern => $message) {
      if (preg_match($pattern, $code, $matches)) {
        $errors[] = [
          'type' => 'security',
          'message' => $message,
          'severity' => 'error',
          'pattern' => $pattern,
          'match' => $matches[0] ?? '',
        ];
      }
    }

    // Check for potential XSS patterns
    $xss_patterns = [
      '/innerHTML\s*=/i' => 'Direct innerHTML assignment may be unsafe',
      '/document\.write/i' => 'document.write() usage should be avoided',
      '/location\.href\s*=/i' => 'Direct location manipulation may be unsafe',
    ];

    foreach ($xss_patterns as $pattern => $message) {
      if (preg_match($pattern, $code)) {
        $errors[] = [
          'type' => 'security',
          'message' => $message,  
          'severity' => 'warning',
          'pattern' => $pattern,
        ];
      }
    }

    return $errors;
  }

  /**
   * Validates code against BaaS standards.
   *
   * @param string $code
   *   The code to validate.
   *
   * @return array
   *   Array of standards warnings.
   */
  protected function validateStandards(string $code): array {
    $warnings = [];

    // Check for proper export structure
    if (!preg_match('/export\s+default\s+async\s+function\s+handler/i', $code)) {
      $warnings[] = [
        'type' => 'standards',
        'message' => 'Functions should export a default async handler function',
        'severity' => 'warning',
        'suggestion' => 'Use: export default async function handler(request, context) { ... }',
      ];
    }

    // Check for config export
    if (!preg_match('/export\s+const\s+config\s*=/i', $code)) {
      $warnings[] = [
        'type' => 'standards',
        'message' => 'Functions should export a config object',
        'severity' => 'info',
        'suggestion' => 'Add: export const config = { name: "Function Name", description: "..." }',
      ];
    }

    // Check for proper context usage
    if (preg_match('/console\.log/i', $code) && !preg_match('/context\.log/i', $code)) {
      $warnings[] = [
        'type' => 'standards',
        'message' => 'Use context.log instead of console.log for better logging',
        'severity' => 'info',
        'suggestion' => 'Replace console.log() with context.log.info()',
      ];
    }

    // Check for error handling
    if (!preg_match('/try\s*\{|catch\s*\(/i', $code)) {
      $warnings[] = [
        'type' => 'standards',
        'message' => 'Consider adding try-catch error handling',
        'severity' => 'info',
        'suggestion' => 'Wrap main logic in try-catch blocks',
      ];
    }

    return $warnings;
  }

  /**
   * Validates function structure.
   *
   * @param string $code
   *   The code to validate.
   *
   * @return array
   *   Array of structure errors.
   */
  protected function validateStructure(string $code): array {
    $errors = [];

    // Check function length
    $lines = explode("\n", $code);
    $line_count = count($lines);
    
    if ($line_count > 500) {
      $errors[] = [
        'type' => 'structure',
        'message' => "Function is too long ({$line_count} lines). Consider breaking it into smaller functions.",
        'severity' => 'warning',
        'limit' => 500,
        'actual' => $line_count,
      ];
    }

    // Check for deeply nested code
    $max_nesting = $this->calculateMaxNesting($code);
    if ($max_nesting > 5) {
      $errors[] = [
        'type' => 'structure',
        'message' => "Code is too deeply nested (depth: {$max_nesting}). Consider refactoring.",
        'severity' => 'warning',
        'limit' => 5,
        'actual' => $max_nesting,
      ];
    }

    // Check for required parameters
    if (!preg_match('/function\s+handler\s*\(\s*request\s*,\s*context\s*\)/i', $code)) {
      $errors[] = [
        'type' => 'structure',
        'message' => 'Handler function must accept (request, context) parameters',
        'severity' => 'error',
        'suggestion' => 'Use: async function handler(request, context)',
      ];
    }

    return $errors;
  }

  /**
   * Calculates maximum nesting depth in code.
   *
   * @param string $code
   *   The code to analyze.
   *
   * @return int
   *   Maximum nesting depth.
   */
  protected function calculateMaxNesting(string $code): int {
    $max_depth = 0;
    $current_depth = 0;
    $chars = str_split($code);
    
    foreach ($chars as $char) {
      if ($char === '{') {
        $current_depth++;
        $max_depth = max($max_depth, $current_depth);
      }
      elseif ($char === '}') {
        $current_depth = max(0, $current_depth - 1);
      }
    }
    
    return $max_depth;
  }

  /**
   * Calculates a quality score for the code.
   *
   * @param string $code
   *   The code to score.
   * @param array $errors
   *   Array of errors found.
   * @param array $warnings
   *   Array of warnings found.
   *
   * @return array
   *   Score information.
   */
  protected function calculateCodeScore(string $code, array $errors, array $warnings): array {
    $base_score = 100;
    
    // Deduct points for errors and warnings
    foreach ($errors as $error) {
      $deduction = match ($error['severity']) {
        'error' => 20,
        'warning' => 10,
        'info' => 5,
        default => 10,
      };
      $base_score -= $deduction;
    }
    
    foreach ($warnings as $warning) {
      $deduction = match ($warning['severity']) {
        'error' => 20,
        'warning' => 5,
        'info' => 2,
        default => 5,
      };
      $base_score -= $deduction;
    }
    
    // Bonus points for good practices
    if (preg_match('/export\s+const\s+config/i', $code)) {
      $base_score += 5;
    }
    
    if (preg_match('/context\.log/i', $code)) {
      $base_score += 5;
    }
    
    if (preg_match('/try\s*\{.*catch/is', $code)) {
      $base_score += 10;
    }
    
    $final_score = max(0, min(100, $base_score));
    
    return [
      'score' => $final_score,
      'grade' => $this->getGradeFromScore($final_score),
      'feedback' => $this->getScoreFeedback($final_score),
    ];
  }

  /**
   * Gets letter grade from numeric score.
   *
   * @param int $score
   *   The numeric score.
   *
   * @return string
   *   Letter grade.
   */
  protected function getGradeFromScore(int $score): string {
    return match (TRUE) {
      $score >= 90 => 'A',
      $score >= 80 => 'B', 
      $score >= 70 => 'C',
      $score >= 60 => 'D',
      default => 'F',
    };
  }

  /**
   * Gets feedback message based on score.
   *
   * @param int $score
   *   The numeric score.
   *
   * @return string
   *   Feedback message.
   */
  protected function getScoreFeedback(int $score): string {
    return match (TRUE) {
      $score >= 90 => 'Excellent code quality! Ready for production.',
      $score >= 80 => 'Good code quality with minor improvements needed.',
      $score >= 70 => 'Acceptable code quality, consider addressing warnings.',
      $score >= 60 => 'Code quality needs improvement before deployment.',
      default => 'Significant issues found. Please review and fix errors.',
    };
  }

}