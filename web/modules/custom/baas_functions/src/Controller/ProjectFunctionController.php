<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\baas_functions\Service\ProjectFunctionManager;
use Drupal\baas_auth\Service\UnifiedPermissionChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Render\Markup;

/**
 * Project Function Management Controller.
 *
 * Provides web interface for managing functions within projects.
 */
class ProjectFunctionController extends ControllerBase {

  public function __construct(
    protected readonly ProjectFunctionManager $functionManager,
    protected readonly UnifiedPermissionChecker $permissionChecker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_functions.manager'),
      $container->get('baas_auth.unified_permission_checker'),
    );
  }

  /**
   * Display functions management interface for a project.
   *
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array
   *   Render array for the functions management interface.
   */
  public function functionsManager(string $tenant_id, string $project_id, Request $request): array {
    try {
      // Get functions for this project
      $functions = $this->functionManager->getFunctionsByProject($project_id);
      
      // Ensure $functions is an array
      if (!is_array($functions)) {
        $functions = [];
      }
      
      // Build HTML output
      $output = '<div class="baas-functions-manager">';
      
      // 添加面包屑导航
      $output .= '<nav class="breadcrumb" style="background: #f8f9fa; padding: 12px 16px; border-radius: 4px; margin-bottom: 24px;">';
      $output .= '<a href="/user/projects" style="color: #007cba; text-decoration: none;">我的项目</a>';
      $output .= ' <span style="margin: 0 12px; color: #6c757d;">></span> ';
      $output .= '<a href="/user/tenants/' . $tenant_id . '/projects/' . $project_id . '" style="color: #007cba; text-decoration: none;">项目详情</a>';
      $output .= ' <span style="margin: 0 12px; color: #6c757d;">></span> ';
      $output .= '<span>Edge Functions</span>';
      $output .= '</nav>';
      
      $output .= '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">';
      $output .= '<div>';
      $output .= '<h1>' . $this->t('Edge Functions 管理') . '</h1>';
      $output .= '<p>' . $this->t('项目: @project_id', ['@project_id' => $project_id]) . '</p>';
      $output .= '</div>';
      $output .= '<div style="display: flex; gap: 0.5rem;">';
      $output .= '<a href="/tenant/' . $tenant_id . '/project/' . $project_id . '/functions/env-vars" class="button">' . $this->t('环境变量') . '</a>';
      $output .= '<a href="/tenant/' . $tenant_id . '/project/' . $project_id . '/functions/create" class="button button--primary">' . $this->t('创建新函数') . '</a>';
      $output .= '</div>';
      $output .= '</div>';
      
      if (!empty($functions)) {
        $output .= '<div class="functions-list">';
        $output .= '<h2>' . $this->t('函数列表') . '</h2>';
        $output .= '<table class="table" style="width: 100%; border-collapse: collapse;">';
        $output .= '<thead><tr style="background-color: #f5f5f5;">';
        $output .= '<th style="padding: 0.5rem; border: 1px solid #ddd;">' . $this->t('函数名') . '</th>';
        $output .= '<th style="padding: 0.5rem; border: 1px solid #ddd;">' . $this->t('状态') . '</th>';
        $output .= '<th style="padding: 0.5rem; border: 1px solid #ddd;">' . $this->t('调用次数') . '</th>';
        $output .= '<th style="padding: 0.5rem; border: 1px solid #ddd;">' . $this->t('平均响应时间') . '</th>';
        $output .= '<th style="padding: 0.5rem; border: 1px solid #ddd;">' . $this->t('操作') . '</th>';
        $output .= '</tr></thead><tbody>';
        
        foreach ($functions as $function) {
          // Ensure function is an array and has required keys
          if (!is_array($function)) {
            continue;
          }
          
          $status = $function['status'] ?? 'unknown';
          $function_name = $function['function_name'] ?? 'Unnamed Function';
          $display_name = $function['display_name'] ?? '';
          $call_count = $function['call_count'] ?? 0;
          $avg_response_time = $function['avg_response_time'] ?? 0;
          
          $status_color = match($status) {
            'online' => 'green',
            'draft' => 'orange',
            'testing' => 'blue',
            default => 'gray'
          };
          
          $output .= '<tr>';
          $output .= '<td style="padding: 0.5rem; border: 1px solid #ddd;"><strong>' . htmlspecialchars($function_name) . '</strong>';
          if (!empty($display_name)) {
            $output .= '<br><small>' . htmlspecialchars($display_name) . '</small>';
          }
          $output .= '</td>';
          $output .= '<td style="padding: 0.5rem; border: 1px solid #ddd;"><span style="color: ' . $status_color . ';">' . ucfirst($status) . '</span></td>';
          $output .= '<td style="padding: 0.5rem; border: 1px solid #ddd;">' . $call_count . '</td>';
          $output .= '<td style="padding: 0.5rem; border: 1px solid #ddd;">' . $avg_response_time . 'ms</td>';
          $output .= '<td style="padding: 0.5rem; border: 1px solid #ddd;">';
          $function_id = $function['id'] ?? '';
          if (!empty($function_id)) {
            // 状态切换按钮
            if ($status === 'draft') {
              $output .= '<a href="/tenant/' . $tenant_id . '/project/' . $project_id . '/functions/' . $function_id . '/activate" class="button button--small button--primary" style="margin-right: 5px;">' . $this->t('发布') . '</a>';
            } elseif ($status === 'online') {
              $output .= '<a href="/tenant/' . $tenant_id . '/project/' . $project_id . '/functions/' . $function_id . '/deactivate" class="button button--small" style="margin-right: 5px;">' . $this->t('下线') . '</a>';
            }
            // 测试按钮
            if ($status === 'online') {
              $output .= '<a href="/tenant/' . $tenant_id . '/project/' . $project_id . '/functions/' . $function_id . '/test" class="button button--small" style="margin-right: 5px; background: #28a745; color: white;">' . $this->t('测试') . '</a>';
            }
            // 编辑和删除按钮
            $output .= '<a href="/tenant/' . $tenant_id . '/project/' . $project_id . '/functions/' . $function_id . '/edit" class="button button--small" style="margin-right: 5px;">' . $this->t('编辑') . '</a>';
            $output .= '<a href="/tenant/' . $tenant_id . '/project/' . $project_id . '/functions/' . $function_id . '/delete" class="button button--small button--danger">' . $this->t('删除') . '</a>';
          }
          $output .= '</td>';
          $output .= '</tr>';
        }
        
        $output .= '</tbody></table>';
        $output .= '</div>';
      } else {
        $output .= '<div class="no-functions" style="text-align: center; padding: 2rem;">';
        $output .= '<h3>' . $this->t('还没有函数') . '</h3>';
        $output .= '<p>' . $this->t('您还没有在此项目中创建任何 Edge Functions。') . '</p>';
        $output .= '<a href="/tenant/' . $tenant_id . '/project/' . $project_id . '/functions/create" class="button button--primary">' . $this->t('创建第一个函数') . '</a>';
        $output .= '</div>';
      }
      
      $output .= '</div>';

      return [
        '#markup' => $output,
        '#cache' => [
          'contexts' => ['user'],
          'tags' => ['baas_functions:' . $project_id],
        ],
      ];
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to load functions: @message', [
        '@message' => $e->getMessage(),
      ]));
      
      return [
        '#markup' => '<div class="error-message">' . $this->t('无法加载函数管理界面，请稍后重试。') . '</div>',
      ];
    }
  }

  /**
   * Access callback for project functions management.
   *
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account to check access for.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function accessProjectFunctions(string $tenant_id, string $project_id, AccountInterface $account): AccessResult {
    try {
      // Check if user can access the project
      $can_access = $this->permissionChecker->canAccessProject((int) $account->id(), $project_id);
      
      if (!$can_access) {
        return AccessResult::forbidden('User cannot access this project.');
      }
      
      // Check if user has basic functions permission
      if ($account->hasPermission('create project functions') || 
          $account->hasPermission('edit project functions') ||
          $account->hasPermission('access baas functions')) {
        return AccessResult::allowed();
      }

      return AccessResult::forbidden('User does not have permission to manage functions.');
    }
    catch (\Exception $e) {
      return AccessResult::forbidden('Access check failed: ' . $e->getMessage());
    }
  }

  /**
   * Activate a function (change status from draft to online).
   *
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   * @param string $function_id
   *   The function ID.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect response.
   */
  public function activateFunction(string $tenant_id, string $project_id, string $function_id): RedirectResponse {
    $current_user = $this->currentUser();
    
    try {
      // Update function status
      $this->functionManager->updateFunction($function_id, ['status' => 'online'], (int) $current_user->id());
      
      $this->messenger()->addStatus($this->t('Function has been successfully published and is now online.'));
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to publish function: @error', ['@error' => $e->getMessage()]));
    }
    
    return new RedirectResponse("/tenant/{$tenant_id}/project/{$project_id}/functions");
  }

  /**
   * Deactivate a function (change status from online to draft).
   *
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   * @param string $function_id
   *   The function ID.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   Redirect response.
   */
  public function deactivateFunction(string $tenant_id, string $project_id, string $function_id): RedirectResponse {
    $current_user = $this->currentUser();
    
    try {
      // Update function status
      $this->functionManager->updateFunction($function_id, ['status' => 'draft'], (int) $current_user->id());
      
      $this->messenger()->addStatus($this->t('Function has been taken offline and is now in draft status.'));
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to take function offline: @error', ['@error' => $e->getMessage()]));
    }
    
    return new RedirectResponse("/tenant/{$tenant_id}/project/{$project_id}/functions");
  }

  /**
   * Test function interface.
   *
   * @param string $tenant_id
   *   The tenant ID.
   * @param string $project_id
   *   The project ID.
   * @param string $function_id
   *   The function ID.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return array
   *   Render array for the test interface.
   */
  public function testFunction(string $tenant_id, string $project_id, string $function_id, Request $request): array {
    $current_user = $this->currentUser();
    
    try {
      // Get function details
      $function = $this->functionManager->getFunctionById($function_id);
      
      // Check if function is online
      if ($function['status'] !== 'online') {
        $this->messenger()->addWarning($this->t('This function is not online. Please publish it first to enable testing.'));
      }
      
      // Build test interface
      $output = '<div class="baas-function-test">';
      $output .= '<h1>' . $this->t('测试函数: @name', ['@name' => $function['function_name']]) . '</h1>';
      
      // Function information
      $output .= '<div class="function-info" style="background: #f8f9fa; padding: 1rem; margin-bottom: 2rem; border-radius: 4px;">';
      $output .= '<h3>' . $this->t('函数信息') . '</h3>';
      $output .= '<p><strong>' . $this->t('函数名称:') . '</strong> ' . htmlspecialchars($function['function_name']) . '</p>';
      $output .= '<p><strong>' . $this->t('显示名称:') . '</strong> ' . htmlspecialchars($function['display_name']) . '</p>';
      $output .= '<p><strong>' . $this->t('状态:') . '</strong> ';
      
      $status_color = match($function['status']) {
        'online' => 'green',
        'draft' => 'orange',
        'testing' => 'blue',
        default => 'gray'
      };
      
      $output .= '<span style="color: ' . $status_color . ';">' . ucfirst($function['status']) . '</span></p>';
      
      if (!empty($function['description'])) {
        $output .= '<p><strong>' . $this->t('描述:') . '</strong> ' . htmlspecialchars($function['description']) . '</p>';
      }
      
      $output .= '<p><strong>' . $this->t('API 端点:') . '</strong> <code>POST /api/v1/' . $tenant_id . '/projects/' . $project_id . '/functions/' . $function['function_name'] . '</code></p>';
      $output .= '</div>';
      
      // Test form
      $output .= '<div class="test-form" style="background: white; padding: 2rem; border: 1px solid #ddd; border-radius: 4px;">';
      $output .= '<h3>' . $this->t('函数测试') . '</h3>';
      
      $output .= '<form id="function-test-form" method="post" action="/tenant/' . $tenant_id . '/project/' . $project_id . '/functions/' . $function_id . '/test">';
      
      $output .= '<div class="form-group" style="margin-bottom: 1rem;">';
      $output .= '<label for="test-method" style="display: block; margin-bottom: 0.5rem; font-weight: bold;">' . $this->t('HTTP 方法:') . '</label>';
      $output .= '<select id="test-method" name="test_method" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;">';
      $output .= '<option value="POST">POST</option>';
      $output .= '<option value="GET">GET</option>';
      $output .= '<option value="PUT">PUT</option>';
      $output .= '<option value="DELETE">DELETE</option>';
      $output .= '</select>';
      $output .= '</div>';
      
      $output .= '<div class="form-group" style="margin-bottom: 1rem;">';
      $output .= '<label for="test-headers" style="display: block; margin-bottom: 0.5rem; font-weight: bold;">' . $this->t('请求头 (JSON):') . '</label>';
      $output .= '<div style="margin-bottom: 0.5rem; padding: 0.5rem; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 4px; font-size: 12px;">';
      $output .= '<strong>⚠️ 提示:</strong> 需要添加有效的 JWT token 才能测试函数。<br>';
      $output .= '请在下方添加: <code>"Authorization": "Bearer YOUR_JWT_TOKEN"</code>';
      $output .= '</div>';
      $output .= '<textarea id="test-headers" name="test_headers" rows="4" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-family: monospace;">';
      $output .= json_encode([
        'Content-Type' => 'application/json',
        'User-Agent' => 'BaaS-Test-Client',
        'Authorization' => 'Bearer YOUR_JWT_TOKEN_HERE'
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
      $output .= '</textarea>';
      $output .= '</div>';
      
      $output .= '<div class="form-group" style="margin-bottom: 1rem;">';
      $output .= '<label for="test-body" style="display: block; margin-bottom: 0.5rem; font-weight: bold;">' . $this->t('请求体 (JSON):') . '</label>';
      $output .= '<textarea id="test-body" name="test_body" rows="8" style="width: 100%; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; font-family: monospace;">';
      $output .= json_encode([
        'message' => 'Hello from test interface!',
        'timestamp' => date('c'),
        'test_data' => [
          'user_id' => (int) $current_user->id(),
          'function_name' => $function['function_name'],
          'test_id' => uniqid()
        ]
      ], JSON_PRETTY_PRINT);
      $output .= '</textarea>';
      $output .= '</div>';
      
      $output .= '<div class="form-actions" style="margin-bottom: 1rem;">';
      $output .= '<button type="button" data-tenant-id="' . $tenant_id . '" data-project-id="' . $project_id . '" data-function-name="' . $function['function_name'] . '" onclick="BaasFunctions.testFunction(this)" class="button button--primary" style="background: #28a745; color: white; padding: 0.75rem 1.5rem; border: none; border-radius: 4px; cursor: pointer; margin-right: 1rem;">' . $this->t('执行测试') . '</button>';
      $output .= '<button type="button" onclick="BaasFunctions.clearResults()" class="button" style="padding: 0.75rem 1.5rem; border: 1px solid #ddd; border-radius: 4px; cursor: pointer; background: white;">' . $this->t('清除结果') . '</button>';
      $output .= '</div>';
      
      $output .= '</form>';
      $output .= '</div>';
      
      // Test results area
      $output .= '<div id="test-results" style="margin-top: 2rem; display: none;">';
      $output .= '<h3>' . $this->t('测试结果') . '</h3>';
      $output .= '<div id="test-output" style="background: #f8f9fa; padding: 1rem; border-radius: 4px; border: 1px solid #ddd; font-family: monospace; white-space: pre-wrap;"></div>';
      $output .= '</div>';
      
      $output .= '</div>';
      
      return [
        '#markup' => Markup::create($output),
        '#attached' => [
          'library' => ['baas_functions/function_test'],
        ],
        '#cache' => [
          'contexts' => ['user'],
          'tags' => ['baas_functions:' . $function_id],
        ],
      ];
      
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to load function test interface: @error', ['@error' => $e->getMessage()]));
      
      return [
        '#markup' => '<div class="error-message">' . $this->t('无法加载测试界面，请稍后重试。') . '</div>',
      ];
    }
  }

}