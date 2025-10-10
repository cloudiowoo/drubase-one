namespace Drupal\baas_entity;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\ContentEntityType;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\EntityViewsData;
use Drupal\Core\Entity\Routing\EntityHtmlRouteProvider;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use function t;

/**
 * EntityRegistry 服务，负责管理动态实体类型的注册。
 */
class EntityRegistry implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * 注册实体类型到 Drupal 的实体类型集合中
   *
   * @param array $entity_types
   *   实体类型对象数组
   */
  public function registerEntityTypes(array &$entity_types) {
    // 防止递归加载
    static $registering = FALSE;

    if ($registering) {
      $this->logger->notice('检测到递归注册调用，跳过此次注册');
      return;
    }

    $registering = TRUE;

    try {
      // 获取注册在数据库中的实体类型
      $entity_defs = $this->getRegisteredEntityTypes();

      foreach ($entity_defs as $entity_type_id => $def) {
        // 跳过已注册的实体类型
        if (isset($entity_types[$entity_type_id])) {
          continue;
        }

        // 验证实体类和存储类文件
        $class_file = $def['class_file'];
        $storage_class_file = $def['storage_class_file'];

        if (!file_exists($class_file) || !file_exists($storage_class_file)) {
          $this->logger->error('实体类型 @type 文件不存在: @class_file 或 @storage_file', [
            '@type' => $entity_type_id,
            '@class_file' => $class_file,
            '@storage_file' => $storage_class_file,
          ]);
          continue;
        }

        // 预先加载实体类和存储类
        include_once $class_file;
        include_once $storage_class_file;

        // 仅创建实体类型对象，不尝试加载实例
        $entityClass = $def['class'];
        $storageClass = $def['storage_class'];

        if (!class_exists($entityClass) || !class_exists($storageClass)) {
          $this->logger->error('实体类型 @type 的类未找到: @entity_class 或 @storage_class', [
            '@type' => $entity_type_id,
            '@entity_class' => $entityClass,
            '@storage_class' => $storageClass,
          ]);
          continue;
        }

        // 创建实体类型定义
        $entity_type = $this->createEntityTypeDefinition($entity_type_id, $def);
        if ($entity_type) {
          $entity_types[$entity_type_id] = $entity_type;
        }
      }
    }
    finally {
      $registering = FALSE;
    }
  }

  /**
   * 创建实体类型定义对象
   *
   * @param string $entity_type_id
   *   实体类型ID
   * @param array $def
   *   实体类型定义数组
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface|null
   *   创建的实体类型对象，失败则返回null
   */
  protected function createEntityTypeDefinition($entity_type_id, array $def) {
    try {
      // 从实体类获取实体类型定义
      $entityClass = $def['class'];
      $bundle = $def['bundle'];
      $label = $def['label'];

      // 使用 ContentEntityType 直接创建实体类型定义
      $entity_type = new ContentEntityType([
        'id' => $entity_type_id,
        'label' => $label,
        'bundle_label' => t('@label', ['@label' => $label]),
        'handlers' => [
          'storage' => $def['storage_class'],
          'view_builder' => EntityViewBuilder::class,
          'list_builder' => EntityListBuilder::class,
          'form' => [
            'default' => ContentEntityForm::class,
            'add' => ContentEntityForm::class,
            'edit' => ContentEntityForm::class,
            'delete' => ContentEntityDeleteForm::class,
          ],
          'access' => EntityAccessControlHandler::class,
          'views_data' => EntityViewsData::class,
          'route_provider' => [
            'html' => EntityHtmlRouteProvider::class,
          ],
        ],
        'base_table' => 'tenant_' . $entity_type_id,
        'data_table' => 'tenant_' . $entity_type_id . '_field_data',
        'revision_table' => 'tenant_' . $entity_type_id . '_revision',
        'revision_data_table' => 'tenant_' . $entity_type_id . '_revision_field_data',
        'entity_keys' => [
          'id' => 'id',
          'revision' => 'vid',
          'bundle' => 'type',
          'label' => 'name',
          'langcode' => 'langcode',
          'uuid' => 'uuid',
        ],
        'bundle' => $bundle,
        'class' => $entityClass,
        'field_ui_base_route' => 'entity.' . $bundle . '.edit_form',
        'links' => [
          'canonical' => '/tenant/' . $bundle . '/{' . $entity_type_id . '}',
          'edit-form' => '/tenant/' . $bundle . '/{' . $entity_type_id . '}/edit',
          'delete-form' => '/tenant/' . $bundle . '/{' . $entity_type_id . '}/delete',
        ],
      ]);

      return $entity_type;
    }
    catch (\Exception $e) {
      $this->logger->error('创建实体类型定义失败: @type, 错误: @error', [
        '@type' => $entity_type_id,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * 从实体定义创建新实例
   *
   * @param array $definition
   *   实体类型定义
   * @param array $values
   *   实体值
   *
   * @return object|null
   *   实体实例或NULL
   */
  public function newInstance(array $definition, array $values = []) {
    try {
      // 获取实体类及必要参数
      $entityClass = $definition['class'];
      $entityTypeId = $definition['id'];

      // 创建EntityTypeInterface实例作为参数
      $entityType = new ContentEntityType([
        'id' => $entityTypeId,
        'label' => $definition['label'],
        'class' => $entityClass,
      ]);

      // 获取实体管理器服务
      $entityTypeManager = \Drupal::service('entity_type.manager');

      // 创建带参数的新实例
      $entity = new $entityClass($entityType, $entityTypeManager->getStorage($entityTypeId), $values);
      return $entity;
    }
    catch (\Exception $e) {
      \Drupal::logger('baas_entity')->error('创建实体实例失败: @class, 错误: @error', [
        '@class' => $definition['class'] ?? 'unknown',
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }
}
