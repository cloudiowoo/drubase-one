<?php

namespace Drupal\baas_api\Commands;

use Drupal\baas_api\Service\ApiDocGenerator;
use Drupal\baas_tenant\TenantManagerInterface;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Output\Output;

/**
 * BaaS API Drush命令.
 *
 * @DrushCommands(
 *   id = "baas_api.commands",
 *   group = "baas-api"
 * )
 */
class BaasApiCommands extends DrushCommands {

  /**
   * API文档生成器.
   *
   * @var \Drupal\baas_api\Service\ApiDocGenerator
   */
  protected $docGenerator;

  /**
   * 租户管理器.
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected $tenantManager;

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_api\Service\ApiDocGenerator $doc_generator
   *   API文档生成器.
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理器.
   */
  public function __construct(
    ApiDocGenerator $doc_generator,
    TenantManagerInterface $tenant_manager
  ) {
    parent::__construct();
    $this->docGenerator = $doc_generator;
    $this->tenantManager = $tenant_manager;
  }

  /**
   * 导出API文档到文件.
   *
   * @param string $tenant_id
   *   租户ID，可选.
   * @param array $options
   *   选项数组.
   *
   * @option format
   *   文档格式(json,yaml)，默认为json.
   * @option path
   *   导出路径，默认为当前目录.
   * @option graphql
   *   是否导出GraphQL模式，默认为否.
   *
   * @usage baas-api:docs-export
   *   导出全局API文档.
   * @usage baas-api:docs-export tenant1
   *   导出租户tenant1的API文档.
   * @usage baas-api:docs-export tenant1 --format=yaml
   *   以YAML格式导出租户tenant1的API文档.
   * @usage baas-api:docs-export tenant1 --graphql
   *   导出租户tenant1的GraphQL模式.
   *
   * @aliases baas-docs
   * @command baas-api:docs-export
   */
  public function docsExport($tenant_id = NULL, array $options = [
    'format' => 'json',
    'path' => NULL,
    'graphql' => FALSE,
  ]) {
    // 确定输出路径
    $path = $options['path'] ?: getcwd();
    if (!is_dir($path) || !is_writable($path)) {
      throw new \Exception(dt('输出路径 @path 不存在或不可写', ['@path' => $path]));
    }

    // 确定是否导出GraphQL模式
    $graphql = (bool) $options['graphql'];

    // 确定格式
    $format = $options['format'];
    if (!in_array($format, ['json', 'yaml', 'yml'])) {
      throw new \Exception(dt('不支持的格式: @format', ['@format' => $format]));
    }
    if ($format === 'yml') {
      $format = 'yaml';
    }

    if ($graphql) {
      // 确保提供了租户ID
      if (empty($tenant_id)) {
        throw new \Exception(dt('导出GraphQL模式需要提供租户ID'));
      }

      // 检查租户是否存在
      if (!$this->tenantManager->getTenant($tenant_id)) {
        throw new \Exception(dt('租户不存在: @tenant_id', ['@tenant_id' => $tenant_id]));
      }

      // 生成并导出GraphQL模式
      $schema = $this->docGenerator->generateGraphQLSchema($tenant_id);
      $filename = sprintf('%s/graphql-schema-tenant-%s.graphql', $path, $tenant_id);

      // 写入文件
      if (file_put_contents($filename, $schema)) {
        $this->logger()->success(dt('已导出GraphQL模式到: @filename', ['@filename' => $filename]));
      }
      else {
        throw new \Exception(dt('导出GraphQL模式失败'));
      }
    }
    else {
      // 生成API文档
      if ($tenant_id) {
        // 检查租户是否存在
        if (!$this->tenantManager->getTenant($tenant_id)) {
          throw new \Exception(dt('租户不存在: @tenant_id', ['@tenant_id' => $tenant_id]));
        }

        $this->logger()->info(dt('生成租户 @tenant_id 的API文档...', ['@tenant_id' => $tenant_id]));
        $docs = $this->docGenerator->generateTenantApiDocs($tenant_id);
        $filename = sprintf('%s/api-docs-tenant-%s.%s', $path, $tenant_id, $format);
      }
      else {
        $this->logger()->info(dt('生成全局API文档...'));
        $docs = $this->docGenerator->generateApiDocs();
        $filename = sprintf('%s/api-docs.%s', $path, $format);
      }

      // 转换格式并写入文件
      if ($format === 'yaml') {
        $content = $this->docGenerator->toYaml($docs);
      }
      else {
        $content = $this->docGenerator->toJson($docs);
      }

      if (file_put_contents($filename, $content)) {
        $this->logger()->success(dt('已导出API文档到: @filename', ['@filename' => $filename]));
      }
      else {
        throw new \Exception(dt('导出API文档失败'));
      }
    }
  }

  /**
   * 清理API相关缓存.
   *
   * @usage baas-api:cache-clear
   *   清理API相关缓存.
   *
   * @aliases baas-cc
   * @command baas-api:cache-clear
   */
  public function cacheClear() {
    // 获取API管理器服务
    $api_manager = \Drupal::service('baas_api.manager');

    // 清理速率限制缓存
    $this->logger()->info(dt('清理API速率限制缓存...'));
    \Drupal::service('baas_api.rate_limiter')->cleanupCache();

    // 清理其他API相关缓存
    $this->logger()->info(dt('清理API文档缓存...'));
    \Drupal::service('cache.data')->deleteAll();

    $this->logger()->success(dt('API缓存清理完成.'));
  }

  /**
   * 创建API令牌表。
   *
   * @usage baas-api:create-token-table
   *   创建API令牌表。
   *
   * @aliases baas-ctt
   * @command baas-api:create-token-table
   */
  public function createTokenTable() {
    // API令牌表结构定义
    $schema = [
      'description' => 'API访问令牌表',
      'fields' => [
        'id' => [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'description' => '主键',
        ],
        'tenant_id' => [
          'type' => 'varchar',
          'length' => 128,
          'not null' => TRUE,
          'description' => '租户ID',
        ],
        'token_hash' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
          'description' => '令牌哈希',
        ],
        'name' => [
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
          'description' => '令牌名称',
        ],
        'scopes' => [
          'type' => 'text',
          'not null' => TRUE,
          'description' => '令牌作用域',
        ],
        'created' => [
          'type' => 'int',
          'not null' => TRUE,
          'description' => '创建时间戳',
        ],
        'expires' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
          'description' => '过期时间戳，0表示永不过期',
        ],
        'last_used' => [
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
          'description' => '最后使用时间戳',
        ],
        'status' => [
          'type' => 'int',
          'size' => 'tiny',
          'not null' => TRUE,
          'default' => 1,
          'description' => '状态: 1=活跃, 0=撤销',
        ],
      ],
      'primary key' => ['id'],
      'indexes' => [
        'token_idx' => ['tenant_id', 'token_hash'],
        'status' => ['status'],
      ],
    ];

    $database = \Drupal::database();
    $schema_handler = $database->schema();

    if ($schema_handler->tableExists('baas_api_tokens')) {
      $this->logger()->info('API令牌表已存在');
      return;
    }

    try {
      $schema_handler->createTable('baas_api_tokens', $schema);
      $this->logger()->success('成功创建API令牌表');
    }
    catch (\Exception $e) {
      $this->logger()->error('创建API令牌表失败: @error', ['@error' => $e->getMessage()]);
    }
  }

}
