const axios = require('axios');

/**
 * BaaS Context - Provides project-scoped access to platform services
 */
class BaasContext {
  constructor(options = {}) {
    this.project_id = options.project_id;
    this.tenant_id = options.tenant_id;
    this.user_id = options.user_id;
    this.execution_id = options.execution_id;
    this.function_id = options.function_id;
    this.config = options.config || {};
    this.test_mode = options.test_mode || false;
    this.logger = options.logger;
    this.env = options.env || {};
    
    // Internal state
    this.logs = [];
    this.startTime = Date.now();
    
    // API base URL (should be configurable)
    this.apiBaseUrl = process.env.DRUPAL_API_URL || 'http://localhost';
  }

  /**
   * Project information
   */
  get project() {
    return {
      id: this.project_id,
      tenant_id: this.tenant_id
    };
  }

  /**
   * Tenant information
   */
  get tenant() {
    return {
      id: this.tenant_id
    };
  }

  /**
   * User information
   */
  get user() {
    return {
      id: this.user_id
    };
  }

  /**
   * Environment variables
   */
  get environment() {
    return this.env;
  }

  /**
   * Success response helper
   */
  success(data, message = 'Success') {
    return {
      success: true,
      data,
      message,
      execution_id: this.execution_id,
      timestamp: new Date().toISOString()
    };
  }

  /**
   * Error response helper
   */
  error(message, code = 'FUNCTION_ERROR', details = null) {
    return {
      success: false,
      error: message,
      code,
      details,
      execution_id: this.execution_id,
      timestamp: new Date().toISOString()
    };
  }

  /**
   * Logging utilities
   */
  get log() {
    return {
      info: (message, data = null) => this.addLog('info', message, data),
      error: (message, data = null) => this.addLog('error', message, data),
      warn: (message, data = null) => this.addLog('warn', message, data),
      debug: (message, data = null) => this.addLog('debug', message, data)
    };
  }

  /**
   * HTTP client for external API calls
   */
  get http() {
    return {
      get: async (url, options = {}) => {
        return this.makeHttpRequest('GET', url, null, options);
      },
      post: async (url, data = null, options = {}) => {
        return this.makeHttpRequest('POST', url, data, options);
      },
      put: async (url, data = null, options = {}) => {
        return this.makeHttpRequest('PUT', url, data, options);
      },
      delete: async (url, options = {}) => {
        return this.makeHttpRequest('DELETE', url, null, options);
      },
      fetch: async (url, options = {}) => {
        // 兼容 fetch API 风格的调用
        const method = options.method || 'GET';
        const data = options.body ? JSON.parse(options.body) : null;
        return this.makeHttpRequest(method, url, data, options);
      }
    };
  }

  /**
   * Database access interface
   */
  get db() {
    return {
      project: {
        entities: new Proxy({}, {
          get: (target, entityName) => this.createEntityInterface(entityName)
        })
      },
      raw: async (query, params = []) => {
        if (this.test_mode) {
          this.log.warn('Database raw query called in test mode', { query });
          return { rows: [], rowCount: 0 };
        }
        // TODO: Implement raw SQL query execution
        throw new Error('Raw database queries not yet implemented');
      }
    };
  }

  /**
   * JWT utilities
   */
  get jwt() {
    return {
      sign: async (payload, expiresIn = '1h') => {
        if (this.test_mode) {
          return 'test_jwt_token_' + Date.now();
        }
        // TODO: Implement JWT signing via Drupal API
        throw new Error('JWT signing not yet implemented');
      },
      verify: async (token) => {
        if (this.test_mode) {
          return { valid: true, payload: { test: true } };
        }
        // TODO: Implement JWT verification via Drupal API
        throw new Error('JWT verification not yet implemented');
      }
    };
  }

  /**
   * File operations interface
   */
  get files() {
    return {
      upload: async (file, options = {}) => {
        if (this.test_mode) {
          return { url: 'test://file-url', id: 'test_file_id' };
        }
        // TODO: Implement file upload via Drupal API
        throw new Error('File upload not yet implemented');
      },
      download: async (fileId) => {
        if (this.test_mode) {
          return { url: 'test://download-url' };
        }
        // TODO: Implement file download via Drupal API
        throw new Error('File download not yet implemented');
      },
      delete: async (fileId) => {
        if (this.test_mode) {
          return { success: true };
        }
        // TODO: Implement file deletion via Drupal API
        throw new Error('File deletion not yet implemented');
      }
    };
  }

