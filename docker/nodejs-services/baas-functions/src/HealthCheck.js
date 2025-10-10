/**
 * Health Check Service - Monitors service health and status
 */
class HealthCheck {
  constructor() {
    this.startTime = Date.now();
    this.checks = new Map();
    this.lastCheck = null;
  }

  /**
   * Gets comprehensive health status
   */
  getStatus() {
    const now = Date.now();
    const uptime = now - this.startTime;
    
    const health = {
      status: 'healthy',
      timestamp: new Date().toISOString(),
      uptime: Math.floor(uptime / 1000), // seconds
      version: '1.0.0',
      service: 'BaaS Functions Service',
      checks: {},
      system: this.getSystemMetrics()
    };

    // Memory check
    const memUsage = process.memoryUsage();
    const memUsedMB = memUsage.heapUsed / 1024 / 1024;
    const memLimitMB = 512; // Configurable limit
    
    health.checks.memory = {
      status: memUsedMB < memLimitMB * 0.9 ? 'healthy' : 'warning',
      used_mb: Math.round(memUsedMB * 100) / 100,
      limit_mb: memLimitMB,
      usage_percent: Math.round((memUsedMB / memLimitMB) * 100)
    };

    if (health.checks.memory.status !== 'healthy') {
      health.status = 'warning';
    }

    // CPU check (simplified)
    const loadAverage = this.getLoadAverage();
    health.checks.cpu = {
      status: loadAverage < 0.8 ? 'healthy' : 'warning',
      load_average: loadAverage
    };

    if (health.checks.cpu.status !== 'healthy') {
      health.status = 'warning';
    }

    // VM2 availability check
    try {
      const { VM } = require('vm2');
      new VM({ timeout: 100 }).run('1 + 1');
      health.checks.vm2 = {
        status: 'healthy',
        message: 'VM2 sandbox available'
      };
    } catch (error) {
      health.checks.vm2 = {
        status: 'error',
        message: `VM2 error: ${error.message}`
      };
      health.status = 'unhealthy';
    }

    // Disk space check (if available)
    try {
      const fs = require('fs');
      const stats = fs.statSync('.');
      health.checks.disk = {
        status: 'healthy',
        message: 'Disk access available'
      };
    } catch (error) {
      health.checks.disk = {
        status: 'warning',
        message: `Disk check failed: ${error.message}`
      };
    }

    this.lastCheck = health;
    return health;
  }

  /**
   * Gets basic system metrics
   */
  getSystemMetrics() {
    const memUsage = process.memoryUsage();
    
    return {
      node_version: process.version,
      platform: process.platform,
      arch: process.arch,
      pid: process.pid,
      memory: {
        rss: Math.round(memUsage.rss / 1024 / 1024 * 100) / 100,
        heap_total: Math.round(memUsage.heapTotal / 1024 / 1024 * 100) / 100,
        heap_used: Math.round(memUsage.heapUsed / 1024 / 1024 * 100) / 100,
        external: Math.round(memUsage.external / 1024 / 1024 * 100) / 100
      },
      uptime: process.uptime()
    };
  }

  /**
   * Gets approximate load average
   */
  getLoadAverage() {
    try {
      const os = require('os');
      const loadavg = os.loadavg();
      return loadavg[0]; // 1 minute average
    } catch (error) {
      return 0;
    }
  }

  /**
   * Adds a custom health check
   */
  addCheck(name, checkFunction) {
    this.checks.set(name, checkFunction);
  }

  /**
   * Removes a custom health check
   */
  removeCheck(name) {
    this.checks.delete(name);
  }

  /**
   * Runs all custom health checks
   */
  async runCustomChecks() {
    const results = {};
    
    for (const [name, checkFn] of this.checks) {
      try {
        const result = await checkFn();
        results[name] = {
          status: 'healthy',
          ...result
        };
      } catch (error) {
        results[name] = {
          status: 'error',
          message: error.message
        };
      }
    }
    
    return results;
  }

  /**
   * Gets the last health check result
   */
  getLastCheck() {
    return this.lastCheck;
  }

  /**
   * Checks if service is ready to accept requests
   */
  isReady() {
    const status = this.getStatus();
    return status.status === 'healthy' || status.status === 'warning';
  }

  /**
   * Checks if service is alive (basic liveness probe)
   */
  isAlive() {
    try {
      return process.uptime() > 0;
    } catch (error) {
      return false;
    }
  }
}

module.exports = HealthCheck;