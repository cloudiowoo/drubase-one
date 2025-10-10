/**
 * @file
 * Swagger UI initialization for BaaS API.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  // Function to initialize Swagger UI with retry mechanism
  function initializeSwaggerUI() {
    const swaggerUIContainer = document.querySelector('#swagger-ui:not(.processed)');
    if (!swaggerUIContainer) {
      return;
    }
    
    // Check if Swagger UI dependencies are loaded
    if (typeof SwaggerUIBundle === 'undefined') {
      console.log('SwaggerUIBundle not loaded yet, retrying in 100ms...');
      setTimeout(initializeSwaggerUI, 100);
      return;
    }
    
    if (typeof SwaggerUIStandalonePreset === 'undefined') {
      console.log('SwaggerUIStandalonePreset not loaded yet, retrying in 100ms...');
      setTimeout(initializeSwaggerUI, 100);
      return;
    }
    
    swaggerUIContainer.classList.add('processed');
    
    // Get the API specification URL
    const specUrl = drupalSettings.baasApi?.specUrl || '/api/docs';
    
    console.log('Initializing Swagger UI with spec URL:', specUrl);
    
    // Initialize Swagger UI
    const ui = SwaggerUIBundle({
        url: specUrl,
        dom_id: '#swagger-ui',
        deepLinking: true,
        presets: [
          SwaggerUIBundle.presets.apis,
          SwaggerUIStandalonePreset.presets.standalone
        ],
        plugins: [
          SwaggerUIBundle.plugins.DownloadUrl
        ],
        layout: "StandaloneLayout",
        defaultModelsExpandDepth: 1,
        defaultModelExpandDepth: 1,
        docExpansion: "none",
        displayOperationId: false,
        displayRequestDuration: true,
        showExtensions: true,
        showCommonExtensions: true,
        tryItOutEnabled: true,
        requestInterceptor: function(request) {
          // Add API key or JWT token if available
          if (drupalSettings.baasApi?.apiKey) {
            request.headers['X-API-Key'] = drupalSettings.baasApi.apiKey;
          }
          if (drupalSettings.baasApi?.jwtToken) {
            request.headers['Authorization'] = 'Bearer ' + drupalSettings.baasApi.jwtToken;
          }
          return request;
        },
        responseInterceptor: function(response) {
          // Handle response logging or modification
          return response;
        },
        onComplete: function(swaggerApi, swaggerUi) {
          console.log('Swagger UI loaded for:', specUrl);
          
          // Custom authentication handling
          if (drupalSettings.baasApi?.enableAuth) {
            // Add authentication UI elements
            const authContainer = document.createElement('div');
            authContainer.innerHTML = `
              <div class="swagger-ui-auth-container" style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 4px;">
                <h3 style="margin-top: 0;">API认证</h3>
                <div style="margin: 10px 0;">
                  <label for="api-key-input">API Key:</label>
                  <input type="text" id="api-key-input" placeholder="输入API Key" style="margin-left: 10px; padding: 5px; border: 1px solid #ddd; border-radius: 3px;">
                  <button id="set-api-key" style="margin-left: 10px; padding: 5px 10px; background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer;">设置</button>
                </div>
                <div style="margin: 10px 0;">
                  <label for="jwt-token-input">JWT Token:</label>
                  <input type="text" id="jwt-token-input" placeholder="输入JWT Token" style="margin-left: 10px; padding: 5px; border: 1px solid #ddd; border-radius: 3px;">
                  <button id="set-jwt-token" style="margin-left: 10px; padding: 5px 10px; background: #28a745; color: white; border: none; border-radius: 3px; cursor: pointer;">设置</button>
                </div>
              </div>
            `;
            
            const swaggerContainer = document.getElementById('swagger-ui');
            swaggerContainer.insertBefore(authContainer, swaggerContainer.firstChild);
            
            // Handle API key setting
            document.getElementById('set-api-key').addEventListener('click', function() {
              const apiKey = document.getElementById('api-key-input').value;
              if (apiKey) {
                drupalSettings.baasApi.apiKey = apiKey;
                localStorage.setItem('baas_api_key', apiKey);
                alert('API Key已设置');
              }
            });
            
            // Handle JWT token setting
            document.getElementById('set-jwt-token').addEventListener('click', function() {
              const jwtToken = document.getElementById('jwt-token-input').value;
              if (jwtToken) {
                drupalSettings.baasApi.jwtToken = jwtToken;
                localStorage.setItem('baas_jwt_token', jwtToken);
                alert('JWT Token已设置');
              }
            });
            
            // Load saved credentials
            const savedApiKey = localStorage.getItem('baas_api_key');
            const savedJwtToken = localStorage.getItem('baas_jwt_token');
            
            if (savedApiKey) {
              document.getElementById('api-key-input').value = savedApiKey;
              drupalSettings.baasApi.apiKey = savedApiKey;
            }
            
            if (savedJwtToken) {
              document.getElementById('jwt-token-input').value = savedJwtToken;
              drupalSettings.baasApi.jwtToken = savedJwtToken;
            }
          }
        }
      });
  }

  // Drupal behavior to trigger initialization
  Drupal.behaviors.baasApiSwaggerUI = {
    attach: function (context, settings) {
      // Start the initialization process
      initializeSwaggerUI();
    }
  };
})(Drupal, drupalSettings);