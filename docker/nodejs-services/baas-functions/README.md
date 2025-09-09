# BaaS Functions Service

Node.js service for executing user-defined functions in a secure VM2 sandbox environment.

## Features

- **Secure Execution**: VM2 sandboxing for safe code execution
- **BaaS Context API**: Comprehensive context object for platform integration
- **Project Isolation**: Functions are scoped to specific projects
- **Performance Monitoring**: Execution time and memory usage tracking
- **Comprehensive Logging**: Structured logging with execution tracing
- **Health Monitoring**: Built-in health checks and metrics
- **Docker Support**: Full containerization with health checks

## Quick Start

### Development

```bash
# Install dependencies
npm install

# Copy environment file
cp .env.example .env

# Start development server
npm run dev
```

### Production with Docker

```bash
# Build and run with Docker Compose
docker-compose up -d

# Or build and run manually
docker build -t baas-functions .
docker run -p 3001:3001 baas-functions
```

## API Endpoints

### Function Execution
- `POST /execute` - Execute a function
- `POST /test` - Test a function in safe mode
- `POST /validate` - Validate function code

### Health & Monitoring
- `GET /health` - Health check endpoint
- `GET /info` - Service information

## Function Format

Functions should follow this format:

```javascript
export default async function handler(request, context) {
  // Function logic here
  const { name } = request.body;
  
  // Use context for platform services
  context.log.info('Processing request', { name });
  
  // Return success response
  return context.success({ message: `Hello, ${name}!` });
}

export const config = {
  name: 'My Function',
  description: 'Example function',
  timeout: 30000,
  memory: 256,
  env_required: ['API_KEY']
};
```

## BaaS Context API

The context object provides access to platform services:

### Database Operations
```javascript
// Entity operations
const users = await context.db.project.entities.users.findMany();
const user = await context.db.project.entities.users.findOne({ id: '123' });
const newUser = await context.db.project.entities.users.create({ name: 'John' });
```

### HTTP Client
```javascript
// External API calls
const data = await context.http.get('https://api.example.com/data');
const result = await context.http.post('https://api.example.com/submit', { data });
```

### Logging
```javascript
// Structured logging
context.log.info('Processing started', { userId: '123' });
context.log.error('Processing failed', { error: 'Invalid data' });
```

### Environment Variables
```javascript
// Access project environment variables
const apiKey = context.env.API_KEY;
const dbUrl = context.env.DATABASE_URL;
```

### Response Helpers
```javascript
// Success response
return context.success({ data: results });

// Error response
return context.error('Something went wrong', 'VALIDATION_ERROR');
```

## Security Features

- **VM2 Sandboxing**: Code runs in isolated VM context
- **Resource Limits**: Configurable timeout and memory limits
- **Code Validation**: Static analysis for dangerous patterns
- **Access Control**: Project-scoped data access
- **Rate Limiting**: Request rate limiting
- **Input Sanitization**: Request data validation

## Configuration

Key environment variables:

```bash
# Server
PORT=3001
NODE_ENV=production

# Execution Limits
DEFAULT_TIMEOUT=30000
MAX_TIMEOUT=300000
DEFAULT_MEMORY_LIMIT=128
MAX_MEMORY_LIMIT=512

# Integration
DRUPAL_API_URL=http://localhost
ALLOWED_ORIGINS=http://localhost
```

## Monitoring

### Health Checks
The service provides comprehensive health monitoring:

```bash
curl http://localhost:3001/health
```

Response includes:
- Service status (healthy/warning/unhealthy)
- Memory usage and limits
- CPU load average
- VM2 availability
- System metrics

### Logging
Structured logging with Winston:
- Console output for development
- File logging for production
- JSON format for log aggregation
- Execution tracing with correlation IDs

## Development

### Running Tests
```bash
npm test
```

### Code Linting
```bash
npm run lint
```

### Building Docker Image
```bash
npm run docker:build
```

## Architecture

```
┌─────────────────┐    ┌──────────────────┐    ┌─────────────────┐
│   Drupal API    │    │  BaaS Functions  │    │    VM2 Sandbox  │
│                 │────│     Service      │────│                 │
│ Function CRUD   │    │                  │    │  User Function  │
│ Auth & Perms    │    │  Context API     │    │   Execution     │
│ Data Storage    │    │  Security        │    │                 │
└─────────────────┘    └──────────────────┘    └─────────────────┘
```

## Performance

- **Cold Start**: ~50ms for simple functions
- **Memory Usage**: 30-50MB base + function memory
- **Throughput**: 100+ RPS on standard hardware
- **Concurrency**: Limited by memory and CPU resources

## Troubleshooting

### Common Issues

1. **Function Timeout**
   - Increase timeout in function config
   - Optimize function code
   - Check external API response times

2. **Memory Limit Exceeded**
   - Increase memory limit in config
   - Optimize memory usage in function
   - Check for memory leaks

3. **Sandbox Errors**
   - Review function code for restricted operations
   - Check VM2 compatibility
   - Verify Node.js version compatibility

### Debug Mode
Enable debug logging:
```bash
LOG_LEVEL=debug npm start
```

## Contributing

1. Follow existing code style
2. Add tests for new features
3. Update documentation
4. Ensure Docker builds succeed