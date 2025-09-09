<?php

declare(strict_types=1);

namespace Drupal\baas_api\Controller;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\baas_api\Service\ApiResponseService;
use Drupal\baas_api\Service\ApiValidationService;
use Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * API使用统计控制器.
 *
 * 提供API使用统计分析功能.
 */
class ApiStatsController extends BaseApiController {

  /**
   * 数据库连接.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * 日期格式化器.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * 请求栈.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * 构造函数.
   *
   * @param \Drupal\baas_api\Service\ApiResponseService $response_service
   *   API响应服务.
   * @param \Drupal\baas_api\Service\ApiValidationService $validation_service
   *   API验证服务.
   * @param \Drupal\baas_auth\Service\UnifiedPermissionCheckerInterface $permission_checker
   *   统一权限检查器.
   * @param \Drupal\Core\Database\Connection $database
   *   数据库连接.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   日期格式化器.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   请求栈.
   */
  public function __construct(
    ApiResponseService $response_service,
    ApiValidationService $validation_service,
    UnifiedPermissionCheckerInterface $permission_checker,
    Connection $database,
    DateFormatterInterface $date_formatter,
    RequestStack $request_stack
  ) {
    parent::__construct($response_service, $validation_service, $permission_checker);
    $this->database = $database;
    $this->dateFormatter = $date_formatter;
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_api.response'),
      $container->get('baas_api.validation'),
      $container->get('baas_auth.unified_permission_checker'),
      $container->get('database'),
      $container->get('date.formatter'),
      $container->get('request_stack')
    );
  }

  /**
   * 获取API使用统计.
   *
   * @return array
   *   渲染数组.
   */
  public function getStats(): array {
    // 构建过滤表单
    $form = $this->formBuilder()->getForm('Drupal\baas_api\Form\ApiStatsFilterForm');

    // 获取过滤条件
    $filters = [];
    $request = $this->requestStack->getCurrentRequest();
    $filters['start_date'] = $request->query->get('start_date', strtotime('-30 days'));
    $filters['end_date'] = $request->query->get('end_date', time());
    $filters['tenant_id'] = $request->query->get('tenant_id', '');
    $filters['endpoint'] = $request->query->get('endpoint', '');
    $filters['method'] = $request->query->get('method', '');
    $filters['status'] = $request->query->get('status', '');

    // 获取统计数据
    $stats = $this->getApiStats($filters);
    $chart_data = $this->prepareChartData($filters);

    // 构建渲染数组
    $build = [
      '#theme' => 'baas_api_stats',
      '#filter_form' => $form,
      '#stats' => $stats,
      '#chart_data' => $chart_data,
      '#attached' => [
        'library' => [
          'baas_api/stats',
        ],
        'drupalSettings' => [
          'baas_api' => [
            'chart_data' => $chart_data,
          ],
        ],
      ],
    ];

    return $build;
  }

  /**
   * 清空API统计数据.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   HTTP请求对象.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\RedirectResponse
   *   响应对象.
   */
  public function clearStats(Request $request) {
    // 检查权限
    if (!$this->currentUser()->hasPermission('administer baas api')) {
      if ($request->isXmlHttpRequest()) {
        return new JsonResponse([
          'success' => false,
          'message' => $this->t('Access denied'),
        ], 403);
      }
      throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }

    try {
      $cleared_requests = 0;
      $cleared_watchdog = 0;

      // 清空API请求统计表
      if ($this->database->schema()->tableExists('baas_api_requests')) {
        $cleared_requests = $this->database->select('baas_api_requests', 'r')
          ->countQuery()
          ->execute()
          ->fetchField();
        $this->database->truncate('baas_api_requests')->execute();
      }

      // 首先统计要删除的记录数
      $count_query = "
        SELECT COUNT(*) FROM watchdog 
        WHERE type IN ('baas_api', 'baas_project', 'baas_api_gateway')
          AND (
            (
              message LIKE '%rate limit exceeded%' OR 
              message LIKE '%Rate limit exceeded%' OR
              message LIKE '%限流超出%' OR
              message LIKE '%速率限制超出%' OR
              message LIKE '%Project rate limit exceeded%' OR
              message LIKE '%Gateway rate limit exceeded%' OR
              message LIKE '%API速率限制超出%'
            ) OR
            (
              message = 'API错误: @code - @error' AND
              variables::text LIKE '%RATE_LIMIT_EXCEEDED%'
            )
          )
          AND severity IN (3, 4)
      ";

      $cleared_watchdog = $this->database->query($count_query)->fetchField();

      // 执行删除操作
      $watchdog_delete_query = "
        DELETE FROM watchdog 
        WHERE type IN ('baas_api', 'baas_project', 'baas_api_gateway')
          AND (
            (
              message LIKE '%rate limit exceeded%' OR 
              message LIKE '%Rate limit exceeded%' OR
              message LIKE '%限流超出%' OR
              message LIKE '%速率限制超出%' OR
              message LIKE '%Project rate limit exceeded%' OR
              message LIKE '%Gateway rate limit exceeded%' OR
              message LIKE '%API速率限制超出%'
            ) OR
            (
              message = 'API错误: @code - @error' AND
              variables::text LIKE '%RATE_LIMIT_EXCEEDED%'
            )
          )
          AND severity IN (3, 4)
      ";

      $this->database->query($watchdog_delete_query);

      $message = $this->t('Statistics cleared successfully. Removed @requests API requests and @logs log entries.', [
        '@requests' => $cleared_requests,
        '@logs' => $cleared_watchdog,
      ]);

      // 记录操作日志
      \Drupal::logger('baas_api')->notice('API统计数据已清空: @user 清空了 @requests 条API请求记录和 @logs 条日志记录', [
        '@user' => $this->currentUser()->getDisplayName(),
        '@requests' => $cleared_requests,
        '@logs' => $cleared_watchdog,
      ]);

      if ($request->isXmlHttpRequest()) {
        return new JsonResponse([
          'success' => true,
          'message' => $message->render(),
          'cleared' => [
            'requests' => $cleared_requests,
            'logs' => $cleared_watchdog,
          ],
        ]);
      }

      $this->messenger()->addStatus($message);
      return new RedirectResponse(Url::fromRoute('baas_api.stats')->toString());

    } catch (\Exception $e) {
      $error_message = $this->t('Failed to clear statistics: @error', ['@error' => $e->getMessage()]);
      \Drupal::logger('baas_api')->error('清空API统计数据失败: @error', ['@error' => $e->getMessage()]);

      if ($request->isXmlHttpRequest()) {
        return new JsonResponse([
          'success' => false,
          'message' => $error_message->render(),
        ], 500);
      }

      $this->messenger()->addError($error_message);
      return new RedirectResponse(Url::fromRoute('baas_api.stats')->toString());
    }
  }

  /**
   * 获取API使用统计数据.
   *
   * @param array $filters
   *   过滤条件.
   *
   * @return array
   *   统计数据.
   */
  protected function getApiStats(array $filters): array {
    // 统计总请求数
    $query = $this->database->select('baas_api_requests', 'l');
    $query->addExpression('COUNT(*)', 'total_requests');
    $query->addExpression('AVG(l.execution_time)', 'avg_response_time');
    $query->addExpression('SUM(CASE WHEN l.status_code >= 400 THEN 1 ELSE 0 END)', 'error_count');

    $this->applyFilters($query, $filters);

    $stats = $query->execute()->fetchAssoc() ?: [
      'total_requests' => 0,
      'avg_response_time' => 0,
      'error_count' => 0,
    ];

    // 按方法统计
    $query = $this->database->select('baas_api_requests', 'l');
    $query->addField('l', 'method');
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('l.method');
    $query->orderBy('count', 'DESC');

    $this->applyFilters($query, $filters);

    $stats['by_method'] = $query->execute()->fetchAllKeyed(0, 1) ?: [];

    // 按终端统计
    $query = $this->database->select('baas_api_requests', 'l');
    $query->addField('l', 'endpoint');
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('l.endpoint');
    $query->orderBy('count', 'DESC');
    $query->range(0, 10);

    $this->applyFilters($query, $filters);

    $stats['by_endpoint'] = $query->execute()->fetchAllKeyed(0, 1) ?: [];

    // 按状态码统计
    $query = $this->database->select('baas_api_requests', 'l');
    $query->addField('l', 'status_code');
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('l.status_code');
    $query->orderBy('l.status_code');

    $this->applyFilters($query, $filters);

    $stats['by_status'] = $query->execute()->fetchAllKeyed(0, 1) ?: [];

    // 按租户统计
    $query = $this->database->select('baas_api_requests', 'l');
    $query->addField('l', 'tenant_id');
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('l.tenant_id');
    $query->orderBy('count', 'DESC');
    $query->range(0, 10);

    $this->applyFilters($query, $filters);

    $stats['by_tenant'] = $query->execute()->fetchAllKeyed(0, 1) ?: [];

    // 获取限流统计
    $stats['rate_limit'] = $this->getRateLimitStats($filters);

    return $stats;
  }

  /**
   * 准备图表数据.
   *
   * @param array $filters
   *   过滤条件.
   *
   * @return array
   *   图表数据.
   */
  protected function prepareChartData(array $filters): array {
    // 请求量时间序列数据
    $query = $this->database->select('baas_api_requests', 'l');
    // PostgreSQL使用to_timestamp函数替代FROM_UNIXTIME
    $query->addExpression("to_char(to_timestamp(l.request_time), 'YYYY-MM-DD')", 'date');
    $query->addExpression('COUNT(*)', 'count');
    $query->groupBy('date');
    $query->orderBy('date');

    $this->applyFilters($query, $filters);

    $data = $query->execute()->fetchAllKeyed(0, 1) ?: [];

    // 格式化为图表需要的格式
    $chart_data = [
      'labels' => array_keys($data),
      'datasets' => [
        [
          'label' => $this->t('请求量'),
          'data' => array_values($data),
          'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
          'borderColor' => 'rgba(54, 162, 235, 1)',
        ],
      ],
    ];

    return $chart_data;
  }

  /**
   * 获取限流统计数据.
   *
   * @param array $filters
   *   过滤条件.
   *
   * @return array
   *   限流统计数据.
   */
  protected function getRateLimitStats(array $filters): array {
    $stats = [
      'total_rate_limited' => 0,
      'global_rate_limited' => 0,
      'project_rate_limited' => 0,
      'rate_limit_by_type' => [],
      'rate_limit_by_endpoint' => [],
      'recent_rate_limits' => [],
    ];

    // 从watchdog表获取限流日志
    $time_condition = '';
    if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
      $time_condition = "AND timestamp >= :start_time AND timestamp <= :end_time";
    }

    // 统计限流触发次数 - 只统计真正的限流拒绝记录
    $rate_limit_query = "
      SELECT 
        type,
        COUNT(*) as count,
        message,
        variables
      FROM watchdog 
      WHERE type IN ('baas_api', 'baas_project', 'baas_api_gateway')
        AND (
          (
            message LIKE '%rate limit exceeded%' OR 
            message LIKE '%Rate limit exceeded%' OR
            message LIKE '%限流超出%' OR
            message LIKE '%速率限制超出%' OR
            message LIKE '%Project rate limit exceeded%' OR
            message LIKE '%Gateway rate limit exceeded%' OR
            message LIKE '%API速率限制超出%'
          ) OR
          (
            message = 'API错误: @code - @error' AND
            variables LIKE '%RATE_LIMIT_EXCEEDED%'
          )
        )
        AND severity IN (3, 4) -- Warning and Error
        $time_condition
      GROUP BY type, message, variables
      ORDER BY count DESC
    ";

    try {
      $params = [];
      if (!empty($filters['start_date'])) {
        $params[':start_time'] = $filters['start_date'];
      }
      if (!empty($filters['end_date'])) {
        $params[':end_time'] = $filters['end_date'];
      }

      $result = $this->database->query($rate_limit_query, $params);
      
      foreach ($result as $row) {
        $stats['total_rate_limited'] += $row->count;
        
        // 根据实际的错误内容来分类，而不是logger类型
        $is_project_limit = false;
        
        // 检查是否为项目级限流
        if ($row->type === 'baas_project') {
          $is_project_limit = true;
        } elseif ($row->message === 'API错误: @code - @error' && $row->variables) {
          // 解析variables来判断错误代码
          $variables = unserialize($row->variables, ['allowed_classes' => false]);
          if ($variables && isset($variables['@code'])) {
            $error_code = $variables['@code'];
            if (strpos($error_code, 'PROJECT') !== false || strpos($error_code, 'project') !== false) {
              $is_project_limit = true;
            }
          }
        }
        
        if ($is_project_limit) {
          $stats['project_rate_limited'] += $row->count;
          $stats['rate_limit_by_type']['project'] = ($stats['rate_limit_by_type']['project'] ?? 0) + $row->count;
        } else {
          $stats['global_rate_limited'] += $row->count;
          $stats['rate_limit_by_type']['global'] = ($stats['rate_limit_by_type']['global'] ?? 0) + $row->count;
        }
      }

      // 获取最近的限流记录 - 只获取真正的限流拒绝记录
      $recent_query = "
        SELECT 
          timestamp,
          type,
          message,
          variables,
          severity
        FROM watchdog 
        WHERE type IN ('baas_api', 'baas_project', 'baas_api_gateway')
          AND (
            (
              message LIKE '%rate limit exceeded%' OR 
              message LIKE '%Rate limit exceeded%' OR
              message LIKE '%限流超出%' OR
              message LIKE '%速率限制超出%' OR
              message LIKE '%Project rate limit exceeded%' OR
              message LIKE '%Gateway rate limit exceeded%' OR
              message LIKE '%API速率限制超出%'
            ) OR
            (
              message = 'API错误: @code - @error' AND
              variables LIKE '%RATE_LIMIT_EXCEEDED%'
            )
          )
          AND severity IN (3, 4)
          $time_condition
        ORDER BY timestamp DESC
        LIMIT 50
      ";

      $recent_result = $this->database->query($recent_query, $params);
      
      foreach ($recent_result as $row) {
        $variables = [];
        if ($row->variables) {
          $variables = unserialize($row->variables, ['allowed_classes' => false]);
        }
        
        // 确定实际的限流类型
        $limit_type = 'global';
        if ($row->type === 'baas_project') {
          $limit_type = 'project';
        } elseif ($row->message === 'API错误: @code - @error' && $variables && isset($variables['@code'])) {
          $error_code = $variables['@code'];
          if (strpos($error_code, 'PROJECT') !== false || strpos($error_code, 'project') !== false) {
            $limit_type = 'project';
          }
        }
        
        $stats['recent_rate_limits'][] = [
          'timestamp' => $row->timestamp,
          'type' => $limit_type, // 使用实际的限流类型而不是logger类型
          'original_type' => $row->type, // 保留原始类型用于调试
          'message' => strtr($row->message, $variables ?: []),
          'severity' => $row->severity,
          'formatted_time' => $this->dateFormatter->format($row->timestamp, 'medium'),
        ];
      }

    } catch (\Exception $e) {
      \Drupal::logger('baas_api_stats')->error('Failed to get rate limit stats: @error', [
        '@error' => $e->getMessage(),
      ]);
    }

    return $stats;
  }

  /**
   * 应用过滤条件到查询.
   *
   * @param \Drupal\Core\Database\Query\SelectInterface $query
   *   查询对象.
   * @param array $filters
   *   过滤条件.
   * @param string $alias
   *   表别名.
   */
  protected function applyFilters($query, array $filters, string $alias = 'l'): void {
    if (!empty($filters['start_date'])) {
      $query->condition($alias . '.request_time', $filters['start_date'], '>=');
    }

    if (!empty($filters['end_date'])) {
      $query->condition($alias . '.request_time', $filters['end_date'], '<=');
    }

    if (!empty($filters['tenant_id'])) {
      $query->condition($alias . '.tenant_id', $filters['tenant_id']);
    }

    if (!empty($filters['endpoint'])) {
      $query->condition($alias . '.endpoint', '%' . $this->database->escapeLike($filters['endpoint']) . '%', 'LIKE');
    }

    if (!empty($filters['method'])) {
      $query->condition($alias . '.method', $filters['method']);
    }

    if (!empty($filters['status'])) {
      $query->condition($alias . '.status_code', $filters['status']);
    }
  }

}
