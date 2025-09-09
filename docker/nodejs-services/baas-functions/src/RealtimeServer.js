const WebSocket = require('ws');
const { Client } = require('pg');
const Redis = require('ioredis');
const jwt = require('jsonwebtoken');
const axios = require('axios');

/**
 * BaaS实时WebSocket服务器
 */
class RealtimeServer {
  constructor(logger, options = {}) {
    this.logger = logger;
    this.options = {
      port: options.port || 4000,
      drupalApiUrl: options.drupalApiUrl || process.env.DRUPAL_API_URL,
      jwtSecret: options.jwtSecret || process.env.JWT_SECRET,
      pgConnectionString: options.pgConnectionString || process.env.DATABASE_URL,
      redisUrl: options.redisUrl || process.env.REDIS_URL,
      ...options
    };

    // WebSocket服务器
    this.wss = null;
    
    // 连接管理
    this.connections = new Map();
    this.subscriptions = new Map();
    
    // 数据库连接
    this.pgClient = null;
    this.pgListener = null;
    this.redis = null;
    
    // 心跳检测
    this.heartbeatInterval = null;
    this.cleanupInterval = null;
  }

  /**
   * 初始化服务器
   */
  async init() {
    try {
      // 初始化数据库连接
      await this.initDatabase();
      
      // 初始化Redis连接
      await this.initRedis();
      
      // 设置数据库监听器
      await this.setupDatabaseListeners();
      
      // 设置WebSocket服务器
      this.setupWebSocketServer();
      
      // 启动心跳检测
      this.startHeartbeat();
      
      // 启动清理任务
      this.startCleanupTasks();
      
      this.logger.info('Realtime server initialized successfully', {
        port: this.options.port,
        drupalApiUrl: this.options.drupalApiUrl
      });
      
    } catch (error) {
      this.logger.error('Failed to initialize realtime server:', error);
      throw error;
    }
  }

  /**
   * 初始化数据库连接
   */
  async initDatabase() {
    this.pgClient = new Client({
      connectionString: this.options.pgConnectionString
    });
    
    this.pgListener = new Client({
      connectionString: this.options.pgConnectionString
    });
    
    await this.pgClient.connect();
    await this.pgListener.connect();
    
    this.logger.info('Database connections established');
  }

  /**
   * 初始化Redis连接
   */
  async initRedis() {
    if (this.options.redisUrl) {
      this.redis = new Redis(this.options.redisUrl);
      this.logger.info('Redis connection established');
    }
  }

  /**
   * 设置数据库监听器
   */
  async setupDatabaseListeners() {
    // 监听实时数据变更
    await this.pgListener.query('LISTEN realtime_changes');
    
    // 监听广播消息
    await this.pgListener.query('LISTEN realtime_broadcast');
    
    this.pgListener.on('notification', (msg) => {
      this.handleDatabaseNotification(msg);
    });
    
    this.logger.info('Database listeners setup complete');
  }

  /**
   * 设置WebSocket服务器
   */
  setupWebSocketServer() {
    this.wss = new WebSocket.Server({ 
      port: this.options.port,
      // 简化配置以提高Safari兼容性
      perMessageDeflate: false,  // 禁用压缩以避免Safari兼容性问题
      // 添加更严格的验证
      verifyClient: (info) => {
        try {
          const url = new URL(info.req.url, `http://${info.req.headers.host}`);
          const apikey = url.searchParams.get('apikey');
          const accessToken = url.searchParams.get('access_token');
          const tenantId = url.searchParams.get('tenant_id');
          const projectId = url.searchParams.get('project_id');
          
          // 基本参数验证
          return !!(apikey && accessToken && tenantId && projectId);
        } catch (error) {
          this.logger.error('verifyClient failed:', error);
          return false;
        }
      },
      // 添加客户端跟踪
      clientTracking: true,
      // 设置最大消息大小
      maxPayload: 100 * 1024 * 1024, // 100MB
    });

    this.wss.on('connection', (ws, req) => {
      this.handleConnection(ws, req);
    });

    this.wss.on('error', (error) => {
      this.logger.error('WebSocket server error:', error);
    });

    this.wss.on('listening', () => {
      this.logger.info(`WebSocket server listening on port ${this.options.port}`);
    });

    // 添加服务器关闭处理
    this.wss.on('close', () => {
      this.logger.info('WebSocket server closed');
    });
  }

