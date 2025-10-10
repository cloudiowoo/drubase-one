/**
 * Error Handler - Centralized error handling and logging
 */
class ErrorHandler {
  
  /**
   * Express error handling middleware
   */
  static handle(error, req, res, next) {
    const executionId = req.get('X-BaaS-Execution-ID') || 'unknown';
    
    // Log the error
    console.error('Unhandled error:', {
      execution_id: executionId,
      error: error.message,
      stack: error.stack,
      url: req.url,
      method: req.method,
      ip: req.ip,
      user_agent: req.get('User-Agent')
    });

    // Determine error type and status code
    let statusCode = 500;
    let errorCode = 'INTERNAL_ERROR';
    let message = 'Internal server error';

    if (error.name === 'ValidationError') {
      statusCode = 400;
      errorCode = 'VALIDATION_ERROR';
      message = error.message;
    } else if (error.name === 'TimeoutError') {
      statusCode = 408;
      errorCode = 'TIMEOUT_ERROR';
      message = 'Request timeout';
    } else if (error.name === 'RateLimitError') {
      statusCode = 429;
      errorCode = 'RATE_LIMIT_ERROR';
      message = 'Rate limit exceeded';
    } else if (error.statusCode) {
      statusCode = error.statusCode;
      message = error.message;
    }

    // Don't expose internal errors in production
    if (process.env.NODE_ENV === 'production' && statusCode === 500) {
      message = 'Internal server error';
    }

    res.status(statusCode).json({
      status: 'error',
      error: message,
      code: errorCode,
      execution_id: executionId,
      timestamp: new Date().toISOString(),
      ...(process.env.NODE_ENV !== 'production' && { stack: error.stack })
    });
  }

  /**
   * Wraps async route handlers to catch errors
   */
  static asyncHandler(fn) {
    return (req, res, next) => {
      Promise.resolve(fn(req, res, next)).catch(next);
    };
  }

  /**
   * Creates standardized error objects
   */
  static createError(message, code = 'GENERIC_ERROR', statusCode = 500, details = null) {
    const error = new Error(message);
    error.code = code;
    error.statusCode = statusCode;
    error.details = details;
    return error;
  }

  /**
   * VM2 specific error handler
   */
  static handleVMError(error, executionId) {
    let errorType = 'EXECUTION_ERROR';
    let message = error.message;

    if (error.message.includes('timeout')) {
      errorType = 'TIMEOUT_ERROR';
      message = 'Function execution timed out';
    } else if (error.message.includes('memory')) {
      errorType = 'MEMORY_ERROR';
      message = 'Function exceeded memory limit';
    } else if (error.message.includes('syntax')) {
      errorType = 'SYNTAX_ERROR';
      message = 'Function syntax error';
    }

    return {
      status: 'error',
      error: message,
      code: errorType,
      execution_id: executionId,
      timestamp: new Date().toISOString()
    };
  }

  /**
   * Network error handler
   */
  static handleNetworkError(error, context) {
    let message = 'Network error';
    let code = 'NETWORK_ERROR';

    if (error.code === 'ECONNREFUSED') {
      message = 'Connection refused';
      code = 'CONNECTION_REFUSED';
    } else if (error.code === 'ENOTFOUND') {
      message = 'Host not found';
      code = 'HOST_NOT_FOUND';
    } else if (error.code === 'ETIMEDOUT') {
      message = 'Connection timeout';
      code = 'CONNECTION_TIMEOUT';
    }

    return {
      status: 'error',
      error: message,
      code,
      context,
      timestamp: new Date().toISOString()
    };
  }

  /**
   * Sanitizes error for client response
   */
  static sanitizeError(error) {
    const sanitized = {
      message: error.message || 'Unknown error',
      code: error.code || 'UNKNOWN_ERROR',
      timestamp: new Date().toISOString()
    };

    // Only include stack trace in development
    if (process.env.NODE_ENV !== 'production') {
      sanitized.stack = error.stack;
    }

    return sanitized;
  }

  /**
   * Logs error with context
   */
  static logError(error, context = {}) {
    console.error('Error occurred:', {
      error: error.message,
      code: error.code,
      stack: error.stack,
      context,
      timestamp: new Date().toISOString()
    });
  }

  /**
   * Process uncaught exceptions
   */
  static setupGlobalHandlers() {
    process.on('uncaughtException', (error) => {
      console.error('Uncaught Exception:', {
        error: error.message,
        stack: error.stack,
        timestamp: new Date().toISOString()
      });
      
      // In production, we might want to exit gracefully
      if (process.env.NODE_ENV === 'production') {
        process.exit(1);
      }
    });

    process.on('unhandledRejection', (reason, promise) => {
      console.error('Unhandled Rejection:', {
        reason: reason instanceof Error ? reason.message : reason,
        stack: reason instanceof Error ? reason.stack : undefined,
        promise,
        timestamp: new Date().toISOString()
      });
    });
  }
}

module.exports = ErrorHandler;