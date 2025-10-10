<?php

declare(strict_types=1);

namespace Drupal\baas_functions\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\baas_functions\Service\EnvironmentVariableManager;
use Drupal\baas_project\ProjectManager;
use Drupal\baas_auth\Service\UnifiedPermissionChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * 环境变量管理界面控制器
 */
class EnvironmentVariableController extends ControllerBase {

  public function __construct(
    protected readonly EnvironmentVariableManager $environmentVariableManager,
    protected readonly ProjectManager $projectManager,
    protected readonly UnifiedPermissionChecker $permissionChecker,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_functions.env_manager'),
      $container->get('baas_project.manager'),
      $container->get('baas_auth.unified_permission_checker'),
    );
  }

  /**
   * 环境变量管理主界面
   */
  public function envVarsManager(Request $request, string $tenant_id, string $project_id): array {
    // 验证项目访问权限
    $project = $this->projectManager->getProject($project_id);
    if (!$project || $project['tenant_id'] !== $tenant_id) {
      throw $this->createNotFoundException('Project not found');
    }

    // 获取环境变量列表
    $env_vars = $this->environmentVariableManager->getProjectEnvVars($project_id, FALSE);

    // 构建表格数据
    $rows = [];
    foreach ($env_vars as $env_var) {
      $actions = [];
      
      // 编辑链接
      $actions[] = Link::createFromRoute(
        '编辑',
        'baas_functions.project_env_var_edit',
        [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
          'var_name' => $env_var['var_name'],
        ],
        ['attributes' => ['class' => ['button', 'button--small']]]
      );

      // 删除链接
      $actions[] = Link::createFromRoute(
        '删除',
        'baas_functions.project_env_var_delete',
        [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
          'var_name' => $env_var['var_name'],
        ],
        ['attributes' => ['class' => ['button', 'button--small', 'button--danger']]]
      );

      $rows[] = [
        'var_name' => $env_var['var_name'],
        'description' => $env_var['description'] ?: '无描述',
        'created_at' => date('Y-m-d H:i:s', (int) $env_var['created_at']),
        'updated_at' => date('Y-m-d H:i:s', (int) $env_var['updated_at']),
        'actions' => [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'edit' => [
                'title' => '编辑',
                'url' => Url::fromRoute('baas_functions.project_env_var_edit', [
                  'tenant_id' => $tenant_id,
                  'project_id' => $project_id,
                  'var_name' => $env_var['var_name'],
                ]),
              ],
              'delete' => [
                'title' => '删除',
                'url' => Url::fromRoute('baas_functions.project_env_var_delete', [
                  'tenant_id' => $tenant_id,
                  'project_id' => $project_id,
                  'var_name' => $env_var['var_name'],
                ]),
              ],
            ],
          ],
        ],
      ];
    }

    $build = [];

    // 加载样式库
    $build['#attached']['library'][] = 'baas_functions/environment_variables';

    // 页面标题和面包屑
    $build['breadcrumb'] = [
      '#theme' => 'item_list',
      '#list_type' => 'ol',
      '#attributes' => ['class' => ['breadcrumb']],
      '#items' => [
        Link::createFromRoute('我的项目', 'baas_project.user_list'),
        Link::createFromRoute($project['name'], 'baas_functions.project_manager', [
          'tenant_id' => $tenant_id,
          'project_id' => $project_id,
        ]),
        ['#markup' => '环境变量管理'],
      ],
    ];

    // 操作按钮
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['action-buttons']],
    ];

    $build['actions']['create'] = [
      '#type' => 'link',
      '#title' => '创建环境变量',
      '#url' => Url::fromRoute('baas_functions.project_env_var_create', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
      ]),
      '#attributes' => [
        'class' => ['button', 'button--primary'],
      ],
    ];

    $build['actions']['import'] = [
      '#type' => 'link',
      '#title' => '批量导入',
      '#url' => Url::fromRoute('baas_functions.project_env_vars_import', [
        'tenant_id' => $tenant_id,
        'project_id' => $project_id,
      ]),
      '#attributes' => [
        'class' => ['button'],
      ],
    ];

    // 环境变量列表表格
    $build['env_vars_table'] = [
      '#type' => 'table',
      '#header' => [
        '变量名',
        '描述',
        '创建时间',
        '更新时间',
        '操作',
      ],
      '#rows' => $rows,
      '#empty' => '暂无环境变量。点击上方按钮创建第一个环境变量。',
      '#attributes' => [
        'class' => ['env-vars-table'],
      ],
    ];

    // 帮助信息
    $build['help'] = [
      '#type' => 'details',
      '#title' => '使用说明',
      '#open' => FALSE,
    ];

    $build['help']['content'] = [
      '#markup' => '
        <div class="env-vars-help">
          <h4>环境变量使用说明：</h4>
          <ul>
            <li><strong>安全存储</strong>：所有环境变量值都经过加密存储，确保敏感信息安全</li>
            <li><strong>函数访问</strong>：在Edge函数中通过 <code>context.env.变量名</code> 访问环境变量</li>
            <li><strong>项目隔离</strong>：环境变量仅在当前项目内可用，实现项目级隔离</li>
            <li><strong>常用场景</strong>：API密钥、数据库连接、第三方服务配置等</li>
          </ul>
          
          <h4>示例用法：</h4>
          <pre><code>export default async function(request, context) {
  // 获取环境变量
  const apiKey = context.env.API_KEY;
  const dbUrl = context.env.DATABASE_URL;
  
  // 使用环境变量
  const response = await fetch(apiUrl, {
    headers: { "Authorization": `Bearer ${apiKey}` }
  });
  
  return context.success(response);
}</code></pre>
        </div>
      ',
    ];

    return $build;
  }

  /**
   * 检查环境变量管理权限
   */
  public function accessManageEnvVars(AccountInterface $account, string $tenant_id, string $project_id): AccessResult {
    // 检查用户是否有管理环境变量的权限
    $has_permission = $this->permissionChecker->checkProjectPermission(
      (int) $account->id(),
      $project_id,
      'manage project env vars'
    );

    return $has_permission ? AccessResult::allowed() : AccessResult::forbidden();
  }
}