<?php

declare(strict_types=1);

namespace Drupal\baas_tenant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_tenant\TenantManagerInterface;
use Drupal\baas_auth\Service\ApiKeyManagerInterface;

/**
 * 用户API密钥管理控制器。
 */
class UserApiKeyController extends ControllerBase
{

  /**
   * 租户管理服务。
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected $tenantManager;

  /**
   * API密钥管理服务。
   *
   * @var \Drupal\baas_auth\Service\ApiKeyManagerInterface
   */
  protected $apiKeyManager;

  /**
   * 构造函数。
   */
  public function __construct(
    TenantManagerInterface $tenant_manager,
    ApiKeyManagerInterface $api_key_manager
  ) {
    $this->tenantManager = $tenant_manager;
    $this->apiKeyManager = $api_key_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_tenant.manager'),
      $container->get('baas_auth.api_key_manager')
    );
  }

  /**
   * 显示用户的API密钥管理页面。
   */
  public function userApiKeys(): array
  {
    $current_user = $this->currentUser();
    $user_id = (int) $current_user->id();
    
    // 获取用户的租户信息
    $user_tenant = $this->getUserTenant($user_id);
    if (!$user_tenant) {
      $this->messenger()->addError($this->t('您还没有租户权限，无法管理API密钥。'));
      return [
        '#markup' => '<p>' . $this->t('请联系管理员为您分配租户权限。') . '</p>',
      ];
    }

    $tenant_id = $user_tenant['tenant_id'];
    
    // 获取用户的API密钥
    $api_keys = $this->apiKeyManager->listApiKeys($tenant_id, $user_id);

    $build = [];

    // 页面标题和描述
    $build['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['api-keys-header']],
    ];

    $build['header']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h1',
      '#value' => $this->t('API密钥管理'),
    ];

    $build['header']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('API密钥用于访问BaaS平台的API服务。请妥善保管您的API密钥，不要与他人分享。'),
      '#attributes' => ['class' => ['help-text']],
    ];

    // 租户信息
    $build['tenant_info'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['tenant-info', 'mb-4']],
    ];

    $build['tenant_info']['info'] = [
      '#type' => 'item',
      '#title' => $this->t('租户信息'),
      '#markup' => $this->t('租户: @name (@id)', [
        '@name' => $user_tenant['name'],
        '@id' => $tenant_id,
      ]),
    ];

    // API密钥列表
    if (!empty($api_keys)) {
      $build['api_keys_table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('名称'),
          $this->t('API密钥'),
          $this->t('权限'),
          $this->t('状态'),
          $this->t('创建时间'),
          $this->t('最后使用'),
          $this->t('操作'),
        ],
        '#empty' => $this->t('您还没有创建任何API密钥。'),
        '#attributes' => ['class' => ['api-keys-table']],
      ];

      foreach ($api_keys as $key) {
        $key_id = $key['id'];
        $masked_key = 'drubase_' . substr($key['api_key'], 8, 8) . '...' . substr($key['api_key'], -8);
        
        $build['api_keys_table'][$key_id] = [
          'name' => [
            '#markup' => '<strong>' . $this->t($key['name']) . '</strong>',
          ],
          'api_key' => [
            'data' => [
              '#type' => 'inline_template',
              '#template' => '<div class="api-key-cell">
                <code class="api-key-value" data-full-key="{{ api_key }}">{{ masked_key }}</code>
                <div class="api-key-buttons mt-1">
                  <button type="button" class="btn btn-sm btn-secondary toggle-key-btn me-1">{{ show_text }}</button>
                  <button type="button" class="btn btn-sm btn-outline-primary copy-key-btn">{{ copy_text }}</button>
                </div>
              </div>',
              '#context' => [
                'api_key' => $key['api_key'],
                'masked_key' => $masked_key,
                'show_text' => $this->t('显示'),
                'copy_text' => $this->t('复制'),
              ],
            ],
          ],
          'permissions' => [
            '#markup' => !empty($key['permissions']) && is_array($key['permissions']) ? implode(', ', $key['permissions']) : $this->t('无特殊权限'),
          ],
          'status' => [
            '#markup' => $key['status'] ? 
              '<span class="badge badge-success">' . $this->t('启用') . '</span>' : 
              '<span class="badge badge-danger">' . $this->t('禁用') . '</span>',
          ],
          'created' => [
            '#markup' => date('Y-m-d H:i', (int) $key['created']),
          ],
          'last_used' => [
            '#markup' => $key['last_used'] ? date('Y-m-d H:i', (int) $key['last_used']) : $this->t('从未使用'),
          ],
          'operations' => [
            '#type' => 'operations',
            '#links' => [
              'delete' => [
                'title' => $this->t('删除'),
                'url' => Url::fromRoute('baas_tenant.user_api_key_delete', ['key_id' => $key_id]),
                'attributes' => [
                  'class' => ['btn', 'btn-sm', 'btn-danger'],
                  'onclick' => 'return confirm("' . $this->t('确定要删除这个API密钥吗？删除后无法恢复。') . '");',
                ],
              ],
            ],
          ],
        ];
      }
    } else {
      $build['empty_state'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['empty-state', 'text-center', 'py-5']],
      ];

      $build['empty_state']['icon'] = [
        '#markup' => '<div class="mb-3"><i class="fas fa-key fa-3x text-muted"></i></div>',
      ];

      $build['empty_state']['message'] = [
        '#markup' => '<h4>' . $this->t('还没有API密钥') . '</h4><p class="text-muted">' . $this->t('创建您的第一个API密钥以开始使用API服务。') . '</p>',
      ];
    }

    // 创建新密钥按钮
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['api-keys-actions', 'mt-4']],
    ];

    $build['actions']['create'] = [
      '#type' => 'link',
      '#title' => $this->t('创建新API密钥'),
      '#url' => Url::fromRoute('baas_tenant.user_api_key_create'),
      '#attributes' => ['class' => ['btn', 'btn-primary', 'btn-lg']],
    ];

    // 添加JavaScript和CSS
    $build['#attached']['library'][] = 'baas_tenant/api-keys';

    return $build;
  }

  /**
   * 检查API密钥管理访问权限。
   */
  public static function checkApiKeyAccess(AccountInterface $account): AccessResult
  {
    // 检查用户是否有项目管理权限或租户权限
    if ($account->hasPermission('create baas project') || 
        $account->hasPermission('view baas project') ||
        in_array('project_manager', $account->getRoles())) {
      return AccessResult::allowed();
    }

    return AccessResult::forbidden('用户没有API密钥管理权限');
  }

  /**
   * 获取用户的租户信息。
   */
  protected function getUserTenant(int $user_id): ?array
  {
    try {
      // 从baas_tenant模块的函数获取用户租户信息
      if (function_exists('baas_tenant_get_user_tenant')) {
        return baas_tenant_get_user_tenant($user_id);
      }

      // 如果函数不存在，尝试直接查询
      $database = \Drupal::database();
      $query = $database->select('baas_tenant_config', 't')
        ->fields('t')
        ->condition('owner_uid', $user_id)
        ->condition('status', 1)
        ->orderBy('created', 'DESC')
        ->range(0, 1);

      $result = $query->execute()->fetchAssoc();
      return $result ?: NULL;
    } catch (\Exception $e) {
      $this->getLogger('baas_tenant')->error('获取用户租户信息失败: @error', ['@error' => $e->getMessage()]);
      return NULL;
    }
  }
}