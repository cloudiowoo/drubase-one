/**
 * @file
 * BaaS API Documentation JavaScript.
 */

(function (Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.baasApiDocs = {
    attach: function (context, settings) {
      // 展开/折叠功能
      const headers = context.querySelectorAll('.operation-header:not(.processed)');
      headers.forEach(function(header) {
        header.classList.add('processed');
        header.addEventListener('click', function() {
          const operation = this.parentElement;
          const details = operation.querySelector('.operation-details');
          const button = this.querySelector('.toggle-button');
          
          if (details.style.display === 'none' || details.style.display === '') {
            details.style.display = 'block';
            button.textContent = '▼';
            operation.classList.add('expanded');
          } else {
            details.style.display = 'none';
            button.textContent = '▶';
            operation.classList.remove('expanded');
          }
        });
      });

      // 默认折叠所有操作
      const details = context.querySelectorAll('.operation-details:not(.processed)');
      details.forEach(function(detail) {
        detail.classList.add('processed');
        detail.style.display = 'none';
      });
      
      const buttons = context.querySelectorAll('.toggle-button:not(.processed)');
      buttons.forEach(function(button) {
        button.classList.add('processed');
        button.textContent = '▶';
      });

      // 展开全部按钮
      const expandAllBtn = context.querySelector('.js-expand-all:not(.processed)');
      if (expandAllBtn) {
        expandAllBtn.classList.add('processed');
        expandAllBtn.addEventListener('click', function(e) {
          e.preventDefault();
          context.querySelectorAll('.operation-details').forEach(function(detail) {
            detail.style.display = 'block';
          });
          context.querySelectorAll('.toggle-button').forEach(function(button) {
            button.textContent = '▼';
          });
          context.querySelectorAll('.operation').forEach(function(op) {
            op.classList.add('expanded');
          });
        });
      }

      // 折叠全部按钮
      const collapseAllBtn = context.querySelector('.js-collapse-all:not(.processed)');
      if (collapseAllBtn) {
        collapseAllBtn.classList.add('processed');
        collapseAllBtn.addEventListener('click', function(e) {
          e.preventDefault();
          context.querySelectorAll('.operation-details').forEach(function(detail) {
            detail.style.display = 'none';
          });
          context.querySelectorAll('.toggle-button').forEach(function(button) {
            button.textContent = '▶';
          });
          context.querySelectorAll('.operation').forEach(function(op) {
            op.classList.remove('expanded');
          });
        });
      }

      // 添加搜索功能
      const searchInput = context.querySelector('.api-search:not(.processed)');
      if (searchInput) {
        searchInput.classList.add('processed');
        searchInput.addEventListener('input', function() {
          const searchTerm = this.value.toLowerCase();
          const operations = context.querySelectorAll('.operation');
          
          operations.forEach(function(operation) {
            const method = operation.querySelector('.method').textContent.toLowerCase();
            const path = operation.querySelector('.path').textContent.toLowerCase();
            const summary = operation.querySelector('.summary').textContent.toLowerCase();
            
            if (method.includes(searchTerm) || path.includes(searchTerm) || summary.includes(searchTerm)) {
              operation.style.display = 'block';
            } else {
              operation.style.display = 'none';
            }
          });
        });
      }

      // 添加方法过滤功能
      const methodFilters = context.querySelectorAll('.method-filter:not(.processed)');
      methodFilters.forEach(function(filter) {
        filter.classList.add('processed');
        filter.addEventListener('click', function() {
          const method = this.dataset.method;
          const operations = context.querySelectorAll('.operation');
          
          // 切换过滤器状态
          this.classList.toggle('active');
          
          // 获取所有活动的过滤器
          const activeFilters = Array.from(context.querySelectorAll('.method-filter.active')).map(f => f.dataset.method);
          
          operations.forEach(function(operation) {
            const operationMethod = operation.dataset.method;
            if (activeFilters.length === 0 || activeFilters.includes(operationMethod)) {
              operation.style.display = 'block';
            } else {
              operation.style.display = 'none';
            }
          });
        });
      });
    }
  };

})(Drupal, drupalSettings);