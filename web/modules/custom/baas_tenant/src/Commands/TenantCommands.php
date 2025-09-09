<?php

namespace Drupal\baas_tenant\Commands;

use Drupal\baas_tenant\TenantManagerInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drush\Drush;
use Psr\Log\LoggerInterface;

/**
 * BaaS租户Drush命令.
 *
 * @DrushCommands(
 *   id = "baas_tenant.commands",
 *   group = "baas_tenant"
 * )
 */
class TenantCommands extends DrushCommands {

  /**
   * 租户管理服务.
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected $tenantManager;

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理服务.
   */
  public function __construct(TenantManagerInterface $tenant_manager) {
    parent::__construct();
    $this->tenantManager = $tenant_manager;
  }

  /**
   * 静态创建实例.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   服务容器.
   *
   * @return static
   *   命令实例.
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('baas_tenant.manager')
    );
  }

  /**
   * 列出所有租户.
   *
   * @command baas-tenant:list
   * @aliases baas-tenants
   * @usage drush baas-tenant:list
   *   列出所有租户信息.
   */
  public function listTenants() {
    $tenants = $this->tenantManager->listTenants();

    if (empty($tenants)) {
      $this->output()->writeln('未找到租户。');
      return;
    }

    $rows = [];
    foreach ($tenants as $tenant) {
      $rows[] = [
        $tenant['tenant_id'],
        $tenant['name'],
        $tenant['status'] ? '启用' : '禁用',
        date('Y-m-d H:i', $tenant['created']),
        isset($tenant['settings']['api_key']) ? '已设置' : '未设置',
      ];
    }

    $this->io()->table(
      ['租户ID', '名称', '状态', '创建时间', 'API密钥'],
      $rows
    );
  }

  /**
   * 生成或查看租户API密钥.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param array $options
   *   命令选项.
   *
   * @option generate 生成新的API密钥
   * @option remove 移除现有API密钥
   *
   * @command baas-tenant:api-key
   * @aliases baas-api-key
   * @usage drush baas-tenant:api-key tenant_123
   *   查看租户tenant_123的API密钥
   * @usage drush baas-tenant:api-key tenant_123 --generate
   *   为租户tenant_123生成新的API密钥
   * @usage drush baas-tenant:api-key tenant_123 --remove
   *   移除租户tenant_123的API密钥
   */
  public function apiKey($tenant_id, array $options = ['generate' => FALSE, 'remove' => FALSE]) {
    // 首先检查租户是否存在
    $tenant = $this->tenantManager->getTenant($tenant_id);
    if (!$tenant) {
      throw new \Exception(dt('找不到租户: @tenant_id', ['@tenant_id' => $tenant_id]));
    }

    // 处理移除选项
    if ($options['remove']) {
      if ($this->io()->confirm(dt('确定要移除租户 @tenant_id 的API密钥吗?', ['@tenant_id' => $tenant_id]))) {
        $result = $this->tenantManager->removeApiKey($tenant_id);
        if ($result) {
          $this->io()->success(dt('已移除租户 @tenant_id 的API密钥', ['@tenant_id' => $tenant_id]));
        }
        else {
          $this->io()->error(dt('移除租户 @tenant_id 的API密钥失败', ['@tenant_id' => $tenant_id]));
        }
      }
      return;
    }

    // 处理生成选项
    if ($options['generate']) {
      if ($this->tenantManager->getApiKey($tenant_id) && !$this->io()->confirm(dt('租户 @tenant_id 已有API密钥，确定要替换吗?', ['@tenant_id' => $tenant_id]))) {
        return;
      }

      $api_key = $this->tenantManager->generateApiKey($tenant_id);
      if ($api_key) {
        $this->io()->success(dt('已为租户 @tenant_id 生成新的API密钥', ['@tenant_id' => $tenant_id]));
        $this->io()->writeln(dt('API密钥: @api_key', ['@api_key' => $api_key]));
      }
      else {
        $this->io()->error(dt('为租户 @tenant_id 生成API密钥失败', ['@tenant_id' => $tenant_id]));
      }
      return;
    }

    // 默认查看API密钥
    $api_key = $this->tenantManager->getApiKey($tenant_id);
    if ($api_key) {
      $this->io()->writeln(dt('租户 @tenant_id 的API密钥: @api_key', [
        '@tenant_id' => $tenant_id,
        '@api_key' => $api_key,
      ]));
    }
    else {
      $this->io()->writeln(dt('租户 @tenant_id 未设置API密钥', ['@tenant_id' => $tenant_id]));
    }
  }

  /**
   * 设置租户API密钥.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param string $api_key
   *   要设置的API密钥.
   *
   * @command baas-tenant:set-api-key
   * @aliases baas-set-api-key
   * @usage drush baas-tenant:set-api-key tenant_123 my_api_key
   *   为租户tenant_123设置指定的API密钥
   */
  public function setApiKey($tenant_id, $api_key) {
    // 首先检查租户是否存在
    $tenant = $this->tenantManager->getTenant($tenant_id);
    if (!$tenant) {
      throw new \Exception(dt('找不到租户: @tenant_id', ['@tenant_id' => $tenant_id]));
    }

    // 更新设置
    $settings = $tenant['settings'] ?? [];
    $settings['api_key'] = $api_key;

    $result = $this->tenantManager->updateTenant($tenant_id, ['settings' => $settings]);
    if ($result) {
      $this->io()->success(dt('API密钥已设置'));
    }
    else {
      $this->io()->error(dt('设置API密钥失败'));
    }
  }

}
