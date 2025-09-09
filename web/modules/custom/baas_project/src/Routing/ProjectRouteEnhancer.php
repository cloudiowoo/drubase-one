<?php

declare(strict_types=1);

namespace Drupal\baas_project\Routing;

use Drupal\Core\Routing\EnhancerInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;
use Drupal\baas_project\ProjectResolverInterface;
use Drupal\baas_project\Exception\ProjectException;

/**
 * 项目路由增强器。
 *
 * 处理项目相关的路由参数解析和上下文设置。
 */
class ProjectRouteEnhancer implements EnhancerInterface
{

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly ProjectResolverInterface $projectResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function enhance(array $defaults, Request $request): array
  {
    $route = $defaults[RouteObjectInterface::ROUTE_OBJECT] ?? null;

    if (!$route instanceof Route) {
      return $defaults;
    }

    // 检查路由是否需要项目上下文
    if (!$this->requiresProjectContext($route)) {
      return $defaults;
    }

    try {
      // 尝试从路由参数解析项目ID
      $project_id = $this->extractProjectIdFromDefaults($defaults);

      if (!$project_id) {
        // 尝试从请求解析项目ID
        $project_id = $this->projectResolver->resolveProjectFromRequest();
      }

      if (!$project_id) {
        // 尝试解析当前项目
        $project_id = $this->projectResolver->resolveCurrentProject();
      }

      if ($project_id) {
        // 设置项目上下文
        if ($this->projectResolver->setCurrentProject($project_id)) {
          $defaults['_project_id'] = $project_id;
          $defaults['_project_context'] = true;
        }
      } else {
        // 如果路由要求项目上下文但无法解析，根据路由配置决定处理方式
        $project_required = $route->getOption('_project_required') ?? false;
        if ($project_required) {
          throw new ProjectException(
            'Project context is required but could not be resolved',
            ProjectException::PROJECT_CONTEXT_REQUIRED
          );
        }
      }
    } catch (ProjectException $e) {
      // 项目相关异常，重新抛出
      throw $e;
    } catch (\Exception $e) {
      // 其他异常，记录日志但不中断路由处理
      \Drupal::logger('baas_project_routing')->error('Error enhancing route with project context: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $defaults;
  }

  /**
   * 检查路由是否需要项目上下文。
   *
   * @param \Symfony\Component\Routing\Route $route
   *   路由对象。
   *
   * @return bool
   *   是否需要项目上下文。
   */
  protected function requiresProjectContext(Route $route): bool
  {
    // 检查路由选项
    if ($route->hasOption('_project_context')) {
      return (bool) $route->getOption('_project_context');
    }

    // 检查路径是否包含项目参数
    $path = $route->getPath();
    if (preg_match('/\{project[_\w]*\}/', $path)) {
      return true;
    }

    // 检查路由名称模式
    $route_name = $route->getDefault('_route_name') ?? '';
    if (str_starts_with($route_name, 'baas_project.') || str_contains($route_name, '.project.')) {
      return true;
    }

    // 检查控制器类名
    $controller = $route->getDefault('_controller') ?? '';
    if (str_contains($controller, 'ProjectController') || str_contains($controller, '\\baas_project\\')) {
      return true;
    }

    return false;
  }

  /**
   * 从路由默认参数中提取项目ID。
   *
   * @param array $defaults
   *   路由默认参数。
   *
   * @return string|null
   *   项目ID或NULL。
   */
  protected function extractProjectIdFromDefaults(array $defaults): ?string
  {
    // 常见的项目ID参数名
    $project_param_names = [
      'project_id',
      'project',
      'baas_project_id',
      'baas_project',
    ];

    foreach ($project_param_names as $param_name) {
      if (!empty($defaults[$param_name])) {
        return (string) $defaults[$param_name];
      }
    }

    return null;
  }
}