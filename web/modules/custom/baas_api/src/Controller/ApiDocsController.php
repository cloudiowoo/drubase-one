<?php

declare(strict_types=1);

namespace Drupal\baas_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\baas_api\Service\ApiDocGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Render\HtmlResponse;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;

/**
 * API文档控制器.
 *
 * 提供API文档查看和导出功能。
 */
class ApiDocsController extends ControllerBase {

  /**
   * API文档生成器.
   *
   * @var \Drupal\baas_api\Service\ApiDocGenerator
   */
  protected ApiDocGenerator $docGenerator;

  /**
   * 渲染器服务.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_api\Service\ApiDocGenerator $doc_generator
   *   API文档生成器.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   渲染器服务.
   */
  public function __construct(ApiDocGenerator $doc_generator, RendererInterface $renderer) {
    $this->docGenerator = $doc_generator;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_api.docs_generator'),
      $container->get('renderer')
    );
  }

  /**
   * 获取全局API文档.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象.
   *
   * @return \Symfony\Component\HttpFoundation\Response|array
   *   响应对象或渲染数组.
   */
  public function getApiDocs(?Request $request = NULL) {
    // 检查是否有认证用户，如果有则包含租户特定的端点
    $tenant_id = NULL;
    
    // 确保我们有request对象
    if (!$request) {
      $request = \Drupal::request();
    }
    
    // 尝试从请求中直接检测认证信息
    if ($request) {
      // 检查JWT token
      $auth_header = $request->headers->get('Authorization');
      if ($auth_header && strpos($auth_header, 'Bearer ') === 0) {
        $token = substr($auth_header, 7);
        try {
          $jwt_manager = \Drupal::service('baas_auth.jwt_token_manager');
          $payload = $jwt_manager->validateToken($token);
          if ($payload && isset($payload['tenant_id'])) {
            $tenant_id = $payload['tenant_id'];
            \Drupal::logger('baas_api')->info('getApiDocs: found tenant_id from JWT = @tenant_id', ['@tenant_id' => $tenant_id]);
          }
        } catch (\Exception $e) {
          // JWT解析失败，继续尝试其他认证方式
        }
      }
      
      // 检查API Key
      if (!$tenant_id) {
        $api_key = $request->headers->get('X-API-Key');
        if ($api_key) {
          try {
            $api_key_manager = \Drupal::service('baas_auth.api_key_manager');
            $key_data = $api_key_manager->validateApiKey($api_key);
            if ($key_data && isset($key_data['tenant_id'])) {
              $tenant_id = $key_data['tenant_id'];
              \Drupal::logger('baas_api')->info('getApiDocs: found tenant_id from API Key = @tenant_id', ['@tenant_id' => $tenant_id]);
            }
          } catch (\Exception $e) {
            // API Key验证失败
          }
        }
      }
    }
    
    if (!$tenant_id) {
      \Drupal::logger('baas_api')->info('getApiDocs: no tenant_id found from authentication headers');
    }
    
    // 生成API文档（包含租户特定端点，如果有认证用户的话）
    $docs = $tenant_id ? $this->docGenerator->generateTenantApiDocs($tenant_id) : $this->docGenerator->generateApiDocs();

    // 检查请求格式
    $format = $request ? $request->query->get('format', 'json') : 'json';

    if ($format === 'yaml' || $format === 'yml') {
      // 返回YAML格式
      $content = $this->docGenerator->toYaml($docs);
      $response = new Response($content);
      $response->headers->set('Content-Type', 'application/yaml');
      return $response;
    }
    else if ($format === 'html') {
      // 返回HTML格式（CSP兼容格式，无外部依赖）
      return [
        '#theme' => 'baas_api_docs_csp',
        '#docs' => $docs,
        '#title' => $this->t('API文档'),
        '#attached' => [
          'library' => ['baas_api/api_docs'],
        ],
      ];
    }
    else {
      // 默认返回JSON格式
      $content = $this->docGenerator->toJson($docs);
      $response = new Response($content);
      $response->headers->set('Content-Type', 'application/json');
      return $response;
    }
  }

  /**
   * 获取全局API文档HTML格式.
   *
   * @return array
   *   渲染数组.
   */
  public function getApiDocsHtml(): array {
    // 检查是否有认证用户，如果有则包含租户特定的端点
    $tenant_id = NULL;
    $current_user = \Drupal::currentUser();
    
    // 检查用户是否为BaasAuthenticatedUser（API Key或JWT认证）
    if ($current_user && method_exists($current_user, 'getTenantId')) {
      $tenant_id = $current_user->getTenantId();
    }
    
    // 生成API文档（包含租户特定端点，如果有认证用户的话）
    $docs = $tenant_id ? $this->docGenerator->generateTenantApiDocs($tenant_id) : $this->docGenerator->generateApiDocs();

    // 返回HTML格式（CSP兼容格式，无外部依赖）
    return [
      '#theme' => 'baas_api_docs_csp',
      '#docs' => $docs,
      '#title' => $this->t('API文档'),
      '#attached' => [
        'library' => ['baas_api/api_docs'],
      ],
    ];
  }

  /**
   * 获取Swagger UI格式的API文档.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   HTML响应.
   */
  public function getApiDocsSwagger(): Response {
    // 获取当前请求的基础URL
    $request = \Drupal::request();
    $base_url = $request->getSchemeAndHttpHost();
    $spec_url = $base_url . '/openapi.json';

    // 创建完全独立的 Swagger UI HTML
    $html = $this->generateSwaggerUIHtml($spec_url);
    
    $response = new Response($html);
    $response->headers->set('Content-Type', 'text/html; charset=utf-8');
    return $response;
  }

  /**
   * 生成独立的 Swagger UI HTML.
   *
   * @param string $spec_url
   *   API 规范 URL.
   *
   * @return string
   *   完整的 HTML 页面.
   */
  private function generateSwaggerUIHtml(string $spec_url): string {
    // 获取基础URL用于加载资源
    $request = \Drupal::request();
    $base_url = $request->getSchemeAndHttpHost();
    
    return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>BaaS API文档</title>
    <link rel="stylesheet" type="text/css" href="{$base_url}/modules/custom/baas_api/css/swagger-ui.css" />
    <style>
        html {
            box-sizing: border-box;
            overflow: -moz-scrollbars-vertical;
            overflow-y: scroll;
        }
        *, *:before, *:after {
            box-sizing: inherit;
        }
        body {
            margin: 0;
            background: #fafafa;
        }
        .auth-container {
            background: #f8f9fa;
            border-radius: 4px;
            padding: 15px;
            margin: 20px auto;
            max-width: 1200px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .auth-container h3 {
            margin-top: 0;
            color: #333;
        }
        .auth-row {
            margin: 10px 0;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        .auth-row label {
            font-weight: 500;
            min-width: 80px;
        }
        .auth-row input {
            flex: 1;
            min-width: 200px;
            padding: 5px;
            border: 1px solid #ddd;
            border-radius: 3px;
        }
        .auth-row button {
            padding: 5px 10px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-weight: 500;
        }
        .btn-primary {
            background: #007bff;
            color: white;
        }
        .btn-success {
            background: #28a745;
            color: white;
        }
        .btn-primary:hover {
            background: #0056b3;
        }
        .btn-success:hover {
            background: #1e7e34;
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <h3>🔐 API 认证设置</h3>
        <div class="auth-row">
            <label for="api-key-input">API Key:</label>
            <input type="text" id="api-key-input" placeholder="输入API Key" />
            <button id="set-api-key" class="btn-primary">设置API Key</button>
        </div>
        <div class="auth-row">
            <label for="jwt-token-input">JWT Token:</label>
            <input type="text" id="jwt-token-input" placeholder="输入JWT Token" />
            <button id="set-jwt-token" class="btn-success">设置JWT Token</button>
        </div>
    </div>
    
    <div id="swagger-ui"></div>
    
    <script src="{$base_url}/modules/custom/baas_api/js/swagger-ui-bundle.js"></script>
    <script src="{$base_url}/modules/custom/baas_api/js/swagger-ui-standalone-preset.js"></script>
    <script>
        // 等待脚本加载完成
        function waitForScripts() {
            if (typeof SwaggerUIBundle === 'undefined') {
                setTimeout(waitForScripts, 100);
                return;
            }
            initializeSwaggerUI();
        }
        
        function initializeSwaggerUI() {
            console.log('🚀 开始初始化 Swagger UI...');
            
            // 检查依赖是否加载
            console.log('SwaggerUIBundle 状态:', typeof SwaggerUIBundle);
            console.log('SwaggerUIStandalonePreset 状态:', typeof SwaggerUIStandalonePreset);
            
            if (typeof SwaggerUIBundle === 'undefined') {
                console.error('❌ SwaggerUIBundle 未加载');
                document.getElementById('swagger-ui').innerHTML = '<h2>❌ Swagger UI 库未加载</h2>';
                return;
            }
            
            // 全局变量存储认证信息
            let currentApiKey = localStorage.getItem('baas_api_key') || '';
            let currentJwtToken = localStorage.getItem('baas_jwt_token') || '';
            
            // 恢复保存的认证信息
            if (currentApiKey) {
                document.getElementById('api-key-input').value = currentApiKey;
            }
            if (currentJwtToken) {
                document.getElementById('jwt-token-input').value = currentJwtToken;
            }
            
            // API Key 设置
            document.getElementById('set-api-key').addEventListener('click', function() {
                const apiKey = document.getElementById('api-key-input').value;
                if (apiKey) {
                    currentApiKey = apiKey;
                    localStorage.setItem('baas_api_key', apiKey);
                    alert('API Key 已设置: ' + apiKey);
                } else {
                    currentApiKey = '';
                    localStorage.removeItem('baas_api_key');
                    alert('API Key 已清除');
                }
            });
            
            // JWT Token 设置
            document.getElementById('set-jwt-token').addEventListener('click', function() {
                const jwtToken = document.getElementById('jwt-token-input').value;
                if (jwtToken) {
                    currentJwtToken = jwtToken;
                    localStorage.setItem('baas_jwt_token', jwtToken);
                    alert('JWT Token 已设置');
                } else {
                    currentJwtToken = '';
                    localStorage.removeItem('baas_jwt_token');
                    alert('JWT Token 已清除');
                }
            });
            
            // 初始化 Swagger UI 配置
            const uiConfig = {
                url: '$spec_url',
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis
                ],
                plugins: [
                    SwaggerUIBundle.plugins.DownloadUrl
                ],
                layout: "BaseLayout",
                defaultModelsExpandDepth: 1,
                defaultModelExpandDepth: 1,
                docExpansion: "none",
                displayOperationId: false,
                displayRequestDuration: true,
                showExtensions: true,
                showCommonExtensions: true,
                tryItOutEnabled: true,
                requestInterceptor: function(request) {
                    console.log('Request intercepted:', request);
                    
                    // 添加 API Key
                    if (currentApiKey) {
                        request.headers['X-API-Key'] = currentApiKey;
                        console.log('Added API Key to request');
                    }
                    
                    // 添加 JWT Token
                    if (currentJwtToken) {
                        request.headers['Authorization'] = 'Bearer ' + currentJwtToken;
                        console.log('Added JWT Token to request');
                    }
                    
                    return request;
                },
                responseInterceptor: function(response) {
                    console.log('Response intercepted:', response);
                    return response;
                },
                onComplete: function() {
                    console.log('✅ Swagger UI 初始化完成');
                    console.log('📋 API 规范 URL: $spec_url');
                    console.log('🔑 当前 API Key: ' + (currentApiKey ? '已设置' : '未设置'));
                    console.log('🎫 当前 JWT Token: ' + (currentJwtToken ? '已设置' : '未设置'));
                }
            };
            
            // 暂时跳过 StandalonePreset 以避免错误
            console.log('使用基础 BaseLayout 配置');
            
            try {
                const ui = SwaggerUIBundle(uiConfig);
                console.log('✅ SwaggerUIBundle 实例化成功');
            } catch (e) {
                console.error('❌ SwaggerUIBundle 实例化失败:', e);
                document.getElementById('swagger-ui').innerHTML = '<h2>❌ Swagger UI 初始化失败</h2><p>' + e.message + '</p>';
            }
        }
        
        // 页面加载完成后开始等待脚本
        window.onload = function() {
            waitForScripts();
        };
    </script>
</body>
</html>
HTML;
  }

  /**
   * 获取租户特定的API文档.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return \Symfony\Component\HttpFoundation\Response|array
   *   响应对象或渲染数组.
   */
  public function getTenantApiDocs(Request $request, string $tenant_id) {
    // 使用新的generate方法生成租户特定的API文档
    $docs = $this->docGenerator->generate($tenant_id);

    // 检查请求格式
    $format = $request->query->get('format', 'json');

    if ($format === 'yaml' || $format === 'yml') {
      // 返回YAML格式
      $content = $this->docGenerator->toYaml($docs);
      $response = new Response($content);
      $response->headers->set('Content-Type', 'application/yaml');
      return $response;
    }
    else if ($format === 'html') {
      // 返回HTML格式（CSP兼容格式）
      return [
        '#theme' => 'baas_api_docs_csp',
        '#docs' => $docs,
        '#title' => $this->t('租户 @tenant_id API文档', ['@tenant_id' => $tenant_id]),
        '#tenant_id' => $tenant_id,
        '#attached' => [
          'library' => ['baas_api/api_docs'],
        ],
      ];
    }
    else {
      // 默认返回JSON格式
      $content = $this->docGenerator->toJson($docs);
      $response = new Response($content);
      $response->headers->set('Content-Type', 'application/json');
      return $response;
    }
  }

  /**
   * 获取租户的GraphQL模式文档.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   响应对象.
   */
  public function getTenantGraphQLSchema(Request $request, string $tenant_id): Response {
    // 生成租户GraphQL模式
    $schema = $this->docGenerator->generateGraphQLSchema($tenant_id);

    // 检查请求格式
    $format = $request->query->get('format', 'sdl');

    if ($format === 'html') {
      // 返回HTML格式（GraphiQL）
      $build = [
        '#theme' => 'baas_api_graphql_docs',
        '#schema' => $schema,
        '#title' => $this->t('租户 @tenant_id GraphQL模式', ['@tenant_id' => $tenant_id]),
        '#tenant_id' => $tenant_id,
      ];

      $context = new RenderContext();
      $output = $this->renderer->executeInRenderContext($context, function () use ($build) {
        return $this->renderer->render($build);
      });

      return new HtmlResponse($output);
    }
    else {
      // 默认返回SDL文本格式
      $response = new Response($schema);
      $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');
      return $response;
    }
  }

  /**
   * 导出API文档为Swagger JSON文件.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象.
   * @param string $tenant_id
   *   租户ID，可选.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   响应对象.
   */
  public function downloadApiDocs(Request $request, ?string $tenant_id = NULL): Response {
    // 生成API文档
    $docs = $tenant_id
      ? $this->docGenerator->generate($tenant_id)
      : $this->docGenerator->generateApiDocs();

    // 将文档转换为JSON
    $content = $this->docGenerator->toJson($docs);

    // 创建文件下载响应
    $filename = $tenant_id ? "api-docs-tenant-{$tenant_id}.json" : "api-docs.json";
    $response = new Response($content);
    $response->headers->set('Content-Type', 'application/json');
    $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");

    return $response;
  }

  /**
   * 导出GraphQL模式文档.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象.
   * @param string $tenant_id
   *   租户ID.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   响应对象.
   */
  public function downloadGraphQLSchema(Request $request, string $tenant_id): Response {
    // 生成GraphQL模式
    $schema = $this->docGenerator->generateGraphQLSchema($tenant_id);

    // 创建文件下载响应
    $filename = "graphql-schema-tenant-{$tenant_id}.graphql";
    $response = new Response($schema);
    $response->headers->set('Content-Type', 'text/plain');
    $response->headers->set('Content-Disposition', "attachment; filename=\"{$filename}\"");

    return $response;
  }

}
