<?php

declare(strict_types=1);

namespace Drupal\baas_tenant\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\UserInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * 租户权限提升控制器 - 管理员提权用户为租户管理员.
 */
class TenantPromotionController extends ControllerBase
{

  use StringTranslationTrait;

  /**
   * 构造函数.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    MessengerInterface $messenger,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('messenger'),
    );
  }

  /**
   * 提升用户为租户.
   *
   * @param \Drupal\user\UserInterface $user
   *   要提升的用户对象.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   重定向响应.
   */
  public function promoteUserToTenant(UserInterface $user): RedirectResponse
  {
    try {
      // 检查用户是否已经是租户
      if ($this->isUserTenant($user)) {
        $this->messenger->addWarning($this->t('用户 @username 已经是租户。', [
          '@username' => $user->getAccountName(),
        ]));
      } else {
        // 1. 设置租户标记
        $this->setUserTenantFlag($user, TRUE);

        // 2. 分配项目管理员角色
        if (!$user->hasRole('project_manager')) {
          $user->addRole('project_manager');
          $user->save();
        }

        // 3. 创建对应的租户实体（保持架构一致性）
        $tenant_id = $this->createTenantForUser($user);

        if ($tenant_id) {
          $this->messenger->addStatus($this->t('用户 @username 已成功提升为租户。租户 @tenant_id 已自动创建。', [
            '@username' => $user->getAccountName(),
            '@tenant_id' => $tenant_id,
          ]));
        } else {
          $this->messenger->addStatus($this->t('用户 @username 已成功提升为租户。', [
            '@username' => $user->getAccountName(),
          ]));
        }

        // 记录操作日志
        \Drupal::logger('baas_tenant')->info('管理员 @admin 将用户 @user (UID: @uid) 提升为租户，租户ID: @tenant_id', [
          '@admin' => $this->currentUser()->getAccountName(),
          '@user' => $user->getAccountName(),
          '@uid' => $user->id(),
          '@tenant_id' => $tenant_id ?? 'N/A',
        ]);
      }
    } catch (\Exception $e) {
      $this->messenger->addError($this->t('提升用户权限时发生错误: @error', [
        '@error' => $e->getMessage(),
      ]));

      \Drupal::logger('baas_tenant')->error('提升用户 @user 为租户失败: @error', [
        '@user' => $user->getAccountName(),
        '@error' => $e->getMessage(),
      ]);
    }

    // 重定向回用户编辑页面
    return new RedirectResponse(Url::fromRoute('entity.user.edit_form', [
      'user' => $user->id(),
    ])->toString());
  }

  /**
   * 降级租户为普通用户.
   *
   * @param \Drupal\user\UserInterface $user
   *   要降级的用户对象.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse
   *   重定向响应.
   */
  public function demoteUserFromTenant(UserInterface $user): RedirectResponse
  {
    try {
      // 检查用户是否是租户
      if (!$this->isUserTenant($user)) {
        $this->messenger->addWarning($this->t('用户 @username 不是租户。', [
          '@username' => $user->getAccountName(),
        ]));
      } else {
        // 检查用户是否有活跃的项目
        $project_count = $this->getProjectCountForUser((int) $user->id());

        if ($project_count > 0) {
          $this->messenger->addError($this->t('无法降级用户 @username，该用户仍拥有 @count 个活跃项目。请先转移或删除这些项目。', [
            '@username' => $user->getAccountName(),
            '@count' => $project_count,
          ]));
        } else {
          // 1. 移除租户标记
          $this->setUserTenantFlag($user, FALSE);

          // 2. 移除项目管理员角色
          if ($user->hasRole('project_manager')) {
            $user->removeRole('project_manager');
            $user->save();
          }

          // 3. 删除对应的租户实体
          $delete_result = $this->deleteTenantForUser($user);

          if ($delete_result) {
            $this->messenger->addStatus($this->t('用户 @username 已成功降级为普通用户。对应的租户和项目已自动清理。', [
              '@username' => $user->getAccountName(),
            ]));
          } else {
            $this->messenger->addStatus($this->t('用户 @username 已降级为普通用户，但租户清理过程中遇到问题。', [
              '@username' => $user->getAccountName(),
            ]));
          }

          // 记录操作日志
          \Drupal::logger('baas_tenant')->info('管理员 @admin 将用户 @user (UID: @uid) 降级为普通用户', [
            '@admin' => $this->currentUser()->getAccountName(),
            '@user' => $user->getAccountName(),
            '@uid' => $user->id(),
          ]);
        }
      }
    } catch (\Exception $e) {
      $this->messenger->addError($this->t('降级用户权限时发生错误: @error', [
        '@error' => $e->getMessage(),
      ]));

      \Drupal::logger('baas_tenant')->error('降级用户 @user 租户权限失败: @error', [
        '@user' => $user->getAccountName(),
        '@error' => $e->getMessage(),
      ]);
    }

    // 重定向回用户编辑页面
    return new RedirectResponse(Url::fromRoute('entity.user.edit_form', [
      'user' => $user->id(),
    ])->toString());
  }

