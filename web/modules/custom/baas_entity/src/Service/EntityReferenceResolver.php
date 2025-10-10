<?php

namespace Drupal\baas_entity\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\baas_entity\Service\TemplateManager;
use Drupal\baas_tenant\TenantManagerInterface;

/**
 * 实体引用解析服务。
 * 
 * 负责处理实体引用字段的查询、验证和关联功能。
 */
class EntityReferenceResolver
{

  /**
   * 数据库连接服务。
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * 实体类型管理器服务。
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * 模板管理服务。
   *
   * @var \Drupal\baas_entity\Service\TemplateManager
   */
  protected $templateManager;

  /**
   * 租户管理服务。
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected $tenantManager;

  /**
   * 构造函数。
   *
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接服务。
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   实体类型管理器服务。
   * @param \Drupal\baas_entity\Service\TemplateManager $template_manager
   *   模板管理服务。
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理服务。
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    TemplateManager $template_manager,
    TenantManagerInterface $tenant_manager
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->templateManager = $template_manager;
    $this->tenantManager = $tenant_manager;
  }

  /**
   * 解析实体引用字段值。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $entity_name
   *   实体名称。
   * @param array $entity_data
   *   实体数据。
   * @param array $reference_fields
   *   引用字段列表。
   *
   * @return array
   *   解析后的实体数据，包含关联实体信息。
   */
  public function resolveEntityReferences(string $tenant_id, string $entity_name, array $entity_data, array $reference_fields): array
  {
    if (empty($reference_fields)) {
      return $entity_data;
    }

    foreach ($reference_fields as $field_name => $field_config) {
      if (isset($entity_data[$field_name]) && !empty($entity_data[$field_name])) {
        $target_type = $field_config['target_type'] ?? 'node';
        $reference_ids = is_array($entity_data[$field_name]) ? $entity_data[$field_name] : [$entity_data[$field_name]];
        
        $resolved_entities = [];
        foreach ($reference_ids as $reference_id) {
          $resolved_entity = $this->loadReferencedEntity($target_type, $reference_id, $tenant_id);
          if ($resolved_entity) {
            $resolved_entities[] = $resolved_entity;
          }
        }
        
        // 如果是单值字段，返回单个实体，否则返回数组
        $multiple = $field_config['multiple'] ?? false;
        if ($multiple || count($resolved_entities) > 1) {
          $entity_data[$field_name . '_resolved'] = $resolved_entities;
        } else {
          $entity_data[$field_name . '_resolved'] = $resolved_entities[0] ?? null;
        }
      }
    }

    return $entity_data;
  }

