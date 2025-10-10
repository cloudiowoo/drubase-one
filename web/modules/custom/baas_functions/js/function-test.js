/**
 * @file
 * BaaS Functions test interface JavaScript functionality.
 */

(function (Drupal) {
  'use strict';

  /**
   * BaaS Functions namespace.
   */
  Drupal.BaasFunctions = Drupal.BaasFunctions || {};

  /**
   * Test function execution.
   */
  Drupal.BaasFunctions.testFunction = async function(button) {
    const tenantId = button.getAttribute('data-tenant-id');
    const projectId = button.getAttribute('data-project-id');
    const functionName = button.getAttribute('data-function-name');
    
    const method = document.getElementById('test-method').value;
    const headers = document.getElementById('test-headers').value;
    const body = document.getElementById('test-body').value;
    const resultsDiv = document.getElementById('test-results');
    const outputDiv = document.getElementById('test-output');
    
    try {
      // Parse headers and body
      let parsedHeaders = {};
      try {
        parsedHeaders = JSON.parse(headers);
      } catch (e) {
        throw new Error('Invalid JSON in headers: ' + e.message);
      }
      
      let parsedBody = {};
      if (body.trim()) {
        try {
          parsedBody = JSON.parse(body);
        } catch (e) {
          throw new Error('Invalid JSON in body: ' + e.message);
        }
      }
      
      // Show loading
      resultsDiv.style.display = 'block';
      outputDiv.textContent = '🔄 正在执行函数测试...\n';
      outputDiv.style.color = '#0066cc';
      
      // Note: Authorization header should be manually added in the headers field above
      // Example: {"Authorization": "Bearer your_jwt_token_here", "Content-Type": "application/json"}
      
      // Make request
      const startTime = Date.now();
      const apiUrl = '/api/v1/' + tenantId + '/projects/' + projectId + '/functions/' + functionName;
      
      const response = await fetch(apiUrl, {
        method: method,
        headers: parsedHeaders,
        body: method !== 'GET' ? JSON.stringify(parsedBody) : undefined
      });
      
      const endTime = Date.now();
      const responseTime = endTime - startTime;
      
      const responseText = await response.text();
      let responseData;
      try {
        responseData = JSON.parse(responseText);
      } catch (e) {
        responseData = responseText;
      }
      
      // Display results
      let output = '📊 测试结果\n';
      output += '================\n';
      output += 'HTTP 状态码: ' + response.status + '\n';
      output += '响应时间: ' + responseTime + 'ms\n';
      output += 'Content-Type: ' + (response.headers.get('content-type') || 'unknown') + '\n\n';
      
      output += '📤 请求信息\n';
      output += '----------------\n';
      output += '方法: ' + method + '\n';
      output += '端点: ' + apiUrl + '\n';
      output += '请求头: ' + JSON.stringify(parsedHeaders, null, 2) + '\n';
      if (method !== 'GET') {
        output += '请求体: ' + JSON.stringify(parsedBody, null, 2) + '\n';
      }
      output += '\n';
      
      output += '📥 响应数据\n';
      output += '----------------\n';
      if (typeof responseData === 'object') {
        output += JSON.stringify(responseData, null, 2);
      } else {
        output += responseData;
      }
      
      outputDiv.textContent = output;
      outputDiv.style.color = response.ok ? '#28a745' : '#dc3545';
      
    } catch (error) {
      resultsDiv.style.display = 'block';
      outputDiv.textContent = '❌ 测试失败\n\n错误信息: ' + error.message;
      outputDiv.style.color = '#dc3545';
    }
  };

  /**
   * Clear test results.
   */
  Drupal.BaasFunctions.clearResults = function() {
    const resultsDiv = document.getElementById('test-results');
    resultsDiv.style.display = 'none';
  };

  // Make functions available globally for onclick handlers
  window.BaasFunctions = Drupal.BaasFunctions;

})(Drupal);