  /**
   * 检查提升权限的访问控制.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   当前用户账户.
   * @param \Drupal\user\UserInterface $user
   *   目标用户.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果.
   */
  public function checkPromoteAccess(AccountInterface $account, UserInterface $user)
  {
    // 只有系统管理员可以提升用户权限
    if (!$account->hasPermission('administer baas tenants')) {
      return AccessResult::forbidden('需要租户系统管理权限');
    }

    // 不能提升自己
    if ($account->id() == $user->id()) {
      return AccessResult::forbidden('不能提升自己的权限');
    }

    // 不能提升超级管理员或已有管理员权限的用户
    if ($user->id() == 1 || $user->hasRole('administrator')) {
      return AccessResult::forbidden('不能提升超级管理员或系统管理员的权限');
    }

    // 不能提升已经是租户的用户
    if ($this->isUserTenant($user)) {
      return AccessResult::forbidden('用户已经是租户');
    }

    return AccessResult::allowed();
  }

  /**
   * 检查降级权限的访问控制.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   当前用户账户.
   * @param \Drupal\user\UserInterface $user
   *   目标用户.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果.
   */
  public function checkDemoteAccess(AccountInterface $account, UserInterface $user)
  {
    // 只有系统管理员可以降级用户权限
    if (!$account->hasPermission('administer baas tenants')) {
      return AccessResult::forbidden('需要租户系统管理权限');
    }

    // 不能降级自己
    if ($account->id() == $user->id()) {
      return AccessResult::forbidden('不能降级自己的权限');
    }

    // 只能降级租户
    if (!$this->isUserTenant($user)) {
      return AccessResult::forbidden('用户不是租户');
    }

    return AccessResult::allowed();
  }

  /**
   * 检查用户是否是租户.
   *
   * @param \Drupal\user\UserInterface $user
   *   用户对象.
   *
   * @return bool
   *   如果用户是租户返回TRUE，否则返回FALSE.
   */
  protected function isUserTenant(UserInterface $user): bool
  {
    // 使用字段API获取租户状态
    if ($user->hasField('field_is_tenant')) {
      return (bool) $user->get('field_is_tenant')->value;
    }

    return FALSE;
  }

  /**
   * 设置用户租户标记.
   *
   * @param \Drupal\user\UserInterface $user
   *   用户对象.
   * @param bool $is_tenant
   *   是否为租户.
   */
  protected function setUserTenantFlag(UserInterface $user, bool $is_tenant): void
  {
    // 使用字段API设置租户状态
    if ($user->hasField('field_is_tenant')) {
      $user->set('field_is_tenant', $is_tenant);
      $user->save();
    }
  }

  /**
   * 为用户创建对应的租户实体.
   *
   * @param \Drupal\user\UserInterface $user
   *   用户对象.
   *
   * @return string|null
   *   创建的租户ID，失败返回NULL.
   */
  protected function createTenantForUser(UserInterface $user): ?string
  {
    try {
      // 使用 TenantManager 服务创建租户
      $tenant_manager = \Drupal::service('baas_tenant.manager');

      // 准备租户基本信息
      $tenant_name = $user->getAccountName() . '的项目空间';

      // 准备租户设置
      $settings = [
        'max_entities' => 100,
        'max_storage' => 1024,
        'max_requests' => 10000,
        'organization_type' => 'personal',
        'contact_email' => $user->getEmail(),
        'created_by_promotion' => TRUE,
      ];

      // 使用 TenantManager 创建租户
      $tenant_id = $tenant_manager->createTenant($tenant_name, (int) $user->id(), $settings);

      if ($tenant_id) {
        \Drupal::logger('baas_tenant')->info('用户提权时自动创建租户成功: @tenant_id for user @uid', [
          '@tenant_id' => $tenant_id,
          '@uid' => $user->id(),
        ]);

        return $tenant_id;
      } else {
        \Drupal::logger('baas_tenant')->error('用户提权时自动创建租户失败 for user @uid', [
          '@uid' => $user->id(),
        ]);

        return null;
      }
    } catch (\Exception $e) {
      \Drupal::logger('baas_tenant')->error('用户提权时创建租户出错: @error for user @uid', [
        '@error' => $e->getMessage(),
        '@uid' => $user->id(),
      ]);

      return null;
    }
  }

