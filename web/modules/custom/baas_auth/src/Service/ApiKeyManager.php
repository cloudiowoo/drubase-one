<?php

namespace Drupal\baas_auth\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * API密钥管理器服务实现。
 */
class ApiKeyManager implements ApiKeyManagerInterface
{

  /**
   * 数据库连接。
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * 日志记录器。
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * 构造函数。
   *
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接。
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂。
   */
  public function __construct(Connection $database, LoggerChannelFactoryInterface $logger_factory)
  {
    $this->database = $database;
    $this->logger = $logger_factory->get('baas_auth');
  }

  /**
   * {@inheritdoc}
   */
  public function createApiKey(string $tenant_id, int $user_id, string $name, array $permissions = [], ?int $expires_at = NULL): ?array
  {
    try {
      $api_key = $this->generateApiKey();
      $now = time();

      $fields = [
        'api_key' => $api_key,
        'tenant_id' => $tenant_id,
        'user_id' => $user_id,
        'name' => $name,
        'permissions' => json_encode($permissions),
        'status' => 1,
        'created' => $now,
      ];

      if ($expires_at !== NULL) {
        $fields['expires_at'] = $expires_at;
      }

      $id = $this->database->insert('baas_auth_api_keys')
        ->fields($fields)
        ->execute();

      $this->logger->info('创建了新的API密钥: @name (ID: @id)', [
        '@name' => $name,
        '@id' => $id,
      ]);

      return [
        'id' => $id,
        'api_key' => $api_key,
        'tenant_id' => $tenant_id,
        'name' => $name,
        'user_id' => $user_id,
        'permissions' => $permissions,
        'expires_at' => $expires_at,
        'status' => 1,
        'created' => $now,
      ];
    } catch (\Exception $e) {
      $this->logger->error('创建API密钥失败: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateApiKey(string $api_key): ?array
  {
    try {
      $result = $this->database->select('baas_auth_api_keys', 'a')
        ->fields('a')
        ->condition('api_key', $api_key)
        ->condition('status', 1)
        ->execute()
        ->fetchAssoc();

      if (!$result) {
        return NULL;
      }

      // 检查过期时间
      if ($result['expires_at'] && $result['expires_at'] < time()) {
        return NULL;
      }

      // 更新最后使用时间
      $this->database->update('baas_auth_api_keys')
        ->fields(['last_used' => time()])
        ->condition('id', $result['id'])
        ->execute();

      // 解析权限
      $result['permissions'] = $result['permissions'] ? json_decode($result['permissions'], TRUE) : [];

      return $result;
    } catch (\Exception $e) {
      $this->logger->error('验证API密钥失败: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getApiKey(int $id): ?array
  {
    try {
      $result = $this->database->select('baas_auth_api_keys', 'a')
        ->fields('a')
        ->condition('id', $id)
        ->execute()
        ->fetchAssoc();

      if ($result) {
        $result['permissions'] = $result['permissions'] ? json_decode($result['permissions'], TRUE) : [];
      }

      return $result ?: NULL;
    } catch (\Exception $e) {
      $this->logger->error('获取API密钥失败: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateApiKey(int $id, array $data): bool
  {
    try {
      $update_fields = [];

      if (isset($data['name'])) {
        $update_fields['name'] = $data['name'];
      }

      if (isset($data['permissions'])) {
        $update_fields['permissions'] = json_encode($data['permissions']);
      }

      if (isset($data['expires_at'])) {
        $update_fields['expires_at'] = $data['expires_at'];
      }

      if (isset($data['status'])) {
        $update_fields['status'] = $data['status'];
      }

      if (empty($update_fields)) {
        return TRUE; // 没有需要更新的字段
      }

      $this->database->update('baas_auth_api_keys')
        ->fields($update_fields)
        ->condition('id', $id)
        ->execute();

      $this->logger->info('更新了API密钥 (ID: @id)', ['@id' => $id]);
      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('更新API密钥失败: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteApiKey(int $id): bool
  {
    try {
      $this->database->delete('baas_auth_api_keys')
        ->condition('id', $id)
        ->execute();

      $this->logger->info('删除了API密钥 (ID: @id)', ['@id' => $id]);
      return TRUE;
    } catch (\Exception $e) {
      $this->logger->error('删除API密钥失败: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function regenerateApiKey(int $id): ?string
  {
    try {
      $new_key = $this->generateApiKey();

      $this->database->update('baas_auth_api_keys')
        ->fields(['api_key' => $new_key])
        ->condition('id', $id)
        ->execute();

      $this->logger->info('重新生成了API密钥 (ID: @id)', ['@id' => $id]);
      return $new_key;
    } catch (\Exception $e) {
      $this->logger->error('重新生成API密钥失败: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function listApiKeys(string $tenant_id, ?int $user_id = NULL): array
  {
    try {
      $query = $this->database->select('baas_auth_api_keys', 'a')
        ->fields('a')
        ->condition('tenant_id', $tenant_id)
        ->orderBy('created', 'DESC');

      if ($user_id !== NULL) {
        $query->condition('user_id', $user_id);
      }

      $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

      // 解析权限
      foreach ($results as &$result) {
        $result['permissions'] = $result['permissions'] ? json_decode($result['permissions'], TRUE) : [];
      }

      return $results;
    } catch (\Exception $e) {
      $this->logger->error('获取API密钥列表失败: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getTenantApiKeys(string $tenant_id): array
  {
    try {
      $results = $this->database->select('baas_auth_api_keys', 'a')
        ->fields('a')
        ->condition('tenant_id', $tenant_id)
        ->orderBy('created', 'DESC')
        ->execute()
        ->fetchAll(\PDO::FETCH_ASSOC);

      // 解析权限
      foreach ($results as &$result) {
        $result['permissions'] = $result['permissions'] ? json_decode($result['permissions'], TRUE) : [];
      }

      return $results;
    } catch (\Exception $e) {
      $this->logger->error('获取租户API密钥列表失败: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * 生成API密钥。
   *
   * @return string
   *   生成的API密钥。
   */
  protected function generateApiKey(): string
  {
    return 'drubase_' . bin2hex(random_bytes(32));
  }
}
