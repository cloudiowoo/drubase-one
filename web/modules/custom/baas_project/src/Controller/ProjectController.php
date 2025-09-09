<?php

declare(strict_types=1);

namespace Drupal\baas_project\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\baas_project\ProjectManagerInterface;
use Drupal\baas_project\ProjectMemberManagerInterface;
use Drupal\baas_project\ProjectUsageTrackerInterface;
use Drupal\baas_project\ProjectResolverInterface;
use Drupal\baas_project\Exception\ProjectException;
// use Drupal\baas_auth\TenantResolverInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * 项目控制器。
 *
 * 提供项目管理的REST API端点。
 */
class ProjectController extends ControllerBase implements ContainerInjectionInterface
{

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly ProjectManagerInterface $projectManager,
    protected readonly ProjectMemberManagerInterface $memberManager,
    protected readonly ProjectUsageTrackerInterface $usageTracker,
    protected readonly ProjectResolverInterface $projectResolver,
    // protected readonly TenantResolverInterface $tenantResolver,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->loggerFactory = $loggerFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('baas_project.manager'),
      $container->get('baas_project.member_manager'),
      $container->get('baas_project.usage_tracker'),
      $container->get('baas_project.resolver'),
      // $container->get('baas_auth.tenant_resolver'),
      $container->get('logger.factory'),
    );
  }

  /**
   * 创建项目。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function createProject(Request $request): JsonResponse
  {
    try {
      $data = json_decode($request->getContent(), true);
      if (!$data) {
        return new JsonResponse([
          'error' => 'Invalid JSON data',
          'code' => 'INVALID_DATA',
        ], Response::HTTP_BAD_REQUEST);
      }

      // 验证必需字段
      $required_fields = ['name', 'machine_name'];
      foreach ($required_fields as $field) {
        if (empty($data[$field])) {
          return new JsonResponse([
            'error' => "Missing required field: {$field}",
            'code' => 'MISSING_FIELD',
          ], Response::HTTP_BAD_REQUEST);
        }
      }

      // 获取当前租户ID
      // $tenant_id = $this->tenantResolver->getCurrentTenantId();
      $tenant_id = null; // TODO: Implement tenant resolution
      if (!$tenant_id) {
        return new JsonResponse([
          'error' => 'Tenant context is required',
          'code' => 'MISSING_TENANT',
        ], Response::HTTP_BAD_REQUEST);
      }

      // 创建项目
      $project_data = [
        'name' => $data['name'],
        'machine_name' => $data['machine_name'],
        'description' => $data['description'] ?? '',
        'settings' => $data['settings'] ?? [],
      ];

      $project_id = $this->projectManager->createProject(
        $tenant_id,
        $project_data
      );

      if (!$project_id) {
        return new JsonResponse([
          'error' => 'Failed to create project',
          'code' => 'CREATE_FAILED',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      $project = $this->projectManager->getProject($project_id);

      return new JsonResponse([
        'success' => true,
        'data' => $project,
        'message' => 'Project created successfully',
      ], Response::HTTP_CREATED);
    } catch (ProjectException $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode(),
        'context' => $e->getContext(),
      ], $e->getHttpStatusCode());
    } catch (\Exception $e) {
      $this->loggerFactory->get('baas_project')->error('Error creating project: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 获取项目详情。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function getProject(string $project_id): JsonResponse
  {
    try {
      $project = $this->projectManager->getProject($project_id);
      if (!$project) {
        return new JsonResponse([
          'error' => 'Project not found',
          'code' => 'PROJECT_NOT_FOUND',
        ], Response::HTTP_NOT_FOUND);
      }

      // 获取项目统计信息
      $stats = $this->projectManager->getProjectStats($project_id);
      $project['stats'] = $stats;

      return new JsonResponse([
        'success' => true,
        'data' => $project,
      ]);
    } catch (ProjectException $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode(),
        'context' => $e->getContext(),
      ], $e->getHttpStatusCode());
    } catch (\Exception $e) {
      $this->loggerFactory->get('baas_project')->error('Error getting project: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 更新项目。
   *
   * @param string $project_id
   *   项目ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function updateProject(string $project_id, Request $request): JsonResponse
  {
    try {
      $data = json_decode($request->getContent(), true);
      if (!$data) {
        return new JsonResponse([
          'error' => 'Invalid JSON data',
          'code' => 'INVALID_DATA',
        ], Response::HTTP_BAD_REQUEST);
      }

      // 过滤允许更新的字段
      $allowed_fields = ['name', 'description', 'settings'];
      $update_data = array_intersect_key($data, array_flip($allowed_fields));

      if (empty($update_data)) {
        return new JsonResponse([
          'error' => 'No valid fields to update',
          'code' => 'NO_UPDATE_DATA',
        ], Response::HTTP_BAD_REQUEST);
      }

      $success = $this->projectManager->updateProject($project_id, $update_data);

      if (!$success) {
        return new JsonResponse([
          'error' => 'Failed to update project',
          'code' => 'UPDATE_FAILED',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      $project = $this->projectManager->getProject($project_id);

      return new JsonResponse([
        'success' => true,
        'data' => $project,
        'message' => 'Project updated successfully',
      ]);
    } catch (ProjectException $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode(),
        'context' => $e->getContext(),
      ], $e->getHttpStatusCode());
    } catch (\Exception $e) {
      $this->loggerFactory->get('baas_project')->error('Error updating project: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 删除项目。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function deleteProject(string $project_id): JsonResponse
  {
    try {
      $this->projectManager->deleteProject($project_id);

      return new JsonResponse([
        'success' => true,
        'message' => 'Project deleted successfully',
      ]);
    } catch (ProjectException $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode(),
        'context' => $e->getContext(),
      ], $e->getHttpStatusCode());
    } catch (\Exception $e) {
      $this->loggerFactory->get('baas_project')->error('Error deleting project: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 获取租户项目列表。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function listProjects(Request $request): JsonResponse
  {
    try {
      // 获取当前租户ID
      // $tenant_id = $this->tenantResolver->getCurrentTenantId();
      $tenant_id = $request->headers->get('X-Tenant-ID'); // 临时从请求头获取
      if (!$tenant_id) {
        return new JsonResponse([
          'error' => 'Tenant context is required',
          'code' => 'MISSING_TENANT',
        ], Response::HTTP_BAD_REQUEST);
      }

      // 解析查询参数
      $filters = [
        'status' => $request->query->get('status'),
        'search' => $request->query->get('search'),
      ];
      $sort = [
        'field' => $request->query->get('sort_field', 'created'),
        'direction' => $request->query->get('sort_direction', 'DESC'),
      ];
      $pagination = [
        'page' => max(1, (int) $request->query->get('page', 1)),
        'limit' => min(100, max(1, (int) $request->query->get('limit', 20))),
      ];

      $projects = $this->projectManager->listTenantProjects(
        $tenant_id,
        $filters
      );

      // 手动实现分页
      $total = count($projects);
      $offset = ($pagination['page'] - 1) * $pagination['limit'];
      $paged_projects = array_slice($projects, $offset, $pagination['limit']);

      return new JsonResponse([
        'success' => true,
        'data' => $paged_projects,
        'pagination' => [
          'page' => $pagination['page'],
          'limit' => $pagination['limit'],
          'total' => $total,
          'pages' => ceil($total / $pagination['limit']),
        ],
      ]);
    } catch (\Exception $e) {
      $this->loggerFactory->get('baas_project')->error('Error listing projects: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 获取项目成员列表。
   *
   * @param string $project_id
   *   项目ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function getProjectMembers(string $project_id, Request $request): JsonResponse
  {
    try {
      // 解析查询参数
      $filters = [
        'role' => $request->query->get('role'),
        'status' => $request->query->get('status'),
      ];
      $pagination = [
        'page' => max(1, (int) $request->query->get('page', 1)),
        'limit' => min(100, max(1, (int) $request->query->get('limit', 20))),
      ];

      $members = $this->memberManager->getMembers($project_id, $filters, ['pagination' => $pagination]);

      // 如果返回的是带分页信息的数组
      if (is_array($members) && isset($members['members'])) {
        return new JsonResponse([
          'success' => true,
          'data' => $members['members'],
          'pagination' => [
            'page' => $pagination['page'],
            'limit' => $pagination['limit'],
            'total' => $members['total'] ?? count($members['members']),
            'pages' => ceil(($members['total'] ?? count($members['members'])) / $pagination['limit']),
          ],
        ]);
      }

      // 如果返回的是简单数组，手动实现分页
      $total = count($members);
      $offset = ($pagination['page'] - 1) * $pagination['limit'];
      $paged_members = array_slice($members, $offset, $pagination['limit']);

      return new JsonResponse([
        'success' => true,
        'data' => $paged_members,
        'pagination' => [
          'page' => $pagination['page'],
          'limit' => $pagination['limit'],
          'total' => $total,
          'pages' => ceil($total / $pagination['limit']),
        ],
      ]);
    } catch (ProjectException $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode(),
        'context' => $e->getContext(),
      ], $e->getHttpStatusCode());
    } catch (\Exception $e) {
      $this->loggerFactory->get('baas_project')->error('Error getting project members: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 添加项目成员。
   *
   * @param string $project_id
   *   项目ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function addProjectMember(string $project_id, Request $request): JsonResponse
  {
    try {
      $data = json_decode($request->getContent(), true);
      if (!$data) {
        return new JsonResponse([
          'error' => 'Invalid JSON data',
          'code' => 'INVALID_DATA',
        ], Response::HTTP_BAD_REQUEST);
      }

      // 验证必需字段
      if (empty($data['user_id']) || empty($data['role'])) {
        return new JsonResponse([
          'error' => 'Missing required fields: user_id, role',
          'code' => 'MISSING_FIELD',
        ], Response::HTTP_BAD_REQUEST);
      }

      $success = $this->memberManager->addMember(
        $project_id,
        (int) $data['user_id'],
        $data['role']
      );

      if (!$success) {
        return new JsonResponse([
          'error' => 'Failed to add member',
          'code' => 'ADD_MEMBER_FAILED',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      // 获取添加的成员信息
      $member = $this->memberManager->getMember($project_id, (int) $data['user_id']);

      return new JsonResponse([
        'success' => true,
        'data' => $member,
        'message' => 'Member added successfully',
      ], Response::HTTP_CREATED);
    } catch (ProjectException $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode(),
        'context' => $e->getContext(),
      ], $e->getHttpStatusCode());
    } catch (\Exception $e) {
      $this->loggerFactory->get('baas_project')->error('Error adding project member: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 更新项目成员角色。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $user_id
   *   用户ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function updateProjectMember(string $project_id, string $user_id, Request $request): JsonResponse
  {
    try {
      $data = json_decode($request->getContent(), true);
      if (!$data || empty($data['role'])) {
        return new JsonResponse([
          'error' => 'Missing required field: role',
          'code' => 'MISSING_FIELD',
        ], Response::HTTP_BAD_REQUEST);
      }

      $success = $this->memberManager->updateMemberRole(
        $project_id,
        (int) $user_id,
        $data['role']
      );

      if (!$success) {
        return new JsonResponse([
          'error' => 'Failed to update member role',
          'code' => 'UPDATE_ROLE_FAILED',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      // 获取更新后的成员信息
      $member = $this->memberManager->getMember($project_id, (int) $user_id);

      return new JsonResponse([
        'success' => true,
        'data' => $member,
        'message' => 'Member role updated successfully',
      ]);
    } catch (ProjectException $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode(),
        'context' => $e->getContext(),
      ], $e->getHttpStatusCode());
    } catch (\Exception $e) {
      $this->loggerFactory->get('baas_project')->error('Error updating project member: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 移除项目成员。
   *
   * @param string $project_id
   *   项目ID。
   * @param string $user_id
   *   用户ID。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function removeProjectMember(string $project_id, string $user_id): JsonResponse
  {
    try {
      $success = $this->memberManager->removeMember($project_id, (int) $user_id);

      if (!$success) {
        return new JsonResponse([
          'error' => 'Failed to remove member',
          'code' => 'REMOVE_MEMBER_FAILED',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      return new JsonResponse([
        'success' => true,
        'message' => 'Member removed successfully',
      ]);
    } catch (ProjectException $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode(),
        'context' => $e->getContext(),
      ], $e->getHttpStatusCode());
    } catch (\Exception $e) {
      $this->loggerFactory->get('baas_project')->error('Error removing project member: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 转移项目所有权。
   *
   * @param string $project_id
   *   项目ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function transferOwnership(string $project_id, Request $request): JsonResponse
  {
    try {
      $data = json_decode($request->getContent(), true);
      if (!$data || empty($data['new_owner_id'])) {
        return new JsonResponse([
          'error' => 'Missing required field: new_owner_id',
          'code' => 'MISSING_FIELD',
        ], Response::HTTP_BAD_REQUEST);
      }

      $success = $this->memberManager->transferOwnership(
        $project_id,
        (int) $this->currentUser()->id(),
        (int) $data['new_owner_id']
      );

      if (!$success) {
        return new JsonResponse([
          'error' => 'Failed to transfer ownership',
          'code' => 'TRANSFER_FAILED',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      return new JsonResponse([
        'success' => true,
        'message' => 'Ownership transferred successfully',
      ]);
    } catch (ProjectException $e) {
      return new JsonResponse([
        'error' => $e->getMessage(),
        'code' => $e->getErrorCode(),
        'context' => $e->getContext(),
      ], $e->getHttpStatusCode());
    } catch (\Exception $e) {
      $this->loggerFactory->get('baas_project')->error('Error transferring ownership: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 获取项目使用统计。
   *
   * @param string $project_id
   *   项目ID。
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function getProjectUsage(string $project_id, Request $request): JsonResponse
  {
    try {
      $resource_type = $request->query->get('resource_type');
      $start_date = $request->query->get('start_date');
      $end_date = $request->query->get('end_date');

      if ($resource_type) {
        // 获取特定资源类型的使用情况
        $usage = $this->usageTracker->getCurrentUsage($project_id, (string) $resource_type);

        // 构建趋势查询选项
        $trend_options = [];
        if ($start_date) {
          $trend_options['start_date'] = $start_date;
        }
        if ($end_date) {
          $trend_options['end_date'] = $end_date;
        }

        $trend = $this->usageTracker->getUsageTrend(
          $project_id,
          'daily',
          30,
          $trend_options
        );

        return new JsonResponse([
          'success' => true,
          'data' => [
            'current_usage' => $usage,
            'trend' => $trend,
          ],
        ]);
      } else {
        // 构建过滤条件
        $filters = [];
        if ($start_date) {
          $filters['start_date'] = $start_date;
        }
        if ($end_date) {
          $filters['end_date'] = $end_date;
        }

        // 获取所有资源类型的使用统计
        $stats = $this->usageTracker->getUsageStats(
          $project_id,
          $filters
        );

        return new JsonResponse([
          'success' => true,
          'data' => $stats,
        ]);
      }
    } catch (\Exception $e) {
      $this->loggerFactory->get('baas_project')->error('Error getting project usage: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }
}
