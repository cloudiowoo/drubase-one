/**
 * 运行时配置文件
 *
 * 此文件通过nginx在运行时注入，允许动态修改配置而无需重新构建
 *
 * 修改此文件后，只需刷新浏览器即可生效，无需重新构建Docker镜像
 *
 * 位置：apps/groups/config/runtime-config.js
 * 映射到：容器内 /etc/nginx/runtime-config/runtime-config.js
 * 访问URL：http://localhost:3000/runtime-config.js
 */
window.__RUNTIME_CONFIG__ = {
  // BaaS平台基础URL
  BAAS_BASE_URL: 'http://localhost',

  // Tenant和Project配置
  BAAS_TENANT_ID: '7375b0cd',
  BAAS_PROJECT_ID: '6888d012be80c',

  // API Key (⚠️ 需要替换为您的实际API Key)
  BAAS_API_KEY: 'your-api-key-here',

  // 服务Token（可选，用于服务账户认证）
  BAAS_SERVICE_TOKEN: '',

  // 服务端点配置
  ENDPOINTS: {
    API: '/api/v1',
    FUNCTIONS: 'http://localhost:3001',
    REALTIME: 'ws://localhost:4000',
    FILES: '/files'
  },

  // 环境标识
  APP_ENV: 'production',
  DEBUG_MODE: false,
  APP_VERSION: '1.0.0'
};

console.log('🔧 运行时配置已加载:', window.__RUNTIME_CONFIG__);
