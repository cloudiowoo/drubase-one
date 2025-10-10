const express = require('express');
const cors = require('cors');
const helmet = require('helmet');
const compression = require('compression');
const rateLimit = require('express-rate-limit');
const winston = require('winston');
require('dotenv').config();

const FunctionExecutor = require('./src/FunctionExecutor');
const BaasContext = require('./src/BaasContext');
const HealthCheck = require('./src/HealthCheck');
const ErrorHandler = require('./src/ErrorHandler');
const RealtimeServer = require('./src/RealtimeServer');

// Configure logger
const logger = winston.createLogger({
  level: process.env.LOG_LEVEL || 'info',
  format: winston.format.combine(
    winston.format.timestamp(),
    winston.format.errors({ stack: true }),
    winston.format.json()
  ),
  transports: [
    new winston.transports.Console({
      format: winston.format.combine(
        winston.format.colorize(),
        winston.format.simple()
      )
    }),
    new winston.transports.File({ 
      filename: 'logs/error.log', 
      level: 'error' 
    }),
    new winston.transports.File({ 
      filename: 'logs/combined.log' 
    })
  ]
});

// Create Express app
const app = express();
const port = process.env.PORT || 3001;

// Security middleware
app.use(helmet({
  contentSecurityPolicy: false, // Disable for API service
}));

// CORS configuration
app.use(cors({
  origin: process.env.ALLOWED_ORIGINS?.split(',') || ['http://localhost'],
  credentials: true,
  methods: ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
  allowedHeaders: ['Content-Type', 'Authorization', 'X-BaaS-Project-ID', 'X-BaaS-Execution-ID']
}));

// Compression and parsing
app.use(compression());
app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true, limit: '10mb' }));

// Rate limiting
const limiter = rateLimit({
  windowMs: 60 * 1000, // 1 minute
  max: process.env.NODE_ENV === 'production' ? 100 : 1000, // requests per windowMs
  message: {
    error: 'Too many requests, please try again later',
    code: 'RATE_LIMIT_EXCEEDED'
  }
});
app.use(limiter);

// Request logging
app.use((req, res, next) => {
  const start = Date.now();
  logger.info(`${req.method} ${req.path}`, {
    ip: req.ip,
    userAgent: req.get('User-Agent'),
    executionId: req.get('X-BaaS-Execution-ID')
  });
  
  res.on('finish', () => {
    const duration = Date.now() - start;
    logger.info(`${req.method} ${req.path} - ${res.statusCode}`, {
      duration,
      executionId: req.get('X-BaaS-Execution-ID')
    });
  });
  
  next();
});

// Initialize services
const functionExecutor = new FunctionExecutor(logger);
const healthCheck = new HealthCheck();

// Initialize realtime server
let realtimeServer = null;
if (process.env.ENABLE_REALTIME !== 'false') {
  realtimeServer = new RealtimeServer(logger, {
    port: parseInt(process.env.REALTIME_PORT) || 4000,
    drupalApiUrl: process.env.DRUPAL_API_URL || 'http://localhost/api',
    jwtSecret: process.env.JWT_SECRET,
    pgConnectionString: process.env.DATABASE_URL,
    redisUrl: process.env.REDIS_URL
  });
}

// Health check endpoint
app.get('/health', (req, res) => {
  const health = healthCheck.getStatus();
  
  // Add realtime server stats if available
  if (realtimeServer) {
    health.realtime = realtimeServer.getStats();
  }
  
  res.status(health.status === 'healthy' ? 200 : 503).json(health);
});

// Function execution endpoint
app.post('/execute', async (req, res) => {
  try {
    const {
      execution_id,
      function_id,
      function_name,
      code,
      config = {},
      request: requestData = {},
      context: contextData = {},
      env = {}
    } = req.body;

    if (!execution_id || !function_id || !code) {
      return res.status(400).json({
        status: 'error',
        error: 'Missing required fields: execution_id, function_id, code',
        code: 'MISSING_FIELDS'
      });
    }

    logger.info('Executing function', {
      execution_id,
      function_id,
      function_name,
      project_id: contextData.project_id,
      env_vars_count: Object.keys(env).length
    });

    // Create BaaS Context
    const baasContext = new BaasContext({
      project_id: contextData.project_id,
      tenant_id: contextData.tenant_id,
      user_id: contextData.user_id,
      execution_id,
      function_id,
      config,
      env,
      logger
    });

    // Execute function
    const result = await functionExecutor.execute(code, requestData, baasContext, config);

    logger.info('Function executed successfully', {
      execution_id,
      status: result.status,
      execution_time: result.execution_time_ms
    });

    res.json(result);

  } catch (error) {
    logger.error('Function execution failed', {
      execution_id: req.body.execution_id,
      error: error.message,
      stack: error.stack
    });

    res.status(500).json({
      status: 'error',
      error: error.message,
      code: 'EXECUTION_ERROR',
      execution_id: req.body.execution_id
    });
  }
});

