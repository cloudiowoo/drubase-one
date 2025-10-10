<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\baas_functions\Service\ProjectFunctionManager;
use Drupal\baas_project\ProjectManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * User Function Controller - Frontend interface for tenant users.
 */
class UserFunctionController extends ControllerBase {

  public function __construct(
    protected readonly Connection $database,
    protected readonly ProjectFunctionManager $functionManager,
    protected readonly ProjectManager $projectManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('baas_functions.manager'),
      $container->get('baas_project.manager'),
    );
  }

  /**
   * Display project selection for creating functions.
   *
   * @return array
   *   Render array for the project selection page.
   */
  public function selectProjectForCreate(): array {
    $current_user = $this->currentUser();
    
    try {
      // Get user's projects where they can create functions
      $user_projects = $this->getUserProjects((int) $current_user->id());
      
      if (empty($user_projects)) {
        return [
          '#markup' => '<div class="no-projects">' . 
            '<h2>' . $this->t('没有可用的项目') . '</h2>' .
            '<p>' . $this->t('您需要先创建或加入一个项目才能创建函数。') . '</p>' .
            '<a href="/user/projects" class="button button--primary">' . $this->t('管理我的项目') . '</a>' .
            '</div>',
        ];
      }
      
      // Build project selection interface
      $output = '<div class="project-selection">';
      $output .= '<h1>' . $this->t('选择项目创建函数') . '</h1>';
      $output .= '<p>' . $this->t('请选择要在哪个项目中创建新的 Edge Function：') . '</p>';
      
      $output .= '<div class="projects-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem; margin: 2rem 0;">';
      
      foreach ($user_projects as $project) {
        $output .= '<div class="project-card" style="border: 1px solid #ddd; border-radius: 8px; padding: 1.5rem; background: #f9f9f9;">';
        $output .= '<h3>' . htmlspecialchars($project['name']) . '</h3>';
        $output .= '<p><strong>' . $this->t('角色:') . '</strong> ' . ucfirst($project['role']) . '</p>';
        $output .= '<a href="/tenant/' . $project['tenant_id'] . '/project/' . $project['project_id'] . '/functions" class="button button--primary">' . $this->t('在此项目中创建函数') . '</a>';
        $output .= '</div>';
      }
      
      $output .= '</div>'; // projects-grid
      $output .= '</div>'; // project-selection
      
      return [
        '#markup' => $output,
        '#cache' => [
          'contexts' => ['user'],
        ],
      ];
      
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('获取项目信息时发生错误: @message', [
        '@message' => $e->getMessage(),
      ]));
      
      return [
        '#markup' => '<div class="error-message">' . $this->t('无法加载项目信息，请稍后重试。') . '</div>',
      ];
    }
  }

  /**
   * Display user functions dashboard.
   *
   * @return array
   *   Render array for the dashboard.
   */
  public function dashboard(): array {
    $current_user = $this->currentUser();
    
    try {
      // Get user's projects
      $user_projects = $this->getUserProjects((int) $current_user->id());
      
      // Get functions from all user projects
      $user_functions = [];
      $function_stats = [
        'total_functions' => 0,
        'online_functions' => 0,
        'draft_functions' => 0,
        'total_executions' => 0,
      ];
      
      foreach ($user_projects as $project) {
        $project_functions = $this->getFunctionsByProject($project['project_id']);
        
        foreach ($project_functions as $function) {
          $function['project_name'] = $project['name'];
          $function['tenant_id'] = $project['tenant_id'];
          $user_functions[] = $function;
          
          $function_stats['total_functions']++;
          if ($function['status'] === 'online') {
            $function_stats['online_functions']++;
          } elseif ($function['status'] === 'draft') {
            $function_stats['draft_functions']++;
          }
          $function_stats['total_executions'] += $function['call_count'];
        }
      }
      
      // Build dashboard output
      $output = '<div class="baas-functions-user-dashboard">';
      $output .= '<h1>' . $this->t('我的函数') . '</h1>';
      
      // Statistics overview
      $output .= '<div class="functions-stats">';
      $output .= '<h2>' . $this->t('概览') . '</h2>';
      $output .= '<div class="stats-grid" style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; margin-bottom: 2rem;">';
      
      $output .= '<div class="stat-card" style="padding: 1rem; border: 1px solid #ddd; border-radius: 4px;">';
      $output .= '<h3>' . $function_stats['total_functions'] . '</h3>';
      $output .= '<p>' . $this->t('总函数数') . '</p>';
      $output .= '</div>';
      
      $output .= '<div class="stat-card" style="padding: 1rem; border: 1px solid #ddd; border-radius: 4px;">';
      $output .= '<h3 style="color: green;">' . $function_stats['online_functions'] . '</h3>';
      $output .= '<p>' . $this->t('在线函数') . '</p>';
      $output .= '</div>';
      
      $output .= '<div class="stat-card" style="padding: 1rem; border: 1px solid #ddd; border-radius: 4px;">';
      $output .= '<h3 style="color: orange;">' . $function_stats['draft_functions'] . '</h3>';
      $output .= '<p>' . $this->t('草稿函数') . '</p>';
      $output .= '</div>';
      
      $output .= '<div class="stat-card" style="padding: 1rem; border: 1px solid #ddd; border-radius: 4px;">';
      $output .= '<h3>' . $function_stats['total_executions'] . '</h3>';
      $output .= '<p>' . $this->t('总执行次数') . '</p>';
      $output .= '</div>';
      
      $output .= '</div>'; // stats-grid
      $output .= '</div>'; // functions-stats
      
      // Functions list
      if (!empty($user_functions)) {
        $output .= '<div class="functions-list">';
        $output .= '<h2>' . $this->t('我的函数列表') . '</h2>';
        $output .= '<table class="table" style="width: 100%; border-collapse: collapse;">';
        $output .= '<thead><tr style="background-color: #f5f5f5;">';
        $output .= '<th style="padding: 0.5rem; border: 1px solid #ddd;">' . $this->t('函数名') . '</th>';
        $output .= '<th style="padding: 0.5rem; border: 1px solid #ddd;">' . $this->t('所属项目') . '</th>';
        $output .= '<th style="padding: 0.5rem; border: 1px solid #ddd;">' . $this->t('状态') . '</th>';
        $output .= '<th style="padding: 0.5rem; border: 1px solid #ddd;">' . $this->t('调用次数') . '</th>';
        $output .= '<th style="padding: 0.5rem; border: 1px solid #ddd;">' . $this->t('平均响应时间') . '</th>';
        $output .= '<th style="padding: 0.5rem; border: 1px solid #ddd;">' . $this->t('操作') . '</th>';
        $output .= '</tr></thead><tbody>';
        
        foreach ($user_functions as $function) {
          $status_color = match($function['status']) {
            'online' => 'green',
            'draft' => 'orange',
            'testing' => 'blue',
            default => 'gray'
          };
          
          $output .= '<tr>';
          $output .= '<td style="padding: 0.5rem; border: 1px solid #ddd;"><strong>' . htmlspecialchars($function['function_name']) . '</strong>';
          if (!empty($function['display_name'])) {
            $output .= '<br><small>' . htmlspecialchars($function['display_name']) . '</small>';
          }
          $output .= '</td>';
          $output .= '<td style="padding: 0.5rem; border: 1px solid #ddd;">' . htmlspecialchars($function['project_name']) . '</td>';
          $output .= '<td style="padding: 0.5rem; border: 1px solid #ddd;"><span style="color: ' . $status_color . ';">' . ucfirst($function['status']) . '</span></td>';
          $output .= '<td style="padding: 0.5rem; border: 1px solid #ddd;">' . $function['call_count'] . '</td>';
          $output .= '<td style="padding: 0.5rem; border: 1px solid #ddd;">' . $function['avg_response_time'] . 'ms</td>';
          $output .= '<td style="padding: 0.5rem; border: 1px solid #ddd;">';
          $output .= '<a href="/tenant/' . $function['tenant_id'] . '/project/' . $function['project_id'] . '/functions" class="button">' . $this->t('管理') . '</a>';
          $output .= '</td>';
          $output .= '</tr>';
        }
        
        $output .= '</tbody></table>';
        $output .= '</div>'; // functions-list
      } else {
        $output .= '<div class="no-functions" style="text-align: center; padding: 2rem;">';
        $output .= '<h3>' . $this->t('还没有函数') . '</h3>';
        $output .= '<p>' . $this->t('您还没有创建任何 Edge Functions。') . '</p>';
        if (!empty($user_projects)) {
          $first_project = reset($user_projects);
          $output .= '<a href="/tenant/' . $first_project['tenant_id'] . '/project/' . $first_project['project_id'] . '/functions" class="button button--primary">' . $this->t('创建第一个函数') . '</a>';
        }
        $output .= '</div>';
      }
      
      $output .= '</div>'; // baas-functions-user-dashboard
      
      return [
        '#markup' => $output,
        '#cache' => [
          'max-age' => 300, // Cache for 5 minutes
          'contexts' => ['user'],
        ],
      ];
      
    } catch (\Exception $e) {
      $this->messenger()->addError($this->t('获取函数信息时发生错误: @message', [
        '@message' => $e->getMessage(),
      ]));
      
      return [
        '#markup' => '<div class="error-message">' . $this->t('无法加载函数信息，请稍后重试。') . '</div>',
      ];
    }
  }

  /**
   * Get projects for a user.
   *
   * @param int $user_id
   *   The user ID.
   *
   * @return array
   *   Array of user projects.
   */
  protected function getUserProjects(int $user_id): array {
    try {
      $query = $this->database->select('baas_project_members', 'pm')
        ->fields('pm', ['project_id', 'role'])
        ->condition('pm.user_id', $user_id)
        ->condition('pm.status', 1);
      
      $query->join('baas_project_config', 'pc', 'pm.project_id = pc.project_id');
      $query->addField('pc', 'name');
      $query->addField('pc', 'tenant_id');
      
      return $query->execute()->fetchAllAssoc('project_id', \PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
      $this->getLogger('baas_functions')->error('Failed to get user projects: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Get functions by project ID.
   *
   * @param string $project_id
   *   The project ID.
   *
   * @return array
   *   Array of project functions.
   */
  protected function getFunctionsByProject(string $project_id): array {
    try {
      return $this->database->select('baas_project_functions', 'f')
        ->fields('f')
        ->condition('f.project_id', $project_id)
        ->orderBy('f.updated_at', 'DESC')
        ->execute()
        ->fetchAllAssoc('id', \PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
      $this->getLogger('baas_functions')->error('Failed to get project functions: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

}