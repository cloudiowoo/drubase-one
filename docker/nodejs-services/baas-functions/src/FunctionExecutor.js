const { VM } = require('vm2');
const vm = require('vm');
const { v4: uuidv4 } = require('uuid');

/**
 * Function Executor - Executes user functions in a secure VM2 sandbox
 */
class FunctionExecutor {
  constructor(logger) {
    this.logger = logger;
    this.defaultTimeout = 30000; // 30 seconds
    this.maxTimeout = 300000; // 5 minutes
    this.defaultMemoryLimit = 128; // MB
    this.maxMemoryLimit = 512; // MB
  }

  /**
   * Executes a function in a secure sandbox
   * 
   * @param {string} code - Function code to execute
   * @param {object} requestData - Request data to pass to function
   * @param {BaasContext} baasContext - BaaS context object
   * @param {object} config - Function configuration
   * @returns {object} Execution result
   */
  async execute(code, requestData, baasContext, config = {}) {
    const executionId = baasContext.execution_id;
    const startTime = process.hrtime.bigint();
    const memoryBefore = process.memoryUsage();

    try {
      // Validate and prepare configuration
      const execConfig = this.prepareConfig(config);
      
      // Create secure VM context
      const vm = this.createSecureVM(execConfig);
      
      // Prepare execution environment
      const environment = this.prepareEnvironment(requestData, baasContext);
      
      // Execute function with timeout
      const result = await this.executeWithTimeout(vm, code, environment, execConfig.timeout);
      
      // Calculate execution metrics
      const endTime = process.hrtime.bigint();
      const executionTime = Number(endTime - startTime) / 1000000; // Convert to milliseconds
      const memoryAfter = process.memoryUsage();
      const memoryUsed = Math.max(0, memoryAfter.heapUsed - memoryBefore.heapUsed) / 1024 / 1024; // MB

      this.logger.info('Function execution completed', {
        execution_id: executionId,
        execution_time_ms: executionTime,
        memory_used_mb: memoryUsed,
        status: result.status
      });

      return {
        status: result.status || 'success',
        data: result.data,
        error: result.error,
        execution_time_ms: Math.round(executionTime),
        memory_used_mb: Math.round(memoryUsed * 100) / 100,
        logs: baasContext.getLogs(),
        execution_id: executionId
      };

    } catch (error) {
      const endTime = process.hrtime.bigint();
      const executionTime = Number(endTime - startTime) / 1000000;

      this.logger.error('Function execution error', {
        execution_id: executionId,
        error: error.message,
        execution_time_ms: executionTime,
        stack: error.stack
      });

      return {
        status: 'error',
        data: null,
        error: error.message,
        execution_time_ms: Math.round(executionTime),
        memory_used_mb: 0,
        logs: baasContext.getLogs(),
        execution_id: executionId,
        error_type: error.name || 'ExecutionError'
      };
    }
  }

  /**
   * Creates a secure VM2 sandbox
   */
  createSecureVM(config) {
    return new VM({
      timeout: config.timeout,
      sandbox: {
        console: {
          log: (...args) => this.sandboxConsole('log', args),
          error: (...args) => this.sandboxConsole('error', args),
          warn: (...args) => this.sandboxConsole('warn', args),
          info: (...args) => this.sandboxConsole('info', args)
        },
        setTimeout,
        clearTimeout,
        setInterval,
        clearInterval,
        JSON,
        Math,
        Date,
        String,
        Number,
        Boolean,
        Array,
        Object,
        RegExp,
        Error,
        TypeError,
        ReferenceError,
        SyntaxError,
        Promise,
        Buffer: {
          from: Buffer.from.bind(Buffer),
          alloc: Buffer.alloc.bind(Buffer),
          isBuffer: Buffer.isBuffer.bind(Buffer)
        },
        // Add fetch API for HTTP requests
        fetch: this.createFetch()
      },
      eval: false,
      wasm: false,
      fixAsync: false,
      allowAsync: true
    });
  }

  /**
   * Prepares the execution environment
   */
  prepareEnvironment(requestData, baasContext) {
    return {
      req: {
        body: requestData.body || requestData,
        headers: requestData.headers || {},
        query: requestData.query || {},
        method: requestData.method || 'POST',
        url: requestData.url || '',
        ip: requestData.ip || '127.0.0.1'
      },
      context: baasContext
    };
  }

