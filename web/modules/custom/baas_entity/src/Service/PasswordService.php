<?php

declare(strict_types=1);

namespace Drupal\baas_entity\Service;

use Drupal\Core\Database\Connection;
use Drupal\baas_entity\Service\FieldTypeManager;

/**
 * 密码服务类，提供密码相关的功能。
 */
class PasswordService
{

  /**
   * 构造函数。
   *
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接。
   * @param \Drupal\baas_entity\Service\FieldTypeManager $fieldTypeManager
   *   字段类型管理器。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly FieldTypeManager $fieldTypeManager,
  ) {}

  /**
   * 验证用户密码。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称（通常是'users'）。
   * @param array $credentials
   *   认证凭据，包含用户标识符（email/username）和密码。
   *
   * @return array|null
   *   如果验证成功返回用户数据，否则返回null。
   */
  public function verifyUserPassword(
    string $tenant_id, 
    string $project_id, 
    string $entity_name, 
    array $credentials
  ): ?array {
    try {
      // 构建表名
      $table_name_generator = \Drupal::service('baas_project.table_name_generator');
      $table_name = $table_name_generator->generateTableName($tenant_id, $project_id, $entity_name);

      // 获取实体模板
      $template = $this->database->select('baas_entity_template', 'et')
        ->fields('et', ['id'])
        ->condition('name', $entity_name)
        ->condition('tenant_id', $tenant_id)
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchAssoc();

      if (!$template) {
        return null;
      }

      // 获取密码字段信息
      $password_field = $this->getPasswordField($template['id']);
      if (!$password_field) {
        return null; // 没有密码字段
      }

      // 查找用户
      $user = $this->findUser($table_name, $credentials);
      if (!$user) {
        return null; // 用户不存在
      }

      // 验证密码
      $password_field_name = $password_field['name'];
      $stored_password = $user[$password_field_name] ?? '';
      
      if (empty($stored_password)) {
        return null; // 没有设置密码
      }

      // 使用密码字段插件验证密码
      $passwordPlugin = $this->fieldTypeManager->getPlugin('password');
      if (!$passwordPlugin->verifyPassword($credentials['password'], $stored_password)) {
        return null; // 密码不匹配
      }

      // 密码验证成功，返回用户数据（不包含密码字段）
      unset($user[$password_field_name]);
      return $user;

    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('密码验证失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }

  /**
   * 获取实体的密码字段。
   *
   * @param string $template_id
   *   模板ID。
   *
   * @return array|null
   *   密码字段信息，如果没有则返回null。
   */
  protected function getPasswordField(string $template_id): ?array {
    $password_field = $this->database->select('baas_entity_field', 'ef')
      ->fields('ef', ['name', 'settings'])
      ->condition('template_id', $template_id)
      ->condition('type', 'password')
      ->range(0, 1)
      ->execute()
      ->fetchAssoc();

    return $password_field ?: null;
  }

  /**
   * 根据凭据查找用户。
   *
   * @param string $table_name
   *   表名。
   * @param array $credentials
   *   认证凭据。
   *
   * @return array|null
   *   用户数据，如果不存在则返回null。
   */
  protected function findUser(string $table_name, array $credentials): ?array {
    if (!$this->database->schema()->tableExists($table_name)) {
      return null;
    }

    $query = $this->database->select($table_name, 'u')
      ->fields('u');

    // 支持通过email或username登录
    if (!empty($credentials['email'])) {
      $query->condition('email', $credentials['email']);
    } elseif (!empty($credentials['username'])) {
      $query->condition('username', $credentials['username']);
    } else {
      return null; // 必须提供email或username
    }

    $user = $query->execute()->fetchAssoc();
    return $user ?: null;
  }

  /**
   * 更改用户密码。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   * @param int|string $user_id
   *   用户ID。
   * @param string $old_password
   *   旧密码。
   * @param string $new_password
   *   新密码。
   *
   * @return bool
   *   是否成功。
   */
  public function changePassword(
    string $tenant_id,
    string $project_id,
    string $entity_name,
    int|string $user_id,
    string $old_password,
    string $new_password
  ): bool {
    try {
      // 首先验证旧密码
      $credentials = ['password' => $old_password];
      $table_name_generator = \Drupal::service('baas_project.table_name_generator');
      $table_name = $table_name_generator->generateTableName($tenant_id, $project_id, $entity_name);

      // 获取用户当前数据
      $user = $this->database->select($table_name, 'u')
        ->fields('u')
        ->condition('id', $user_id)
        ->execute()
        ->fetchAssoc();

      if (!$user) {
        return false;
      }

      // 获取实体模板和密码字段
      $template = $this->database->select('baas_entity_template', 'et')
        ->fields('et', ['id'])
        ->condition('name', $entity_name)
        ->condition('tenant_id', $tenant_id)
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchAssoc();

      if (!$template) {
        return false;
      }

      $password_field = $this->getPasswordField($template['id']);
      if (!$password_field) {
        return false;
      }

      $password_field_name = $password_field['name'];
      $stored_password = $user[$password_field_name] ?? '';

      // 验证旧密码
      $passwordPlugin = $this->fieldTypeManager->getPlugin('password');
      if (!$passwordPlugin->verifyPassword($old_password, $stored_password)) {
        return false; // 旧密码不正确
      }

      // 处理新密码
      $settings = is_string($password_field['settings']) ? 
        json_decode($password_field['settings'], TRUE) : 
        ($password_field['settings'] ?? []);

      $hashed_new_password = $passwordPlugin->processValue($new_password, $settings);

      // 更新密码
      $updated = $this->database->update($table_name)
        ->fields([
          $password_field_name => $hashed_new_password,
          'updated' => time(),
        ])
        ->condition('id', $user_id)
        ->execute();

      return $updated > 0;

    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('更改密码失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * 重置用户密码。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   * @param string $email
   *   用户邮箱。
   * @param string $new_password
   *   新密码。
   *
   * @return bool
   *   是否成功。
   */
  public function resetPassword(
    string $tenant_id,
    string $project_id,
    string $entity_name,
    string $email,
    string $new_password
  ): bool {
    try {
      $table_name_generator = \Drupal::service('baas_project.table_name_generator');
      $table_name = $table_name_generator->generateTableName($tenant_id, $project_id, $entity_name);

      // 查找用户
      $user = $this->database->select($table_name, 'u')
        ->fields('u')
        ->condition('email', $email)
        ->execute()
        ->fetchAssoc();

      if (!$user) {
        return false;
      }

      // 获取实体模板和密码字段
      $template = $this->database->select('baas_entity_template', 'et')
        ->fields('et', ['id'])
        ->condition('name', $entity_name)
        ->condition('tenant_id', $tenant_id)
        ->condition('project_id', $project_id)
        ->execute()
        ->fetchAssoc();

      if (!$template) {
        return false;
      }

      $password_field = $this->getPasswordField($template['id']);
      if (!$password_field) {
        return false;
      }

      // 处理新密码
      $settings = is_string($password_field['settings']) ? 
        json_decode($password_field['settings'], TRUE) : 
        ($password_field['settings'] ?? []);

      $passwordPlugin = $this->fieldTypeManager->getPlugin('password');
      $hashed_new_password = $passwordPlugin->processValue($new_password, $settings);

      // 更新密码
      $password_field_name = $password_field['name'];
      $updated = $this->database->update($table_name)
        ->fields([
          $password_field_name => $hashed_new_password,
          'updated' => time(),
        ])
        ->condition('id', $user['id'])
        ->execute();

      return $updated > 0;

    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('重置密码失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }
}