  /**
   * Creates entity interface for database operations
   */
  createEntityInterface(entityName) {
    return {
      findMany: async (query = {}, options = {}) => {
        if (this.test_mode) {
          this.log.info(`Test mode: findMany called on ${entityName}`, { query, options });
          return [];
        }
        return this.makeEntityRequest('GET', entityName, query, options);
      },

      findOne: async (query = {}) => {
        if (this.test_mode) {
          this.log.info(`Test mode: findOne called on ${entityName}`, { query });
          return null;
        }
        
        const results = await this.makeEntityRequest('GET', entityName, query, { limit: 1 });
        return results.length > 0 ? results[0] : null;
      },

      create: async (data) => {
        if (this.test_mode) {
          this.log.info(`Test mode: create called on ${entityName}`, { data });
          return { id: 'test_' + Date.now(), ...data };
        }
        return this.makeEntityRequest('POST', entityName, data);
      },

      update: async (id, data) => {
        if (this.test_mode) {
          this.log.info(`Test mode: update called on ${entityName}`, { id, data });
          return { id, ...data };
        }
        return this.makeEntityRequest('PUT', `${entityName}/${id}`, data);
      },

      delete: async (id) => {
        if (this.test_mode) {
          this.log.info(`Test mode: delete called on ${entityName}`, { id });
          return { success: true };
        }
        return this.makeEntityRequest('DELETE', `${entityName}/${id}`);
      },

      count: async (query = {}) => {
        if (this.test_mode) {
          this.log.info(`Test mode: count called on ${entityName}`, { query });
          return 0;
        }
        // TODO: Implement count via API
        const results = await this.makeEntityRequest('GET', entityName, query);
        return results.length;
      }
    };
  }

  /**
   * Makes HTTP request with proper error handling
   */
  async makeHttpRequest(method, url, data = null, options = {}) {
    try {
      const config = {
        method,
        url,
        timeout: options.timeout || 30000,
        headers: {
          'Content-Type': 'application/json',
          'User-Agent': `BaaS-Function/${this.execution_id}`,
          ...options.headers
        }
      };

      if (data) {
        config.data = data;
      }

      if (options.params) {
        config.params = options.params;
      }

      this.log.info(`HTTP ${method} request`, { url, execution_id: this.execution_id });

      const response = await axios(config);
      
      this.log.info(`HTTP ${method} response`, { 
        url, 
        status: response.status,
        execution_id: this.execution_id 
      });

      return response.data;

    } catch (error) {
      this.log.error(`HTTP ${method} error`, { 
        url, 
        error: error.message,
        execution_id: this.execution_id 
      });

      if (error.response) {
        throw new Error(`HTTP ${error.response.status}: ${error.response.statusText}`);
      } else if (error.request) {
        throw new Error('Network error: No response received');
      } else {
        throw new Error(`Request error: ${error.message}`);
      }
    }
  }

  /**
   * Makes entity API request to Drupal backend
   */
  async makeEntityRequest(method, endpoint, data = null, options = {}) {
    const url = `${this.apiBaseUrl}/api/v1/${this.tenant_id}/projects/${this.project_id}/entities/${endpoint}`;
    
    const requestOptions = {
      headers: {
        'X-BaaS-Function-Execution': this.execution_id,
        'X-BaaS-Project-ID': this.project_id,
        ...options.headers
      },
      timeout: options.timeout || 30000
    };

    if (options.limit) {
      requestOptions.params = { limit: options.limit };
    }

    return this.makeHttpRequest(method, url, data, requestOptions);
  }

  /**
   * Adds log entry
   */
  addLog(level, message, data = null) {
    const logEntry = {
      level,
      message,
      data,
      timestamp: new Date().toISOString(),
      execution_id: this.execution_id
    };

    this.logs.push(logEntry);

    // Also log to system logger
    if (this.logger) {
      this.logger[level] ? this.logger[level](message, data) : this.logger.info(message, data);
    }
  }

  /**
   * Gets all logs for this execution
   */
  getLogs() {
    return this.logs;
  }

  /**
   * Gets execution metrics
   */
  getMetrics() {
    return {
      execution_id: this.execution_id,
      start_time: this.startTime,
      duration_ms: Date.now() - this.startTime,
      log_count: this.logs.length,
      project_id: this.project_id,
      tenant_id: this.tenant_id
    };
  }

  /**
   * Serializes context for VM transmission
   */
  serialize() {
    return {
      project_id: this.project_id,
      tenant_id: this.tenant_id,
      user_id: this.user_id,
      execution_id: this.execution_id,
      function_id: this.function_id,
      test_mode: this.test_mode,
      env: this.env,
      config: this.config
    };
  }

  /**
   * Utility functions
   */
  get utils() {
    return {
      generateId: () => require('uuid').v4(),
      timestamp: () => new Date().toISOString(),
      sleep: (ms) => new Promise(resolve => setTimeout(resolve, ms)),
      hash: (data) => require('crypto').createHash('sha256').update(String(data)).digest('hex')
    };
  }
}

module.exports = BaasContext;