  /**
   * 处理新的WebSocket连接
   */
  async handleConnection(ws, req) {
    try {
      // 设置WebSocket特性以提高Safari兼容性
      ws.binaryType = 'nodebuffer';
      
      // 从查询参数中获取认证信息和项目信息
      const url = new URL(req.url, `http://${req.headers.host}`);
      const apikey = url.searchParams.get('apikey');
      const accessToken = url.searchParams.get('access_token');
      const tenantId = url.searchParams.get('tenant_id');
      const projectId = url.searchParams.get('project_id');
      
      // 记录用户代理信息，用于调试Safari问题
      const userAgent = req.headers['user-agent'] || 'unknown';
      const isSafari = userAgent.includes('Safari') && !userAgent.includes('Chrome');
      
      this.logger.info('New WebSocket connection attempt:', {
        userAgent,
        isSafari,
        hasApikey: !!apikey,
        hasAccessToken: !!accessToken,
        tenantId,
        projectId
      });
      
      if (!apikey || !accessToken || !tenantId || !projectId) {
        this.logger.error('Missing authentication parameters');
        ws.close(1008, 'Missing authentication parameters: apikey, access_token, tenant_id, project_id required');
        return;
      }

      // 调用Drupal API验证连接（使用项目级端点）
      const connectionData = await this.authenticateConnection(apikey, accessToken, tenantId, projectId);
      if (!connectionData) {
        ws.close(1008, 'Authentication failed');
        return;
      }

      // 使用Drupal返回的连接ID，确保与数据库中的记录一致
      const connectionId = connectionData.connection_id;
      const socketId = this.generateSocketId();

      // 存储连接信息
      const connection = {
        id: connectionId,
        socketId: socketId,
        ws: ws,
        userId: connectionData.user_id,
        projectId: connectionData.project_id,
        tenantId: connectionData.tenant_id,
        permissions: connectionData.permissions,
        apikey: apikey,        // 保存API Key用于后续API调用
        accessToken: accessToken,  // 保存Access Token用于后续API调用
        subscriptions: new Set(),
        lastHeartbeat: Date.now(),
        ipAddress: req.socket.remoteAddress,
        userAgent: req.headers['user-agent'],
        connectedAt: Date.now()
      };

      this.connections.set(connectionId, connection);

      // 绑定消息处理
      ws.on('message', (data) => {
        this.handleMessage(connectionId, data);
      });

      ws.on('close', () => {
        this.handleDisconnection(connectionId);
      });

      ws.on('error', (error) => {
        this.logger.error('WebSocket error:', error);
        this.handleDisconnection(connectionId);
      });

      // 发送连接确认
      this.sendMessage(ws, {
        event: 'phx_reply',
        topic: 'phoenix',
        payload: { 
          status: 'ok', 
          response: {
            connection_id: connectionId,
            heartbeat_interval: 30000
          }
        },
        ref: null
      });

      this.logger.info('New WebSocket connection established', {
        connectionId: connectionId,
        userId: connection.userId,
        projectId: connection.projectId
      });

    } catch (error) {
      this.logger.error('Connection handling failed:', error);
      ws.close(1011, 'Internal server error');
    }
  }