  /**
   * 加载被引用的实体。
   *
   * @param string $target_type
   *   目标实体类型。
   * @param mixed $entity_id
   *   实体ID。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return array|null
   *   实体数据或null。
   */
  protected function loadReferencedEntity(string $target_type, $entity_id, string $tenant_id): ?array
  {
    try {
      // 处理Drupal核心实体类型
      if (in_array($target_type, ['node', 'user', 'taxonomy_term', 'file', 'media'])) {
        return $this->loadCoreEntity($target_type, $entity_id);
      }
      
      // 处理自定义动态实体类型
      if (strpos($target_type, $tenant_id . '_') === 0) {
        $entity_name = substr($target_type, strlen($tenant_id . '_'));
        return $this->loadDynamicEntity($tenant_id, $entity_name, $entity_id);
      }
      
      // 处理其他实体类型
      return $this->loadGenericEntity($target_type, $entity_id);
    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('加载引用实体失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }

  /**
   * 加载Drupal核心实体。
   *
   * @param string $entity_type
   *   实体类型。
   * @param mixed $entity_id
   *   实体ID。
   *
   * @return array|null
   *   实体数据或null。
   */
  protected function loadCoreEntity(string $entity_type, $entity_id): ?array
  {
    try {
      $storage = $this->entityTypeManager->getStorage($entity_type);
      $entity = $storage->load($entity_id);
      
      if (!$entity) {
        return null;
      }
      
      $data = [
        'id' => $entity->id(),
        'uuid' => $entity->uuid(),
        'type' => $entity_type,
      ];
      
      // 添加标签字段
      if ($entity->hasField('title')) {
        $data['title'] = $entity->get('title')->value;
      } elseif ($entity->hasField('name')) {
        $data['title'] = $entity->get('name')->value;
      } elseif ($entity->hasField('label')) {
        $data['title'] = $entity->get('label')->value;
      }
      
      // 添加创建和修改时间
      if ($entity->hasField('created')) {
        $data['created'] = $entity->get('created')->value;
      }
      if ($entity->hasField('changed')) {
        $data['changed'] = $entity->get('changed')->value;
      }
      
      return $data;
    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('加载核心实体失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }

  /**
   * 加载动态实体。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $entity_name
   *   实体名称。
   * @param mixed $entity_id
   *   实体ID。
   *
   * @return array|null
   *   实体数据或null。
   */
  protected function loadDynamicEntity(string $tenant_id, string $entity_name, $entity_id): ?array
  {
    try {
      $table_name = "tenant_{$tenant_id}_{$entity_name}";
      
      // 检查表是否存在
      if (!$this->database->schema()->tableExists($table_name)) {
        return null;
      }
      
      $query = $this->database->select($table_name, 'e')
        ->fields('e')
        ->condition('e.id', $entity_id)
        ->condition('e.tenant_id', $tenant_id);
      
      $result = $query->execute()->fetchAssoc();
      
      if (!$result) {
        return null;
      }
      
      // 添加实体类型信息
      $result['type'] = $tenant_id . '_' . $entity_name;
      
      return $result;
    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('加载动态实体失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }

  /**
   * 加载通用实体。
   *
   * @param string $entity_type
   *   实体类型。
   * @param mixed $entity_id
   *   实体ID。
   *
   * @return array|null
   *   实体数据或null。
   */
  protected function loadGenericEntity(string $entity_type, $entity_id): ?array
  {
    try {
      $storage = $this->entityTypeManager->getStorage($entity_type);
      $entity = $storage->load($entity_id);
      
      if (!$entity) {
        return null;
      }
      
      return [
        'id' => $entity->id(),
        'uuid' => $entity->uuid(),
        'type' => $entity_type,
        'title' => method_exists($entity, 'label') ? $entity->label() : (string) $entity,
      ];
    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('加载通用实体失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }

  /**
   * 获取实体的所有引用字段。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $entity_name
   *   实体名称。
   *
   * @return array
   *   引用字段配置数组。
   */
  public function getEntityReferenceFields(string $tenant_id, string $entity_name): array
  {
    try {
      $template = $this->templateManager->getTemplateByName($tenant_id, $entity_name);
      if (!$template) {
        return [];
      }
      
      $fields = $this->templateManager->getTemplateFields($template->id);
      $reference_fields = [];
      
      foreach ($fields as $field) {
        if ($field->type === 'reference') {
          $reference_fields[$field->name] = [
            'target_type' => $field->settings['target_type'] ?? 'node',
            'target_bundles' => $field->settings['target_bundles'] ?? [],
            'multiple' => $field->settings['multiple'] ?? false,
          ];
        }
      }
      
      return $reference_fields;
    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('获取引用字段失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * 验证实体引用值。
   *
   * @param string $target_type
   *   目标实体类型。
   * @param mixed $entity_id
   *   实体ID。
   * @param array $field_settings
   *   字段设置。
   * @param string $tenant_id
   *   租户ID。
   *
   * @return bool
   *   验证结果。
   */
  public function validateEntityReference(string $target_type, $entity_id, array $field_settings, string $tenant_id): bool
  {
    try {
      // 验证实体是否存在
      $entity_data = $this->loadReferencedEntity($target_type, $entity_id, $tenant_id);
      if (!$entity_data) {
        return false;
      }
      
      // 验证bundle限制
      if (!empty($field_settings['target_bundles'])) {
        $entity_bundle = $this->getEntityBundle($target_type, $entity_id);
        if ($entity_bundle && !in_array($entity_bundle, $field_settings['target_bundles'])) {
          return false;
        }
      }
      
      return true;
    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('验证实体引用失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return false;
    }
  }

  /**
   * 获取实体的bundle。
   *
   * @param string $entity_type
   *   实体类型。
   * @param mixed $entity_id
   *   实体ID。
   *
   * @return string|null
   *   Bundle名称或null。
   */
  protected function getEntityBundle(string $entity_type, $entity_id): ?string
  {
    try {
      $storage = $this->entityTypeManager->getStorage($entity_type);
      $entity = $storage->load($entity_id);
      
      if (!$entity) {
        return null;
      }
      
      return $entity->bundle();
    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('获取实体bundle失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return null;
    }
  }

  /**
   * 搜索可引用的实体。
   *
   * @param string $target_type
   *   目标实体类型。
   * @param string $search_string
   *   搜索字符串。
   * @param array $field_settings
   *   字段设置。
   * @param string $tenant_id
   *   租户ID。
   * @param int $limit
   *   返回结果数量限制。
   *
   * @return array
   *   搜索结果。
   */
  public function searchReferencableEntities(string $target_type, string $search_string, array $field_settings, string $tenant_id, int $limit = 10): array
  {
    try {
      // 处理Drupal核心实体类型
      if (in_array($target_type, ['node', 'user', 'taxonomy_term', 'file', 'media'])) {
        return $this->searchCoreEntities($target_type, $search_string, $field_settings, $limit);
      }
      
      // 处理自定义动态实体类型
      if (strpos($target_type, $tenant_id . '_') === 0) {
        $entity_name = substr($target_type, strlen($tenant_id . '_'));
        return $this->searchDynamicEntities($tenant_id, $entity_name, $search_string, $limit);
      }
      
      return [];
    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('搜索可引用实体失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * 搜索核心实体。
   *
   * @param string $entity_type
   *   实体类型。
   * @param string $search_string
   *   搜索字符串。
   * @param array $field_settings
   *   字段设置。
   * @param int $limit
   *   结果限制。
   *
   * @return array
   *   搜索结果。
   */
  protected function searchCoreEntities(string $entity_type, string $search_string, array $field_settings, int $limit): array
  {
    try {
      $storage = $this->entityTypeManager->getStorage($entity_type);
      $query = $storage->getQuery();
      
      // 根据实体类型设置搜索字段
      $label_field = $this->getLabelField($entity_type);
      if ($label_field) {
        $query->condition($label_field, $search_string, 'CONTAINS');
      }
      
      // 添加bundle限制
      if (!empty($field_settings['target_bundles'])) {
        $bundle_key = $this->entityTypeManager->getDefinition($entity_type)->getKey('bundle');
        if ($bundle_key) {
          $query->condition($bundle_key, $field_settings['target_bundles'], 'IN');
        }
      }
      
      // 添加排序
      if (isset($field_settings['sort']['field'])) {
        $query->sort($field_settings['sort']['field'], $field_settings['sort']['direction'] ?? 'ASC');
      }
      
      $query->range(0, $limit);
      $entity_ids = $query->execute();
      
      $results = [];
      foreach ($entity_ids as $entity_id) {
        $entity_data = $this->loadCoreEntity($entity_type, $entity_id);
        if ($entity_data) {
          $results[] = $entity_data;
        }
      }
      
      return $results;
    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('搜索核心实体失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * 搜索动态实体。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $entity_name
   *   实体名称。
   * @param string $search_string
   *   搜索字符串。
   * @param int $limit
   *   结果限制。
   *
   * @return array
   *   搜索结果。
   */
  protected function searchDynamicEntities(string $tenant_id, string $entity_name, string $search_string, int $limit): array
  {
    try {
      $table_name = "tenant_{$tenant_id}_{$entity_name}";
      
      // 检查表是否存在
      if (!$this->database->schema()->tableExists($table_name)) {
        return [];
      }
      
      $query = $this->database->select($table_name, 'e')
        ->fields('e')
        ->condition('e.tenant_id', $tenant_id);
      
      // 在title字段中搜索
      if (!empty($search_string)) {
        $query->condition('e.title', '%' . $search_string . '%', 'LIKE');
      }
      
      $query->orderBy('e.title', 'ASC');
      $query->range(0, $limit);
      
      $results = [];
      foreach ($query->execute() as $row) {
        $data = (array) $row;
        $data['type'] = $tenant_id . '_' . $entity_name;
        $results[] = $data;
      }
      
      return $results;
    } catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('搜索动态实体失败: @error', [
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * 获取实体类型的标签字段名。
   *
   * @param string $entity_type
   *   实体类型。
   *
   * @return string|null
   *   标签字段名或null。
   */
  protected function getLabelField(string $entity_type): ?string
  {
    $label_fields = [
      'node' => 'title',
      'user' => 'name',
      'taxonomy_term' => 'name',
      'file' => 'filename',
      'media' => 'name',
    ];
    
    return $label_fields[$entity_type] ?? null;
  }
}