  /**
   * Executes function with timeout protection
   */
  async executeWithTimeout(vmInstance, code, environment, timeout) {
    return new Promise(async (resolve, reject) => {
      const timer = setTimeout(() => {
        reject(new Error(`Function execution timed out after ${timeout}ms`));
      }, timeout);

      try {
        // Create secure execution context
        const context = this.createExecutionContext(environment);
        
        // Check if the code contains async patterns
        const isAsync = this.detectAsyncCode(code);
        
        this.logger.info('Code analysis', {
          is_async: isAsync,
          code_preview: code.substring(0, 100) + '...'
        });
        
        let result;
        
        if (isAsync) {
          // Use Node.js built-in vm for async support
          this.logger.info('Using async execution path');
          result = await this.executeAsyncFunction(code, context, timeout);
        } else {
          // Use VM2 for sync functions (more secure)
          this.logger.info('Using sync execution path');
          result = this.executeSyncFunction(vmInstance, code, context);
        }

        clearTimeout(timer);

        // Handle both sync and async results
        if (result && typeof result.then === 'function') {
          try {
            const data = await result;
            resolve({ status: 'success', data });
          } catch (error) {
            resolve({ status: 'error', error: error.message });
          }
        } else {
          resolve({ status: 'success', data: result });
        }

      } catch (error) {
        clearTimeout(timer);
        reject(error);
      }
    });
  }

  /**
   * Wraps user code in standardized function format
   */
  wrapUserCode(code) {
    // Check if code exports default function
    if (code.includes('export default')) {
      return `
        // Execute the exported handler function
        (() => {
          try {
            // Extract the function from export default
            const handler = (function() {
              ${code.replace(/export\s+default\s+/, 'return ')}
            })();
            
            if (typeof handler === 'function') {
              // Call the handler and return the result (may be a Promise)
              return handler(request, context);
            } else {
              throw new Error('Exported default is not a function');
            }
          } catch (error) {
            throw error;
          }
        })();
      `;
    } else {
      // Assume it's a simple function body
      return `
        (() => {
          try {
            ${code}
          } catch (error) {
            throw error;
          }
        })();
      `;
    }
  }

