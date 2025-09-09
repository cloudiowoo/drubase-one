<?php

namespace Drupal\baas_entity\Service;

use Drupal\baas_entity\Plugin\FieldType\FieldTypePluginInterface;

/**
 * 字段类型管理器服务。
 *
 * 负责注册和管理所有字段类型插件。
 */
class FieldTypeManager
{

  /**
   * 已注册的字段类型插件。
   *
   * @var \Drupal\baas_entity\Plugin\FieldType\FieldTypePluginInterface[]
   */
  protected array $plugins = [];

  /**
   * 字段类型缓存。
   *
   * @var array
   */
  protected array $typeCache = [];

  /**
   * 构造函数。
   */
  public function __construct()
  {
    $this->initializeDefaultPlugins();
    // 不在构造函数中发现插件，而是在需要时延迟加载
  }

  /**
   * 注册字段类型插件。
   *
   * @param \Drupal\baas_entity\Plugin\FieldType\FieldTypePluginInterface $plugin
   *   要注册的插件。
   */
  public function registerPlugin(FieldTypePluginInterface $plugin): void
  {
    $type = $plugin->getFieldType();
    $this->plugins[$type] = $plugin;

    // 清除相关缓存
    unset($this->typeCache[$type]);
  }

  /**
   * 获取字段类型插件。
   *
   * @param string $type
   *   字段类型。
   *
   * @return \Drupal\baas_entity\Plugin\FieldType\FieldTypePluginInterface|null
   *   插件实例，如果不存在则返回NULL。
   */
  public function getPlugin(string $type): ?FieldTypePluginInterface
  {
    $this->ensurePluginsDiscovered();
    return $this->plugins[$type] ?? NULL;
  }

  /**
   * 检查字段类型是否存在。
   *
   * @param string $type
   *   字段类型。
   *
   * @return bool
   *   如果类型存在则返回TRUE。
   */
  public function hasType(string $type): bool
  {
    $this->ensurePluginsDiscovered();
    return isset($this->plugins[$type]);
  }

  /**
   * 获取所有可用的字段类型。
   *
   * @return array
   *   字段类型数组，键为类型ID，值为显示名称。
   */
  public function getAvailableTypes(): array
  {
    // 确保插件已经发现
    $this->ensurePluginsDiscovered();
    
    $types = [];
    foreach ($this->plugins as $type => $plugin) {
      $types[$type] = $plugin->getLabel();
    }
    return $types;
  }

  /**
   * 获取字段类型信息。
   *
   * @param string $type
   *   字段类型。
   *
   * @return array|null
   *   字段类型信息数组，如果类型不存在则返回NULL。
   */
  public function getTypeInfo(string $type): ?array
  {
    if (!isset($this->typeCache[$type])) {
      $plugin = $this->getPlugin($type);
      if (!$plugin) {
        return NULL;
      }

      $this->typeCache[$type] = [
        'type' => $plugin->getFieldType(),
        'label' => $plugin->getLabel(),
        'description' => $plugin->getDescription(),
        'drupal_type' => $plugin->getDrupalFieldType(),
        'widget_type' => $plugin->getWidgetType(),
        'formatter_type' => $plugin->getFormatterType(),
        'supports_multiple' => $plugin->supportsMultiple(),
        'needs_index' => $plugin->needsIndex(),
        'weight' => $plugin->getWeight(),
        'default_settings' => $plugin->getDefaultSettings(),
      ];
    }

    return $this->typeCache[$type];
  }

  /**
   * 获取字段类型的存储Schema。
   *
   * @param string $type
   *   字段类型。
   *
   * @return array|null
   *   存储Schema数组，如果类型不存在则返回NULL。
   */
  public function getStorageSchema(string $type): ?array
  {
    $plugin = $this->getPlugin($type);
    return $plugin ? $plugin->getStorageSchema() : NULL;
  }

  /**
   * 验证字段值。
   *
   * @param string $type
   *   字段类型。
   * @param mixed $value
   *   要验证的值。
   * @param array $settings
   *   字段设置。
   * @param array $context
   *   验证上下文。
   *
   * @return array
   *   验证错误数组。
   */
  public function validateValue(string $type, $value, array $settings, array $context = []): array
  {
    $plugin = $this->getPlugin($type);
    if (!$plugin) {
      return [$this->t('Unknown field type: @type', ['@type' => $type])];
    }

    return $plugin->validateValue($value, $settings, $context);
  }

  /**
   * 处理字段值。
   *
   * @param string $type
   *   字段类型。
   * @param mixed $value
   *   要处理的值。
   * @param array $settings
   *   字段设置。
   *
   * @return mixed
   *   处理后的值。
   */
  public function processValue(string $type, $value, array $settings): mixed
  {
    $plugin = $this->getPlugin($type);
    if (!$plugin) {
      return $value;
    }

    return $plugin->processValue($value, $settings);
  }

