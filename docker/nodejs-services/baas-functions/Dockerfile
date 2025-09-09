# BaaS Functions Service Dockerfile
FROM node:18-alpine

# Install curl for health checks
RUN apk add --no-cache curl

# Set working directory
WORKDIR /app

# Create app user for security
RUN addgroup -g 1001 -S nodejs && \
    adduser -S baas -u 1001

# Install dependencies first (for better caching)
COPY package.json ./
# Install all dependencies including dev dependencies for development mode
RUN npm install && \
    npm cache clean --force

# Copy application code
COPY . .

# Create logs directory
RUN mkdir -p logs && chown -R baas:nodejs logs

# Security: Clean up cache and temporary files
RUN rm -rf /var/cache/apk/* /tmp/* /var/tmp/* /root/.npm

# Set proper permissions
RUN chown -R baas:nodejs /app
USER baas

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
  CMD node healthcheck.js

# Expose port
EXPOSE 3001

# Environment variables
ENV NODE_ENV=production
ENV PORT=3001
ENV LOG_LEVEL=info

# Start the application
# Use nodemon in development mode for hot reload, node in production
CMD ["sh", "-c", "if [ \"$NODE_ENV\" = \"development\" ]; then npm run dev; else npm start; fi"]