<?php

namespace Drupal\baas_entity\Service;

/**
 * 实体模板管理接口.
 *
 * 定义实体模板管理服务应该实现的方法.
 */
interface TemplateManagerInterface {

  /**
   * 创建新的实体模板.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param string $name
   *   实体名称.
   * @param string $label
   *   实体标签.
   * @param string $description
   *   实体描述.
   * @param array $settings
   *   实体设置.
   *
   * @return int
   *   新创建的实体模板ID.
   */
  public function createTemplate($tenant_id, $name, $label, $description = '', array $settings = []);

  /**
   * 更新实体模板.
   *
   * @param int $template_id
   *   模板ID.
   * @param array $values
   *   更新的值.
   *
   * @return bool
   *   更新是否成功.
   */
  public function updateTemplate($template_id, array $values);

  /**
   * 删除实体模板.
   *
   * @param int $template_id
   *   模板ID.
   *
   * @return bool
   *   删除是否成功.
   */
  public function deleteTemplate($template_id);

  /**
   * 获取实体模板.
   *
   * @param int $template_id
   *   模板ID.
   *
   * @return object|false
   *   模板对象，如果不存在则返回FALSE.
   */
  public function getTemplate($template_id);

  /**
   * 根据名称获取实体模板.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param string $name
   *   模板名称.
   *
   * @return object|false
   *   模板对象，如果不存在则返回FALSE.
   */
  public function getTemplateByName($tenant_id, $name);

  /**
   * 获取租户的所有实体模板.
   *
   * @param string $tenant_id
   *   租户ID.
   * @param bool $active_only
   *   是否只返回激活的模板.
   *
   * @return array
   *   模板列表.
   */
  public function getTemplatesByTenant($tenant_id, $active_only = FALSE);

  /**
   * 获取租户的所有实体模板列表（数组格式）.
   *
   * @param string $tenant_id
   *   租户ID.
   *
   * @return array
   *   模板数组列表.
   */
  public function getTemplates($tenant_id);

  /**
   * 添加字段到实体模板.
   *
   * @param int $template_id
   *   模板ID.
   * @param string $name
   *   字段名称.
   * @param string $label
   *   字段标签.
   * @param string $type
   *   字段类型.
   * @param string $description
   *   字段描述.
   * @param bool $required
   *   是否必填.
   * @param int $weight
   *   权重.
   * @param array $settings
   *   字段设置.
   *
   * @return int
   *   新创建的字段ID.
   */
  public function addField($template_id, $name, $label, $type, $description = '', $required = FALSE, $weight = 0, array $settings = []);

  /**
   * 更新字段.
   *
   * @param int $field_id
   *   字段ID.
   * @param array $values
   *   更新的值.
   *
   * @return bool
   *   更新是否成功.
   */
  public function updateField($field_id, array $values);

  /**
   * 删除字段.
   *
   * @param int $field_id
   *   字段ID.
   *
   * @return bool
   *   删除是否成功.
   */
  public function deleteField($field_id);

  /**
   * 获取字段.
   *
   * @param int $field_id
   *   字段ID.
   *
   * @return object|false
   *   字段对象，如果不存在则返回FALSE.
   */
  public function getField($field_id);

  /**
   * 根据名称获取字段.
   *
   * @param int $template_id
   *   模板ID.
   * @param string $name
   *   字段名称.
   *
   * @return object|false
   *   字段对象，如果不存在则返回FALSE.
   */
  public function getFieldByName($template_id, $name);

  /**
   * 获取模板的所有字段.
   *
   * @param int $template_id
   *   模板ID.
   *
   * @return array
   *   字段列表.
   */
  public function getTemplateFields($template_id);

}
