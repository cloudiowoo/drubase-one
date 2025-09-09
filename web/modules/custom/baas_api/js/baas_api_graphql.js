/**
 * @file
 * BaaS API GraphQL文档页面的JavaScript增强.
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * GraphQL文档相关行为.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.baasApiGraphqlDocs = {
    attach: function (context, settings) {
      // 处理代码语法高亮
      if (typeof hljs !== 'undefined') {
        hljs.highlightAll();
      }

      // 处理复制功能
      if (typeof ClipboardJS !== 'undefined') {
        $('.js-baas-api-graphql-docs-copy', context).once('clipboard').each(function () {
          var clipboard = new ClipboardJS(this);

          clipboard.on('success', function(e) {
            var button = e.trigger;
            var originalText = button.textContent;
            button.textContent = '已复制!';
            setTimeout(function() {
              button.textContent = originalText;
            }, 2000);
          });

          clipboard.on('error', function(e) {
            var button = e.trigger;
            button.textContent = '复制失败!';
            setTimeout(function() {
              button.textContent = '复制模式';
            }, 2000);
          });
        });
      }

      // 处理GraphQL查询示例的可执行功能
      $('.js-graphql-example-run', context).once('graphql-run').each(function () {
        $(this).on('click', function (e) {
          e.preventDefault();

          var queryElement = $(this).closest('.graphql-example').find('code');
          var query = queryElement.text();
          var tenantId = drupalSettings.baas_api.tenant_id;

          if (tenantId && query) {
            // 打开GraphQL查询编辑器
            var url = '/api/graphql/' + tenantId + '/ui?query=' + encodeURIComponent(query);
            window.open(url, '_blank');
          }
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
