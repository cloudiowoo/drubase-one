<?php

declare(strict_types=1);

namespace Drupal\baas_project\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\baas_project\ProjectManager;
use Drupal\baas_project\Service\ResourceLimitManager;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * 项目资源限制配置表单
 * 
 * 用于配置项目级别的资源限制，包括：
 * - 存储空间限制
 * - API调用次数限制
 * - 实体数量限制
 * - 其他资源限制
 */
class ResourceLimitsForm extends FormBase {

  protected ProjectManager $projectManager;
  protected ResourceLimitManager $resourceLimitManager;
  protected LoggerChannelFactoryInterface $logger;

  public function __construct(
    ProjectManager $project_manager,
    ResourceLimitManager $resource_limit_manager,
    LoggerChannelFactoryInterface $logger
  ) {
    $this->projectManager = $project_manager;
    $this->resourceLimitManager = $resource_limit_manager;
    $this->logger = $logger;
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('baas_project.manager'),
      $container->get('baas_project.resource_limit_manager'),
      $container->get('logger.factory')
    );
  }

  public function getFormId(): string {
    return 'baas_project_resource_limits_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?string $project_id = NULL): array {
    if (!$project_id) {
      $this->messenger()->addError($this->t('项目ID参数缺失'));
      return $form;
    }

    try {
      $project = $this->projectManager->getProject($project_id);
      if (!$project) {
        $this->messenger()->addError($this->t('项目不存在'));
        return $form;
      }

      // 获取当前项目设置
      $project_settings_raw = $project['settings'] ?? '{}';
      if (is_string($project_settings_raw)) {
        $settings = json_decode($project_settings_raw, true) ?: [];
      } else {
        $settings = is_array($project_settings_raw) ? $project_settings_raw : [];
      }
      
      // 获取有效限制（包括继承的租户限制）
      $effective_limits = $this->resourceLimitManager->getEffectiveLimits($project_id);
      
      $form['#tree'] = TRUE;
      $form['project_id'] = [
        '#type' => 'value',
        '#value' => $project_id,
      ];

      $form['project_info'] = [
        '#type' => 'item',
        '#title' => $this->t('项目信息'),
        '#markup' => $this->t('项目: @name (@id)', [
          '@name' => $project['name'],
          '@id' => $project_id,
        ]),
      ];

      // 存储限制配置
      $form['storage_limits'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('存储限制'),
        '#description' => $this->t('配置项目可使用的存储空间限制'),
      ];

      $form['storage_limits']['max_storage'] = [
        '#type' => 'number',
        '#title' => $this->t('最大存储空间 (MB)'),
        '#default_value' => $settings['max_storage'] ?? '',
        '#min' => 1,
        '#max' => 10240,
        '#step' => 1,
        '#description' => $this->t('项目可使用的最大存储空间，留空表示使用租户默认值。当前有效值: @value MB', [
          '@value' => number_format($effective_limits['max_storage'] / 1024 / 1024, 0),
        ]),
        '#placeholder' => $this->t('留空使用租户默认值'),
      ];

      $form['storage_limits']['max_file_count'] = [
        '#type' => 'number',
        '#title' => $this->t('最大文件数量'),
        '#default_value' => $settings['max_file_count'] ?? '',
        '#min' => 1,
        '#max' => 10000,
        '#step' => 1,
        '#description' => $this->t('项目可上传的最大文件数量，留空表示无限制'),
        '#placeholder' => $this->t('留空表示无限制'),
      ];

      // API配额由租户层面统一管理，项目级不再设置配额

      // API访问控制配置
      $form['rate_limiting'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('API访问控制'),
        '#description' => $this->t('配置项目级别的API访问频率控制，防止恶意调用和保护系统稳定性。设置的值不能超过全局限制。'),
      ];

      // 获取全局限制用于验证
      $global_config = \Drupal::config('baas_api.settings');
      $global_limits = $global_config->get('rate_limits') ?? [];
      $global_user_limit = $global_limits['user']['requests'] ?? 60;
      $global_ip_limit = $global_limits['ip']['requests'] ?? 30;

      $form['rate_limiting']['enable_project_rate_limiting'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('启用项目级API访问控制'),
        '#description' => $this->t('开启后将对此项目的API请求进行频率控制，防止滥用'),
        '#default_value' => $settings['rate_limits']['enable_project_rate_limiting'] ?? FALSE,
      ];

      // 用户级访问频率控制
      $form['rate_limiting']['user_limits'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('认证用户访问频率'),
        '#description' => $this->t('控制单个认证用户的API调用频率，防止单用户过度使用'),
        '#states' => [
          'visible' => [
            ':input[name="rate_limiting[enable_project_rate_limiting]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['rate_limiting']['user_limits']['user_requests'] = [
        '#type' => 'number',
        '#title' => $this->t('每分钟请求数'),
        '#default_value' => $settings['rate_limits']['user']['requests'] ?? '',
        '#min' => 1,
        '#max' => $global_user_limit,
        '#description' => $this->t('认证用户每分钟允许的最大请求数（不能超过全局限制: @limit）', [
          '@limit' => $global_user_limit
        ]),
        '#placeholder' => $this->t('留空使用全局默认值'),
      ];

      $form['rate_limiting']['user_limits']['user_window'] = [
        '#type' => 'select',
        '#title' => $this->t('时间窗口'),
        '#options' => [
          60 => $this->t('1分钟'),
          300 => $this->t('5分钟'), 
          600 => $this->t('10分钟'),
          3600 => $this->t('1小时'),
        ],
        '#default_value' => $settings['rate_limits']['user']['window'] ?? 60,
        '#description' => $this->t('限流时间窗口'),
      ];

      $form['rate_limiting']['user_limits']['user_burst'] = [
        '#type' => 'number',
        '#title' => $this->t('突发容量'),
        '#default_value' => $settings['rate_limits']['user']['burst'] ?? '',
        '#min' => 1,
        '#max' => 100,
        '#description' => $this->t('允许的突发请求数量'),
        '#placeholder' => $this->t('留空自动计算'),
      ];

      // IP级访问频率控制
      $form['rate_limiting']['ip_limits'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('匿名访问频率控制'),
        '#description' => $this->t('控制单个IP地址的API调用频率，防止恶意攻击和爬虫'),
        '#states' => [
          'visible' => [
            ':input[name="rate_limiting[enable_project_rate_limiting]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['rate_limiting']['ip_limits']['ip_requests'] = [
        '#type' => 'number',
        '#title' => $this->t('每分钟请求数'),
        '#default_value' => $settings['rate_limits']['ip']['requests'] ?? '',
        '#min' => 1,
        '#max' => $global_ip_limit,
        '#description' => $this->t('匿名用户每分钟允许的最大请求数（不能超过全局限制: @limit）', [
          '@limit' => $global_ip_limit
        ]),
        '#placeholder' => $this->t('留空使用全局默认值'),
      ];

      $form['rate_limiting']['ip_limits']['ip_window'] = [
        '#type' => 'select',
        '#title' => $this->t('时间窗口'),
        '#options' => [
          60 => $this->t('1分钟'),
          300 => $this->t('5分钟'), 
          600 => $this->t('10分钟'),
          3600 => $this->t('1小时'),
        ],
        '#default_value' => $settings['rate_limits']['ip']['window'] ?? 60,
        '#description' => $this->t('限流时间窗口'),
      ];

      $form['rate_limiting']['ip_limits']['ip_burst'] = [
        '#type' => 'number',
        '#title' => $this->t('突发容量'),
        '#default_value' => $settings['rate_limits']['ip']['burst'] ?? '',
        '#min' => 1,
        '#max' => 50,
        '#description' => $this->t('允许的突发请求数量'),
        '#placeholder' => $this->t('留空自动计算'),
      ];

      // 特殊端点保护
      $form['rate_limiting']['endpoint_limits'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('敏感接口保护'),
        '#description' => $this->t('对特殊接口（如认证、密码重置等）设置更严格的访问限制'),
        '#collapsible' => TRUE,
        '#collapsed' => TRUE,
        '#states' => [
          'visible' => [
            ':input[name="rate_limiting[enable_project_rate_limiting]"]' => ['checked' => TRUE],
          ],
        ],
      ];

      $form['rate_limiting']['endpoint_limits']['auth_endpoints'] = [
        '#type' => 'number',
        '#title' => $this->t('认证端点限制 (/api/auth/*)'),
        '#default_value' => $settings['rate_limits']['endpoints']['/api/auth/']['requests'] ?? '',
        '#min' => 1,
        '#max' => 20,
        '#description' => $this->t('认证相关端点每分钟请求限制'),
        '#placeholder' => $this->t('留空表示无特殊限制'),
      ];

      $form['rate_limiting']['endpoint_limits']['auth_window'] = [
        '#type' => 'select',
        '#title' => $this->t('认证端点时间窗口'),
        '#options' => [
          60 => $this->t('1分钟'),
          300 => $this->t('5分钟'),
          600 => $this->t('10分钟'),
        ],
        '#default_value' => $settings['rate_limits']['endpoints']['/api/auth/']['window'] ?? 60,
      ];

      // 实体限制配置
      $form['entity_limits'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('实体限制'),
        '#description' => $this->t('配置项目中实体数量和复杂度限制'),
      ];

      $form['entity_limits']['max_entities'] = [
        '#type' => 'number',
        '#title' => $this->t('最大实体模板数量'),
        '#default_value' => $settings['max_entities'] ?? '',
        '#min' => 1,
        '#max' => 1000,
        '#step' => 1,
        '#description' => $this->t('项目可创建的最大实体模板数量，留空表示使用租户默认值。当前有效值: @value', [
          '@value' => $effective_limits['max_entities'] ?? 100,
        ]),
        '#placeholder' => $this->t('留空使用租户默认值'),
      ];

      $form['entity_limits']['max_entity_records'] = [
        '#type' => 'number',
        '#title' => $this->t('单个实体最大记录数'),
        '#default_value' => $settings['max_entity_records'] ?? '',
        '#min' => 100,
        '#max' => 100000,
        '#step' => 100,
        '#description' => $this->t('单个实体类型允许的最大记录数量'),
        '#placeholder' => $this->t('留空表示无限制'),
      ];

      $form['entity_limits']['max_entity_fields'] = [
        '#type' => 'number',
        '#title' => $this->t('单个实体最大字段数'),
        '#default_value' => $settings['max_entity_fields'] ?? '',
        '#min' => 5,
        '#max' => 100,
        '#step' => 1,
        '#description' => $this->t('单个实体模板允许的最大字段数量'),
        '#placeholder' => $this->t('留空表示无限制'),
      ];

      // 功能限制配置
      $form['feature_limits'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('功能限制'),
        '#description' => $this->t('配置项目可使用的功能限制'),
        '#collapsible' => TRUE,
        '#collapsed' => TRUE,
      ];

      $form['feature_limits']['enable_file_uploads'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('允许文件上传'),
        '#default_value' => $settings['enable_file_uploads'] ?? TRUE,
        '#description' => $this->t('是否允许在此项目中上传文件'),
      ];

      $form['feature_limits']['enable_realtime_features'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('允许实时功能'),
        '#default_value' => $settings['enable_realtime_features'] ?? TRUE,
        '#description' => $this->t('是否允许使用WebSocket等实时功能'),
      ];

      $form['feature_limits']['enable_functions'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('允许云函数'),
        '#default_value' => $settings['enable_functions'] ?? TRUE,
        '#description' => $this->t('是否允许部署和运行云函数'),
      ];

      // 警告阈值配置
      $form['warning_thresholds'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('警告阈值'),
        '#description' => $this->t('配置资源使用警告阈值'),
        '#collapsible' => TRUE,
        '#collapsed' => TRUE,
      ];

      $form['warning_thresholds']['storage_warning_threshold'] = [
        '#type' => 'number',
        '#title' => $this->t('存储空间警告阈值 (%)'),
        '#default_value' => $settings['storage_warning_threshold'] ?? 80,
        '#min' => 50,
        '#max' => 95,
        '#step' => 5,
        '#description' => $this->t('当存储空间使用率达到此百分比时发送警告'),
      ];

      // API调用警告阈值已移除，因为项目级不再设置API配额

      // 生效时间配置
      $form['effective_from'] = [
        '#type' => 'datetime',
        '#title' => $this->t('生效时间'),
        '#default_value' => !empty($settings['effective_from']) 
          ? \DateTime::createFromFormat('U', (string)$settings['effective_from'])
          : new \DateTime(),
        '#description' => $this->t('配置的生效时间，留空表示立即生效'),
      ];

      $form['actions'] = [
        '#type' => 'actions',
      ];

      $form['actions']['submit'] = [
        '#type' => 'submit',
        '#value' => $this->t('保存配置'),
        '#button_type' => 'primary',
      ];

      $form['actions']['cancel'] = [
        '#type' => 'link',
        '#title' => $this->t('取消'),
        '#url' => Url::fromRoute('baas_project.user_view', [
          'tenant_id' => $project['tenant_id'],
          'project_id' => $project_id,
        ]),
        '#attributes' => [
          'class' => ['button'],
        ],
      ];

    } catch (\Exception $e) {
      $this->logger->get('baas_project')->error('加载项目资源限制配置失败: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
      ]);
      $this->messenger()->addError($this->t('加载项目配置失败，请稍后重试'));
    }

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $values = $form_state->getValues();
    
    // 验证存储限制
    if (!empty($values['storage_limits']['max_storage'])) {
      $max_storage = (int) $values['storage_limits']['max_storage'];
      if ($max_storage < 1 || $max_storage > 10240) {
        $form_state->setErrorByName('storage_limits][max_storage', 
          $this->t('存储空间限制必须在1MB到10GB之间'));
      }
    }

    // API配额相关验证已移除，由租户层面统一管理

    // 验证实体限制
    if (!empty($values['entity_limits']['max_entities']) && 
        !empty($values['entity_limits']['max_entity_records'])) {
      $max_entities = (int) $values['entity_limits']['max_entities'];
      $max_records = (int) $values['entity_limits']['max_entity_records'];
      
      // 防止总记录数过大
      if ($max_entities * $max_records > 1000000) {
        $form_state->setErrorByName('entity_limits][max_entity_records', 
          $this->t('实体数量和记录数的乘积不能超过1,000,000'));
      }
    }

    // 验证警告阈值
    $storage_threshold = (int) ($values['warning_thresholds']['storage_warning_threshold'] ?? 80);
    
    if ($storage_threshold < 50 || $storage_threshold > 95) {
      $form_state->setErrorByName('warning_thresholds][storage_warning_threshold', 
        $this->t('存储警告阈值必须在50%到95%之间'));
    }
    
    // API调用警告阈值验证已移除，因为项目级不再设置API配额

    // 验证限流配置
    if (!empty($values['rate_limiting']['enable_project_rate_limiting'])) {
      // 获取全局限制用于验证
      $global_config = \Drupal::config('baas_api.settings');
      $global_limits = $global_config->get('rate_limits') ?? [];
      
      // 验证用户限制
      if (!empty($values['rate_limiting']['user_limits']['user_requests'])) {
        $user_requests = (int)$values['rate_limiting']['user_limits']['user_requests'];
        $global_user_limit = $global_limits['user']['requests'] ?? 60;
        
        if ($user_requests > $global_user_limit) {
          $form_state->setErrorByName(
            'rate_limiting][user_limits][user_requests',
            $this->t('用户限制不能超过全局限制(@limit)', ['@limit' => $global_user_limit])
          );
        }
      }

      // 验证IP限制
      if (!empty($values['rate_limiting']['ip_limits']['ip_requests'])) {
        $ip_requests = (int)$values['rate_limiting']['ip_limits']['ip_requests'];
        $global_ip_limit = $global_limits['ip']['requests'] ?? 30;
        
        if ($ip_requests > $global_ip_limit) {
          $form_state->setErrorByName(
            'rate_limiting][ip_limits][ip_requests',
            $this->t('IP限制不能超过全局限制(@limit)', ['@limit' => $global_ip_limit])
          );
        }
      }

      // 验证突发容量不超过请求限制
      if (!empty($values['rate_limiting']['user_limits']['user_requests']) && 
          !empty($values['rate_limiting']['user_limits']['user_burst'])) {
        $user_requests = (int)$values['rate_limiting']['user_limits']['user_requests'];
        $user_burst = (int)$values['rate_limiting']['user_limits']['user_burst'];
        
        if ($user_burst > $user_requests) {
          $form_state->setErrorByName(
            'rate_limiting][user_limits][user_burst',
            $this->t('突发容量不能超过请求限制')
          );
        }
      }

      if (!empty($values['rate_limiting']['ip_limits']['ip_requests']) && 
          !empty($values['rate_limiting']['ip_limits']['ip_burst'])) {
        $ip_requests = (int)$values['rate_limiting']['ip_limits']['ip_requests'];
        $ip_burst = (int)$values['rate_limiting']['ip_limits']['ip_burst'];
        
        if ($ip_burst > $ip_requests) {
          $form_state->setErrorByName(
            'rate_limiting][ip_limits][ip_burst',
            $this->t('突发容量不能超过请求限制')
          );
        }
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $project_id = $form_state->getValue('project_id');
    $values = $form_state->getValues();

    try {
      $project = $this->projectManager->getProject($project_id);
      $project_settings_raw = $project['settings'] ?? '{}';
      if (is_string($project_settings_raw)) {
        $current_settings = json_decode($project_settings_raw, true) ?: [];
      } else {
        $current_settings = is_array($project_settings_raw) ? $project_settings_raw : [];
      }

      // 构建新的设置
      $new_settings = $current_settings;

      // 存储限制设置
      if (!empty($values['storage_limits']['max_storage'])) {
        $new_settings['max_storage'] = (int) $values['storage_limits']['max_storage'];
      } else {
        unset($new_settings['max_storage']);
      }

      if (!empty($values['storage_limits']['max_file_count'])) {
        $new_settings['max_file_count'] = (int) $values['storage_limits']['max_file_count'];
      } else {
        unset($new_settings['max_file_count']);
      }

      // API配额设置已移除，由租户层面统一管理

      // 实体限制设置
      if (!empty($values['entity_limits']['max_entities'])) {
        $new_settings['max_entities'] = (int) $values['entity_limits']['max_entities'];
      } else {
        unset($new_settings['max_entities']);
      }

      if (!empty($values['entity_limits']['max_entity_records'])) {
        $new_settings['max_entity_records'] = (int) $values['entity_limits']['max_entity_records'];
      } else {
        unset($new_settings['max_entity_records']);
      }

      if (!empty($values['entity_limits']['max_entity_fields'])) {
        $new_settings['max_entity_fields'] = (int) $values['entity_limits']['max_entity_fields'];
      } else {
        unset($new_settings['max_entity_fields']);
      }

      // 功能限制设置
      $new_settings['enable_file_uploads'] = (bool) $values['feature_limits']['enable_file_uploads'];
      $new_settings['enable_realtime_features'] = (bool) $values['feature_limits']['enable_realtime_features'];
      $new_settings['enable_functions'] = (bool) $values['feature_limits']['enable_functions'];

      // 警告阈值设置
      $new_settings['storage_warning_threshold'] = (int) $values['warning_thresholds']['storage_warning_threshold'];
      // API调用警告阈值已移除，因为项目级不再设置API配额

      // 处理限流配置
      if (!empty($values['rate_limiting']['enable_project_rate_limiting'])) {
        $new_settings['rate_limits'] = [
          'enable_project_rate_limiting' => TRUE,
        ];

        // 用户级限流配置
        if (!empty($values['rate_limiting']['user_limits']['user_requests'])) {
          $user_requests = (int)$values['rate_limiting']['user_limits']['user_requests'];
          $user_window = (int)$values['rate_limiting']['user_limits']['user_window'];
          $user_burst = !empty($values['rate_limiting']['user_limits']['user_burst']) 
            ? (int)$values['rate_limiting']['user_limits']['user_burst']
            : max(1, (int)($user_requests / 5)); // 默认为请求数的1/5

          $new_settings['rate_limits']['user'] = [
            'requests' => $user_requests,
            'window' => $user_window,
            'burst' => $user_burst,
          ];
        }

        // IP级限流配置
        if (!empty($values['rate_limiting']['ip_limits']['ip_requests'])) {
          $ip_requests = (int)$values['rate_limiting']['ip_limits']['ip_requests'];
          $ip_window = (int)$values['rate_limiting']['ip_limits']['ip_window'];
          $ip_burst = !empty($values['rate_limiting']['ip_limits']['ip_burst'])
            ? (int)$values['rate_limiting']['ip_limits']['ip_burst']
            : max(1, (int)($ip_requests / 3)); // 默认为请求数的1/3

          $new_settings['rate_limits']['ip'] = [
            'requests' => $ip_requests,
            'window' => $ip_window,
            'burst' => $ip_burst,
          ];
        }

        // 特殊端点限制
        if (!empty($values['rate_limiting']['endpoint_limits']['auth_endpoints'])) {
          $auth_requests = (int)$values['rate_limiting']['endpoint_limits']['auth_endpoints'];
          $auth_window = (int)$values['rate_limiting']['endpoint_limits']['auth_window'];

          $new_settings['rate_limits']['endpoints'] = [
            '/api/auth/' => [
              'requests' => $auth_requests,
              'window' => $auth_window,
            ],
          ];
        }
      } else {
        // 如果禁用项目级限流，则清除配置
        $new_settings['rate_limits'] = [
          'enable_project_rate_limiting' => FALSE,
        ];
      }

      // 生效时间
      if ($values['effective_from'] instanceof \DateTime) {
        $new_settings['effective_from'] = $values['effective_from']->getTimestamp();
      }

      // 添加更新时间戳
      $new_settings['resource_limits_updated'] = time();
      $new_settings['resource_limits_updated_by'] = $this->currentUser()->id();

      // 更新项目设置
      $this->projectManager->updateProject($project_id, ['settings' => $new_settings]);

      // 清除相关缓存
      $this->resourceLimitManager->clearCache($project_id);

      $this->messenger()->addMessage($this->t('项目资源限制配置已成功保存'));
      
      $this->logger->get('baas_project')->info('项目资源限制配置已更新: @project_id', [
        '@project_id' => $project_id,
        'settings' => $new_settings,
      ]);

      // 重定向到项目详情页面
      $form_state->setRedirect('baas_project.user_view', [
        'tenant_id' => $project['tenant_id'],
        'project_id' => $project_id,
      ]);

    } catch (\Exception $e) {
      $this->logger->get('baas_project')->error('保存项目资源限制配置失败: @error', [
        '@error' => $e->getMessage(),
        'project_id' => $project_id,
      ]);
      $this->messenger()->addError($this->t('保存配置失败，请稍后重试'));
    }
  }

}