<?php

declare(strict_types=1);

namespace Drupal\baas_realtime\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\baas_realtime\Service\RealtimeManagerInterface;
use Drupal\baas_realtime\Service\ConnectionManager;
use Drupal\baas_realtime\Service\RealtimePermissionChecker;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * WebSocket控制器，处理实时连接相关API。
 */
class WebSocketController implements ContainerInjectionInterface
{

  /**
   * Logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly RealtimeManagerInterface $realtimeManager,
    protected readonly ConnectionManager $connectionManager,
    protected readonly RealtimePermissionChecker $permissionChecker,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('baas_realtime');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('baas_realtime.manager'),
      $container->get('baas_realtime.connection_manager'),
      $container->get('baas_realtime.permission_checker'),
      $container->get('logger.factory')
    );
  }

  /**
   * 验证WebSocket连接。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   * @param string|null $tenant_id
   *   租户ID（从路径参数获取）。
   * @param string|null $project_id
   *   项目ID（从路径参数获取，可选）。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function authenticateConnection(Request $request, ?string $tenant_id = null, ?string $project_id = null): JsonResponse {
    try {
      // 首先检查是否有认证信息
      $auth_data = $request->attributes->get('auth_data');
      $authorization = $request->headers->get('Authorization');
      $api_key = $request->headers->get('X-API-Key');
      
      // 尝试从请求体获取认证信息（WebSocket客户端发送的）
      $data = json_decode($request->getContent(), TRUE);
      if ($data) {
        if (isset($data['access_token']) && !$authorization) {
          $authorization = 'Bearer ' . $data['access_token'];
          $request->headers->set('Authorization', $authorization);
        }
        if (isset($data['apikey']) && !$api_key) {
          $api_key = $data['apikey'];
          $request->headers->set('X-API-Key', $api_key);
        }
      }

      // 检查必需的认证信息
      if (!$authorization && !$api_key) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => '缺少认证信息，请提供有效的Authorization令牌或API密钥',
          'code' => 'MISSING_AUTH',
        ], Response::HTTP_UNAUTHORIZED);
      }

      // 验证连接权限
      $connection_data = $this->permissionChecker->validateConnection(
        $api_key ?? '',
        $authorization ? substr($authorization, 7) : '',
        $project_id  // 传递项目ID参数
      );

      if (!$connection_data) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Authentication failed',
          'code' => 'AUTH_FAILED',
        ], Response::HTTP_UNAUTHORIZED);
      }

      // 验证租户权限 - 确保连接的租户ID与路径参数匹配
      // 统一ID格式进行比较（移除tenant_前缀）
      if ($tenant_id) {
        $clean_connection_tenant_id = preg_replace('/^tenant_/', '', $connection_data['tenant_id']);
        $clean_path_tenant_id = preg_replace('/^tenant_/', '', $tenant_id);

        if ($clean_connection_tenant_id !== $clean_path_tenant_id) {
          return new JsonResponse([
            'success' => FALSE,
            'error' => 'Tenant access denied',
            'code' => 'TENANT_ACCESS_DENIED',
          ], Response::HTTP_FORBIDDEN);
        }
      }

      // 验证项目权限 - 如果路径包含项目ID，确保用户有项目访问权限
      // 统一ID格式进行比较（移除tenant_xxx_project_前缀，但保留租户hash前缀）
      if ($project_id) {
        $clean_connection_project_id = $connection_data['project_id'];
        if (strpos($clean_connection_project_id, 'tenant_') === 0) {
          // 如果是长格式 tenant_7375b0cd_project_6888d012be80c，提取短格式 7375b0cd_6888d012be80c
          $clean_connection_project_id = preg_replace('/^tenant_([^_]+)_project_/', '$1_', $clean_connection_project_id);
        }

        $clean_path_project_id = $project_id;
        if (strpos($clean_path_project_id, 'tenant_') === 0) {
          $clean_path_project_id = preg_replace('/^tenant_([^_]+)_project_/', '$1_', $clean_path_project_id);
        }

        if ($clean_connection_project_id !== $clean_path_project_id) {
          return new JsonResponse([
            'success' => FALSE,
            'error' => 'Project access denied',
            'code' => 'PROJECT_ACCESS_DENIED',
          ], Response::HTTP_FORBIDDEN);
        }

        // 项目权限已在validateConnection()中检查
      }

      // 生成连接ID
      $connection_id = $this->generateConnectionId();

      // 将长格式ID转换为短格式ID（用于Realtime服务器调用API）
      $short_tenant_id = preg_replace('/^tenant_/', '', $connection_data['tenant_id']);
      $short_project_id = $connection_data['project_id'];
      if (strpos($short_project_id, 'tenant_') === 0) {
        // 如果是长格式 tenant_7375b0cd_project_6888d012be80c，提取短格式 7375b0cd_6888d012be80c
        $short_project_id = preg_replace('/^tenant_([^_]+)_project_/', '$1_', $short_project_id);
      }

      // 记录连接信息（使用短格式ID）
      $connection_info = [
        'connection_id' => $connection_id,
        'user_id' => $connection_data['user_id'],
        'project_id' => $short_project_id,
        'tenant_id' => $short_tenant_id,
        'socket_id' => $data['socket_id'] ?? '',
        'ip_address' => $request->getClientIp(),
        'user_agent' => $request->headers->get('User-Agent'),
        'connected_at' => time(),
        'last_heartbeat' => time(),
        'status' => 'connected',
        'metadata' => json_encode($connection_data['permissions'] ?? []),
      ];

      $success = $this->connectionManager->createConnection($connection_info);

      if (!$success) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Failed to create connection',
          'code' => 'CONNECTION_FAILED',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      $this->logger->info('WebSocket connection authenticated', [
        'connection_id' => $connection_id,
        'user_id' => $connection_data['user_id'],
        'project_id' => $connection_data['project_id'],
      ]);

      return new JsonResponse([
        'success' => TRUE,
        'data' => [
          'connection_id' => $connection_id,
          'user_id' => $connection_data['user_id'],
          'project_id' => $connection_data['project_id'],
          'tenant_id' => $connection_data['tenant_id'],
          'permissions' => $connection_data['permissions'],
        ],
      ]);

    } catch (\Exception $e) {
      $this->logger->error('Connection authentication failed: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 过滤实时消息。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function filterMessage(Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);
      
      if (!$data || !isset($data['connection_id']) || !isset($data['payload'])) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Missing required fields: connection_id, payload',
          'code' => 'MISSING_FIELDS',
        ], Response::HTTP_BAD_REQUEST);
      }

      // 获取连接信息
      $connection = $this->connectionManager->getConnection($data['connection_id']);
      if (!$connection) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Connection not found',
          'code' => 'CONNECTION_NOT_FOUND',
        ], Response::HTTP_NOT_FOUND);
      }

      $payload = $data['payload'];
      $table_name = $payload['table'] ?? '';
      $record = $payload['record'] ?? NULL;

      // 检查表级权限
      if (!$this->permissionChecker->validateChannelSubscription($connection, "table:{$table_name}")) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Access denied to table',
          'code' => 'ACCESS_DENIED',
        ], Response::HTTP_FORBIDDEN);
      }

      // 应用行级安全策略
      if (!$this->permissionChecker->checkRowLevelSecurity($connection, $table_name, $record)) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Row access denied',
          'code' => 'ROW_ACCESS_DENIED',
        ], Response::HTTP_FORBIDDEN);
      }

      // 过滤敏感字段
      if ($record) {
        $payload['record'] = $this->permissionChecker->filterSensitiveFields($record, $connection);
      }

      if (isset($payload['old_record'])) {
        $payload['old_record'] = $this->permissionChecker->filterSensitiveFields($payload['old_record'], $connection);
      }

      return new JsonResponse([
        'success' => TRUE,
        'data' => $payload,
      ]);

    } catch (\Exception $e) {
      $this->logger->error('Message filtering failed: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 订阅频道。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function subscribe(Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);
      
      if (!$data || !isset($data['connection_id']) || !isset($data['channel'])) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Missing required fields: connection_id, channel',
          'code' => 'MISSING_FIELDS',
        ], Response::HTTP_BAD_REQUEST);
      }

      $connection_id = $data['connection_id'];
      $channel = $data['channel'];
      $filters = $data['filters'] ?? [];
      $event_types = $data['event_types'] ?? ['INSERT', 'UPDATE', 'DELETE'];

      // 获取连接信息
      $connection = $this->connectionManager->getConnection($connection_id);
      if (!$connection) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Connection not found',
          'code' => 'CONNECTION_NOT_FOUND',
        ], Response::HTTP_NOT_FOUND);
      }

      // 验证频道订阅权限
      if (!$this->permissionChecker->validateChannelSubscription($connection, $channel)) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Access denied to channel',
          'code' => 'ACCESS_DENIED',
        ], Response::HTTP_FORBIDDEN);
      }

      // 创建订阅
      $subscription_data = [
        'connection_id' => $connection_id,
        'channel_name' => $channel,
        'channel_type' => $this->getChannelType($channel),
        'table_name' => $this->getTableFromChannel($channel),
        'filters' => json_encode($filters),
        'event_types' => implode(',', $event_types),
        'subscribed_at' => time(),
      ];

      $success = $this->connectionManager->createSubscription($subscription_data);

      if (!$success) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Failed to create subscription',
          'code' => 'SUBSCRIPTION_FAILED',
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
      }

      $this->logger->info('Channel subscription created', [
        'connection_id' => $connection_id,
        'channel' => $channel,
        'user_id' => $connection['user_id'],
      ]);

      return new JsonResponse([
        'success' => TRUE,
        'data' => [
          'channel' => $channel,
          'subscribed' => TRUE,
          'filters' => $filters,
          'event_types' => $event_types,
        ],
      ]);

    } catch (\Exception $e) {
      $this->logger->error('Channel subscription failed: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 取消订阅频道。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function unsubscribe(Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);
      
      if (!$data || !isset($data['connection_id']) || !isset($data['channel'])) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Missing required fields: connection_id, channel',
          'code' => 'MISSING_FIELDS',
        ], Response::HTTP_BAD_REQUEST);
      }

      $success = $this->connectionManager->removeSubscription(
        $data['connection_id'],
        $data['channel']
      );

      return new JsonResponse([
        'success' => $success,
        'data' => [
          'channel' => $data['channel'],
          'unsubscribed' => $success,
        ],
      ]);

    } catch (\Exception $e) {
      $this->logger->error('Channel unsubscription failed: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 发送广播消息。
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   请求对象。
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON响应。
   */
  public function broadcast(Request $request): JsonResponse {
    try {
      $data = json_decode($request->getContent(), TRUE);
      
      if (!$data || !isset($data['channel']) || !isset($data['payload'])) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Missing required fields: channel, payload',
          'code' => 'MISSING_FIELDS',
        ], Response::HTTP_BAD_REQUEST);
      }

      // TODO: 添加权限验证，确保用户有广播权限

      $success = $this->realtimeManager->broadcast(
        $data['channel'],
        $data['payload'],
        $data['options'] ?? []
      );

      return new JsonResponse([
        'success' => $success,
        'data' => [
          'channel' => $data['channel'],
          'broadcasted' => $success,
        ],
      ]);

    } catch (\Exception $e) {
      $this->logger->error('Broadcast failed: @error', [
        '@error' => $e->getMessage(),
      ]);

      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Internal server error',
        'code' => 'INTERNAL_ERROR',
      ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

  /**
   * 生成连接ID。
   *
   * @return string
   *   连接ID。
   */
  protected function generateConnectionId(): string {
    return 'conn_' . uniqid() . '_' . mt_rand(1000, 9999);
  }

  /**
   * 从频道名获取频道类型。
   *
   * @param string $channel
   *   频道名。
   *
   * @return string
   *   频道类型。
   */
  protected function getChannelType(string $channel): string {
    $parts = explode(':', $channel, 2);
    return $parts[0] ?? 'unknown';
  }

  /**
   * 从频道名获取表名。
   *
   * @param string $channel
   *   频道名。
   *
   * @return string|null
   *   表名或NULL。
   */
  protected function getTableFromChannel(string $channel): ?string {
    if (strpos($channel, 'table:') === 0) {
      return substr($channel, 6);
    }
    return NULL;
  }

}