// Function testing endpoint
app.post('/test', async (req, res) => {
  try {
    const {
      execution_id,
      code,
      config = {},
      request: requestData = {},
      context: contextData = {},
      test_mode = true
    } = req.body;

    if (!execution_id || !code) {
      return res.status(400).json({
        status: 'error',
        error: 'Missing required fields: execution_id, code',
        code: 'MISSING_FIELDS'
      });
    }

    logger.info('Testing function', {
      execution_id,
      project_id: contextData.project_id,
      test_mode
    });

    // Create test context
    const baasContext = new BaasContext({
      project_id: contextData.project_id || 'test_project',
      tenant_id: contextData.tenant_id || 'test_tenant',
      user_id: contextData.user_id || 'test_user',
      execution_id,
      function_id: 'test_function',
      config,
      test_mode,
      logger
    });

    // Execute function in test mode
    const result = await functionExecutor.execute(code, requestData, baasContext, {
      ...config,
      timeout: Math.min(config.timeout || 10000, 30000), // Max 30s for tests
      memory_limit: Math.min(config.memory_limit || 128, 256) // Max 256MB for tests
    });

    // Add validation information for test mode
    result.validation = await functionExecutor.validateCode(code);

    logger.info('Function test completed', {
      execution_id,
      status: result.status,
      validation_score: result.validation?.score
    });

    res.json(result);

  } catch (error) {
    logger.error('Function test failed', {
      execution_id: req.body.execution_id,
      error: error.message,
      stack: error.stack
    });

    res.status(500).json({
      status: 'error',
      error: error.message,
      code: 'TEST_ERROR',
      execution_id: req.body.execution_id
    });
  }
});

// Function validation endpoint
app.post('/validate', async (req, res) => {
  try {
    const { code, config = {} } = req.body;

    if (!code) {
      return res.status(400).json({
        status: 'error',
        error: 'Missing required field: code',
        code: 'MISSING_CODE'
      });
    }

    const validation = await functionExecutor.validateCode(code);
    
    res.json({
      status: 'success',
      validation
    });

  } catch (error) {
    logger.error('Code validation failed', {
      error: error.message,
      stack: error.stack
    });

    res.status(500).json({
      status: 'error',
      error: error.message,
      code: 'VALIDATION_ERROR'
    });
  }
});

// Service info endpoint
app.get('/info', (req, res) => {
  res.json({
    service: 'BaaS Functions Service',
    version: '1.0.0',
    node_version: process.version,
    uptime: process.uptime(),
    environment: process.env.NODE_ENV || 'development',
    memory_usage: process.memoryUsage(),
    timestamp: new Date().toISOString()
  });
});

// Error handling middleware
app.use(ErrorHandler.handle);

// 404 handler
app.use('*', (req, res) => {
  res.status(404).json({
    status: 'error',
    error: 'Endpoint not found',
    code: 'NOT_FOUND',
    path: req.originalUrl
  });
});

// Graceful shutdown
process.on('SIGTERM', async () => {
  logger.info('SIGTERM received, shutting down gracefully');
  
  if (realtimeServer) {
    try {
      await realtimeServer.close();
      logger.info('Realtime server closed');
    } catch (error) {
      logger.error('Error closing realtime server:', error);
    }
  }
  
  server.close(() => {
    logger.info('Process terminated');
    process.exit(0);
  });
});

process.on('SIGINT', async () => {
  logger.info('SIGINT received, shutting down gracefully');
  
  if (realtimeServer) {
    try {
      await realtimeServer.close();
      logger.info('Realtime server closed');
    } catch (error) {
      logger.error('Error closing realtime server:', error);
    }
  }
  
  server.close(() => {
    logger.info('Process terminated');
    process.exit(0);
  });
});

// Start server
const server = app.listen(port, '0.0.0.0', async () => {
  logger.info(`BaaS Functions Service running on port ${port}`, {
    environment: process.env.NODE_ENV || 'development',
    node_version: process.version
  });
  
  // Initialize realtime server
  if (realtimeServer) {
    try {
      await realtimeServer.init();
      logger.info('Realtime server initialized successfully');
    } catch (error) {
      logger.error('Failed to initialize realtime server:', error);
    }
  }
});

module.exports = app;