  /**
   * 删除用户对应的租户实体.
   *
   * @param \Drupal\user\UserInterface $user
   *   用户对象.
   *
   * @return bool
   *   成功返回TRUE，失败返回FALSE.
   */
  protected function deleteTenantForUser(UserInterface $user): bool
  {
    try {
      $tenant_manager = \Drupal::service('baas_tenant.manager');

      // 查找用户拥有的租户
      $user_tenants = $this->getUserTenantMappings((int) $user->id());

      foreach ($user_tenants as $tenant_id) {
        // 获取租户信息
        $tenant = $tenant_manager->getTenant($tenant_id);

        // 只删除通过用户提权创建的租户
        if ($tenant && isset($tenant['settings']['created_by_promotion'])) {
          $result = $tenant_manager->deleteTenant($tenant_id);

          if ($result) {
            \Drupal::logger('baas_tenant')->info('用户降权时自动删除租户成功: @tenant_id for user @uid', [
              '@tenant_id' => $tenant_id,
              '@uid' => $user->id(),
            ]);
          } else {
            \Drupal::logger('baas_tenant')->error('用户降权时自动删除租户失败: @tenant_id for user @uid', [
              '@tenant_id' => $tenant_id,
              '@uid' => $user->id(),
            ]);
          }
        }
      }

      return true;
    } catch (\Exception $e) {
      \Drupal::logger('baas_tenant')->error('用户降权时删除租户出错: @error for user @uid', [
        '@error' => $e->getMessage(),
        '@uid' => $user->id(),
      ]);

      return false;
    }
  }

  /**
   * 获取用户的租户映射（新版本）.
   *
   * @param int $user_id
   *   用户ID.
   *
   * @return array
   *   租户ID数组.
   */
  protected function getUserTenantMappings(int $user_id): array
  {
    try {
      $database = \Drupal::database();

      // 查询用户作为所有者的租户
      $query = $database->select('baas_tenant_config', 't')
        ->fields('t', ['tenant_id'])
        ->condition('owner_uid', $user_id);

      $result = $query->execute();
      $tenant_ids = [];

      while ($record = $result->fetchAssoc()) {
        $tenant_ids[] = $record['tenant_id'];
      }

      return $tenant_ids;
    } catch (\Exception $e) {
      \Drupal::logger('baas_tenant')->error('获取用户租户映射失败: @error', [
        '@error' => $e->getMessage(),
      ]);

      return [];
    }
  }

  /**
   * 原始的为用户创建租户实体方法（已弃用）.
   */
  protected function createTenantForUserOld(UserInterface $user): ?string
  {
    try {
      $database = \Drupal::database();

      // 生成租户ID
      $tenant_id = 'user_' . $user->id() . '_' . substr(hash('sha256', uniqid((string) mt_rand(), true)), 0, 8);

      // 准备租户基本信息
      $tenant_name = $user->getAccountName() . '的项目空间';

      // 1. 插入租户配置（使用正确的表结构）
      $tenant_data = [
        'tenant_id' => $tenant_id,
        'name' => $tenant_name,
        'status' => 1,
        'created' => \Drupal::time()->getRequestTime(),
        'updated' => \Drupal::time()->getRequestTime(),
        'settings' => json_encode([
          'max_entities' => 100,
          'max_storage' => 1024,
          'max_requests' => 10000,
          'organization_type' => 'team',
          'contact_email' => $user->getEmail(),
          'auto_created' => TRUE,
        ]),
      ];

      $database->insert('baas_tenant_config')
        ->fields($tenant_data)
        ->execute();

      // 2. 插入用户-租户映射（用户作为所有者）
      $mapping_data = [
        'user_id' => (int) $user->id(),
        'tenant_id' => $tenant_id,
        'role' => 'owner',
        'is_owner' => 1,
        'status' => 1,
        'created' => \Drupal::time()->getRequestTime(),
        'updated' => \Drupal::time()->getRequestTime(),
      ];

      $database->insert('baas_user_tenant_mapping')
        ->fields($mapping_data)
        ->execute();

      \Drupal::logger('baas_tenant')->info('为用户 @user (UID: @uid) 自动创建租户 @tenant_id', [
        '@user' => $user->getAccountName(),
        '@uid' => $user->id(),
        '@tenant_id' => $tenant_id,
      ]);

      return $tenant_id;
    } catch (\Exception $e) {
      \Drupal::logger('baas_tenant')->error('为用户 @user 创建租户失败: @error', [
        '@user' => $user->getAccountName(),
        '@error' => $e->getMessage(),
      ]);

      return NULL;
    }
  }

