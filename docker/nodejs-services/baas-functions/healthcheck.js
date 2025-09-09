#!/usr/bin/env node

/**
 * Docker Health Check Script
 */

const http = require('http');

// Simple health check using localhost and IPv4
const req = http.get({
  hostname: 'localhost',
  port: process.env.PORT || 3001,
  path: '/health',
  timeout: 3000,
  family: 4
}, (res) => {
  if (res.statusCode === 200) {
    console.log('✅ Service is healthy');
    process.exit(0);
  } else {
    console.error(`❌ Service unhealthy: status ${res.statusCode}`);
    process.exit(1);
  }
});

req.on('error', (error) => {
  console.error(`❌ Health check failed: ${error.message}`);
  process.exit(1);
});

req.on('timeout', () => {
  console.error('❌ Health check timeout');
  req.destroy();
  process.exit(1);
});