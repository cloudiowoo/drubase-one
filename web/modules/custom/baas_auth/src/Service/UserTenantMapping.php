<?php

namespace Drupal\baas_auth\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\baas_auth\Exception\UserTenantMappingException;

/**
 * Service for managing user-tenant relationships.
 */
class UserTenantMapping implements UserTenantMappingInterface
{

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a UserTenantMapping object.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger channel factory.
   */
  public function __construct(Connection $database, LoggerChannelFactoryInterface $logger_factory)
  {
    $this->database = $database;
    $this->logger = $logger_factory->get('baas_auth');
  }

  /**
   * {@inheritdoc}
   */
  public function addUserToTenant($user_id, $tenant_id, $role = 'tenant_user', $is_owner = FALSE)
  {
    try {
      // 检查映射是否已存在
      $existing = $this->database->select('baas_user_tenant_mapping', 'm')
        ->fields('m', ['id'])
        ->condition('user_id', $user_id)
        ->condition('tenant_id', $tenant_id)
        ->execute()
        ->fetchField();

      if ($existing) {
        throw new UserTenantMappingException("User {$user_id} is already mapped to tenant {$tenant_id}");
      }

      // 如果设置为所有者，需要先移除其他所有者
      if ($is_owner) {
        $this->database->update('baas_user_tenant_mapping')
          ->fields(['is_owner' => 0, 'updated' => time()])
          ->condition('tenant_id', $tenant_id)
          ->condition('is_owner', 1)
          ->execute();
      }

      // 插入新映射
      $mapping_id = $this->database->insert('baas_user_tenant_mapping')
        ->fields([
          'user_id' => $user_id,
          'tenant_id' => $tenant_id,
          'role' => $role,
          'is_owner' => $is_owner ? 1 : 0,
          'status' => 1,
          'created' => time(),
          'updated' => time(),
        ])
        ->execute();

      $this->logger->info('User @user_id added to tenant @tenant_id with role @role', [
        '@user_id' => $user_id,
        '@tenant_id' => $tenant_id,
        '@role' => $role,
      ]);

      return $mapping_id;
    } catch (\Exception $e) {
      $this->logger->error('Failed to add user @user_id to tenant @tenant_id: @error', [
        '@user_id' => $user_id,
        '@tenant_id' => $tenant_id,
        '@error' => $e->getMessage(),
      ]);
      throw new UserTenantMappingException('Failed to add user to tenant: ' . $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function removeUserFromTenant($user_id, $tenant_id)
  {
    try {
      $deleted = $this->database->delete('baas_user_tenant_mapping')
        ->condition('user_id', $user_id)
        ->condition('tenant_id', $tenant_id)
        ->execute();

      if ($deleted) {
        $this->logger->info('User @user_id removed from tenant @tenant_id', [
          '@user_id' => $user_id,
          '@tenant_id' => $tenant_id,
        ]);
      }

      return $deleted > 0;
    } catch (\Exception $e) {
      $this->logger->error('Failed to remove user @user_id from tenant @tenant_id: @error', [
        '@user_id' => $user_id,
        '@tenant_id' => $tenant_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function updateUserRole($user_id, $tenant_id, $role)
  {
    try {
      $updated = $this->database->update('baas_user_tenant_mapping')
        ->fields([
          'role' => $role,
          'updated' => time(),
        ])
        ->condition('user_id', $user_id)
        ->condition('tenant_id', $tenant_id)
        ->execute();

      if ($updated) {
        $this->logger->info('User @user_id role updated to @role in tenant @tenant_id', [
          '@user_id' => $user_id,
          '@role' => $role,
          '@tenant_id' => $tenant_id,
        ]);
      }

      return $updated > 0;
    } catch (\Exception $e) {
      $this->logger->error('Failed to update user @user_id role in tenant @tenant_id: @error', [
        '@user_id' => $user_id,
        '@tenant_id' => $tenant_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUserRole($user_id, $tenant_id)
  {
    try {
      return $this->database->select('baas_user_tenant_mapping', 'm')
        ->fields('m', ['role'])
        ->condition('user_id', $user_id)
        ->condition('tenant_id', $tenant_id)
        ->condition('status', 1)
        ->execute()
        ->fetchField();
    } catch (\Exception $e) {
      $this->logger->error('Failed to get user @user_id role in tenant @tenant_id: @error', [
        '@user_id' => $user_id,
        '@tenant_id' => $tenant_id,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getUserTenants($user_id, $active_only = TRUE)
  {
    try {
      $query = $this->database->select('baas_user_tenant_mapping', 'm')
        ->fields('m', ['tenant_id', 'role', 'is_owner', 'created'])
        ->condition('user_id', $user_id);

      if ($active_only) {
        $query->condition('status', 1);
      }

      return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
      $this->logger->error('Failed to get tenants for user @user_id: @error', [
        '@user_id' => $user_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getTenantUsers($tenant_id, $active_only = TRUE)
  {
    try {
      $query = $this->database->select('baas_user_tenant_mapping', 'm')
        ->fields('m', ['user_id', 'role', 'is_owner', 'created']);

      $query->leftJoin('users_field_data', 'u', 'm.user_id = u.uid');
      $query->addField('u', 'name', 'username');
      $query->addField('u', 'mail', 'email');

      $query->condition('m.tenant_id', $tenant_id);

      if ($active_only) {
        $query->condition('m.status', 1);
        $query->condition('u.status', 1);
      }

      return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
      $this->logger->error('Failed to get users for tenant @tenant_id: @error', [
        '@tenant_id' => $tenant_id,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isUserInTenant($user_id, $tenant_id)
  {
    try {
      $count = $this->database->select('baas_user_tenant_mapping', 'm')
        ->condition('user_id', $user_id)
        ->condition('tenant_id', $tenant_id)
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      return $count > 0;
    } catch (\Exception $e) {
      $this->logger->error('Failed to check if user @user_id is in tenant @tenant_id: @error', [
        '@user_id' => $user_id,
        '@tenant_id' => $tenant_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isUserTenantOwner($user_id, $tenant_id)
  {
    try {
      $count = $this->database->select('baas_user_tenant_mapping', 'm')
        ->condition('user_id', $user_id)
        ->condition('tenant_id', $tenant_id)
        ->condition('is_owner', 1)
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      return $count > 0;
    } catch (\Exception $e) {
      $this->logger->error('Failed to check if user @user_id is owner of tenant @tenant_id: @error', [
        '@user_id' => $user_id,
        '@tenant_id' => $tenant_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getTenantOwner($tenant_id)
  {
    try {
      $query = $this->database->select('baas_user_tenant_mapping', 'm')
        ->fields('m', ['user_id', 'role', 'created']);

      $query->leftJoin('users_field_data', 'u', 'm.user_id = u.uid');
      $query->addField('u', 'name', 'username');
      $query->addField('u', 'mail', 'email');

      $query->condition('m.tenant_id', $tenant_id)
        ->condition('m.is_owner', 1)
        ->condition('m.status', 1);

      return $query->execute()->fetchAssoc();
    } catch (\Exception $e) {
      $this->logger->error('Failed to get owner for tenant @tenant_id: @error', [
        '@tenant_id' => $tenant_id,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function transferTenantOwnership($tenant_id, $new_owner_user_id)
  {
    $transaction = $this->database->startTransaction();

    try {
      // 检查新所有者是否已在租户中
      if (!$this->isUserInTenant($new_owner_user_id, $tenant_id)) {
        throw new UserTenantMappingException("User {$new_owner_user_id} is not a member of tenant {$tenant_id}");
      }

      // 移除当前所有者的所有者身份
      $this->database->update('baas_user_tenant_mapping')
        ->fields(['is_owner' => 0, 'updated' => time()])
        ->condition('tenant_id', $tenant_id)
        ->condition('is_owner', 1)
        ->execute();

      // 设置新所有者
      $updated = $this->database->update('baas_user_tenant_mapping')
        ->fields([
          'is_owner' => 1,
          'role' => 'tenant_admin',
          'updated' => time(),
        ])
        ->condition('user_id', $new_owner_user_id)
        ->condition('tenant_id', $tenant_id)
        ->execute();

      if ($updated) {
        $this->logger->info('Tenant @tenant_id ownership transferred to user @user_id', [
          '@tenant_id' => $tenant_id,
          '@user_id' => $new_owner_user_id,
        ]);
      }

      return $updated > 0;
    } catch (\Exception $e) {
      $transaction->rollBack();
      $this->logger->error('Failed to transfer tenant @tenant_id ownership to user @user_id: @error', [
        '@tenant_id' => $tenant_id,
        '@user_id' => $new_owner_user_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setMappingStatus($user_id, $tenant_id, $status)
  {
    try {
      $updated = $this->database->update('baas_user_tenant_mapping')
        ->fields([
          'status' => $status ? 1 : 0,
          'updated' => time(),
        ])
        ->condition('user_id', $user_id)
        ->condition('tenant_id', $tenant_id)
        ->execute();

      if ($updated) {
        $this->logger->info('User @user_id mapping status in tenant @tenant_id set to @status', [
          '@user_id' => $user_id,
          '@tenant_id' => $tenant_id,
          '@status' => $status ? 'active' : 'inactive',
        ]);
      }

      return $updated > 0;
    } catch (\Exception $e) {
      $this->logger->error('Failed to set mapping status for user @user_id in tenant @tenant_id: @error', [
        '@user_id' => $user_id,
        '@tenant_id' => $tenant_id,
        '@error' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }
}