  /**
   * 格式化字段值。
   *
   * @param string $type
   *   字段类型。
   * @param mixed $value
   *   要格式化的值。
   * @param array $settings
   *   字段设置。
   * @param string $format
   *   格式化类型。
   *
   * @return string
   *   格式化后的值。
   */
  public function formatValue(string $type, $value, array $settings, string $format = 'default'): string
  {
    $plugin = $this->getPlugin($type);
    if (!$plugin) {
      return (string) $value;
    }

    return $plugin->formatValue($value, $settings, $format);
  }

  /**
   * 获取字段类型的设置表单。
   *
   * @param string $type
   *   字段类型。
   * @param array $settings
   *   当前设置。
   * @param array $form
   *   表单数组。
   * @param mixed $form_state
   *   表单状态。
   *
   * @return array
   *   设置表单数组。
   */
  public function getSettingsForm(string $type, array $settings, array $form, $form_state): array
  {
    $plugin = $this->getPlugin($type);
    if (!$plugin) {
      return [];
    }

    return $plugin->getSettingsForm($settings, $form, $form_state);
  }

  /**
   * 清除类型缓存。
   *
   * @param string|null $type
   *   要清除的类型，如果为NULL则清除所有缓存。
   */
  public function clearCache(?string $type = NULL): void
  {
    if ($type === NULL) {
      $this->typeCache = [];
    } else {
      unset($this->typeCache[$type]);
    }
  }

  /**
   * 初始化默认的字段类型插件。
   */
  protected function initializeDefaultPlugins(): void
  {
    // 注册字符串字段类型插件
    require_once __DIR__ . '/../Plugin/FieldType/FieldTypePluginInterface.php';
    require_once __DIR__ . '/../Plugin/FieldType/BaseFieldTypePlugin.php';
    require_once __DIR__ . '/../Plugin/FieldType/StringFieldTypePlugin.php';
    require_once __DIR__ . '/../Plugin/FieldType/ListStringFieldTypePlugin.php';
    require_once __DIR__ . '/../Plugin/FieldType/ListIntegerFieldTypePlugin.php';
    require_once __DIR__ . '/../Plugin/FieldType/PasswordFieldTypePlugin.php';

    // 注册基础字段类型插件
    $this->registerPlugin(new \Drupal\baas_entity\Plugin\FieldType\StringFieldTypePlugin());
    $this->registerPlugin(new \Drupal\baas_entity\Plugin\FieldType\ListStringFieldTypePlugin());
    $this->registerPlugin(new \Drupal\baas_entity\Plugin\FieldType\ListIntegerFieldTypePlugin());
    $this->registerPlugin(new \Drupal\baas_entity\Plugin\FieldType\PasswordFieldTypePlugin());
  }

  /**
   * 标记插件是否已经被发现。
   */
  protected bool $pluginsDiscovered = false;

  /**
   * 确保插件已经被发现。
   */
  protected function ensurePluginsDiscovered(): void
  {
    if (!$this->pluginsDiscovered) {
      $this->discoverModulePlugins();
      $this->pluginsDiscovered = true;
    }
  }

  /**
   * 发现其他模块提供的字段类型插件。
   */
  protected function discoverModulePlugins(): void
  {
    // 使用hook系统发现其他模块的字段类型
    if (class_exists('\Drupal') && method_exists('\Drupal', 'moduleHandler')) {
      try {
        $field_types = \Drupal::moduleHandler()->invokeAll('baas_entity_field_types');
        
        foreach ($field_types as $type_info) {
          if (isset($type_info['class']) && class_exists($type_info['class'])) {
            try {
              $plugin = new $type_info['class']();
              if ($plugin instanceof FieldTypePluginInterface) {
                $this->registerPlugin($plugin);
              }
            } catch (\Exception $e) {
              // 记录错误但继续处理其他插件
              if (class_exists('\Drupal') && method_exists('\Drupal', 'logger')) {
                \Drupal::logger('baas_entity')->error('加载字段类型插件失败: @class, 错误: @error', [
                  '@class' => $type_info['class'],
                  '@error' => $e->getMessage(),
                ]);
              }
            }
          }
        }
      } catch (\Exception $e) {
        // 如果Drupal还没有完全初始化，忽略错误
      }
    }
  }

  /**
   * 简单的翻译函数。
   *
   * @param string $string
   *   要翻译的字符串。
   * @param array $args
   *   替换参数。
   *
   * @return string
   *   翻译后的字符串。
   */
  protected function t(string $string, array $args = []): string
  {
    if (function_exists('t')) {
      return t($string, $args);
    }
    return strtr($string, $args);
  }
}
