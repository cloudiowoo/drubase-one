<?php

namespace Drupal\baas_entity\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\baas_entity\Service\TemplateManager;
use Drupal\baas_entity\Service\EntityGenerator;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * 实体生成器控制器，处理实体生成相关请求。
 */
class EntityGeneratorController extends ControllerBase {

  /**
   * 模板管理服务。
   *
   * @var \Drupal\baas_entity\Service\TemplateManager
   */
  protected $templateManager;

  /**
   * 实体生成器服务。
   *
   * @var \Drupal\baas_entity\Service\EntityGenerator
   */
  protected $entityGenerator;

  /**
   * 日志服务。
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * 请求对象。
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $currentRequest;

  /**
   * 构造函数。
   *
   * @param \Drupal\baas_entity\Service\TemplateManager $template_manager
   *   模板管理服务。
   * @param \Drupal\baas_entity\Service\EntityGenerator $entity_generator
   *   实体生成器服务。
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   日志工厂服务。
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   请求栈服务。
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   实体类型管理器服务。
   */
  public function __construct(
    TemplateManager $template_manager,
    EntityGenerator $entity_generator,
    LoggerChannelFactoryInterface $logger_factory,
    RequestStack $request_stack,
    EntityTypeManagerInterface $entity_type_manager
  ) {
    $this->templateManager = $template_manager;
    $this->entityGenerator = $entity_generator;
    $this->logger = $logger_factory->get('baas_entity');
    $this->currentRequest = $request_stack->getCurrentRequest();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('baas_entity.template_manager'),
      $container->get('baas_entity.entity_generator'),
      $container->get('logger.factory'),
      $container->get('request_stack'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * 生成实体类和表结构。
   *
   * @param int $template_id
   *   实体模板ID。
   *
   * @return array
   *   渲染数组。
   */
  public function generateEntity($template_id) {
    // 获取模板信息
    $template = $this->templateManager->getTemplate($template_id);

    if (!$template) {
      $this->messenger()->addError($this->t('模板不存在。'));
      return $this->redirect('baas_entity.list');
    }

    // 获取模板字段
    $fields = $this->templateManager->getTemplateFields($template_id);

    // 生成实体类型
    $result = $this->entityGenerator->createEntityType($template, $fields);

    if ($result) {
      $this->messenger()->addStatus(
        $this->t('成功生成实体类型: @type', ['@type' => $template->tenant_id . '_' . $template->name])
      );

      // 清除缓存，重新注册实体类型
      drupal_flush_all_caches();

      // 检查API路径是否可访问
      $entity_type_id = $template->tenant_id . '_' . $template->name;
      $api_url = Url::fromRoute('baas_entity.api_tenant_entities', [
        'tenant_id' => $template->tenant_id,
        'entity_name' => $template->name,
      ])->toString();

      return [
        '#type' => 'markup',
        '#markup' => $this->t('<h2>实体类型生成成功</h2>') .
          '<p>' . $this->t('实体类型ID: @type', ['@type' => $entity_type_id]) . '</p>' .
          '<p>' . $this->t('数据表名: @table', ['@table' => 'tenant_' . $template->tenant_id . '_entity_' . $template->name]) . '</p>' .
          '<p>' . $this->t('API路径: <a href="@url" target="_blank">@url</a>', ['@url' => $api_url]) . '</p>' .
          '<p>' . Link::fromTextAndUrl($this->t('返回模板列表'), Url::fromRoute('baas_entity.list'))->toString() . '</p>',
      ];
    }
    else {
      $this->messenger()->addError(
        $this->t('生成实体类型失败: @type', ['@type' => $template->tenant_id . '_' . $template->name])
      );
      return $this->redirect('baas_entity.list');
    }
  }

  /**
   * 重新生成实体类。
   *
   * @param int $template_id
   *   实体模板ID。
   *
   * @return array
   *   渲染数组。
   */
  public function regenerateEntity($template_id) {
    // 获取模板信息
    $template = $this->templateManager->getTemplate($template_id);

    if (!$template) {
      $this->messenger()->addError($this->t('模板不存在。'));
      return $this->redirect('baas_entity.list');
    }

    // 获取模板字段
    $fields = $this->templateManager->getTemplateFields($template_id);

    // 更新实体类型
    $result = $this->entityGenerator->updateEntityType($template, $fields);

    if ($result) {
      $this->messenger()->addStatus(
        $this->t('成功更新实体类型: @type', ['@type' => $template->tenant_id . '_' . $template->name])
      );

      // 清除缓存，重新注册实体类型
      drupal_flush_all_caches();

      return $this->redirect('baas_entity.list');
    }
    else {
      $this->messenger()->addError(
        $this->t('更新实体类型失败: @type', ['@type' => $template->tenant_id . '_' . $template->name])
      );
      return $this->redirect('baas_entity.list');
    }
  }

  /**
   * API端点：生成实体类型。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function apiGenerateEntity() {
    // 从请求体获取数据
    $data = json_decode($this->currentRequest->getContent(), TRUE);

    if (!$data || !isset($data['template_id'])) {
      return new JsonResponse(['success' => FALSE, 'message' => '缺少模板ID'], 400);
    }

    $template_id = $data['template_id'];

    // 获取模板信息
    $template = $this->templateManager->getTemplate($template_id);

    if (!$template) {
      return new JsonResponse(['success' => FALSE, 'message' => '模板不存在'], 404);
    }

    // 获取模板字段
    $fields = $this->templateManager->getTemplateFields($template_id);

    // 生成实体类型
    $result = $this->entityGenerator->createEntityType($template, $fields);

    if ($result) {
      // 清除缓存，重新注册实体类型
      drupal_flush_all_caches();

      return new JsonResponse([
        'success' => TRUE,
        'message' => '实体类型生成成功',
        'entity_type_id' => $template->tenant_id . '_' . $template->name,
        'table_name' => 'tenant_' . $template->tenant_id . '_entity_' . $template->name,
      ]);
    }
    else {
      return new JsonResponse(['success' => FALSE, 'message' => '实体类型生成失败'], 500);
    }
  }
}