  /**
   * Detects if code contains async patterns
   */
  detectAsyncCode(code) {
    const asyncPatterns = [
      /\basync\s+function/,
      /\basync\s*\(/,
      /\basync\s*\w+\s*=>/,
      /\bawait\s+/,
      /\.then\s*\(/,
      /\.catch\s*\(/,
      /new\s+Promise\s*\(/
    ];
    
    return asyncPatterns.some(pattern => pattern.test(code));
  }

  /**
   * Creates execution context with all necessary globals
   */
  createExecutionContext(environment) {
    return {
      // Core JavaScript globals
      console: {
        log: (...args) => this.sandboxConsole('log', args),
        error: (...args) => this.sandboxConsole('error', args),
        warn: (...args) => this.sandboxConsole('warn', args),
        info: (...args) => this.sandboxConsole('info', args)
      },
      setTimeout,
      clearTimeout,
      setInterval,
      clearInterval,
      JSON,
      Math,
      Date,
      String,
      Number,
      Boolean,
      Array,
      Object,
      RegExp,
      Error,
      TypeError,
      ReferenceError,
      SyntaxError,
      Promise,
      Buffer: {
        from: Buffer.from.bind(Buffer),
        alloc: Buffer.alloc.bind(Buffer),
        isBuffer: Buffer.isBuffer.bind(Buffer)
      },
      // Add fetch API for HTTP requests
      fetch: this.createFetch(),
      
      // Request and context data
      req: environment.req,
      ctx: this.createContextObject(environment.context),
      
      // Global scope protection
      global: undefined,
      process: undefined,
      require: undefined,
      module: undefined,
      exports: undefined,
      __dirname: undefined,
      __filename: undefined
    };
  }

  /**
   * Creates the context object for user functions
   */
  createContextObject(contextData) {
    const serialized = contextData.serialize();
    return {
      project: { id: serialized.project_id },
      tenant: { id: serialized.tenant_id },
      user: { id: serialized.user_id },
      execution_id: serialized.execution_id,
      env: serialized.env || {},
      
      // Response helpers
      success: function(data, headers = {}) {
        return {
          status: 'success',
          data: data,
          headers: headers
        };
      },
      error: function(message, code = 500) {
        return {
          status: 'error',
          error: message,
          code: code
        };
      },
      
      // Logging
      log: {
        info: (message, data) => console.log('[INFO]', message, data ? JSON.stringify(data) : ''),
        error: (message, data) => console.error('[ERROR]', message, data ? JSON.stringify(data) : ''),
        warn: (message, data) => console.warn('[WARN]', message, data ? JSON.stringify(data) : ''),
        debug: (message, data) => console.log('[DEBUG]', message, data ? JSON.stringify(data) : '')
      },
      
      // HTTP client (placeholder)
      http: {
        get: async (url, options) => {
          throw new Error('HTTP client not implemented in sandbox');
        },
        post: async (url, data, options) => {
          throw new Error('HTTP client not implemented in sandbox');
        }
      },
      
      // Database access (placeholder)
      db: {
        project: {
          entities: new Proxy({}, {
            get: (target, entityName) => ({
              findMany: async (query, options) => {
                throw new Error('Database access not implemented in sandbox');
              },
              findOne: async (query) => {
                throw new Error('Database access not implemented in sandbox');
              },
              create: async (data) => {
                throw new Error('Database access not implemented in sandbox');
              },
              update: async (id, data) => {
                throw new Error('Database access not implemented in sandbox');
              },
              delete: async (id) => {
                throw new Error('Database access not implemented in sandbox');
              }
            })
          })
        }
      },
      
      // JWT utilities (placeholder)
      jwt: {
        sign: (payload, expiresIn) => {
          throw new Error('JWT signing not implemented in sandbox');
        },
        verify: (token) => {
          throw new Error('JWT verification not implemented in sandbox');
        }
      }
    };
  }

  /**
   * Executes async function using Node.js built-in vm
   */
  async executeAsyncFunction(code, context, timeout) {
    const wrappedCode = this.wrapAsyncUserCode(code);
    
    // Create a new context for execution
    const vmContext = vm.createContext(context);
    
    try {
      // Run the code and get the result
      const result = vm.runInContext(wrappedCode, vmContext, {
        timeout: timeout,
        displayErrors: true
      });
      
      return result;
    } catch (error) {
      throw error;
    }
  }

  /**
   * Executes sync function using VM2
   */
  executeSyncFunction(vmInstance, code, context) {
    // Set basic context in VM2 sandbox
    vmInstance.run(`
      const request = ${JSON.stringify(context.request)};
      const context = ${JSON.stringify(context.context)};
    `);
    
    const wrappedCode = this.wrapSyncUserCode(code);
    return vmInstance.run(wrappedCode);
  }

  /**
   * Wraps user code for async execution
   */
  wrapAsyncUserCode(code) {
    if (code.includes('export default')) {
      return `
        (async () => {
          try {
            // Extract the function from export default
            const handler = (function() {
              ${code.replace(/export\s+default\s+/, 'return ')}
            })();
            
            if (typeof handler === 'function') {
              return await handler(req, ctx);
            } else {
              throw new Error('Exported default is not a function');
            }
          } catch (error) {
            throw error;
          }
        })();
      `;
    } else {
      return `
        (async () => {
          try {
            ${code}
          } catch (error) {
            throw error;
          }
        })();
      `;
    }
  }

  /**
   * Wraps user code for sync execution
   */
  wrapSyncUserCode(code) {
    if (code.includes('export default')) {
      return `
        (() => {
          try {
            const handler = (function() {
              ${code.replace(/export\s+default\s+/, 'return ')}
            })();
            
            if (typeof handler === 'function') {
              return handler(request, context);
            } else {
              throw new Error('Exported default is not a function');
            }
          } catch (error) {
            throw error;
          }
        })();
      `;
    } else {
      return `
        (() => {
          try {
            ${code}
          } catch (error) {
            throw error;
          }
        })();
      `;
    }
  }

  /**
   * Creates a context proxy for the sandbox
   */
  createContextProxy() {
    return `(contextData) => ({
      project: { id: contextData.project_id },
      tenant: { id: contextData.tenant_id },
      user: { id: contextData.user_id },
      execution_id: contextData.execution_id,
      env: contextData.env || {},
      
      // Response helpers
      success: (data) => ({ status: 'success', data }),
      error: (message, code) => ({ status: 'error', error: message, code }),
      
      // Logging
      log: {
        info: (message, data) => console.log('[INFO]', message, data ? JSON.stringify(data) : ''),
        error: (message, data) => console.error('[ERROR]', message, data ? JSON.stringify(data) : ''),
        warn: (message, data) => console.warn('[WARN]', message, data ? JSON.stringify(data) : ''),
        debug: (message, data) => console.log('[DEBUG]', message, data ? JSON.stringify(data) : '')
      },
      
      // HTTP client (simplified)
      http: {
        get: async (url, options) => {
          throw new Error('HTTP client not implemented in sandbox');
        },
        post: async (url, data, options) => {
          throw new Error('HTTP client not implemented in sandbox');
        }
      },
      
      // Database access (placeholder)
      db: {
        project: {
          entities: new Proxy({}, {
            get: (target, entityName) => ({
              findMany: async (query, options) => {
                throw new Error('Database access not implemented in sandbox');
              },
              findOne: async (query) => {
                throw new Error('Database access not implemented in sandbox');
              },
              create: async (data) => {
                throw new Error('Database access not implemented in sandbox');
              },
              update: async (id, data) => {
                throw new Error('Database access not implemented in sandbox');
              },
              delete: async (id) => {
                throw new Error('Database access not implemented in sandbox');
              }
            })
          })
        }
      },
      
      // JWT utilities (placeholder)
      jwt: {
        sign: (payload, expiresIn) => {
          throw new Error('JWT signing not implemented in sandbox');
        },
        verify: (token) => {
          throw new Error('JWT verification not implemented in sandbox');
        }
      }
    })`;
  }

  /**
   * Creates a fetch API implementation for the sandbox
   */
  createFetch() {
    const https = require('https');
    const http = require('http');
    const url = require('url');
    
    return async function fetch(resource, options = {}) {
      const parsedUrl = url.parse(resource);
      const isHttps = parsedUrl.protocol === 'https:';
      const client = isHttps ? https : http;
      
      const requestOptions = {
        hostname: parsedUrl.hostname,
        port: parsedUrl.port || (isHttps ? 443 : 80),
        path: parsedUrl.path,
        method: options.method || 'GET',
        headers: options.headers || {}
      };
      
      return new Promise((resolve, reject) => {
        const req = client.request(requestOptions, (res) => {
          let data = '';
          res.on('data', (chunk) => data += chunk);
          res.on('end', () => {
            const response = {
              ok: res.statusCode >= 200 && res.statusCode < 300,
              status: res.statusCode,
              statusText: res.statusMessage,
              headers: res.headers,
              json: async () => {
                try {
                  return JSON.parse(data);
                } catch (error) {
                  throw new Error('Invalid JSON response');
                }
              },
              text: async () => data
            };
            resolve(response);
          });
        });
        
        req.on('error', reject);
        
        if (options.body) {
          req.write(options.body);
        }
        
        req.end();
        
        // Add timeout
        req.setTimeout(options.timeout || 30000, () => {
          req.destroy();
          reject(new Error('Request timeout'));
        });
      });
    };
  }

  /**
   * Sandbox console implementation
   */
  sandboxConsole(level, args) {
    const message = args.map(arg => 
      typeof arg === 'object' ? JSON.stringify(arg) : String(arg)
    ).join(' ');
    
    this.logger[level] ? this.logger[level](message) : this.logger.info(message);
  }

  /**
   * Prepares execution configuration
   */
  prepareConfig(config) {
    return {
      timeout: Math.min(config.timeout || this.defaultTimeout, this.maxTimeout),
      memory_limit: Math.min(config.memory_limit || this.defaultMemoryLimit, this.maxMemoryLimit),
      env_required: config.env_required || []
    };
  }

  /**
   * Validates function code
   */
  async validateCode(code) {
    const validation = {
      is_valid: true,
      errors: [],
      warnings: [],
      score: 100
    };

    try {
      // Basic syntax check
      if (!code || code.trim().length === 0) {
        validation.errors.push('Function code cannot be empty');
        validation.is_valid = false;
        validation.score = 0;
        return validation;
      }

      // Check for dangerous patterns
      const dangerousPatterns = [
        { pattern: /require\s*\(/i, message: 'require() is not allowed' },
        { pattern: /import\s+.*from/i, message: 'import statements are restricted' },
        { pattern: /eval\s*\(/i, message: 'eval() is prohibited' },
        { pattern: /Function\s*\(/i, message: 'Function constructor is not allowed' },
        { pattern: /process\./i, message: 'Process access is not allowed' },
        { pattern: /global\./i, message: 'Global object access is restricted' }
      ];

      dangerousPatterns.forEach(({ pattern, message }) => {
        if (pattern.test(code)) {
          validation.errors.push(message);
          validation.score -= 20;
        }
      });

      // Check for good practices
      if (code.includes('export default')) {
        validation.score += 10;
      } else {
        validation.warnings.push('Consider using export default function pattern');
        validation.score -= 5;
      }

      if (code.includes('try') && code.includes('catch')) {
        validation.score += 10;
      } else {
        validation.warnings.push('Consider adding error handling with try-catch');
        validation.score -= 5;
      }

      // Test compilation
      try {
        const vm = new VM({ timeout: 1000 });
        vm.run(`(function() { ${code} })`);
      } catch (error) {
        validation.errors.push(`Syntax error: ${error.message}`);
        validation.score -= 30;
      }

      validation.is_valid = validation.errors.length === 0;
      validation.score = Math.max(0, Math.min(100, validation.score));

    } catch (error) {
      validation.errors.push(`Validation error: ${error.message}`);
      validation.is_valid = false;
      validation.score = 0;
    }

    return validation;
  }
}

module.exports = FunctionExecutor;