  /**
   * 执行完整的用户降级清理流程.
   *
   * @param \Drupal\user\UserInterface $user
   *   要降级的用户对象.
   *
   * @return array
   *   清理结果数组，包含success状态、cleaned项目列表和errors列表.
   */
  protected function performFullDemotion(UserInterface $user): array
  {
    $result = [
      'success' => TRUE,
      'cleaned' => [],
      'errors' => [],
    ];

    $database = \Drupal::database();
    $user_id = (int) $user->id();

    try {
      // 1. 移除用户角色和字段标记
      $this->cleanupUserRolesAndFields($user, $result);

      // 2. 获取用户的租户列表
      $user_tenants = $this->getUserTenantMappingsOld((int) $user_id);

      // 3. 清理每个租户的数据
      foreach ($user_tenants as $tenant_mapping) {
        $tenant_id = $tenant_mapping->tenant_id;
        $this->cleanupTenantData($tenant_id, $user_id, $result);
      }

      // 4. 清理用户-租户映射
      $this->cleanupUserTenantMappings($user_id, $result);
    } catch (\Exception $e) {
      $result['success'] = FALSE;
      $result['errors'][] = '清理过程异常: ' . $e->getMessage();
      \Drupal::logger('baas_tenant')->error('用户 @user 降级清理异常: @error', [
        '@user' => $user->getAccountName(),
        '@error' => $e->getMessage(),
      ]);
    }

    return $result;
  }

  /**
   * 清理用户角色和字段标记.
   *
   * @param \Drupal\user\UserInterface $user
   *   用户对象.
   * @param array &$result
   *   结果数组引用.
   */
  protected function cleanupUserRolesAndFields(UserInterface $user, array &$result): void
  {
    try {
      // 移除租户字段标记
      $this->setUserTenantFlag($user, FALSE);
      $result['cleaned'][] = '租户标记';

      // 移除项目管理员角色
      if ($user->hasRole('project_manager')) {
        $user->removeRole('project_manager');
        $user->save();
        $result['cleaned'][] = '项目管理员角色';
      }
    } catch (\Exception $e) {
      $result['errors'][] = '清理用户权限失败: ' . $e->getMessage();
    }
  }

  /**
   * 获取用户的租户映射（旧版本，已弃用）.
   *
   * @param int $user_id
   *   用户ID.
   *
   * @return array
   *   租户映射数组.
   */
  protected function getUserTenantMappingsOld(int $user_id): array
  {
    $database = \Drupal::database();

    if (!$database->schema()->tableExists('baas_user_tenant_mapping')) {
      return [];
    }

    return $database->select('baas_user_tenant_mapping', 'm')
      ->fields('m', ['tenant_id', 'role', 'is_owner'])
      ->condition('user_id', $user_id)
      ->condition('status', 1)
      ->execute()
      ->fetchAll();
  }

  /**
   * 清理租户相关数据.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param int $user_id
   *   用户ID.
   * @param array &$result
   *   结果数组引用.
   */
  protected function cleanupTenantData(string $tenant_id, int $user_id, array &$result): void
  {
    $database = \Drupal::database();

    try {
      // 检查租户是否只有这一个用户（自动创建的租户）
      $other_users_count = $database->select('baas_user_tenant_mapping', 'm')
        ->condition('tenant_id', $tenant_id)
        ->condition('user_id', $user_id, '<>')
        ->condition('status', 1)
        ->countQuery()
        ->execute()
        ->fetchField();

      if ($other_users_count == 0) {
        // 这是该用户专属的租户，可以完全删除
        $this->deleteTenantCompletely($tenant_id, $result);
      } else {
        // 租户有其他用户，只移除当前用户的关联
        $result['cleaned'][] = "租户 {$tenant_id} (仅移除关联)";
      }
    } catch (\Exception $e) {
      $result['errors'][] = "清理租户 {$tenant_id} 失败: " . $e->getMessage();
    }
  }

