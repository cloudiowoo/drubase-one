<?php

declare(strict_types=1);

namespace Drupal\baas_realtime\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\baas_realtime\Service\ProjectRealtimeManager;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Drupal\baas_project\ProjectManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 项目实时功能管理控制器。
 */
class ProjectRealtimeController extends ControllerBase
{

  /**
   * Logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly UnifiedPermissionCheckerInterface $permissionChecker,
    protected readonly ProjectRealtimeManager $projectRealtimeManager,
    protected readonly ProjectManagerInterface $projectManager,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('baas_realtime');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_auth.unified_permission_checker'),
      $container->get('baas_realtime.project_manager'),
      $container->get('baas_project.manager'),
      $container->get('logger.factory')
    );
  }

  /**
   * 项目实时管理页面。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   渲染数组。
   */
  public function managePage(string $tenant_id, string $project_id): array {
    // 验证项目访问权限
    $this->validateProjectAccess($tenant_id, $project_id, 'manage realtime');

    // 验证项目存在
    $project = $this->projectManager->getProject($project_id);
    if (!$project || $project['tenant_id'] !== $tenant_id) {
      throw new NotFoundHttpException('项目不存在');
    }

    // 获取项目实时配置
    $config = $this->projectRealtimeManager->getProjectRealtimeConfig($project_id, $tenant_id);
    
    // 获取可用实体
    $entities = $this->projectRealtimeManager->getAvailableEntities($project_id, $tenant_id);

    $build = [
      '#theme' => 'baas_realtime_project_manage',
      '#project' => $project,
      '#config' => $config,
      '#entities' => $entities,
      '#attached' => [
        'library' => ['baas_realtime/project-manage'],
        'drupalSettings' => [
          'baasRealtime' => [
            'projectId' => $project_id,
            'tenantId' => $tenant_id,
            'apiEndpoint' => '/api/v1/realtime/project/' . $tenant_id . '/' . $project_id,
          ],
        ],
      ],
    ];

    return $build;
  }

  /**
   * 检查访问权限。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   访问结果。
   */
  public function checkAccess(string $tenant_id, string $project_id) {
    try {
      $user_id = (int) $this->currentUser()->id();
      
      // 使用统一权限检查器进行项目级权限检查
      if (!$this->permissionChecker->canAccessProject($user_id, $project_id)) {
        return \Drupal\Core\Access\AccessResult::forbidden('用户无权访问此项目');
      }
      
      // 检查项目级实时管理权限
      if (!$this->permissionChecker->checkProjectPermission($user_id, $project_id, 'manage realtime')) {
        return \Drupal\Core\Access\AccessResult::forbidden('用户无权管理此项目的实时功能');
      }
      
      return \Drupal\Core\Access\AccessResult::allowed();
    } catch (\Exception $e) {
      $this->logger->error('实时功能访问权限检查失败: @message', ['@message' => $e->getMessage()]);
      return \Drupal\Core\Access\AccessResult::forbidden('权限检查失败');
    }
  }

  /**
   * 获取项目实时配置API。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function getConfig(string $tenant_id, string $project_id, Request $request): JsonResponse {
    try {
      // 验证项目访问权限
      $this->validateProjectAccess($tenant_id, $project_id, 'view realtime');

      // 获取配置和实体
      $config = $this->projectRealtimeManager->getProjectRealtimeConfig($project_id, $tenant_id);
      $entities = $this->projectRealtimeManager->getAvailableEntities($project_id, $tenant_id);

      return new JsonResponse([
        'success' => true,
        'data' => [
          'config' => $config,
          'entities' => $entities,
        ],
      ]);

    } catch (AccessDeniedHttpException $e) {
      return new JsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
      ], 403);
    } catch (NotFoundHttpException $e) {
      return new JsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
      ], 404);
    } catch (\Exception $e) {
      $this->logger->error('Failed to get realtime config: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => false,
        'error' => 'Failed to get realtime configuration',
      ], 500);
    }
  }

  /**
   * 保存项目实时配置API。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function saveConfig(string $tenant_id, string $project_id, Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);
      
      if (!$data) {
        return new JsonResponse([
          'success' => false,
          'error' => 'Invalid JSON data',
        ], 400);
      }

      // 验证项目访问权限
      $this->validateProjectAccess($tenant_id, $project_id, 'manage realtime');

      // 保存配置
      $user_id = (int) $this->currentUser()->id();
      $success = $this->projectRealtimeManager->saveProjectRealtimeConfig(
        $project_id,
        $tenant_id,
        $data,
        $user_id
      );

      if ($success) {
        return new JsonResponse([
          'success' => true,
          'message' => '实时配置已保存',
        ]);
      } else {
        return new JsonResponse([
          'success' => false,
          'error' => 'Failed to save configuration',
        ], 500);
      }

    } catch (AccessDeniedHttpException $e) {
      return new JsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
      ], 403);
    } catch (NotFoundHttpException $e) {
      return new JsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
      ], 404);
    } catch (\Exception $e) {
      $this->logger->error('Failed to save realtime config: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => false,
        'error' => 'Failed to save realtime configuration',
      ], 500);
    }
  }

  /**
   * 切换实体实时状态API。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $entity_name
   *   实体名称。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function toggleEntity(string $tenant_id, string $project_id, string $entity_name, Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);
      
      if (!isset($data['enabled'])) {
        return new JsonResponse([
          'success' => false,
          'error' => 'Missing enabled parameter',
        ], 400);
      }

      // 验证项目访问权限
      $this->validateProjectAccess($tenant_id, $project_id, 'manage realtime');

      // 切换实体状态
      $user_id = (int) $this->currentUser()->id();
      $success = $this->projectRealtimeManager->toggleEntityRealtime(
        $project_id,
        $tenant_id,
        $entity_name,
        (bool) $data['enabled'],
        $user_id
      );

      if ($success) {
        $action = $data['enabled'] ? '启用' : '禁用';
        return new JsonResponse([
          'success' => true,
          'message' => "实体 {$entity_name} 的实时功能已{$action}",
        ]);
      } else {
        return new JsonResponse([
          'success' => false,
          'error' => 'Failed to toggle entity realtime',
        ], 500);
      }

    } catch (AccessDeniedHttpException $e) {
      return new JsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
      ], 403);
    } catch (NotFoundHttpException $e) {
      return new JsonResponse([
        'success' => false,
        'error' => $e->getMessage(),
      ], 404);
    } catch (\Exception $e) {
      $this->logger->error('Failed to toggle entity realtime: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => false,
        'error' => 'Failed to toggle entity realtime',
      ], 500);
    }
  }

  /**
   * 验证项目访问权限。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param string $project_id
   *   项目ID。
   * @param string $permission
   *   所需权限。
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   权限不足时抛出异常。
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   项目不存在时抛出异常。
   */
  protected function validateProjectAccess(string $tenant_id, string $project_id, string $permission): void {
    $user_id = (int) $this->currentUser()->id();
    
    // 验证项目存在且属于指定租户
    $project = $this->projectManager->getProject($project_id);
    if (!$project || $project['tenant_id'] !== $tenant_id) {
      throw new NotFoundHttpException('项目不存在');
    }
    
    // 验证用户是否可以访问该项目
    if (!$this->permissionChecker->canAccessProject($user_id, $project_id)) {
      throw new AccessDeniedHttpException('用户无权访问此项目');
    }
    
    // 验证用户是否有指定的项目权限
    if (!$this->permissionChecker->checkProjectPermission($user_id, $project_id, $permission)) {
      throw new AccessDeniedHttpException("用户无权执行操作: {$permission}");
    }
  }

}