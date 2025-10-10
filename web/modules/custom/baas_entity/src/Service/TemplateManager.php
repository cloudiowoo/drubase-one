<?php

namespace Drupal\baas_entity\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\baas_tenant\TenantManagerInterface;

/**
 * 实体模板管理服务，负责实体模板的CRUD操作。
 */
class TemplateManager implements TemplateManagerInterface
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
   * 租户管理服务。
   *
   * @var \Drupal\baas_tenant\TenantManagerInterface
   */
  protected $tenantManager;

  /**
   * 字段映射服务。
   *
   * @var \Drupal\baas_entity\Service\FieldMapper
   */
  protected $fieldMapper;

  /**
   * 构造函数。
   *
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接服务。
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   实体类型管理器服务。
   * @param \Drupal\baas_tenant\TenantManagerInterface $tenant_manager
   *   租户管理服务。
   * @param \Drupal\baas_entity\Service\FieldMapper $field_mapper
   *   字段映射服务。
   */
  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    TenantManagerInterface $tenant_manager,
    FieldMapper $field_mapper
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->tenantManager = $tenant_manager;
    $this->fieldMapper = $field_mapper;
  }

  /**
   * 创建新的实体模板。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $name
   *   实体名称。
   * @param string $label
   *   实体标签。
   * @param string $description
   *   实体描述。
   * @param array $settings
   *   实体设置。
   *
   * @return int
   *   新创建的实体模板ID。
   */
  public function createTemplate($tenant_id, $name, $label, $description = '', array $settings = [])
  {
    // 确保租户存在
    $tenant = $this->tenantManager->getTenant($tenant_id);
    if (!$tenant) {
      throw new \InvalidArgumentException("租户不存在: {$tenant_id}");
    }

    // 确保名称唯一
    if ($this->getTemplateByName($tenant_id, $name)) {
      throw new \InvalidArgumentException("实体模板名称已存在: {$name}");
    }

    // 创建模板记录
    $template_id = $this->database->insert('baas_entity_template')
      ->fields([
        'tenant_id' => $tenant_id,
        'name' => $name,
        'label' => $label,
        'description' => $description,
        'settings' => serialize($settings),
        'status' => 1,
        'created' => time(),
        'updated' => time(),
      ])
      ->execute();

    return $template_id;
  }

  /**
   * 更新实体模板。
   *
   * @param int $template_id
   *   模板ID。
   * @param array $values
   *   更新的值。
   *
   * @return bool
   *   更新是否成功。
   */
  public function updateTemplate($template_id, array $values)
  {
    // 移除不允许更新的字段
    unset($values['id'], $values['tenant_id'], $values['name'], $values['created']);

    // 添加更新时间
    $values['updated'] = time();

    // 序列化设置
    if (isset($values['settings']) && is_array($values['settings'])) {
      $values['settings'] = serialize($values['settings']);
    }

    // 更新记录
    $result = $this->database->update('baas_entity_template')
      ->fields($values)
      ->condition('id', $template_id)
      ->execute();

    return (bool) $result;
  }

  /**
   * 删除实体模板。
   *
   * @param int $template_id
   *   模板ID。
   *
   * @return bool
   *   删除是否成功。
   */
  public function deleteTemplate($template_id)
  {
    // 首先删除关联的字段
    $this->database->delete('baas_entity_field')
      ->condition('template_id', $template_id)
      ->execute();

    // 然后删除模板
    $result = $this->database->delete('baas_entity_template')
      ->condition('id', $template_id)
      ->execute();

    return (bool) $result;
  }

  /**
   * 获取实体模板。
   *
   * @param int $template_id
   *   模板ID。
   *
   * @return object|false
   *   模板对象，如果不存在则返回FALSE。
   */
  public function getTemplate($template_id)
  {
    $template = $this->database->select('baas_entity_template', 't')
      ->fields('t')
      ->condition('id', $template_id)
      ->execute()
      ->fetchObject();

    if ($template && isset($template->settings)) {
      if (is_string($template->settings)) {
        // 尝试JSON解码，如果失败则尝试unserialize
        $json_data = json_decode($template->settings, true);
        if (json_last_error() === JSON_ERROR_NONE) {
          $template->settings = $json_data;
        } else {
          $unserialized = @unserialize($template->settings);
          $template->settings = $unserialized !== false ? $unserialized : [];
        }
      }
    }

    return $template;
  }

  /**
   * 根据名称获取实体模板。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $name
   *   模板名称。
   *
   * @return object|false
   *   模板对象，如果不存在则返回FALSE。
   */
  public function getTemplateByName($tenant_id, $name)
  {
    $template = $this->database->select('baas_entity_template', 't')
      ->fields('t')
      ->condition('tenant_id', $tenant_id)
      ->condition('name', $name)
      ->execute()
      ->fetchObject();

    if ($template && isset($template->settings)) {
      if (is_string($template->settings)) {
        // 尝试JSON解码，如果失败则尝试unserialize
        $json_data = json_decode($template->settings, true);
        if (json_last_error() === JSON_ERROR_NONE) {
          $template->settings = $json_data;
        } else {
          $unserialized = @unserialize($template->settings);
          $template->settings = $unserialized !== false ? $unserialized : [];
        }
      }
    }

    return $template;
  }

  /**
   * 获取租户的所有实体模板。
   *
   * @param string $tenant_id
   *   租户ID，使用'all'获取所有租户的模板。
   * @param bool $active_only
   *   是否只返回激活的模板。
   *
   * @return array
   *   模板列表。
   */
  public function getTemplatesByTenant($tenant_id, $active_only = FALSE)
  {
    $query = $this->database->select('baas_entity_template', 't')
      ->fields('t')
      ->orderBy('tenant_id')
      ->orderBy('name');

    // 如果不是获取所有租户，则添加租户条件
    if ($tenant_id !== 'all') {
      $query->condition('tenant_id', $tenant_id);
    }

    if ($active_only) {
      $query->condition('status', 1);
    }

    $templates = $query->execute()->fetchAll();

    // 反序列化设置
    foreach ($templates as $template) {
      if (isset($template->settings)) {
        if (is_string($template->settings)) {
          // 尝试JSON解码，如果失败则尝试unserialize
          $json_data = json_decode($template->settings, true);
          if (json_last_error() === JSON_ERROR_NONE) {
            $template->settings = $json_data;
          } else {
            $unserialized = @unserialize($template->settings);
            $template->settings = $unserialized !== false ? $unserialized : [];
          }
        }
      }
    }

    return $templates;
  }

  /**
   * 添加字段到实体模板。
   *
   * @param int $template_id
   *   模板ID。
   * @param string $name
   *   字段名称。
   * @param string $label
   *   字段标签。
   * @param string $type
   *   字段类型。
   * @param string $description
   *   字段描述。
   * @param bool $required
   *   是否必填。
   * @param int $weight
   *   权重。
   * @param array $settings
   *   字段设置。
   *
   * @return int
   *   新添加的字段ID。
   */
  public function addField($template_id, $name, $label, $type, $description = '', $required = FALSE, $weight = 0, array $settings = [])
  {
    // 确保模板存在
    $template = $this->getTemplate($template_id);
    if (!$template) {
      throw new \InvalidArgumentException("实体模板不存在: {$template_id}");
    }

    // 确保字段名称唯一
    if ($this->getFieldByName($template_id, $name)) {
      throw new \InvalidArgumentException("字段名称已存在: {$name}");
    }

    // 确保字段类型有效
    $supported_types = array_keys($this->fieldMapper->getSupportedFieldTypes());
    if (!in_array($type, $supported_types)) {
      throw new \InvalidArgumentException("不支持的字段类型: {$type}");
    }

    // 如果没有指定权重，获取当前最大权重
    if ($weight === 0) {
      $max_weight = $this->database->select('baas_entity_field', 'f')
        ->condition('template_id', $template_id)
        ->orderBy('weight', 'DESC')
        ->range(0, 1)
        ->fields('f', ['weight'])
        ->execute()
        ->fetchField();

      $weight = $max_weight !== FALSE ? $max_weight + 1 : 0;
    }

    // 添加字段记录
    $field_id = $this->database->insert('baas_entity_field')
      ->fields([
        'template_id' => $template_id,
        'name' => $name,
        'label' => $label,
        'type' => $type,
        'description' => $description,
        'required' => $required ? 1 : 0,
        'settings' => serialize($settings),
        'weight' => $weight,
        'created' => time(),
        'updated' => time(),
      ])
      ->execute();

    // 更新模板修改时间
    $this->updateTemplate($template_id, ['updated' => time()]);

    return $field_id;
  }

  /**
   * 更新字段。
   *
   * @param int $field_id
   *   字段ID。
   * @param array $values
   *   更新的值。
   *
   * @return bool
   *   更新是否成功。
   */
  public function updateField($field_id, array $values)
  {
    // 获取字段
    $field = $this->getField($field_id);
    if (!$field) {
      throw new \InvalidArgumentException("字段不存在: {$field_id}");
    }

    // 移除不允许更新的字段
    unset($values['id'], $values['template_id'], $values['name']);

    // 添加更新时间
    $values['updated'] = time();

    // 确保数据类型正确
    if (isset($values['required'])) {
      $values['required'] = (int) $values['required'];
    }

    if (isset($values['weight'])) {
      $values['weight'] = (int) $values['weight'];
    }

    // 序列化设置
    if (isset($values['settings']) && is_array($values['settings'])) {
      $values['settings'] = serialize($values['settings']);
    }

    // 更新记录
    $result = $this->database->update('baas_entity_field')
      ->fields($values)
      ->condition('id', $field_id)
      ->execute();

    // 更新模板修改时间
    if ($result) {
      $this->updateTemplate($field->template_id, ['updated' => time()]);
    }

    return (bool) $result;
  }

  /**
   * 删除字段。
   *
   * @param int $field_id
   *   字段ID。
   *
   * @return bool
   *   删除是否成功。
   */
  public function deleteField($field_id)
  {
    // 获取字段
    $field = $this->getField($field_id);
    if (!$field) {
      throw new \InvalidArgumentException("字段不存在: {$field_id}");
    }

    // 删除字段
    $result = $this->database->delete('baas_entity_field')
      ->condition('id', $field_id)
      ->execute();

    // 更新模板修改时间
    if ($result) {
      $this->updateTemplate($field->template_id, ['updated' => time()]);
    }

    return (bool) $result;
  }

  /**
   * 获取字段。
   *
   * @param int $field_id
   *   字段ID。
   *
   * @return object|false
   *   字段对象，如果不存在则返回FALSE。
   */
  public function getField($field_id)
  {
    $field = $this->database->select('baas_entity_field', 'f')
      ->fields('f')
      ->condition('id', $field_id)
      ->execute()
      ->fetchObject();

    if ($field && isset($field->settings)) {
      if (is_string($field->settings)) {
        // 尝试JSON解码，如果失败则尝试unserialize
        $json_data = json_decode($field->settings, true);
        if (json_last_error() === JSON_ERROR_NONE) {
          $field->settings = $json_data;
        } else {
          $unserialized = @unserialize($field->settings);
          $field->settings = $unserialized !== false ? $unserialized : [];
        }
      }
    }

    return $field;
  }

  /**
   * 根据名称获取字段。
   *
   * @param int $template_id
   *   模板ID。
   * @param string $name
   *   字段名称。
   *
   * @return object|false
   *   字段对象，如果不存在则返回FALSE。
   */
  public function getFieldByName($template_id, $name)
  {
    $field = $this->database->select('baas_entity_field', 'f')
      ->fields('f')
      ->condition('template_id', $template_id)
      ->condition('name', $name)
      ->execute()
      ->fetchObject();

    if ($field && isset($field->settings)) {
      if (is_string($field->settings)) {
        // 尝试JSON解码，如果失败则尝试unserialize
        $json_data = json_decode($field->settings, true);
        if (json_last_error() === JSON_ERROR_NONE) {
          $field->settings = $json_data;
        } else {
          $unserialized = @unserialize($field->settings);
          $field->settings = $unserialized !== false ? $unserialized : [];
        }
      }
    }

    return $field;
  }

  /**
   * 获取模板的所有字段。
   *
   * @param int $template_id
   *   模板ID。
   *
   * @return array
   *   字段列表。
   */
  public function getTemplateFields($template_id)
  {
    $fields = [];

    // 查询模板字段
    $query = $this->database->select('baas_entity_field', 'f')
      ->fields('f')
      ->condition('template_id', $template_id)
      ->orderBy('weight', 'ASC');

    $result = $query->execute();

    if ($result) {
      foreach ($result as $field) {
        if (isset($field->settings)) {
          if (is_string($field->settings)) {
            // 尝试JSON解码，如果失败则尝试unserialize
            $json_data = json_decode($field->settings, true);
            if (json_last_error() === JSON_ERROR_NONE) {
              $field->settings = $json_data;
            } else {
              $unserialized = @unserialize($field->settings);
              $field->settings = $unserialized !== false ? $unserialized : [];
            }
          }
        }
        $fields[] = $field;
      }
    }

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getTemplates($tenant_id)
  {
    $templateObjects = $this->getTemplatesByTenant($tenant_id);
    $templates = [];

    foreach ($templateObjects as $template) {
      $templateArray = (array) $template;

      // 获取模板字段
      $fields = [];
      $templateFields = $this->getTemplateFields($template->id);
      foreach ($templateFields as $field) {
        $fields[$field->name] = (array) $field;
      }

      $templateArray['fields'] = $fields;
      $templates[$template->id] = $templateArray;
    }

    return $templates;
  }
}
