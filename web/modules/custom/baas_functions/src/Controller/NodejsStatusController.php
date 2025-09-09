<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\baas_functions\Service\FunctionExecutor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Node.js Service Status Controller.
 *
 * Provides status information about the Node.js Functions service.
 */
class NodejsStatusController extends ControllerBase {

  public function __construct(
    protected readonly FunctionExecutor $functionExecutor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_functions.executor'),
    );
  }

  /**
   * Display Node.js service status.
   *
   * @return array|JsonResponse
   *   Render array for status page or JSON response.
   */
  public function status() {
    try {
      // Get service status
      $status = $this->functionExecutor->checkServiceStatus();
      
      // If this is an AJAX request, return JSON
      $request = \Drupal::request();
      if ($request->isXmlHttpRequest() || $request->getRequestFormat() === 'json') {
        return new JsonResponse([
          'success' => true,
          'status' => $status,
        ]);
      }

      // Build status indicators
      $status_class = $status['status'] === 'online' ? 'color: green;' : 'color: red;';
      $status_text = $status['status'] === 'online' ? $this->t('Online') : $this->t('Offline');

      // Build HTML output directly
      $output = '<div class="baas-functions-nodejs-status">';
      $output .= '<h2>' . $this->t('Node.js Functions Service Status') . '</h2>';
      
      $output .= '<div class="status-overview">';
      $output .= '<p><strong>' . $this->t('Service Status:') . '</strong> <span style="' . $status_class . '">' . $status_text . '</span></p>';
      $output .= '<p><strong>' . $this->t('Service URL:') . '</strong> ' . $status['service_url'] . '</p>';
      
      if ($status['status'] === 'online') {
        $output .= '<p><strong>' . $this->t('Response Time:') . '</strong> ' . $status['response_time_ms'] . 'ms</p>';
        $output .= '<p><strong>' . $this->t('Version:') . '</strong> ' . $status['version'] . '</p>';
        $output .= '<p><strong>' . $this->t('Uptime:') . '</strong> ' . round($status['uptime']) . 's</p>';
        
        if (!empty($status['checks'])) {
          $output .= '<h3>' . $this->t('Health Checks') . '</h3>';
          $output .= '<ul>';
          foreach ($status['checks'] as $check_name => $check_data) {
            $check_status = $check_data['status'] ?? 'unknown';
            $check_color = $check_status === 'healthy' ? 'green' : 'red';
            $output .= '<li><strong>' . ucfirst($check_name) . ':</strong> <span style="color: ' . $check_color . ';">' . $check_status . '</span>';
            if (isset($check_data['message'])) {
              $output .= ' - ' . $check_data['message'];
            }
            $output .= '</li>';
          }
          $output .= '</ul>';
        }
        
        if (!empty($status['system'])) {
          $output .= '<h3>' . $this->t('System Information') . '</h3>';
          $system = $status['system'];
          $output .= '<ul>';
          $output .= '<li><strong>' . $this->t('Node.js Version:') . '</strong> ' . ($system['node_version'] ?? 'unknown') . '</li>';
          $output .= '<li><strong>' . $this->t('Platform:') . '</strong> ' . ($system['platform'] ?? 'unknown') . '</li>';
          $output .= '<li><strong>' . $this->t('Architecture:') . '</strong> ' . ($system['arch'] ?? 'unknown') . '</li>';
          if (isset($system['memory']['heap_used'])) {
            $output .= '<li><strong>' . $this->t('Memory Usage:') . '</strong> ' . round($system['memory']['heap_used'], 2) . 'MB</li>';
          }
          $output .= '</ul>';
        }
      } else {
        $output .= '<p style="color: red;"><strong>' . $this->t('Error:') . '</strong> ' . ($status['error'] ?? 'Service is offline') . '</p>';
      }
      
      $output .= '</div>';
      $output .= '</div>';

      return [
        '#markup' => $output,
        '#cache' => [
          'max-age' => 30, // Cache for 30 seconds
        ],
      ];
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to check Node.js service status: @message', [
        '@message' => $e->getMessage(),
      ]));
      
      return [
        '#markup' => $this->t('Unable to check Node.js service status.'),
      ];
    }
  }

}