  /**
   * 验证连接
   */
  async authenticateConnection(apikey, accessToken, tenantId, projectId) {
    try {
      // 使用项目级别的认证端点
      const authUrl = `${this.options.drupalApiUrl}/api/v1/${tenantId}/projects/${projectId}/realtime/auth`;
      
      this.logger.info('Authenticating WebSocket connection:', {
        authUrl,
        tenantId,
        projectId,
        hasApikey: !!apikey,
        hasAccessToken: !!accessToken
      });
      
      const response = await axios.post(authUrl, {
        apikey: apikey,
        access_token: accessToken
      }, {
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${accessToken}`,
          'X-API-Key': apikey
        },
        timeout: 5000
      });

      if (response.data.success) {
        this.logger.info('WebSocket authentication successful:', response.data.data);
        return response.data.data;
      } else {
        this.logger.warning('WebSocket authentication failed:', response.data);
        return null;
      }

    } catch (error) {
      this.logger.error('Connection authentication failed:', {
        message: error.message,
        status: error.response?.status,
        data: error.response?.data
      });
      return null;
    }
  }

  /**
   * 处理WebSocket消息
   */
  async handleMessage(connectionId, data) {
    const connection = this.connections.get(connectionId);
    if (!connection) {
      return;
    }

    try {
      const message = JSON.parse(data.toString());
      
      switch (message.event) {
        case 'heartbeat':
          this.handleHeartbeat(connectionId, message);
          break;
          
        case 'phx_join':
          await this.handleChannelJoin(connectionId, message);
          break;
          
        case 'phx_leave':
          await this.handleChannelLeave(connectionId, message);
          break;
          
        case 'broadcast':
          await this.handleBroadcast(connectionId, message);
          break;
          
        case 'presence_update':
          await this.handlePresenceUpdate(connectionId, message);
          break;
          
        default:
          this.logger.warning('Unknown message event:', message.event);
      }

    } catch (error) {
      this.logger.error('Message handling failed:', error);
      this.sendMessage(connection.ws, {
        event: 'error',
        topic: null,
        payload: { error: 'Invalid message format' },
        ref: message?.ref
      });
    }
  }

  /**
   * 处理心跳消息
   */
  handleHeartbeat(connectionId, message) {
    const connection = this.connections.get(connectionId);
    if (connection) {
      connection.lastHeartbeat = Date.now();
      
      this.sendMessage(connection.ws, {
        event: 'heartbeat',
        topic: null,
        payload: { status: 'ok' },
        ref: message.ref
      });
    }
  }

  /**
   * 处理频道加入
   */
  async handleChannelJoin(connectionId, message) {
    const connection = this.connections.get(connectionId);
    if (!connection) {
      return;
    }

    try {
      const topic = message.topic;
      
      // 调用Drupal API验证订阅权限
      const canSubscribe = await this.validateSubscription(connectionId, topic, message.payload);
      
      if (!canSubscribe) {
        this.sendMessage(connection.ws, {
          event: 'phx_reply',
          topic: topic,
          payload: { 
            status: 'error', 
            response: { error: 'Access denied' }
          },
          ref: message.ref
        });
        return;
      }

      // 添加订阅
      connection.subscriptions.add(topic);
      
      if (!this.subscriptions.has(topic)) {
        this.subscriptions.set(topic, new Set());
      }
      this.subscriptions.get(topic).add(connectionId);

      // 发送成功响应
      this.sendMessage(connection.ws, {
        event: 'phx_reply',
        topic: topic,
        payload: { 
          status: 'ok', 
          response: { subscribed: true }
        },
        ref: message.ref
      });

      this.logger.debug('Channel subscription added', {
        connectionId: connectionId,
        topic: topic,
        userId: connection.userId
      });

    } catch (error) {
      this.logger.error('Channel join failed:', error);
      this.sendMessage(connection.ws, {
        event: 'phx_reply',
        topic: message.topic,
        payload: { 
          status: 'error', 
          response: { error: 'Internal server error' }
        },
        ref: message.ref
      });
    }
  }

  /**
   * 处理频道离开
   */
  async handleChannelLeave(connectionId, message) {
    const connection = this.connections.get(connectionId);
    if (!connection) {
      return;
    }

    const topic = message.topic;
    
    // 移除订阅
    connection.subscriptions.delete(topic);
    
    if (this.subscriptions.has(topic)) {
      this.subscriptions.get(topic).delete(connectionId);
      
      // 如果没有订阅者了，删除主题
      if (this.subscriptions.get(topic).size === 0) {
        this.subscriptions.delete(topic);
      }
    }

    this.sendMessage(connection.ws, {
      event: 'phx_reply',
      topic: topic,
      payload: { 
        status: 'ok', 
        response: { unsubscribed: true }
      },
      ref: message.ref
    });

    this.logger.debug('Channel subscription removed', {
      connectionId: connectionId,
      topic: topic,
      userId: connection.userId
    });
  }

  /**
   * 验证订阅权限
   */
  async validateSubscription(connectionId, topic, payload) {
    try {
      // 获取连接信息以确定正确的API URL
      const connection = this.connections.get(connectionId);
      if (!connection) {
        return false;
      }

      const tenantId = connection.tenantId;
      const projectId = connection.projectId;
      
      // 使用项目级API端点（如果有项目ID），否则使用租户级端点
      let subscribeUrl;
      if (projectId) {
        subscribeUrl = `${this.options.drupalApiUrl}/api/v1/${tenantId}/projects/${projectId}/realtime/subscribe`;
      } else {
        subscribeUrl = `${this.options.drupalApiUrl}/api/v1/${tenantId}/realtime/subscribe`;
      }

      const response = await axios.post(subscribeUrl, {
        connection_id: connectionId,
        channel: topic,
        filters: payload.filters || {},
        event_types: payload.event_types || ['INSERT', 'UPDATE', 'DELETE']
      }, {
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${connection.accessToken}`,
          'X-API-Key': connection.apikey
        },
        timeout: 5000
      });

      return response.data.success;

    } catch (error) {
      this.logger.error('Subscription validation failed:', error);
      return false;
    }
  }

  /**
   * 处理数据库通知
   */
  handleDatabaseNotification(notification) {
    try {
      this.logger.info('Database notification received:', {
        channel: notification.channel,
        payload: notification.payload
      });
      
      const payload = JSON.parse(notification.payload);
      const channelName = this.getChannelNameFromPayload(payload);
      
      this.logger.info('Processing notification for channel:', {
        channelName,
        payloadTable: payload.table,
        payloadType: payload.type
      });
      
      if (!channelName) {
        this.logger.error('No channel name derived from payload:', payload);
        return;
      }

      // 获取订阅此频道的连接
      const subscribers = this.subscriptions.get(channelName);
      if (!subscribers || subscribers.size === 0) {
        this.logger.info('No subscribers for channel:', {
          channelName,
          totalChannels: this.subscriptions.size,
          totalConnections: this.connections.size
        });
        return;
      }

      this.logger.info('Broadcasting to subscribers:', {
        channelName,
        subscriberCount: subscribers.size
      });

      // 过滤并发送消息给订阅者
      subscribers.forEach(async (connectionId) => {
        const connection = this.connections.get(connectionId);
        if (connection && connection.ws.readyState === WebSocket.OPEN) {
          await this.filterAndSendMessage(connection, payload, channelName);
        }
      });

    } catch (error) {
      this.logger.error('Database notification handling failed:', error);
    }
  }

  /**
   * 从payload获取频道名
   */
  getChannelNameFromPayload(payload) {
    if (payload.table) {
      return `table:${payload.table}`;
    }
    
    if (payload.channel) {
      return payload.channel;
    }
    
    return null;
  }

  /**
   * 过滤并发送消息
   */
  async filterAndSendMessage(connection, payload, channelName) {
    try {
      const tenantId = connection.tenantId;
      const projectId = connection.projectId;
      
      // 使用项目级API端点（如果有项目ID），否则使用租户级端点
      let filterUrl;
      if (projectId) {
        filterUrl = `${this.options.drupalApiUrl}/api/v1/${tenantId}/projects/${projectId}/realtime/filter`;
      } else {
        filterUrl = `${this.options.drupalApiUrl}/api/v1/${tenantId}/realtime/filter`;
      }

      // 调用Drupal API进行消息过滤
      const response = await axios.post(filterUrl, {
        connection_id: connection.id,
        payload: payload
      }, {
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${connection.accessToken}`,
          'X-API-Key': connection.apikey
        },
        timeout: 5000
      });

      if (response.data.success && response.data.data) {
        this.sendMessage(connection.ws, {
          event: payload.type || 'message',
          topic: channelName,
          payload: response.data.data,
          ref: null
        });
      }

    } catch (error) {
      this.logger.error('Message filtering failed:', error);
    }
  }

  /**
   * 处理连接断开
   */
  handleDisconnection(connectionId) {
    const connection = this.connections.get(connectionId);
    if (!connection) {
      return;
    }

    // 清理订阅
    connection.subscriptions.forEach(topic => {
      if (this.subscriptions.has(topic)) {
        this.subscriptions.get(topic).delete(connectionId);
        if (this.subscriptions.get(topic).size === 0) {
          this.subscriptions.delete(topic);
        }
      }
    });

    // 移除连接
    this.connections.delete(connectionId);

    this.logger.info('WebSocket connection closed', {
      connectionId: connectionId,
      userId: connection.userId,
      duration: Date.now() - connection.connectedAt
    });
  }

  /**
   * 发送消息
   */
  sendMessage(ws, message) {
    if (ws.readyState === WebSocket.OPEN) {
      try {
        ws.send(JSON.stringify(message));
      } catch (error) {
        this.logger.error('Failed to send message:', error);
      }
    }
  }

  /**
   * 启动心跳检测
   */
  startHeartbeat() {
    this.heartbeatInterval = setInterval(() => {
      this.connections.forEach((connection, connectionId) => {
        const timeSinceLastHeartbeat = Date.now() - connection.lastHeartbeat;
        
        if (timeSinceLastHeartbeat > 60000) { // 60秒超时
          this.logger.info('Connection timeout, closing:', connectionId);
          connection.ws.close(1001, 'Heartbeat timeout');
          this.handleDisconnection(connectionId);
        }
      });
    }, 30000); // 每30秒检查一次
  }

  /**
   * 启动清理任务
   */
  startCleanupTasks() {
    this.cleanupInterval = setInterval(async () => {
      // 清理数据库中的过期连接
      try {
        await this.pgClient.query(`
          DELETE FROM baas_realtime_connections 
          WHERE last_heartbeat < $1 AND status = 'connected'
        `, [Math.floor(Date.now() / 1000) - 300]);
        
        // 清理过期消息
        await this.pgClient.query(`
          DELETE FROM baas_realtime_messages 
          WHERE expires_at < $1
        `, [Math.floor(Date.now() / 1000)]);
        
      } catch (error) {
        this.logger.error('Cleanup task failed:', error);
      }
    }, 300000); // 每5分钟清理一次
  }

  /**
   * 生成连接ID
   */
  generateConnectionId() {
    return `conn_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
  }

  /**
   * 生成Socket ID
   */
  generateSocketId() {
    return `sock_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`;
  }

  /**
   * 关闭服务器
   */
  async close() {
    if (this.heartbeatInterval) {
      clearInterval(this.heartbeatInterval);
    }
    
    if (this.cleanupInterval) {
      clearInterval(this.cleanupInterval);
    }

    if (this.wss) {
      this.wss.close();
    }

    if (this.pgClient) {
      await this.pgClient.end();
    }

    if (this.pgListener) {
      await this.pgListener.end();
    }

    if (this.redis) {
      this.redis.disconnect();
    }

    this.logger.info('Realtime server closed');
  }

  /**
   * 获取服务器统计信息
   */
  getStats() {
    return {
      connections: this.connections.size,
      subscriptions: this.subscriptions.size,
      uptime: process.uptime(),
      memory: process.memoryUsage()
    };
  }
}

module.exports = RealtimeServer;