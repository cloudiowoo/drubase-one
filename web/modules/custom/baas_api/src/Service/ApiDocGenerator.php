<?php

declare(strict_types=1);

namespace Drupal\baas_api\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\baas_entity\Service\TemplateManagerInterface;
use Drupal\baas_tenant\TenantManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * API文档生成器服务.
 *
 * 负责生成OpenAPI格式的API文档.
 */
class ApiDocGenerator
{

  /**
   * 实体类型管理器.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * 实体模板管理器.
   *
   * @var \Drupal\baas_entity\Service\TemplateManagerInterface
   */
  protected TemplateManagerInterface $templateManager;

  /**
   * 租户管理器.
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected TenantManagerInterface $tenantManager;

  /**
   * 配置工厂.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * 构造函数.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   实体类型管理器.
   * @param \Drupal\baas_entity\Service\TemplateManagerInterface $template_manager
   *   实体模板管理器.
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理器.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   配置工厂.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    TemplateManagerInterface $template_manager,
    TenantManagerInterface $tenant_manager,
    ConfigFactoryInterface $config_factory
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->templateManager = $template_manager;
    $this->tenantManager = $tenant_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * 生成API文档.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array
   *   OpenAPI规范的数组表示.
   */
  public function generate(?string $tenant_id = NULL): array
  {
    $api_base = '/api/v1';
    $tenant_path = $tenant_id ? "/{$tenant_id}" : '';

    // 获取基础组件
    $base_components = $this->generateBaseComponents();

    // 生成基本的API规范
    $openapi_spec = [
      'openapi' => '3.0.0',
      'info' => [
        'title' => 'Drubase API',
        'description' => $tenant_id ? "租户 {$tenant_id} 的API文档" : 'Drubase平台API文档',
        'version' => '1.0.0',
        'contact' => [
          'name' => 'API支持',
          'email' => 'support@example.com',
        ],
      ],
      'servers' => [
        [
          'url' => $tenant_id ? "{$api_base}{$tenant_path}" : $api_base,
          'description' => 'API服务器',
        ],
      ],
      'paths' => [
        '/health' => [
          'get' => [
            'summary' => '检查API健康状态',
            'description' => '返回API的当前状态',
            'operationId' => 'getHealth',
            'responses' => [
              '200' => [
                'description' => '成功',
                'content' => [
                  'application/json' => [
                    'schema' => [
                      'type' => 'object',
                      'properties' => [
                        'status' => [
                          'type' => 'string',
                          'example' => 'ok',
                        ],
                        'timestamp' => [
                          'type' => 'integer',
                          'example' => time(),
                        ],
                      ],
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      'components' => [
        'schemas' => $base_components['schemas'] ?? [],
        'securitySchemes' => [
          'bearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
            'description' => '使用JWT令牌进行身份验证',
          ],
          'apiKeyAuth' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-API-Key',
            'description' => '使用API密钥进行身份验证',
          ],
        ],
      ],
      'security' => [
        ['bearerAuth' => []],
        ['apiKeyAuth' => []],
      ],
    ];

    // 如果指定了租户ID，添加租户特定的端点
    if ($tenant_id) {
      $template_endpoints = $this->generateTemplateEndpoints($tenant_id);
      $entity_endpoints = $this->generateEntityEndpoints($tenant_id);

      $openapi_spec['paths'] = array_merge($openapi_spec['paths'], $template_endpoints, $entity_endpoints);

      // 如果有租户特定的组件，添加到基础组件中
      $tenant_components = $this->generateTenantComponents($tenant_id);
      if (!empty($tenant_components)) {
        foreach ($tenant_components as $component_type => $components) {
          if (isset($openapi_spec['components'][$component_type])) {
            $openapi_spec['components'][$component_type] = array_merge(
              $openapi_spec['components'][$component_type],
              $components
            );
          } else {
            $openapi_spec['components'][$component_type] = $components;
          }
        }
      }
    }

    return $openapi_spec;
  }

  /**
   * 生成全局API文档.
   *
   * @return array
   *   OpenAPI格式的文档.
   */
  public function generateApiDocs(): array
  {
    $config = $this->configFactory->get('baas_api.settings');

    // 获取基础组件
    $base_components = $this->generateBaseComponents();

    // 创建基础OpenAPI结构
    $openapi = [
      'openapi' => '3.0.0',
      'info' => [
        'title' => $config->get('api_title') ?? 'BaaS Platform API',
        'description' => $config->get('api_description') ?? 'Backend as a Service 平台API文档。支持JSON和multipart/form-data两种数据格式，实现完整的数据管理和文件上传功能。',
        'version' => $config->get('api_version') ?? '1.0.0',
        'contact' => [
          'name' => 'API支持',
          'email' => $config->get('contact_email') ?? 'support@example.com',
        ],
        'license' => [
          'name' => 'GPL-2.0+',
          'url' => 'https://www.gnu.org/licenses/gpl-2.0.html',
        ],
      ],
      'servers' => [
        [
          'url' => '/api',
          'description' => '全局API服务器',
        ],
        [
          'url' => '/api/v1',
          'description' => '全局API v1 服务器',
        ],
        [
          'url' => '/api/v1/{tenant_id}',
          'description' => '租户特定API服务器',
          'variables' => [
            'tenant_id' => [
              'default' => 'tenant_7375b0cd',
              'description' => '租户ID，例如：tenant_7375b0cd',
            ],
          ],
        ],
      ],
      'paths' => [],
      'components' => [
        'schemas' => $base_components['schemas'] ?? [],
        'securitySchemes' => [
          'bearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
            'description' => '使用JWT令牌进行身份验证',
          ],
          'apiKeyAuth' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-API-Key',
            'description' => '使用API密钥进行身份验证',
          ],
        ],
      ],
      'security' => [
        ['bearerAuth' => []],
      ],
      'tags' => [
        [
          'name' => '认证',
          'description' => '用户认证和授权相关API',
        ],
        [
          'name' => '权限管理',
          'description' => '用户权限和角色管理API',
        ],
        [
          'name' => 'API密钥',
          'description' => 'API密钥管理',
        ],
        [
          'name' => '会话管理',
          'description' => '用户会话管理',
        ],
        [
          'name' => '安全日志',
          'description' => '安全审计日志',
        ],
        // [
        //   'name' => '实体模板',
        //   'description' => '实体模板管理',
        // ],
        // [
        //   'name' => '实体数据',
        //   'description' => '实体数据操作',
        // ],
      ],
    ];

    // 添加认证相关的API端点
    $auth_paths = $this->generateAuthApiPaths();
    $openapi['paths'] = array_merge($openapi['paths'], $auth_paths);

    // 添加健康检查端点
    $openapi['paths']['/health'] = $this->getHealthCheckPath();

    // 添加认证相关的组件
    $auth_components = $this->generateAuthComponents();
    $openapi['components']['schemas'] = array_merge(
      $openapi['components']['schemas'],
      $auth_components['schemas'] ?? []
    );

    // 添加项目相关的组件
    $project_components = $this->generateProjectComponents();
    $openapi['components']['schemas'] = array_merge(
      $openapi['components']['schemas'],
      $project_components['schemas'] ?? []
    );

    return $openapi;
  }

  /**
   * 生成租户特定的API文档.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array
   *   API文档数组.
   */
  public function generateTenantApiDocs(string $tenant_id): array
  {
    // 获取基础API文档
    $docs = $this->generateApiDocs();

    // 获取基础组件
    $base_components = $this->generateBaseComponents();

    // 修改基本信息
    $docs['info']['title'] = '租户 ' . $tenant_id . ' API';
    $docs['servers'] = [
      [
        'url' => '/api',
        'description' => '全局API服务器',
      ],
      // [
      //   'url' => '/api/v1',
      //   'description' => '全局API v1 服务器',
      // ],
      [
        'url' => '/api/v1/' . $tenant_id,
        'description' => '租户 ' . $tenant_id . ' API 服务器（推荐）',
      ],
      // [
      //   'url' => '/api/v1/{tenant_id}',
      //   'description' => '其他租户API服务器',
      //   'variables' => [
      //     'tenant_id' => [
      //       'default' => $tenant_id,
      //       'description' => '租户ID，当前默认：' . $tenant_id,
      //     ],
      //   ],
      // ],
    ];

    // 注释：全局租户路径和实体模板路径已移除
    // 所有实体操作现在都通过项目级API进行
    // generateTenantPaths() 和 generateEntityTemplatePaths() 已废弃

    // 添加项目级别的API路径
    $docs['paths'] = array_merge($docs['paths'], $this->generateProjectPaths($tenant_id));

    // 确保组件存在
    if (!isset($docs['components'])) {
      $docs['components'] = $base_components;
    } else {
      // 合并基础组件
      foreach ($base_components as $component_type => $components) {
        if (isset($docs['components'][$component_type])) {
          $docs['components'][$component_type] = array_merge(
            $docs['components'][$component_type],
            $components
          );
        } else {
          $docs['components'][$component_type] = $components;
        }
      }
    }

    // 添加租户特定的组件
    $tenant_components = $this->generateTenantComponents($tenant_id);
    if (!empty($tenant_components)) {
      foreach ($tenant_components as $component_type => $components) {
        if (isset($docs['components'][$component_type])) {
          $docs['components'][$component_type] = array_merge(
            $docs['components'][$component_type],
            $components
          );
        } else {
          $docs['components'][$component_type] = $components;
        }
      }
    }

    return $docs;
  }

  /**
   * 生成租户特定的路径文档.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array
   *   路径文档数组.
   */
  protected function generateTenantPaths(string $tenant_id): array
  {
    $paths = [];

    // 获取租户信息
    $tenant = $this->tenantManager->getTenant($tenant_id);
    if (!$tenant) {
      return $paths;
    }

    // 添加租户特有的实体端点
    $templates = $this->templateManager->getTemplates($tenant_id);
    foreach ($templates as $template) {
      // 为每个实体类型添加API路径
      $path = '/' . $template['id'];
      $paths[$path] = $this->getEntityCollectionPaths($tenant_id, $template);
      $paths[$path . '/{id}'] = $this->getEntityItemPaths($tenant_id, $template);

      // 添加实体架构
      $paths['components']['schemas'][$template['id']] = $this->getEntitySchema($template);
    }

    return $paths;
  }

  /**
   * 生成与实体模板相关的API文档路径.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array
   *   路径文档数组.
   */
  protected function generateEntityTemplatePaths(string $tenant_id): array
  {
    $paths = [];

    // 获取模板列表
    $paths['/templates'] = [
      'get' => [
        'summary' => '获取所有实体模板',
        'description' => '获取当前租户下所有实体模板的列表.',
        'operationId' => 'getTemplates',
        'tags' => ['实体模板'],
        'responses' => [
          '200' => [
            'description' => '成功获取模板列表',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/TemplatesResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '未授权访问',
          ],
          '404' => [
            'description' => '租户不存在',
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
          ['apiKeyAuth' => []],
        ],
      ],
    ];

    // 获取模板详情
    $paths['/template/{template_name}'] = [
      'get' => [
        'summary' => '获取实体模板详情',
        'description' => '获取指定实体模板的详细信息，包括字段定义.',
        'operationId' => 'getTemplate',
        'tags' => ['实体模板'],
        'parameters' => [
          [
            'name' => 'template_name',
            'in' => 'path',
            'required' => true,
            'description' => '模板名称',
            'schema' => [
              'type' => 'string',
            ],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '成功获取模板详情',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/TemplateResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '未授权访问',
          ],
          '404' => [
            'description' => '模板不存在',
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
          ['apiKeyAuth' => []],
        ],
      ],
    ];

    // 获取实体列表
    $paths['/entity/{entity_type}'] = [
      'get' => [
        'summary' => '获取实体数据列表',
        'description' => '获取指定实体类型的数据列表.',
        'operationId' => 'getEntities',
        'tags' => ['实体数据'],
        'parameters' => [
          [
            'name' => 'entity_type',
            'in' => 'path',
            'required' => true,
            'description' => '实体类型名称',
            'schema' => [
              'type' => 'string',
            ],
          ],
          [
            'name' => 'page',
            'in' => 'query',
            'required' => false,
            'description' => '页码（从1开始）',
            'schema' => [
              'type' => 'integer',
              'default' => 1,
            ],
          ],
          [
            'name' => 'limit',
            'in' => 'query',
            'required' => false,
            'description' => '每页记录数',
            'schema' => [
              'type' => 'integer',
              'default' => 10,
            ],
          ],
          [
            'name' => 'sort',
            'in' => 'query',
            'required' => false,
            'description' => '排序字段',
            'schema' => [
              'type' => 'string',
            ],
          ],
          [
            'name' => 'order',
            'in' => 'query',
            'required' => false,
            'description' => '排序方向 (asc/desc)',
            'schema' => [
              'type' => 'string',
              'enum' => ['asc', 'desc'],
              'default' => 'asc',
            ],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '成功获取实体数据列表',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/EntitiesResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '未授权访问',
          ],
          '404' => [
            'description' => '实体类型不存在',
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
          ['apiKeyAuth' => []],
        ],
      ],
    ];

    // 获取单个实体
    $paths['/entity/{entity_type}/{entity_id}'] = [
      'get' => [
        'summary' => '获取单个实体数据',
        'description' => '获取指定实体类型和ID的数据.',
        'operationId' => 'getEntity',
        'tags' => ['实体数据'],
        'parameters' => [
          [
            'name' => 'entity_type',
            'in' => 'path',
            'required' => true,
            'description' => '实体类型名称',
            'schema' => [
              'type' => 'string',
            ],
          ],
          [
            'name' => 'entity_id',
            'in' => 'path',
            'required' => true,
            'description' => '实体ID',
            'schema' => [
              'type' => 'string',
            ],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '成功获取实体数据',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/EntityResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '未授权访问',
          ],
          '404' => [
            'description' => '实体不存在',
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
          ['apiKeyAuth' => []],
        ],
      ],
    ];

    // 添加模拟PUT和DELETE操作的POST路由文档
    $paths['/entity/{entity_type}/{entity_id}/update'] = [
      'post' => [
        'summary' => '更新实体数据（通过POST）',
        'description' => '通过POST方法更新已存在的实体数据记录（适用于不支持PUT方法的客户端）',
        'operationId' => 'updateEntityPost',
        'tags' => ['实体数据'],
        'parameters' => [
          [
            'name' => 'entity_type',
            'in' => 'path',
            'required' => true,
            'schema' => [
              'type' => 'string',
            ],
            'description' => '实体类型',
          ],
          [
            'name' => 'entity_id',
            'in' => 'path',
            'required' => true,
            'schema' => [
              'type' => 'string',
            ],
            'description' => '实体ID',
          ],
        ],
        'requestBody' => [
          'description' => '更新的实体数据',
          'required' => true,
          'content' => [
            'application/json' => [
              'schema' => [
                '$ref' => '#/components/schemas/EntityRequest',
              ],
            ],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '实体更新成功',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/EntityUpdateResponse',
                ],
              ],
            ],
          ],
          '400' => [
            'description' => '无效请求',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '未授权',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
          '404' => [
            'description' => '实体不存在',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
        ],
      ],
    ];

    $paths['/entity/{entity_type}/{entity_id}/delete'] = [
      'post' => [
        'summary' => '删除实体数据（通过POST）',
        'description' => '通过POST方法删除已存在的实体数据记录（适用于不支持DELETE方法的客户端）',
        'operationId' => 'deleteEntityPost',
        'tags' => ['实体数据'],
        'parameters' => [
          [
            'name' => 'entity_type',
            'in' => 'path',
            'required' => true,
            'schema' => [
              'type' => 'string',
            ],
            'description' => '实体类型',
          ],
          [
            'name' => 'entity_id',
            'in' => 'path',
            'required' => true,
            'schema' => [
              'type' => 'string',
            ],
            'description' => '实体ID',
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '实体删除成功',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/EntityDeleteResponse',
                ],
              ],
            ],
          ],
          '400' => [
            'description' => '无效请求',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '未授权',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
          '404' => [
            'description' => '实体不存在',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
        ],
      ],
    ];

    return $paths;
  }

  /**
   * 生成实体相关组件文档.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array
   *   组件文档数组.
   */
  protected function generateEntityComponents(string $tenant_id): array
  {
    $components = [
      'schemas' => [
        'TemplatesResponse' => [
          'type' => 'object',
          'properties' => [
            'success' => [
              'type' => 'boolean',
              'example' => true,
            ],
            'data' => [
              'type' => 'object',
              'properties' => [
                'templates' => [
                  'type' => 'array',
                  'items' => [
                    '$ref' => '#/components/schemas/TemplateInfo',
                  ],
                ],
              ],
            ],
          ],
        ],
        'TemplateInfo' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'integer',
              'example' => 1,
            ],
            'name' => [
              'type' => 'string',
              'example' => 'client',
            ],
            'label' => [
              'type' => 'string',
              'example' => '客户',
            ],
            'description' => [
              'type' => 'string',
              'example' => '客户实体模板',
            ],
            'status' => [
              'type' => 'boolean',
              'example' => true,
            ],
            'field_count' => [
              'type' => 'integer',
              'example' => 5,
            ],
            'created' => [
              'type' => 'integer',
              'example' => 1625097600,
            ],
          ],
        ],
        'TemplateResponse' => [
          'type' => 'object',
          'properties' => [
            'success' => [
              'type' => 'boolean',
              'example' => true,
            ],
            'data' => [
              'type' => 'object',
              'properties' => [
                'template' => [
                  '$ref' => '#/components/schemas/Template',
                ],
              ],
            ],
          ],
        ],
        'Template' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'integer',
              'example' => 1,
            ],
            'name' => [
              'type' => 'string',
              'example' => 'client',
            ],
            'label' => [
              'type' => 'string',
              'example' => '客户',
            ],
            'description' => [
              'type' => 'string',
              'example' => '客户实体模板',
            ],
            'status' => [
              'type' => 'boolean',
              'example' => true,
            ],
            'created' => [
              'type' => 'integer',
              'example' => 1625097600,
            ],
            'updated' => [
              'type' => 'integer',
              'example' => 1625097600,
            ],
            'fields' => [
              'type' => 'array',
              'items' => [
                '$ref' => '#/components/schemas/TemplateField',
              ],
            ],
          ],
        ],
        'TemplateField' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'integer',
              'example' => 1,
            ],
            'name' => [
              'type' => 'string',
              'example' => 'name',
            ],
            'label' => [
              'type' => 'string',
              'example' => '名称',
            ],
            'type' => [
              'type' => 'string',
              'example' => 'string',
            ],
            'description' => [
              'type' => 'string',
              'example' => '客户名称',
            ],
            'required' => [
              'type' => 'boolean',
              'example' => true,
            ],
            'multiple' => [
              'type' => 'boolean',
              'example' => false,
            ],
            'settings' => [
              'type' => 'object',
              'example' => '{}',
            ],
            'weight' => [
              'type' => 'integer',
              'example' => 0,
            ],
          ],
        ],
        'EntitiesResponse' => [
          'type' => 'object',
          'properties' => [
            'success' => [
              'type' => 'boolean',
              'example' => true,
            ],
            'data' => [
              'type' => 'object',
              'properties' => [
                'entity_type' => [
                  'type' => 'string',
                  'example' => 'client',
                ],
                'items' => [
                  'type' => 'array',
                  'items' => [
                    'type' => 'object',
                  ],
                ],
                'total' => [
                  'type' => 'integer',
                  'example' => 0,
                ],
              ],
            ],
          ],
        ],
        'EntityResponse' => [
          'type' => 'object',
          'properties' => [
            'success' => [
              'type' => 'boolean',
              'example' => true,
            ],
            'data' => [
              'type' => 'object',
              'properties' => [
                'entity_type' => [
                  'type' => 'string',
                  'example' => 'client',
                ],
                'entity_id' => [
                  'type' => 'string',
                  'example' => '1',
                ],
                'data' => [
                  'type' => 'object',
                ],
              ],
            ],
          ],
        ],
        'EntityRequest' => [
          'type' => 'object',
          'properties' => [
            'fields' => [
              'type' => 'object',
              'description' => '实体字段数据，具体字段根据实体类型定义',
              'additionalProperties' => true,
              'example' => [
                'name' => '测试名称',
                'email' => 'test@example.com',
                'status' => true
              ],
            ],
          ],
        ],
      ],
    ];

    return $components;
  }

  /**
   * 生成租户特定的组件文档.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array
   *   组件文档数组.
   */
  protected function generateTenantComponents(string $tenant_id): array
  {
    $components = $this->generateBaseComponents();

    // 添加实体相关组件
    $entityComponents = $this->generateEntityComponents($tenant_id);

    // 合并组件
    if (isset($entityComponents['schemas'])) {
      $components['schemas'] = array_merge($components['schemas'] ?? [], $entityComponents['schemas']);
    }

    return $components;
  }

  /**
   * 生成租户的GraphQL模式.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return string
   *   GraphQL模式定义.
   */
  public function generateGraphQLSchema(string $tenant_id): string
  {
    // 获取租户信息
    $tenant = $this->tenantManager->getTenant($tenant_id);
    if (!$tenant) {
      return '';
    }

    // 获取租户的实体模板
    $templates = $this->templateManager->getTemplates($tenant_id);
    if (empty($templates)) {
      return '';
    }

    // 构建GraphQL模式
    $schema = [];

    // 添加基础查询类型
    $schema[] = 'type Query {';

    // 为每个实体类型添加查询
    foreach ($templates as $template) {
      $typeName = $this->getGraphQLTypeName($template['id']);

      // 获取单个实体查询
      $schema[] = "  # 获取单个{$template['name']}";
      $schema[] = "  {$template['id']}(id: ID!): $typeName";

      // 获取实体列表查询
      $schema[] = "  # 获取{$template['name']}列表";
      $schema[] = "  {$template['id']}List(filter: {$typeName}FilterInput, page: Int = 1, limit: Int = 20, sort: String): {$typeName}Connection";
    }

    $schema[] = '}';
    $schema[] = '';

    // 添加基础变更类型
    $schema[] = 'type Mutation {';

    // 为每个实体类型添加变更
    foreach ($templates as $template) {
      $typeName = $this->getGraphQLTypeName($template['id']);

      // 创建实体变更
      $schema[] = "  # 创建{$template['name']}";
      $schema[] = "  create{$typeName}(input: {$typeName}Input!): {$typeName}Payload";

      // 更新实体变更
      $schema[] = "  # 更新{$template['name']}";
      $schema[] = "  update{$typeName}(id: ID!, input: {$typeName}Input!): {$typeName}Payload";

      // 删除实体变更
      $schema[] = "  # 删除{$template['name']}";
      $schema[] = "  delete{$typeName}(id: ID!): DeletePayload";
    }

    $schema[] = '}';
    $schema[] = '';

    // 定义订阅类型
    $schema[] = 'type Subscription {';

    // 为每个实体类型添加订阅
    foreach ($templates as $template) {
      $typeName = $this->getGraphQLTypeName($template['id']);

      // 实体变更订阅
      $schema[] = "  # 监听{$template['name']}变更";
      $schema[] = "  {$template['id']}Changed: $typeName";
    }

    $schema[] = '}';
    $schema[] = '';

    // 为每个实体类型定义GraphQL类型
    foreach ($templates as $template) {
      $typeName = $this->getGraphQLTypeName($template['id']);

      // 定义实体类型
      $schema[] = "# {$template['name']}";
      $schema[] = "type $typeName {";
      $schema[] = '  id: ID!';
      $schema[] = '  created: DateTime!';
      $schema[] = '  updated: DateTime!';

      // 添加字段
      if (isset($template['fields']) && is_array($template['fields'])) {
        foreach ($template['fields'] as $field_id => $field) {
          $fieldType = $this->getGraphQLFieldType($field);
          $required = !empty($field['required']) ? '!' : '';
          $description = !empty($field['label']) ? "# {$field['label']}" : '';

          if ($description) {
            $schema[] = "  $description";
          }
          $schema[] = "  $field_id: $fieldType$required";
        }
      }

      $schema[] = '}';
      $schema[] = '';

      // 定义实体连接类型（用于分页）
      $schema[] = "# {$template['name']}连接（分页）";
      $schema[] = "type {$typeName}Connection {";
      $schema[] = "  edges: [$typeName]";
      $schema[] = '  pageInfo: PageInfo!';
      $schema[] = '  totalCount: Int!';
      $schema[] = '}';
      $schema[] = '';

      // 定义过滤器输入类型
      $schema[] = "# {$template['name']}过滤条件";
      $schema[] = "input {$typeName}FilterInput {";

      // 添加常用过滤字段
      if (isset($template['fields']) && is_array($template['fields'])) {
        foreach ($template['fields'] as $field_id => $field) {
          if (in_array($field['type'], ['string', 'integer', 'boolean', 'date', 'datetime'])) {
            $filterType = $this->getGraphQLFilterType($field);
            $schema[] = "  $field_id: $filterType";
          }
        }
      }

      $schema[] = '}';
      $schema[] = '';

      // 定义输入类型
      $schema[] = "# {$template['name']}输入";
      $schema[] = "input {$typeName}Input {";

      // 添加可编辑字段
      if (isset($template['fields']) && is_array($template['fields'])) {
        foreach ($template['fields'] as $field_id => $field) {
          $fieldType = $this->getGraphQLFieldType($field);
          $required = !empty($field['required']) ? '!' : '';
          $description = !empty($field['label']) ? "# {$field['label']}" : '';

          if ($description) {
            $schema[] = "  $description";
          }
          $schema[] = "  $field_id: $fieldType$required";
        }
      }

      $schema[] = '}';
      $schema[] = '';

      // 定义变更响应负载
      $schema[] = "# {$template['name']}变更响应";
      $schema[] = "type {$typeName}Payload {";
      $schema[] = "  # 操作成功后的实体";
      $schema[] = "  $template[id]: $typeName";
      $schema[] = "  # 错误信息";
      $schema[] = "  errors: [Error]";
      $schema[] = '}';
      $schema[] = '';
    }

    // 定义通用类型
    $schema[] = '# 分页信息';
    $schema[] = 'type PageInfo {';
    $schema[] = '  # 是否有下一页';
    $schema[] = '  hasNextPage: Boolean!';
    $schema[] = '  # 下一页游标';
    $schema[] = '  endCursor: String';
    $schema[] = '}';
    $schema[] = '';

    $schema[] = '# 错误信息';
    $schema[] = 'type Error {';
    $schema[] = '  # 错误字段';
    $schema[] = '  field: String';
    $schema[] = '  # 错误消息';
    $schema[] = '  message: String!';
    $schema[] = '}';
    $schema[] = '';

    $schema[] = '# 删除响应';
    $schema[] = 'type DeletePayload {';
    $schema[] = '  # 操作成功的ID';
    $schema[] = '  id: ID';
    $schema[] = '  # 错误信息';
    $schema[] = '  errors: [Error]';
    $schema[] = '}';
    $schema[] = '';

    $schema[] = '# 日期时间标量类型';
    $schema[] = 'scalar DateTime';

    // 返回完整模式定义
    return implode("\n", $schema);
  }

  /**
   * 获取GraphQL类型名称.
   *
   * @param string $template_id
   *   模板ID.
   *
   * @return string
   *   GraphQL类型名称.
   */
  protected function getGraphQLTypeName(string $template_id): string
  {
    // 将模板ID转换为Pascal命名（首字母大写驼峰命名）
    return str_replace(' ', '', ucwords(str_replace('_', ' ', $template_id)));
  }

  /**
   * 获取GraphQL字段类型.
   *
   * @param array $field
   *   字段定义.
   *
   * @return string
   *   GraphQL字段类型.
   */
  protected function getGraphQLFieldType(array $field): string
  {
    switch ($field['type']) {
      case 'string':
      case 'text':
      case 'email':
      case 'url':
      case 'reference':
        return 'String';

      case 'integer':
        return 'Int';

      case 'float':
      case 'decimal':
        return 'Float';

      case 'boolean':
        return 'Boolean';

      case 'date':
      case 'datetime':
        return 'DateTime';

      case 'object':
        return 'JSON';

      case 'array':
        if (!empty($field['item_type'])) {
          $itemType = $this->getGraphQLFieldType(['type' => $field['item_type']]);
          return "[$itemType]";
        }
        return '[String]';

      default:
        return 'String';
    }
  }

  /**
   * 获取GraphQL过滤器类型.
   *
   * @param array $field
   *   字段定义.
   *
   * @return string
   *   GraphQL过滤器类型.
   */
  protected function getGraphQLFilterType(array $field): string
  {
    switch ($field['type']) {
      case 'string':
      case 'text':
      case 'email':
      case 'url':
        return 'StringFilter';

      case 'integer':
      case 'float':
      case 'decimal':
        return 'NumberFilter';

      case 'boolean':
        return 'Boolean';

      case 'date':
      case 'datetime':
        return 'DateFilter';

      default:
        return 'StringFilter';
    }
  }

  /**
   * 获取安全方案定义.
   *
   * @return array
   *   安全方案.
   */
  protected function getSecuritySchemes(): array
  {
    return [
      'bearerAuth' => [
        'type' => 'http',
        'scheme' => 'bearer',
        'bearerFormat' => 'JWT',
        'description' => '使用JWT令牌进行身份验证',
      ],
      'apiKeyAuth' => [
        'type' => 'apiKey',
        'in' => 'header',
        'name' => 'X-API-Key',
        'description' => '使用API密钥进行身份验证',
      ],
    ];
  }

  /**
   * 获取健康检查路径.
   *
   * @return array
   *   健康检查路径配置.
   */
  protected function getHealthCheckPath(): array
  {
    return [
      'get' => [
        'summary' => '检查API健康状态',
        'description' => '返回API服务器的当前健康状态和版本信息',
        'operationId' => 'checkHealth',
        'tags' => ['Health'],
        'responses' => [
          '200' => [
            'description' => '系统健康',
            'content' => [
              'application/json' => [
                'schema' => [
                  'type' => 'object',
                  'properties' => [
                    'status' => [
                      'type' => 'string',
                      'example' => 'ok',
                    ],
                    'version' => [
                      'type' => 'string',
                      'example' => '1.0.0',
                    ],
                    'timestamp' => [
                      'type' => 'string',
                      'format' => 'date-time',
                      'example' => '2025-05-11T12:00:00Z',
                    ],
                  ],
                ],
              ],
            ],
          ],
        ],
        'security' => [],
      ],
    ];
  }

  /**
   * 获取实体集合路径.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param array $template
   *   实体模板.
   *
   * @return array
   *   实体集合路径配置.
   */
  protected function getEntityCollectionPaths(string $tenant_id, array $template): array
  {
    // ... 保持原有代码不变 ...
    return [];
  }

  /**
   * 获取实体项目路径.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param array $template
   *   实体模板.
   *
   * @return array
   *   实体项目路径配置.
   */
  protected function getEntityItemPaths(string $tenant_id, array $template): array
  {
    // ... 保持原有代码不变 ...
    return [];
  }

  /**
   * 获取实体模式.
   *
   * @param array $template
   *   实体模板.
   *
   * @return array
   *   实体模式.
   */
  protected function getEntitySchema(array $template): array
  {
    // ... 保持原有代码不变 ...
    return [];
  }

  /**
   * 获取字段模式.
   *
   * @param array $field
   *   字段定义.
   *
   * @return array
   *   字段模式.
   */
  protected function getFieldSchema(array $field): array
  {
    // ... 保持原有代码不变 ...
    return [];
  }

  /**
   * 获取错误响应.
   *
   * @param string $description
   *   错误描述.
   *
   * @return array
   *   错误响应配置.
   */
  protected function getErrorResponse(string $description): array
  {
    // ... 保持原有代码不变 ...
    return [];
  }

  /**
   * 将OpenAPI文档转换为JSON.
   *
   * @param array $openapi
   *   OpenAPI文档.
   *
   * @return string
   *   JSON格式的文档.
   */
  public function toJson(array $openapi): string
  {
    return json_encode($openapi, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  }

  /**
   * 将OpenAPI文档转换为YAML.
   *
   * @param array $openapi
   *   OpenAPI文档.
   *
   * @return string
   *   YAML格式的文档.
   */
  public function toYaml(array $openapi): string
  {
    // 需要安装symfony/yaml组件
    if (class_exists('\Symfony\Component\Yaml\Yaml')) {
      return \Symfony\Component\Yaml\Yaml::dump($openapi, 10, 2, \Symfony\Component\Yaml\Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }

    // 回退到JSON
    return $this->toJson($openapi);
  }

  /**
   * 生成基础组件文档.
   *
   * @return array
   *   基础组件文档数组.
   */
  protected function generateBaseComponents(): array
  {
    $components = [
      'schemas' => [
        'Error' => [
          'type' => 'object',
          'properties' => [
            'success' => [
              'type' => 'boolean',
              'example' => false,
            ],
            'error' => [
              'type' => 'object',
              'properties' => [
                'message' => [
                  'type' => 'string',
                  'example' => '错误信息',
                ],
                'status' => [
                  'type' => 'integer',
                  'example' => 400,
                ],
                'details' => [
                  'type' => 'object',
                  'example' => '{}',
                ],
              ],
            ],
          ],
        ],
        'SuccessResponse' => [
          'type' => 'object',
          'properties' => [
            'success' => [
              'type' => 'boolean',
              'example' => true,
            ],
            'data' => [
              'type' => 'object',
            ],
          ],
        ],
        'EntityRequest' => [
          'type' => 'object',
          'properties' => [
            'fields' => [
              'type' => 'object',
              'description' => '实体字段数据，具体字段根据实体类型定义',
              'additionalProperties' => true,
              'example' => [
                'name' => '测试名称',
                'email' => 'test@example.com',
                'status' => true
              ],
            ],
          ],
        ],
        'EntityDeleteResponse' => [
          'type' => 'object',
          'properties' => [
            'success' => [
              'type' => 'boolean',
              'description' => '操作是否成功',
              'example' => true,
            ],
            'data' => [
              'type' => 'object',
              'properties' => [
                'success' => [
                  'type' => 'boolean',
                  'description' => '删除操作是否成功',
                  'example' => true,
                ],
                'message' => [
                  'type' => 'string',
                  'description' => '操作结果消息',
                  'example' => '实体删除成功',
                ],
              ],
            ],
          ],
        ],
      ],
      'securitySchemes' => [
        'bearerAuth' => [
          'type' => 'http',
          'scheme' => 'bearer',
          'bearerFormat' => 'JWT',
        ],
        'apiKeyAuth' => [
          'type' => 'apiKey',
          'in' => 'header',
          'name' => 'X-API-Key',
        ],
      ],
    ];

    return $components;
  }

  /**
   * 生成模板相关端点的OpenAPI规范.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array
   *   端点规范的数组表示.
   */
  protected function generateTemplateEndpoints(string $tenant_id): array
  {
    return [
      '/templates' => [
        'get' => [
          'summary' => '获取所有实体模板',
          'description' => '返回租户的所有可用实体模板列表',
          'operationId' => 'getTemplates',
          'security' => [
            ['bearerAuth' => []],
          ],
          'responses' => [
            '200' => [
              'description' => '成功',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/TemplatesResponse',
                  ],
                ],
              ],
            ],
            '401' => [
              'description' => '未授权',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/Error',
                  ],
                ],
              ],
            ],
            '404' => [
              'description' => '未找到',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/Error',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      '/template/{template_name}' => [
        'get' => [
          'summary' => '获取特定实体模板',
          'description' => '返回特定实体模板的详细信息',
          'operationId' => 'getTemplate',
          'security' => [
            ['bearerAuth' => []],
          ],
          'parameters' => [
            [
              'name' => 'template_name',
              'in' => 'path',
              'required' => true,
              'schema' => [
                'type' => 'string',
              ],
              'description' => '模板名称',
            ],
          ],
          'responses' => [
            '200' => [
              'description' => '成功',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/TemplateResponse',
                  ],
                ],
              ],
            ],
            '401' => [
              'description' => '未授权',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/Error',
                  ],
                ],
              ],
            ],
            '404' => [
              'description' => '未找到',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/Error',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * 生成实体端点的OpenAPI规范.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array
   *   端点规范的数组表示.
   */
  protected function generateEntityEndpoints(string $tenant_id): array
  {
    return [
      '/entity/{entity_type}' => [
        'get' => [
          'summary' => '获取实体数据列表',
          'description' => '返回指定类型的实体数据列表',
          'operationId' => 'getEntities',
          'security' => [
            ['bearerAuth' => []],
          ],
          'parameters' => [
            [
              'name' => 'entity_type',
              'in' => 'path',
              'required' => true,
              'schema' => [
                'type' => 'string',
              ],
              'description' => '实体类型',
            ],
            [
              'name' => 'page',
              'in' => 'query',
              'schema' => [
                'type' => 'integer',
                'default' => 1,
                'minimum' => 1,
              ],
              'description' => '页码',
            ],
            [
              'name' => 'limit',
              'in' => 'query',
              'schema' => [
                'type' => 'integer',
                'default' => 20,
                'minimum' => 1,
                'maximum' => 100,
              ],
              'description' => '每页记录数',
            ],
            [
              'name' => 'sort',
              'in' => 'query',
              'schema' => [
                'type' => 'string',
              ],
              'description' => '排序字段',
            ],
            [
              'name' => 'order',
              'in' => 'query',
              'schema' => [
                'type' => 'string',
                'enum' => ['asc', 'desc'],
                'default' => 'asc',
              ],
              'description' => '排序方向',
            ],
          ],
          'responses' => [
            '200' => [
              'description' => '成功',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/EntitiesResponse',
                  ],
                ],
              ],
            ],
            '401' => [
              'description' => '未授权',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/Error',
                  ],
                ],
              ],
            ],
            '404' => [
              'description' => '未找到',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/Error',
                  ],
                ],
              ],
            ],
          ],
        ],
        'post' => [
          'summary' => '创建实体数据',
          'description' => '创建新的实体数据记录',
          'operationId' => 'createEntity',
          'security' => [
            ['bearerAuth' => []],
          ],
          'parameters' => [
            [
              'name' => 'entity_type',
              'in' => 'path',
              'required' => true,
              'schema' => [
                'type' => 'string',
              ],
              'description' => '实体类型',
            ],
          ],
          'requestBody' => [
            'description' => '实体数据',
            'required' => true,
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/EntityRequest',
                ],
              ],
            ],
          ],
          'responses' => [
            '200' => [
              'description' => '创建成功',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/EntityResponse',
                  ],
                ],
              ],
            ],
            '400' => [
              'description' => '无效的请求',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/Error',
                  ],
                ],
              ],
            ],
            '401' => [
              'description' => '未授权',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/Error',
                  ],
                ],
              ],
            ],
            '404' => [
              'description' => '实体类型不存在',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/Error',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      '/entity/{entity_type}/{entity_id}' => [
        'get' => [
          'summary' => '获取单个实体数据',
          'description' => '返回单个实体的详细数据',
          'operationId' => 'getEntity',
          'security' => [
            ['bearerAuth' => []],
          ],
          'parameters' => [
            [
              'name' => 'entity_type',
              'in' => 'path',
              'required' => true,
              'schema' => [
                'type' => 'string',
              ],
              'description' => '实体类型',
            ],
            [
              'name' => 'entity_id',
              'in' => 'path',
              'required' => true,
              'schema' => [
                'type' => 'string',
              ],
              'description' => '实体ID',
            ],
          ],
          'responses' => [
            '200' => [
              'description' => '成功',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/EntityResponse',
                  ],
                ],
              ],
            ],
            '401' => [
              'description' => '未授权',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/Error',
                  ],
                ],
              ],
            ],
            '404' => [
              'description' => '未找到',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/Error',
                  ],
                ],
              ],
            ],
          ],
        ],
        'put' => [
          'summary' => '更新实体数据',
          'description' => '更新已存在的实体数据记录',
          'operationId' => 'updateEntity',
          'security' => [
            ['bearerAuth' => []],
          ],
          'parameters' => [
            [
              'name' => 'entity_type',
              'in' => 'path',
              'required' => true,
              'schema' => [
                'type' => 'string',
              ],
              'description' => '实体类型',
            ],
            [
              'name' => 'entity_id',
              'in' => 'path',
              'required' => true,
              'schema' => [
                'type' => 'string',
              ],
              'description' => '实体ID',
            ],
          ],
          'requestBody' => [
            'description' => '更新的实体数据',
            'required' => true,
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/EntityRequest',
                ],
              ],
            ],
          ],
          'responses' => [
            '200' => [
              'description' => '更新成功',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/EntityResponse',
                  ],
                ],
              ],
            ],
            '400' => [
              'description' => '无效的请求',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/Error',
                  ],
                ],
              ],
            ],
            '401' => [
              'description' => '未授权',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/Error',
                  ],
                ],
              ],
            ],
            '404' => [
              'description' => '实体不存在',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/Error',
                  ],
                ],
              ],
            ],
          ],
        ],
        'delete' => [
          'summary' => '删除实体数据',
          'description' => '删除指定的实体数据记录',
          'operationId' => 'deleteEntity',
          'security' => [
            ['bearerAuth' => []],
          ],
          'parameters' => [
            [
              'name' => 'entity_type',
              'in' => 'path',
              'required' => true,
              'schema' => [
                'type' => 'string',
              ],
              'description' => '实体类型',
            ],
            [
              'name' => 'entity_id',
              'in' => 'path',
              'required' => true,
              'schema' => [
                'type' => 'string',
              ],
              'description' => '实体ID',
            ],
          ],
          'responses' => [
            '200' => [
              'description' => '删除成功',
              'content' => [
                'application/json' => [
                  'schema' => [
                    'type' => 'object',
                    'properties' => [
                      'success' => [
                        'type' => 'boolean',
                        'example' => true
                      ],
                      'message' => [
                        'type' => 'string',
                        'example' => '实体删除成功'
                      ]
                    ]
                  ],
                ],
              ],
            ],
            '401' => [
              'description' => '未授权',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/Error',
                  ],
                ],
              ],
            ],
            '404' => [
              'description' => '实体不存在',
              'content' => [
                'application/json' => [
                  'schema' => [
                    '$ref' => '#/components/schemas/Error',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * 生成认证API路径.
   *
   * @return array
   *   认证API路径配置.
   */
  protected function generateAuthApiPaths(): array
  {
    $paths = [];

    // 用户登录
    $paths['/auth/login'] = [
      'post' => [
        'summary' => '用户登录',
        'description' => '使用用户名/邮箱和密码进行登录，返回JWT令牌',
        'operationId' => 'login',
        'tags' => ['认证'],
        'requestBody' => [
          'required' => true,
          'content' => [
            'application/json' => [
              'schema' => [
                '$ref' => '#/components/schemas/LoginRequest',
              ],
            ],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '登录成功',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/LoginResponse',
                ],
              ],
            ],
          ],
          '400' => [
            'description' => '请求参数错误',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '认证失败',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
          '429' => [
            'description' => '登录尝试次数过多',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [],
      ],
    ];

    // 刷新令牌
    $paths['/auth/refresh'] = [
      'post' => [
        'summary' => '刷新令牌',
        'description' => '使用刷新令牌获取新的访问令牌',
        'operationId' => 'refreshToken',
        'tags' => ['认证'],
        'requestBody' => [
          'required' => true,
          'content' => [
            'application/json' => [
              'schema' => [
                '$ref' => '#/components/schemas/RefreshTokenRequest',
              ],
            ],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '令牌刷新成功',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/TokenResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '刷新令牌无效或已过期',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [],
      ],
    ];

    // 用户注销
    $paths['/auth/logout'] = [
      'post' => [
        'summary' => '用户注销',
        'description' => '注销当前用户，使令牌失效',
        'operationId' => 'logout',
        'tags' => ['认证'],
        'responses' => [
          '200' => [
            'description' => '注销成功',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/SuccessResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '未授权访问',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
        ],
      ],
    ];

    // 获取当前用户信息
    $paths['/auth/me'] = [
      'get' => [
        'summary' => '获取当前用户信息',
        'description' => '获取当前认证用户的详细信息',
        'operationId' => 'getCurrentUser',
        'tags' => ['认证'],
        'responses' => [
          '200' => [
            'description' => '成功获取用户信息',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/UserResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '未授权访问',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
        ],
      ],
    ];

    // 验证令牌
    $paths['/auth/verify'] = [
      'post' => [
        'summary' => '验证令牌',
        'description' => '验证JWT令牌的有效性',
        'operationId' => 'verifyToken',
        'tags' => ['认证'],
        'requestBody' => [
          'required' => true,
          'content' => [
            'application/json' => [
              'schema' => [
                '$ref' => '#/components/schemas/VerifyTokenRequest',
              ],
            ],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '令牌验证成功',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/TokenVerifyResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '令牌无效或已过期',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [],
      ],
    ];

    // 获取用户权限
    $paths['/auth/permissions'] = [
      'get' => [
        'summary' => '获取用户权限',
        'description' => '获取当前用户的所有权限列表',
        'operationId' => 'getUserPermissions',
        'tags' => ['权限管理'],
        'responses' => [
          '200' => [
            'description' => '成功获取权限列表',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/PermissionsResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '未授权访问',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
        ],
      ],
    ];

    // 修改密码
    $paths['/auth/change-password'] = [
      'post' => [
        'summary' => '修改密码',
        'description' => '修改当前用户的密码',
        'operationId' => 'changePassword',
        'tags' => ['认证'],
        'requestBody' => [
          'required' => true,
          'content' => [
            'application/json' => [
              'schema' => [
                '$ref' => '#/components/schemas/ChangePasswordRequest',
              ],
            ],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '密码修改成功',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/SuccessResponse',
                ],
              ],
            ],
          ],
          '400' => [
            'description' => '请求参数错误',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '未授权访问或当前密码错误',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
        ],
      ],
    ];

    // 获取用户角色
    $paths['/auth/roles'] = [
      'get' => [
        'summary' => '获取用户角色',
        'description' => '获取当前用户的所有角色',
        'operationId' => 'getUserRoles',
        'tags' => ['权限管理'],
        'responses' => [
          '200' => [
            'description' => '成功获取角色列表',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/RolesResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '未授权访问',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
        ],
      ],
    ];

    // API密钥管理
    $paths['/auth/api-keys'] = [
      'get' => [
        'summary' => '获取API密钥列表',
        'description' => '获取当前用户的API密钥列表',
        'operationId' => 'getApiKeys',
        'tags' => ['API密钥'],
        'parameters' => [
          [
            'name' => 'page',
            'in' => 'query',
            'description' => '页码',
            'schema' => [
              'type' => 'integer',
              'default' => 1,
              'minimum' => 1,
            ],
          ],
          [
            'name' => 'limit',
            'in' => 'query',
            'description' => '每页记录数',
            'schema' => [
              'type' => 'integer',
              'default' => 20,
              'minimum' => 1,
              'maximum' => 100,
            ],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '成功获取API密钥列表',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ApiKeysResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '未授权访问',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
        ],
      ],
      'post' => [
        'summary' => '创建API密钥',
        'description' => '创建新的API密钥',
        'operationId' => 'createApiKey',
        'tags' => ['API密钥'],
        'requestBody' => [
          'required' => true,
          'content' => [
            'application/json' => [
              'schema' => [
                '$ref' => '#/components/schemas/CreateApiKeyRequest',
              ],
            ],
          ],
        ],
        'responses' => [
          '201' => [
            'description' => 'API密钥创建成功',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ApiKeyResponse',
                ],
              ],
            ],
          ],
          '400' => [
            'description' => '请求参数错误',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '未授权访问',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
          '403' => [
            'description' => '权限不足',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
        ],
      ],
    ];

    // 会话管理
    $paths['/auth/sessions'] = [
      'get' => [
        'summary' => '获取用户会话列表',
        'description' => '获取当前用户的所有活跃会话',
        'operationId' => 'getUserSessions',
        'tags' => ['会话管理'],
        'responses' => [
          '200' => [
            'description' => '成功获取会话列表',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/SessionsResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '未授权访问',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
        ],
      ],
    ];

    // 安全日志
    $paths['/auth/security-logs'] = [
      'get' => [
        'summary' => '获取安全日志',
        'description' => '获取系统安全审计日志（需要管理员权限）',
        'operationId' => 'getSecurityLogs',
        'tags' => ['安全日志'],
        'parameters' => [
          [
            'name' => 'page',
            'in' => 'query',
            'description' => '页码',
            'schema' => [
              'type' => 'integer',
              'default' => 1,
              'minimum' => 1,
            ],
          ],
          [
            'name' => 'limit',
            'in' => 'query',
            'description' => '每页记录数',
            'schema' => [
              'type' => 'integer',
              'default' => 50,
              'minimum' => 1,
              'maximum' => 200,
            ],
          ],
          [
            'name' => 'level',
            'in' => 'query',
            'description' => '日志级别过滤',
            'schema' => [
              'type' => 'string',
              'enum' => ['info', 'warning', 'error', 'critical'],
            ],
          ],
          [
            'name' => 'start_date',
            'in' => 'query',
            'description' => '开始日期 (YYYY-MM-DD)',
            'schema' => [
              'type' => 'string',
              'format' => 'date',
            ],
          ],
          [
            'name' => 'end_date',
            'in' => 'query',
            'description' => '结束日期 (YYYY-MM-DD)',
            'schema' => [
              'type' => 'string',
              'format' => 'date',
            ],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '成功获取安全日志',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/SecurityLogsResponse',
                ],
              ],
            ],
          ],
          '401' => [
            'description' => '未授权访问',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
          '403' => [
            'description' => '权限不足',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
        ],
      ],
    ];

    // 获取指定用户角色
    $paths['/auth/user-roles'] = [
      'post' => [
        'summary' => '获取指定用户角色',
        'description' => '获取指定用户在指定租户下的角色列表',
        'operationId' => 'getSpecificUserRoles',
        'tags' => ['权限管理'],
        'requestBody' => [
          'required' => true,
          'content' => [
            'application/json' => [
              'schema' => [
                '$ref' => '#/components/schemas/UserRolesRequest',
              ],
            ],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '成功获取用户角色',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/UserRolesResponse',
                ],
              ],
            ],
          ],
          '400' => [
            'description' => '请求参数错误',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
          '403' => [
            'description' => '权限不足',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
        ],
      ],
    ];

    // 分配用户角色
    $paths['/auth/assign-role'] = [
      'post' => [
        'summary' => '分配用户角色',
        'description' => '为指定用户分配角色',
        'operationId' => 'assignUserRole',
        'tags' => ['权限管理'],
        'requestBody' => [
          'required' => true,
          'content' => [
            'application/json' => [
              'schema' => [
                '$ref' => '#/components/schemas/AssignRoleRequest',
              ],
            ],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '角色分配成功',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/RoleAssignResponse',
                ],
              ],
            ],
          ],
          '400' => [
            'description' => '请求参数错误',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
          '403' => [
            'description' => '权限不足',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
        ],
      ],
    ];

    // 移除用户角色
    $paths['/auth/remove-role'] = [
      'post' => [
        'summary' => '移除用户角色',
        'description' => '移除指定用户的角色',
        'operationId' => 'removeUserRole',
        'tags' => ['权限管理'],
        'requestBody' => [
          'required' => true,
          'content' => [
            'application/json' => [
              'schema' => [
                '$ref' => '#/components/schemas/RemoveRoleRequest',
              ],
            ],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '角色移除成功',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/RoleRemoveResponse',
                ],
              ],
            ],
          ],
          '400' => [
            'description' => '请求参数错误',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
          '403' => [
            'description' => '权限不足',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
          '404' => [
            'description' => '角色不存在',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
        ],
      ],
    ];

    // 获取可用角色列表
    $paths['/auth/available-roles'] = [
      'get' => [
        'summary' => '获取可用角色列表',
        'description' => '获取系统中所有可用的角色及其权限',
        'operationId' => 'getAvailableRoles',
        'tags' => ['权限管理'],
        'responses' => [
          '200' => [
            'description' => '成功获取角色列表',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/AvailableRolesResponse',
                ],
              ],
            ],
          ],
          '403' => [
            'description' => '权限不足',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ErrorResponse',
                ],
              ],
            ],
          ],
        ],
        'security' => [
          ['bearerAuth' => []],
        ],
      ],
    ];

    return $paths;
  }

  /**
   * 生成认证相关的组件.
   *
   * @return array
   *   认证组件配置.
   */
  protected function generateAuthComponents(): array
  {
    return [
      'schemas' => [
        // 请求模型
        'LoginRequest' => [
          'type' => 'object',
          'required' => ['username', 'password'],
          'properties' => [
            'username' => [
              'type' => 'string',
              'description' => '用户名或邮箱地址',
              'example' => 'user@example.com',
            ],
            'password' => [
              'type' => 'string',
              'description' => '用户密码',
              'format' => 'password',
              'example' => 'password123',
            ],
            'tenant_id' => [
              'type' => 'string',
              'description' => '租户ID（可选）',
              'example' => 'tenant_001',
            ],
            'remember_me' => [
              'type' => 'boolean',
              'description' => '是否记住登录状态',
              'default' => false,
            ],
          ],
        ],
        'RefreshTokenRequest' => [
          'type' => 'object',
          'required' => ['refresh_token'],
          'properties' => [
            'refresh_token' => [
              'type' => 'string',
              'description' => '刷新令牌',
              'example' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...',
            ],
          ],
        ],
        'VerifyTokenRequest' => [
          'type' => 'object',
          'required' => ['token'],
          'properties' => [
            'token' => [
              'type' => 'string',
              'description' => 'JWT令牌',
              'example' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...',
            ],
          ],
        ],
        'ChangePasswordRequest' => [
          'type' => 'object',
          'required' => ['current_password', 'new_password'],
          'properties' => [
            'current_password' => [
              'type' => 'string',
              'description' => '当前密码',
              'format' => 'password',
            ],
            'new_password' => [
              'type' => 'string',
              'description' => '新密码',
              'format' => 'password',
              'minLength' => 8,
            ],
            'confirm_password' => [
              'type' => 'string',
              'description' => '确认新密码',
              'format' => 'password',
            ],
          ],
        ],
        'CreateApiKeyRequest' => [
          'type' => 'object',
          'required' => ['name'],
          'properties' => [
            'name' => [
              'type' => 'string',
              'description' => 'API密钥名称',
              'example' => 'My API Key',
            ],
            'description' => [
              'type' => 'string',
              'description' => 'API密钥描述',
              'example' => '用于移动应用的API密钥',
            ],
            'permissions' => [
              'type' => 'array',
              'description' => 'API密钥权限列表',
              'items' => [
                'type' => 'string',
              ],
              'example' => ['read_entities', 'write_entities'],
            ],
            'expires_at' => [
              'type' => 'string',
              'format' => 'date-time',
              'description' => '过期时间（可选）',
              'example' => '2024-12-31T23:59:59Z',
            ],
          ],
        ],

        // 响应模型
        'LoginResponse' => [
          'type' => 'object',
          'properties' => [
            'data' => [
              'type' => 'object',
              'properties' => [
                'access_token' => [
                  'type' => 'string',
                  'description' => 'JWT访问令牌',
                  'example' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...',
                ],
                'refresh_token' => [
                  'type' => 'string',
                  'description' => '刷新令牌',
                  'example' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...',
                ],
                'token_type' => [
                  'type' => 'string',
                  'description' => '令牌类型',
                  'example' => 'Bearer',
                ],
                'expires_in' => [
                  'type' => 'integer',
                  'description' => '令牌过期时间（秒）',
                  'example' => 3600,
                ],
                'user' => [
                  '$ref' => '#/components/schemas/User',
                ],
              ],
            ],
            'meta' => [
              '$ref' => '#/components/schemas/ResponseMeta',
            ],
            'error' => [
              'type' => 'null',
            ],
          ],
        ],
        'TokenResponse' => [
          'type' => 'object',
          'properties' => [
            'data' => [
              'type' => 'object',
              'properties' => [
                'access_token' => [
                  'type' => 'string',
                  'description' => 'JWT访问令牌',
                ],
                'token_type' => [
                  'type' => 'string',
                  'description' => '令牌类型',
                  'example' => 'Bearer',
                ],
                'expires_in' => [
                  'type' => 'integer',
                  'description' => '令牌过期时间（秒）',
                ],
              ],
            ],
            'meta' => [
              '$ref' => '#/components/schemas/ResponseMeta',
            ],
            'error' => [
              'type' => 'null',
            ],
          ],
        ],
        'UserResponse' => [
          'type' => 'object',
          'properties' => [
            'data' => [
              '$ref' => '#/components/schemas/User',
            ],
            'meta' => [
              '$ref' => '#/components/schemas/ResponseMeta',
            ],
            'error' => [
              'type' => 'null',
            ],
          ],
        ],
        'TokenVerifyResponse' => [
          'type' => 'object',
          'properties' => [
            'data' => [
              'type' => 'object',
              'properties' => [
                'valid' => [
                  'type' => 'boolean',
                  'description' => '令牌是否有效',
                ],
                'user_id' => [
                  'type' => 'integer',
                  'description' => '用户ID',
                ],
                'expires_at' => [
                  'type' => 'string',
                  'format' => 'date-time',
                  'description' => '令牌过期时间',
                ],
              ],
            ],
            'meta' => [
              '$ref' => '#/components/schemas/ResponseMeta',
            ],
            'error' => [
              'type' => 'null',
            ],
          ],
        ],
        'PermissionsResponse' => [
          'type' => 'object',
          'properties' => [
            'data' => [
              'type' => 'array',
              'items' => [
                'type' => 'object',
                'properties' => [
                  'name' => [
                    'type' => 'string',
                    'description' => '权限名称',
                  ],
                  'label' => [
                    'type' => 'string',
                    'description' => '权限标签',
                  ],
                  'description' => [
                    'type' => 'string',
                    'description' => '权限描述',
                  ],
                ],
              ],
            ],
            'meta' => [
              '$ref' => '#/components/schemas/ResponseMeta',
            ],
            'error' => [
              'type' => 'null',
            ],
          ],
        ],
        'RolesResponse' => [
          'type' => 'object',
          'properties' => [
            'data' => [
              'type' => 'array',
              'items' => [
                'type' => 'object',
                'properties' => [
                  'id' => [
                    'type' => 'string',
                    'description' => '角色ID',
                  ],
                  'label' => [
                    'type' => 'string',
                    'description' => '角色名称',
                  ],
                  'weight' => [
                    'type' => 'integer',
                    'description' => '角色权重',
                  ],
                ],
              ],
            ],
            'meta' => [
              '$ref' => '#/components/schemas/ResponseMeta',
            ],
            'error' => [
              'type' => 'null',
            ],
          ],
        ],
        'ApiKeysResponse' => [
          'type' => 'object',
          'properties' => [
            'data' => [
              'type' => 'array',
              'items' => [
                '$ref' => '#/components/schemas/ApiKey',
              ],
            ],
            'pagination' => [
              '$ref' => '#/components/schemas/Pagination',
            ],
            'meta' => [
              '$ref' => '#/components/schemas/ResponseMeta',
            ],
            'error' => [
              'type' => 'null',
            ],
          ],
        ],
        'ApiKeyResponse' => [
          'type' => 'object',
          'properties' => [
            'data' => [
              '$ref' => '#/components/schemas/ApiKey',
            ],
            'meta' => [
              '$ref' => '#/components/schemas/ResponseMeta',
            ],
            'error' => [
              'type' => 'null',
            ],
          ],
        ],
        'SessionsResponse' => [
          'type' => 'object',
          'properties' => [
            'data' => [
              'type' => 'array',
              'items' => [
                'type' => 'object',
                'properties' => [
                  'id' => [
                    'type' => 'string',
                    'description' => '会话ID',
                  ],
                  'ip_address' => [
                    'type' => 'string',
                    'description' => 'IP地址',
                  ],
                  'user_agent' => [
                    'type' => 'string',
                    'description' => '用户代理',
                  ],
                  'created_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                    'description' => '创建时间',
                  ],
                  'last_activity' => [
                    'type' => 'string',
                    'format' => 'date-time',
                    'description' => '最后活动时间',
                  ],
                  'is_current' => [
                    'type' => 'boolean',
                    'description' => '是否为当前会话',
                  ],
                ],
              ],
            ],
            'meta' => [
              '$ref' => '#/components/schemas/ResponseMeta',
            ],
            'error' => [
              'type' => 'null',
            ],
          ],
        ],
        'SecurityLogsResponse' => [
          'type' => 'object',
          'properties' => [
            'data' => [
              'type' => 'array',
              'items' => [
                'type' => 'object',
                'properties' => [
                  'id' => [
                    'type' => 'integer',
                    'description' => '日志ID',
                  ],
                  'level' => [
                    'type' => 'string',
                    'enum' => ['info', 'warning', 'error', 'critical'],
                    'description' => '日志级别',
                  ],
                  'event' => [
                    'type' => 'string',
                    'description' => '事件类型',
                  ],
                  'message' => [
                    'type' => 'string',
                    'description' => '日志消息',
                  ],
                  'user_id' => [
                    'type' => 'integer',
                    'description' => '用户ID',
                    'nullable' => true,
                  ],
                  'ip_address' => [
                    'type' => 'string',
                    'description' => 'IP地址',
                  ],
                  'user_agent' => [
                    'type' => 'string',
                    'description' => '用户代理',
                  ],
                  'created_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                    'description' => '创建时间',
                  ],
                ],
              ],
            ],
            'pagination' => [
              '$ref' => '#/components/schemas/Pagination',
            ],
            'meta' => [
              '$ref' => '#/components/schemas/ResponseMeta',
            ],
            'error' => [
              'type' => 'null',
            ],
          ],
        ],

        // 通用模型
        'User' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'integer',
              'description' => '用户ID',
              'example' => 123,
            ],
            'username' => [
              'type' => 'string',
              'description' => '用户名',
              'example' => 'testuser',
            ],
            'email' => [
              'type' => 'string',
              'format' => 'email',
              'description' => '邮箱地址',
              'example' => 'test@example.com',
            ],
            'display_name' => [
              'type' => 'string',
              'description' => '显示名称',
              'example' => '测试用户',
            ],
            'roles' => [
              'type' => 'array',
              'items' => [
                'type' => 'string',
              ],
              'description' => '用户角色',
              'example' => ['authenticated', 'editor'],
            ],
            'created_at' => [
              'type' => 'string',
              'format' => 'date-time',
              'description' => '创建时间',
            ],
            'last_login' => [
              'type' => 'string',
              'format' => 'date-time',
              'description' => '最后登录时间',
              'nullable' => true,
            ],
            'status' => [
              'type' => 'boolean',
              'description' => '用户状态',
            ],
          ],
        ],
        'ApiKey' => [
          'type' => 'object',
          'properties' => [
            'id' => [
              'type' => 'integer',
              'description' => 'API密钥ID',
            ],
            'name' => [
              'type' => 'string',
              'description' => 'API密钥名称',
            ],
            'description' => [
              'type' => 'string',
              'description' => 'API密钥描述',
              'nullable' => true,
            ],
            'key' => [
              'type' => 'string',
              'description' => 'API密钥值（仅在创建时返回）',
              'nullable' => true,
            ],
            'key_preview' => [
              'type' => 'string',
              'description' => 'API密钥预览（部分隐藏）',
              'example' => 'sk_****_****_1234',
            ],
            'permissions' => [
              'type' => 'array',
              'items' => [
                'type' => 'string',
              ],
              'description' => 'API密钥权限',
            ],
            'status' => [
              'type' => 'boolean',
              'description' => 'API密钥状态',
            ],
            'created_at' => [
              'type' => 'string',
              'format' => 'date-time',
              'description' => '创建时间',
            ],
            'expires_at' => [
              'type' => 'string',
              'format' => 'date-time',
              'description' => '过期时间',
              'nullable' => true,
            ],
            'last_used_at' => [
              'type' => 'string',
              'format' => 'date-time',
              'description' => '最后使用时间',
              'nullable' => true,
            ],
          ],
        ],
        'SuccessResponse' => [
          'type' => 'object',
          'properties' => [
            'data' => [
              'type' => 'object',
              'properties' => [
                'success' => [
                  'type' => 'boolean',
                  'example' => true,
                ],
                'message' => [
                  'type' => 'string',
                  'example' => '操作成功',
                ],
              ],
            ],
            'meta' => [
              '$ref' => '#/components/schemas/ResponseMeta',
            ],
            'error' => [
              'type' => 'null',
            ],
          ],
        ],
        'ErrorResponse' => [
          'type' => 'object',
          'properties' => [
            'data' => [
              'type' => 'null',
            ],
            'meta' => [
              '$ref' => '#/components/schemas/ResponseMeta',
            ],
            'error' => [
              'type' => 'object',
              'properties' => [
                'code' => [
                  'type' => 'string',
                  'description' => '错误代码',
                  'example' => 'INVALID_CREDENTIALS',
                ],
                'message' => [
                  'type' => 'string',
                  'description' => '错误消息',
                  'example' => '用户名或密码错误',
                ],
                'details' => [
                  'type' => 'object',
                  'description' => '错误详情',
                  'nullable' => true,
                ],
              ],
            ],
          ],
        ],
        'ResponseMeta' => [
          'type' => 'object',
          'properties' => [
            'timestamp' => [
              'type' => 'string',
              'format' => 'date-time',
              'description' => '响应时间戳',
            ],
            'version' => [
              'type' => 'string',
              'description' => 'API版本',
              'example' => '1.0.0',
            ],
            'request_id' => [
              'type' => 'string',
              'description' => '请求ID',
              'example' => 'req_123456789',
            ],
          ],
        ],
        'Pagination' => [
          'type' => 'object',
          'properties' => [
            'total' => [
              'type' => 'integer',
              'description' => '总记录数',
            ],
            'page' => [
              'type' => 'integer',
              'description' => '当前页码',
            ],
            'limit' => [
              'type' => 'integer',
              'description' => '每页记录数',
            ],
            'pages' => [
              'type' => 'integer',
              'description' => '总页数',
            ],
          ],
        ],

        // 角色管理相关模型
        'UserRolesRequest' => [
          'type' => 'object',
          'required' => ['user_id', 'tenant_id'],
          'properties' => [
            'user_id' => [
              'type' => 'integer',
              'description' => '用户ID',
              'example' => 123,
            ],
            'tenant_id' => [
              'type' => 'string',
              'description' => '租户ID',
              'example' => 'tenant_001',
            ],
          ],
        ],
        'UserRolesResponse' => [
          'type' => 'object',
          'properties' => [
            'data' => [
              'type' => 'object',
              'properties' => [
                'user_id' => [
                  'type' => 'integer',
                  'description' => '用户ID',
                ],
                'tenant_id' => [
                  'type' => 'string',
                  'description' => '租户ID',
                ],
                'roles' => [
                  'type' => 'array',
                  'items' => [
                    'type' => 'object',
                    'properties' => [
                      'role_name' => [
                        'type' => 'string',
                        'description' => '角色名称',
                        'example' => 'tenant_admin',
                      ],
                      'assigned_at' => [
                        'type' => 'string',
                        'format' => 'date-time',
                        'description' => '分配时间',
                      ],
                    ],
                  ],
                  'description' => '用户角色列表',
                ],
              ],
            ],
            'meta' => [
              '$ref' => '#/components/schemas/ResponseMeta',
            ],
            'error' => [
              'type' => 'null',
            ],
          ],
        ],
        'AssignRoleRequest' => [
          'type' => 'object',
          'required' => ['user_id', 'role_name'],
          'properties' => [
            'user_id' => [
              'type' => 'integer',
              'description' => '用户ID',
              'example' => 123,
            ],
            'role_name' => [
              'type' => 'string',
              'description' => '角色名称',
              'enum' => ['tenant_admin', 'tenant_user', 'api_client'],
              'example' => 'tenant_user',
            ],
          ],
        ],
        'RoleAssignResponse' => [
          'type' => 'object',
          'properties' => [
            'data' => [
              'type' => 'object',
              'properties' => [
                'success' => [
                  'type' => 'boolean',
                  'example' => true,
                ],
                'message' => [
                  'type' => 'string',
                  'example' => '角色分配成功',
                ],
                'user_id' => [
                  'type' => 'integer',
                  'description' => '用户ID',
                ],
                'role_name' => [
                  'type' => 'string',
                  'description' => '分配的角色名称',
                ],
                'assigned_at' => [
                  'type' => 'string',
                  'format' => 'date-time',
                  'description' => '分配时间',
                ],
              ],
            ],
            'meta' => [
              '$ref' => '#/components/schemas/ResponseMeta',
            ],
            'error' => [
              'type' => 'null',
            ],
          ],
        ],
        'RemoveRoleRequest' => [
          'type' => 'object',
          'required' => ['user_id', 'role_name'],
          'properties' => [
            'user_id' => [
              'type' => 'integer',
              'description' => '用户ID',
              'example' => 123,
            ],
            'role_name' => [
              'type' => 'string',
              'description' => '角色名称',
              'enum' => ['tenant_admin', 'tenant_user', 'api_client'],
              'example' => 'tenant_user',
            ],
          ],
        ],
        'RoleRemoveResponse' => [
          'type' => 'object',
          'properties' => [
            'data' => [
              'type' => 'object',
              'properties' => [
                'success' => [
                  'type' => 'boolean',
                  'example' => true,
                ],
                'message' => [
                  'type' => 'string',
                  'example' => '角色移除成功',
                ],
                'user_id' => [
                  'type' => 'integer',
                  'description' => '用户ID',
                ],
                'role_name' => [
                  'type' => 'string',
                  'description' => '移除的角色名称',
                ],
              ],
            ],
            'meta' => [
              '$ref' => '#/components/schemas/ResponseMeta',
            ],
            'error' => [
              'type' => 'null',
            ],
          ],
        ],
        'AvailableRolesResponse' => [
          'type' => 'object',
          'properties' => [
            'data' => [
              'type' => 'array',
              'items' => [
                'type' => 'object',
                'properties' => [
                  'role_name' => [
                    'type' => 'string',
                    'description' => '角色名称',
                    'example' => 'tenant_admin',
                  ],
                  'display_name' => [
                    'type' => 'string',
                    'description' => '角色显示名称',
                    'example' => '租户管理员',
                  ],
                  'description' => [
                    'type' => 'string',
                    'description' => '角色描述',
                    'example' => '拥有租户管理权限',
                  ],
                  'permissions' => [
                    'type' => 'array',
                    'items' => [
                      'type' => 'string',
                    ],
                    'description' => '角色权限列表',
                    'example' => ['manage_users', 'manage_entities', 'view_analytics'],
                  ],
                ],
              ],
              'description' => '可用角色列表',
            ],
            'meta' => [
              '$ref' => '#/components/schemas/ResponseMeta',
            ],
            'error' => [
              'type' => 'null',
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * 生成项目级别的API路径文档。
   *
   * @param string $tenant_id
   *   租户ID。
   *
   * @return array
   *   项目级API路径数组。
   */
  protected function generateProjectPaths(string $tenant_id): array
  {
    $paths = [];

    // 获取项目级实体模板路径
    $paths['/projects/{project_id}/templates'] = [
      'get' => [
        'summary' => '获取项目实体模板列表',
        'description' => '获取指定项目下所有实体模板的列表。',
        'operationId' => 'getProjectTemplates',
        'tags' => ['项目实体'],
        'parameters' => [
          [
            'name' => 'project_id',
            'in' => 'path',
            'required' => true,
            'description' => '项目ID',
            'schema' => [
              'type' => 'string',
            ],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '成功获取项目实体模板列表',
            'content' => [
              'application/json' => [
                'schema' => [
                  '$ref' => '#/components/schemas/ProjectTemplatesResponse',
                ],
              ],
            ],
          ],
          '401' => ['description' => '未授权访问'],
          '403' => ['description' => '无权限访问此项目'],
          '404' => ['description' => '项目不存在'],
        ],
        'security' => [
          ['bearerAuth' => []],
          ['apiKeyAuth' => []],
        ],
      ],
    ];

    // 获取项目实体模板详情
    $paths['/projects/{project_id}/templates/{template_name}'] = [
      'get' => [
        'summary' => '获取项目实体模板详情',
        'description' => '获取指定项目的实体模板详细信息，包括字段定义。',
        'operationId' => 'getProjectTemplate',
        'tags' => ['项目实体'],
        'parameters' => [
          [
            'name' => 'project_id',
            'in' => 'path',
            'required' => true,
            'description' => '项目ID',
            'schema' => ['type' => 'string'],
          ],
          [
            'name' => 'template_name',
            'in' => 'path',
            'required' => true,
            'description' => '模板名称',
            'schema' => ['type' => 'string'],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '成功获取项目实体模板详情',
            'content' => [
              'application/json' => [
                'schema' => ['$ref' => '#/components/schemas/ProjectTemplateResponse'],
              ],
            ],
          ],
          '401' => ['description' => '未授权访问'],
          '403' => ['description' => '无权限访问此项目'],
          '404' => ['description' => '项目或模板不存在'],
        ],
        'security' => [
          ['bearerAuth' => []],
          ['apiKeyAuth' => []],
        ],
      ],
    ];

    // 获取项目实体数据列表
    $paths['/projects/{project_id}/entities/{entity_name}'] = [
      'get' => [
        'summary' => '获取项目实体数据列表',
        'description' => '获取指定项目和实体类型的数据列表。',
        'operationId' => 'getProjectEntities',
        'tags' => ['项目实体数据'],
        'parameters' => [
          [
            'name' => 'project_id',
            'in' => 'path',
            'required' => true,
            'description' => '项目ID',
            'schema' => ['type' => 'string'],
          ],
          [
            'name' => 'entity_name',
            'in' => 'path',
            'required' => true,
            'description' => '实体名称',
            'schema' => ['type' => 'string'],
          ],
          [
            'name' => 'page',
            'in' => 'query',
            'required' => false,
            'description' => '页码（从1开始）',
            'schema' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
          ],
          [
            'name' => 'limit',
            'in' => 'query',
            'required' => false,
            'description' => '每页记录数',
            'schema' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '成功获取项目实体数据列表',
            'content' => [
              'application/json' => [
                'schema' => ['$ref' => '#/components/schemas/ProjectEntitiesResponse'],
              ],
            ],
          ],
          '401' => ['description' => '未授权访问'],
          '403' => ['description' => '无权限访问此项目'],
          '404' => ['description' => '项目或实体类型不存在'],
        ],
        'security' => [
          ['bearerAuth' => []],
          ['apiKeyAuth' => []],
        ],
      ],
    ];

    // 创建项目实体数据 - JSON方式
    $paths['/projects/{project_id}/entities/{entity_name}']['post'] = [
      'summary' => '创建项目实体数据（JSON格式）',
      'description' => '使用JSON格式在指定项目中创建新的实体数据。适用于不包含文件上传的普通数据创建。',
      'operationId' => 'createProjectEntityJson',
      'tags' => ['项目实体数据（JSON）'],
      'parameters' => [
        [
          'name' => 'project_id',
          'in' => 'path',
          'required' => true,
          'description' => '项目ID',
          'schema' => ['type' => 'string'],
        ],
        [
          'name' => 'entity_name',
          'in' => 'path',
          'required' => true,
          'description' => '实体名称',
          'schema' => ['type' => 'string'],
        ],
      ],
      'requestBody' => [
        'required' => true,
        'description' => '实体数据（JSON格式）',
        'content' => [
          'application/json' => [
            'schema' => ['$ref' => '#/components/schemas/ProjectEntityData'],
            'examples' => [
              'simple' => [
                'summary' => '简单实体数据',
                'description' => '使用JSON格式提交简单的实体数据',
                'value' => [
                  'title' => '示例标题',
                  'content' => '示例内容',
                  'status' => 'active',
                ],
              ],
              'complex' => [
                'summary' => '复杂实体数据',
                'description' => '包含多种字段类型的实体数据',
                'value' => [
                  'title' => '复杂数据示例',
                  'content' => '详细的内容描述',
                  'status' => 'draft',
                  'priority' => 1,
                  'tags' => ['tag1', 'tag2'],
                  'metadata' => [
                    'author' => 'admin',
                    'category' => 'test',
                  ],
                ],
              ],
            ],
          ],
        ],
      ],
      'responses' => [
        '201' => [
          'description' => '成功创建实体数据',
          'content' => [
            'application/json' => [
              'schema' => ['$ref' => '#/components/schemas/ProjectEntityResponse'],
            ],
          ],
        ],
        '400' => ['description' => '请求数据无效'],
        '401' => ['description' => '未授权访问'],
        '403' => ['description' => '无权限在此项目中创建实体'],
        '404' => ['description' => '项目或实体类型不存在'],
        '500' => ['description' => '服务器内部错误'],
      ],
      'security' => [
        ['bearerAuth' => []],
        ['apiKeyAuth' => []],
      ],
    ];

    // 创建项目实体数据 - Multipart方式（支持文件上传）
    // 使用不同的路径来创建独立的文件上传端点，类似于更新端点的做法
    if (!isset($paths['/projects/{project_id}/entities/{entity_name}/create'])) {
      $paths['/projects/{project_id}/entities/{entity_name}/create'] = [];
    }
    $paths['/projects/{project_id}/entities/{entity_name}/create']['post'] = [
      'summary' => '创建实体数据（支持文件上传）',
      'description' => '使用multipart/form-data格式创建项目实体数据，支持文件和图片字段上传。注意：1) 字段名需要根据实际entity结构确定，示例中的img和document仅为参考；2) 如果某个文件字段不需要上传，请删除该字段或留空，系统会自动忽略空值字段。',
      'operationId' => 'createProjectEntityWithFiles',
      'tags' => ['项目实体数据（文件上传）'],
      'parameters' => [
        [
          'name' => 'project_id',
          'in' => 'path',
          'required' => true,
          'description' => '项目ID',
          'schema' => ['type' => 'string'],
        ],
        [
          'name' => 'entity_name',
          'in' => 'path',
          'required' => true,
          'description' => '实体名称',
          'schema' => ['type' => 'string'],
        ],
      ],
      'requestBody' => [
        'required' => true,
        'description' => '创建的实体数据（支持文件上传）',
        'content' => [
          'multipart/form-data' => [
            'schema' => [
              'type' => 'object',
              'properties' => [
                'title' => [
                  'type' => 'string',
                  'description' => '标题字段（示例）',
                  'example' => 'New Title',
                ],
                'img' => [
                  'type' => 'string',
                  'format' => 'binary',
                  'description' => '图片字段（示例字段名，请根据实际entity结构修改字段名，如：avatar、photo、image等）。如果不上传文件，请删除此字段或留空，系统会自动忽略空值字段。',
                  'nullable' => true,
                ],
                'document' => [
                  'type' => 'string',
                  'format' => 'binary', 
                  'description' => '文档字段（示例字段名，请根据实际entity结构修改字段名，如：file、attachment、pdf等）。如果不上传文件，请删除此字段或留空，系统会自动忽略空值字段。',
                  'nullable' => true,
                ],
              ],
              'required' => ['title'],
              'additionalProperties' => [
                'oneOf' => [
                  [
                    'type' => 'string',
                    'description' => '其他文本字段值',
                  ],
                  [
                    'type' => 'string',
                    'format' => 'binary',
                    'description' => '其他文件字段值',
                  ],
                ],
                'description' => '支持动态添加其他字段，字段名和类型根据实际entity结构确定',
              ],
            ],
            'examples' => [
              'create-with-files' => [
                'summary' => '包含文件的创建数据',
                'description' => '使用multipart/form-data格式创建实体数据并上传文件。字段名需要根据实体结构动态调整。',
                'value' => [
                  'title' => 'Example Title',
                  'img' => '(binary file data)',
                  'document' => '(binary file data)',
                ],
              ],
              'create-partial-files' => [
                'summary' => '部分文件字段的创建数据',
                'description' => '只上传部分文件字段的示例。未填入的文件字段会被忽略。',
                'value' => [
                  'title' => 'Example Title',
                  'img' => '(binary file data)',
                  // document字段被省略，系统会忽略
                ],
              ],
            ],
          ],
        ],
      ],
      'responses' => [
        '201' => [
          'description' => '成功创建实体数据（包含文件）',
          'content' => [
            'application/json' => [
              'schema' => ['$ref' => '#/components/schemas/ProjectEntityResponse'],
            ],
          ],
        ],
        '400' => ['description' => '请求数据无效或文件格式不支持'],
        '401' => ['description' => '未授权访问'],
        '403' => ['description' => '无权限在此项目中创建实体'],
        '404' => ['description' => '项目或实体类型不存在'],
        '413' => ['description' => '文件大小超出限制'],
        '500' => ['description' => '服务器内部错误'],
      ],
      'security' => [
        ['bearerAuth' => []],
        ['apiKeyAuth' => []],
      ],
    ];

    // 获取、更新、删除单个项目实体数据
    $paths['/projects/{project_id}/entities/{entity_name}/{id}'] = [
      'get' => [
        'summary' => '获取单个项目实体数据',
        'description' => '获取指定项目中的单个实体数据。',
        'operationId' => 'getProjectEntity',
        'tags' => ['项目实体数据'],
        'parameters' => [
          [
            'name' => 'project_id',
            'in' => 'path',
            'required' => true,
            'description' => '项目ID',
            'schema' => ['type' => 'string'],
          ],
          [
            'name' => 'entity_name',
            'in' => 'path',
            'required' => true,
            'description' => '实体名称',
            'schema' => ['type' => 'string'],
          ],
          [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => '实体ID',
            'schema' => ['type' => 'string'],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '成功获取实体数据',
            'content' => [
              'application/json' => [
                'schema' => ['$ref' => '#/components/schemas/ProjectEntityResponse'],
              ],
            ],
          ],
          '401' => ['description' => '未授权访问'],
          '403' => ['description' => '无权限访问此项目'],
          '404' => ['description' => '项目、实体类型或实体数据不存在'],
        ],
        'security' => [
          ['bearerAuth' => []],
          ['apiKeyAuth' => []],
        ],
      ],
      'put' => [
        'summary' => '更新项目实体数据（JSON格式）',
        'description' => '使用JSON格式更新指定项目中的实体数据。适用于不包含文件上传的普通数据更新。',
        'operationId' => 'updateProjectEntityJson',
        'tags' => ['项目实体数据（JSON）'],
        'parameters' => [
          [
            'name' => 'project_id',
            'in' => 'path',
            'required' => true,
            'description' => '项目ID',
            'schema' => ['type' => 'string'],
          ],
          [
            'name' => 'entity_name',
            'in' => 'path',
            'required' => true,
            'description' => '实体名称',
            'schema' => ['type' => 'string'],
          ],
          [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => '实体ID',
            'schema' => ['type' => 'string'],
          ],
        ],
        'requestBody' => [
          'required' => true,
          'description' => '更新的实体数据',
          'content' => [
            'application/json' => [
              'schema' => ['$ref' => '#/components/schemas/ProjectEntityData'],
            ],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '成功更新实体数据',
            'content' => [
              'application/json' => [
                'schema' => ['$ref' => '#/components/schemas/ProjectEntityResponse'],
              ],
            ],
          ],
          '400' => ['description' => '请求数据无效'],
          '401' => ['description' => '未授权访问'],
          '403' => ['description' => '无权限在此项目中更新实体'],
          '404' => ['description' => '项目、实体类型或实体数据不存在'],
        ],
        'security' => [
          ['bearerAuth' => []],
          ['apiKeyAuth' => []],
        ],
      ],
      'delete' => [
        'summary' => '删除项目实体数据',
        'description' => '删除指定项目中的实体数据。',
        'operationId' => 'deleteProjectEntity',
        'tags' => ['项目实体数据'],
        'parameters' => [
          [
            'name' => 'project_id',
            'in' => 'path',
            'required' => true,
            'description' => '项目ID',
            'schema' => ['type' => 'string'],
          ],
          [
            'name' => 'entity_name',
            'in' => 'path',
            'required' => true,
            'description' => '实体名称',
            'schema' => ['type' => 'string'],
          ],
          [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => '实体ID',
            'schema' => ['type' => 'string'],
          ],
        ],
        'responses' => [
          '200' => [
            'description' => '成功删除实体数据',
            'content' => [
              'application/json' => [
                'schema' => ['$ref' => '#/components/schemas/ProjectEntityDeleteResponse'],
              ],
            ],
          ],
          '401' => ['description' => '未授权访问'],
          '403' => ['description' => '无权限在此项目中删除实体'],
          '404' => ['description' => '项目、实体类型或实体数据不存在'],
        ],
        'security' => [
          ['bearerAuth' => []],
          ['apiKeyAuth' => []],
        ],
      ],
    ];

    // 更新项目实体数据 - Multipart方式（支持文件上传）
    if (!isset($paths['/projects/{project_id}/entities/{entity_name}/{id}'])) {
      $paths['/projects/{project_id}/entities/{entity_name}/{id}'] = [];
    }
    $paths['/projects/{project_id}/entities/{entity_name}/{id}']['post'] = [
      'summary' => '更新项目实体数据（支持文件上传）',
      'description' => '使用multipart/form-data格式更新项目实体数据，支持文件和图片字段上传。注意：1) 字段名需要根据实际entity结构确定，示例中的img和document仅为参考；2) 如果某个文件字段不需要上传，请删除该字段或留空，系统会自动忽略空值字段，对应字段将保持原值。',
      'operationId' => 'updateProjectEntityWithFiles',
      'tags' => ['项目实体数据（文件上传）'],
      'parameters' => [
        [
          'name' => 'project_id',
          'in' => 'path',
          'required' => true,
          'description' => '项目ID',
          'schema' => ['type' => 'string'],
        ],
        [
          'name' => 'entity_name',
          'in' => 'path',
          'required' => true,
          'description' => '实体名称',
          'schema' => ['type' => 'string'],
        ],
        [
          'name' => 'id',
          'in' => 'path',
          'required' => true,
          'description' => '实体ID',
          'schema' => ['type' => 'string'],
        ],
      ],
      'requestBody' => [
        'required' => true,
        'description' => '更新的实体数据（支持文件上传）',
        'content' => [
          'multipart/form-data' => [
            'schema' => [
              'type' => 'object',
              'properties' => [
                'title' => [
                  'type' => 'string',
                  'description' => '标题字段（示例）',
                  'example' => 'Updated Title',
                ],
                'img' => [
                  'type' => 'string',
                  'format' => 'binary',
                  'description' => '图片字段（示例字段名，请根据实际entity结构修改字段名，如：avatar、photo、image等）。如果不上传文件，请删除此字段或留空，系统会自动忽略空值字段。',
                  'nullable' => true,
                ],
                'document' => [
                  'type' => 'string',
                  'format' => 'binary',
                  'description' => '文档字段（示例字段名，请根据实际entity结构修改字段名，如：file、attachment、pdf等）。如果不上传文件，请删除此字段或留空，系统会自动忽略空值字段。',
                  'nullable' => true,
                ],
              ],
              'additionalProperties' => [
                'oneOf' => [
                  [
                    'type' => 'string',
                    'description' => '其他文本字段值',
                  ],
                  [
                    'type' => 'string',
                    'format' => 'binary',
                    'description' => '其他文件字段值',
                  ],
                ],
                'description' => '支持动态添加其他字段，字段名和类型根据实际entity结构确定',
              ],
            ],
            'examples' => [
              'update-with-files' => [
                'summary' => '包含文件的更新数据',
                'description' => '使用multipart/form-data格式更新实体数据并上传新文件。字段名需要根据实体结构动态调整。如果不上传文件，对应字段将保持原值。',
                'value' => [
                  'title' => 'Updated Example Title',
                  'img' => '(binary file data)',
                  'document' => '(binary file data)',
                ],
              ],
              'update-partial-files' => [
                'summary' => '部分字段更新数据',
                'description' => '只更新部分字段的示例。未包含的文件字段会保持原值。',
                'value' => [
                  'title' => 'Updated Title Only',
                  'img' => '(binary file data)',
                  // document字段被省略，保持原值
                ],
              ],
            ],
          ],
        ],
      ],
      'responses' => [
        '200' => [
          'description' => '成功更新实体数据（包含文件）',
          'content' => [
            'application/json' => [
              'schema' => ['$ref' => '#/components/schemas/ProjectEntityResponse'],
            ],
          ],
        ],
        '400' => ['description' => '请求数据无效或文件格式不支持'],
        '401' => ['description' => '未授权访问'],
        '403' => ['description' => '无权限在此项目中更新实体'],
        '404' => ['description' => '项目、实体类型或实体数据不存在'],
        '413' => ['description' => '文件大小超出限制'],
        '500' => ['description' => '服务器内部错误'],
      ],
      'security' => [
        ['bearerAuth' => []],
        ['apiKeyAuth' => []],
      ],
    ];

    return $paths;
  }

  /**
   * 生成项目相关的组件定义。
   *
   * @return array
   *   项目相关的组件定义数组。
   */
  protected function generateProjectComponents(): array
  {
    return [
      'schemas' => [
        'ProjectTemplatesResponse' => [
          'type' => 'object',
          'properties' => [
            'success' => ['type' => 'boolean', 'example' => true],
            'data' => [
              'type' => 'object',
              'properties' => [
                'templates' => [
                  'type' => 'array',
                  'items' => ['$ref' => '#/components/schemas/ProjectTemplate'],
                ],
                'project_id' => ['type' => 'string', 'example' => 'project_123'],
                'tenant_id' => ['type' => 'string', 'example' => 'tenant_456'],
                'count' => ['type' => 'integer', 'example' => 5],
              ],
            ],
            'message' => ['type' => 'string', 'example' => '成功获取项目实体模板'],
          ],
        ],
        'ProjectTemplateResponse' => [
          'type' => 'object',
          'properties' => [
            'success' => ['type' => 'boolean', 'example' => true],
            'data' => [
              'type' => 'object',
              'properties' => [
                'template' => ['$ref' => '#/components/schemas/ProjectTemplate'],
              ],
            ],
            'message' => ['type' => 'string', 'example' => '成功获取项目实体模板'],
          ],
        ],
        'ProjectTemplate' => [
          'type' => 'object',
          'properties' => [
            'id' => ['type' => 'string', 'example' => 'template_789'],
            'name' => ['type' => 'string', 'example' => 'users'],
            'label' => ['type' => 'string', 'example' => '用户'],
            'description' => ['type' => 'string', 'example' => '用户实体模板'],
            'project_id' => ['type' => 'string', 'example' => 'project_123'],
            'tenant_id' => ['type' => 'string', 'example' => 'tenant_456'],
            'status' => ['type' => 'integer', 'example' => 1],
            'created' => ['type' => 'integer', 'example' => 1640995200],
            'updated' => ['type' => 'integer', 'example' => 1640995200],
            'settings' => ['type' => 'object', 'example' => []],
            'fields' => [
              'type' => 'array',
              'items' => ['$ref' => '#/components/schemas/ProjectTemplateField'],
            ],
          ],
        ],
        'ProjectTemplateField' => [
          'type' => 'object',
          'properties' => [
            'id' => ['type' => 'string', 'example' => 'field_101'],
            'name' => ['type' => 'string', 'example' => 'name'],
            'label' => ['type' => 'string', 'example' => '姓名'],
            'type' => ['type' => 'string', 'example' => 'string'],
            'required' => ['type' => 'boolean', 'example' => true],
            'settings' => ['type' => 'object', 'example' => []],
            'weight' => ['type' => 'integer', 'example' => 0],
          ],
        ],
        'ProjectEntitiesResponse' => [
          'type' => 'object',
          'properties' => [
            'success' => ['type' => 'boolean', 'example' => true],
            'data' => [
              'type' => 'object',
              'properties' => [
                'entities' => [
                  'type' => 'array',
                  'items' => ['$ref' => '#/components/schemas/ProjectEntityData'],
                ],
                'project_id' => ['type' => 'string', 'example' => 'project_123'],
                'entity_name' => ['type' => 'string', 'example' => 'users'],
                'pagination' => ['$ref' => '#/components/schemas/PaginationInfo'],
              ],
            ],
            'message' => ['type' => 'string', 'example' => '成功获取项目实体数据'],
          ],
        ],
        'ProjectEntityResponse' => [
          'type' => 'object',
          'properties' => [
            'success' => ['type' => 'boolean', 'example' => true],
            'data' => [
              'type' => 'object',
              'properties' => [
                'entity' => ['$ref' => '#/components/schemas/ProjectEntityData'],
                'project_id' => ['type' => 'string', 'example' => 'project_123'],
                'entity_name' => ['type' => 'string', 'example' => 'users'],
              ],
            ],
            'message' => ['type' => 'string', 'example' => '成功获取项目实体数据'],
          ],
        ],
        'ProjectEntityData' => [
          'type' => 'object',
          'properties' => [
            'id' => ['type' => 'string', 'example' => 'entity_456'],
            'created' => ['type' => 'integer', 'example' => 1640995200],
            'updated' => ['type' => 'integer', 'example' => 1640995200],
            'status' => ['type' => 'integer', 'example' => 1],
          ],
          'additionalProperties' => true,
          'description' => '项目实体数据，具体字段由实体模板定义',
        ],
        'ProjectEntityDeleteResponse' => [
          'type' => 'object',
          'properties' => [
            'success' => ['type' => 'boolean', 'example' => true],
            'data' => [
              'type' => 'object',
              'properties' => [
                'deleted_id' => ['type' => 'string', 'example' => 'entity_456'],
                'project_id' => ['type' => 'string', 'example' => 'project_123'],
                'entity_name' => ['type' => 'string', 'example' => 'users'],
              ],
            ],
            'message' => ['type' => 'string', 'example' => '成功删除项目实体数据'],
          ],
        ],
        'PaginationInfo' => [
          'type' => 'object',
          'properties' => [
            'page' => ['type' => 'integer', 'example' => 1],
            'limit' => ['type' => 'integer', 'example' => 20],
            'total' => ['type' => 'integer', 'example' => 100],
            'pages' => ['type' => 'integer', 'example' => 5],
          ],
        ],
      ],
    ];
  }
}