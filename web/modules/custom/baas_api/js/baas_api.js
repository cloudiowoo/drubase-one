/**
 * @file
 * BaaS API模块JavaScript功能
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * 初始化Swagger UI
   */
  Drupal.behaviors.baasApiSwagger = {
    attach: function (context, settings) {
      // 确保Swagger UI只初始化一次
      if (context !== document) {
        return;
      }

      // 检查是否存在Swagger UI容器
      const swaggerContainer = document.getElementById('swagger-ui');
      if (!swaggerContainer) {
        return;
      }

      // 从drupalSettings获取API文档数据
      const apiDocs = drupalSettings.baasApi && drupalSettings.baasApi.docs ? drupalSettings.baasApi.docs : null;
      
      if (!apiDocs) {
        console.warn('未找到API文档数据');
        return;
      }

      try {
        // 初始化Swagger UI
        const ui = SwaggerUIBundle({
          url: apiDocs.url || '/api/docs?format=json',
          dom_id: '#swagger-ui',
          deepLinking: true,
          presets: [
            SwaggerUIBundle.presets.apis,
            SwaggerUIStandalonePreset
          ],
          plugins: [
            SwaggerUIBundle.plugins.DownloadUrl
          ],
          layout: "StandaloneLayout",
          validatorUrl: null,
          tryItOutEnabled: true,
          supportedSubmitMethods: ['get', 'post', 'put', 'patch', 'delete'],
          onComplete: function() {
            console.log('Swagger UI初始化完成');
          },
          onFailure: function(data) {
            console.error('Swagger UI初始化失败:', data);
          }
        });

        // 存储UI实例供其他函数使用
        window.baasSwaggerUI = ui;

      } catch (error) {
        console.error('初始化Swagger UI时出错:', error);
        // 显示错误信息给用户
        swaggerContainer.innerHTML = '<div class="error-message">' +
          '<h3>API文档加载失败</h3>' +
          '<p>无法加载Swagger UI。请检查浏览器控制台获取详细错误信息。</p>' +
          '</div>';
      }
    }
  };

  /**
   * API文档格式切换功能
   */
  Drupal.behaviors.baasApiFormatSwitcher = {
    attach: function (context, settings) {
      $('.api-format-switcher', context).once('baas-api-format').each(function() {
        const $switcher = $(this);
        
        $switcher.on('click', 'a', function(e) {
          e.preventDefault();
          
          const $link = $(this);
          const format = $link.data('format');
          const currentUrl = window.location.href.split('?')[0];
          
          // 更新URL并重新加载页面
          window.location.href = currentUrl + '?format=' + format;
        });
      });
    }
  };

  /**
   * API统计图表功能
   */
  Drupal.behaviors.baasApiStats = {
    attach: function (context, settings) {
      $('.api-stats-chart', context).once('baas-api-stats').each(function() {
        const chartContainer = this;
        const chartData = drupalSettings.baasApi && drupalSettings.baasApi.chartData ? 
          drupalSettings.baasApi.chartData : null;

        if (!chartData) {
          return;
        }

        // 这里可以集成Chart.js或其他图表库
        // 目前仅提供基础的数据展示
        this.innerHTML = '<div class="stats-summary">' +
          '<h4>API使用统计</h4>' +
          '<ul>' +
          '<li>总请求数: ' + (chartData.totalRequests || 0) + '</li>' +
          '<li>成功率: ' + (chartData.successRate || 0) + '%</li>' +
          '<li>平均响应时间: ' + (chartData.avgResponseTime || 0) + 'ms</li>' +
          '</ul>' +
          '</div>';
      });
    }
  };

  /**
   * API测试功能
   */
  Drupal.behaviors.baasApiTester = {
    attach: function (context, settings) {
      $('.api-test-form', context).once('baas-api-test').each(function() {
        const $form = $(this);
        
        $form.on('submit', function(e) {
          e.preventDefault();
          
          const endpoint = $form.find('[name="endpoint"]').val();
          const method = $form.find('[name="method"]').val();
          const data = $form.find('[name="data"]').val();
          const $result = $form.find('.test-result');
          
          // 显示加载状态
          $result.html('<div class="loading">执行中...</div>');
          
          // 准备请求数据
          const requestOptions = {
            method: method,
            headers: {
              'Content-Type': 'application/json',
              'X-Requested-With': 'XMLHttpRequest'
            }
          };
          
          if (data && (method === 'POST' || method === 'PUT' || method === 'PATCH')) {
            try {
              requestOptions.body = JSON.stringify(JSON.parse(data));
            } catch (error) {
              $result.html('<div class="error">JSON数据格式错误</div>');
              return;
            }
          }
          
          // 发送API请求
          fetch(endpoint, requestOptions)
            .then(response => {
              return response.text().then(text => {
                try {
                  const json = JSON.parse(text);
                  return {
                    status: response.status,
                    statusText: response.statusText,
                    data: json
                  };
                } catch (e) {
                  return {
                    status: response.status,
                    statusText: response.statusText,
                    data: text
                  };
                }
              });
            })
            .then(result => {
              $result.html(
                '<div class="result-status">状态: ' + result.status + ' ' + result.statusText + '</div>' +
                '<div class="result-data"><pre>' + JSON.stringify(result.data, null, 2) + '</pre></div>'
              );
            })
            .catch(error => {
              $result.html('<div class="error">请求失败: ' + error.message + '</div>');
            });
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