  /**
   * 完全删除租户及其相关数据.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param array &$result
   *   结果数组引用.
   */
  protected function deleteTenantCompletely(string $tenant_id, array &$result): void
  {
    $database = \Drupal::database();

    try {
      // 1. 删除租户下的所有项目
      if ($database->schema()->tableExists('baas_project_config')) {
        $deleted_projects = $database->delete('baas_project_config')
          ->condition('tenant_id', $tenant_id)
          ->execute();

        if ($deleted_projects > 0) {
          $result['cleaned'][] = "{$deleted_projects} 个项目";
        }
      }

      // 2. 删除租户配置
      if ($database->schema()->tableExists('baas_tenant_config')) {
        $deleted_tenant = $database->delete('baas_tenant_config')
          ->condition('tenant_id', $tenant_id)
          ->execute();

        if ($deleted_tenant > 0) {
          $result['cleaned'][] = "租户配置 ({$tenant_id})";
        }
      }

      // 3. 删除实体模板（如果存在）
      if ($database->schema()->tableExists('baas_entity_template')) {
        $deleted_templates = $database->delete('baas_entity_template')
          ->condition('tenant_id', $tenant_id)
          ->execute();

        if ($deleted_templates > 0) {
          $result['cleaned'][] = "{$deleted_templates} 个实体模板";
        }
      }

      // 4. 删除动态数据表（租户特有的表）
      $this->dropTenantSpecificTables($tenant_id, $result);
    } catch (\Exception $e) {
      $result['errors'][] = "删除租户 {$tenant_id} 数据失败: " . $e->getMessage();
    }
  }

  /**
   * 删除租户特有的动态数据表.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param array &$result
   *   结果数组引用.
   */
  protected function dropTenantSpecificTables(string $tenant_id, array &$result): void
  {
    $database = \Drupal::database();

    try {
      // 查找以租户ID为前缀的表
      $table_prefix = 'tenant_' . $tenant_id . '_';

      $tables_query = $database->query("
        SELECT table_name
        FROM information_schema.tables
        WHERE table_name LIKE :prefix
        AND table_schema = current_schema()
      ", [':prefix' => $table_prefix . '%']);

      $dropped_tables = 0;
      while ($table = $tables_query->fetchObject()) {
        try {
          $database->schema()->dropTable($table->table_name);
          $dropped_tables++;
        } catch (\Exception $e) {
          $result['errors'][] = "删除表 {$table->table_name} 失败: " . $e->getMessage();
        }
      }

      if ($dropped_tables > 0) {
        $result['cleaned'][] = "{$dropped_tables} 个数据表";
      }
    } catch (\Exception $e) {
      $result['errors'][] = "查询租户数据表失败: " . $e->getMessage();
    }
  }

  /**
   * 清理用户-租户映射关系.
   *
   * @param int $user_id
   *   用户ID.
   * @param array &$result
   *   结果数组引用.
   */
  protected function cleanupUserTenantMappings(int $user_id, array &$result): void
  {
    $database = \Drupal::database();

    try {
      if ($database->schema()->tableExists('baas_user_tenant_mapping')) {
        $deleted_mappings = $database->delete('baas_user_tenant_mapping')
          ->condition('user_id', $user_id)
          ->execute();

        if ($deleted_mappings > 0) {
          $result['cleaned'][] = "用户映射关系 ({$deleted_mappings} 条)";
        }
      }
    } catch (\Exception $e) {
      $result['errors'][] = '清理用户映射失败: ' . $e->getMessage();
    }
  }

  /**
   * 获取用户拥有的项目数量.
   *
   * @param int $user_id
   *   用户ID.
   *
   * @return int
   *   项目数量.
   */
  protected function getProjectCountForUser(int $user_id): int
  {
    $database = \Drupal::database();

    // 检查baas_project_config表是否存在
    if (!$database->schema()->tableExists('baas_project_config')) {
      return 0;
    }

    return (int) $database->select('baas_project_config', 'p')
      ->condition('owner_uid', $user_id)
      ->condition('status', 1)
      ->countQuery()
      ->execute()
      ->fetchField();
  }
}
