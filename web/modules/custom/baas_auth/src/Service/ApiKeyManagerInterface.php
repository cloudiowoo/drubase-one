<?php
/*
 * @Date: 2025-06-02 23:27:25
 * @LastEditors: cloudio cloudio.woo@gmail.com
 * @LastEditTime: 2025-06-02 23:31:16
 * @FilePath: /drubase/web/modules/custom/baas_auth/src/Service/ApiKeyManagerInterface.php
 */

namespace Drupal\baas_auth\Service;

/**
 * API密钥管理器接口。
 */
interface ApiKeyManagerInterface
{

  /**
   * 创建API密钥。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param int $user_id
   *   用户ID。
   * @param string $name
   *   密钥名称。
   * @param array $permissions
   *   权限数组。
   * @param int|null $expires_at
   *   过期时间戳。
   *
   * @return array|null
   *   创建成功返回密钥数据，失败返回NULL。
   */
  public function createApiKey(string $tenant_id, int $user_id, string $name, array $permissions = [], int $expires_at = NULL): ?array;

  /**
   * 验证API密钥。
   *
   * @param string $api_key
   *   API密钥字符串。
   *
   * @return array|null
   *   验证成功返回密钥数据，失败返回NULL。
   */
  public function validateApiKey(string $api_key): ?array;

  /**
   * 获取API密钥信息。
   *
   * @param int $id
   *   密钥ID。
   *
   * @return array|null
   *   密钥数据。
   */
  public function getApiKey(int $id): ?array;

  /**
   * 获取租户的所有API密钥。
   *
   * @param string $tenant_id
   *   租户ID。
   *
   * @return array
   *   密钥数组。
   */
  public function getTenantApiKeys(string $tenant_id): array;

  /**
   * 更新API密钥。
   *
   * @param int $id
   *   密钥ID。
   * @param array $data
   *   更新数据。
   *
   * @return bool
   *   更新成功返回TRUE，失败返回FALSE。
   */
  public function updateApiKey(int $id, array $data): bool;

  /**
   * 删除API密钥。
   *
   * @param int $id
   *   密钥ID。
   *
   * @return bool
   *   删除成功返回TRUE，失败返回FALSE。
   */
  public function deleteApiKey(int $id): bool;

  /**
   * 重新生成API密钥。
   *
   * @param int $id
   *   密钥ID。
   *
   * @return string|null
   *   新密钥字符串。
   */
  public function regenerateApiKey(int $id): ?string;
}
