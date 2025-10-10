/**
 * @file
 * JavaScript for entity template form validation.
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * 实体模板表单验证行为。
   */
  Drupal.behaviors.entityTemplateFormValidation = {
    attach: function (context, settings) {
      $('.entity-name-field', context).once('entity-name-validation').each(function () {
        const $field = $(this);
        const maxLength = parseInt($field.attr('data-max-length'), 10);
        const tenantId = $field.attr('data-tenant-id');
        const projectId = $field.attr('data-project-id');

        // 创建字符计数器和验证消息容器
        const $wrapper = $field.closest('.form-item');
        const $description = $wrapper.find('.description');
        
        // 添加字符计数器
        const $counter = $('<div class="entity-name-counter"></div>');
        $description.after($counter);
        
        // 添加验证消息容器
        const $validation = $('<div class="entity-name-validation"></div>');
        $counter.after($validation);

        // 更新计数器和验证状态
        function updateValidation() {
          const currentLength = $field.val().length;
          const remaining = maxLength - currentLength;
          
          // 更新计数器
          $counter.text(`${currentLength}/${maxLength} 字符`);
          
          // 更新验证状态
          if (currentLength === 0) {
            $counter.removeClass('warning error').addClass('info');
            $validation.removeClass('error warning').empty();
            $field.removeClass('error');
          } else if (currentLength > maxLength) {
            $counter.removeClass('info warning').addClass('error');
            $validation.removeClass('warning').addClass('error')
              .text(`超出 ${currentLength - maxLength} 个字符，最多 ${maxLength} 个字符（受Drupal 32字符实体类型ID限制）`);
            $field.addClass('error');
          } else if (remaining <= 5) {
            $counter.removeClass('info error').addClass('warning');
            $validation.removeClass('error').addClass('warning')
              .text(`还剩 ${remaining} 个字符`);
            $field.removeClass('error');
          } else {
            $counter.removeClass('warning error').addClass('info');
            $validation.removeClass('error warning').empty();
            $field.removeClass('error');
          }
          
          // 检查格式
          const value = $field.val();
          if (value && !/^[a-z0-9_]+$/.test(value)) {
            $validation.addClass('error').text('只能包含小写字母、数字和下划线');
            $field.addClass('error');
          }
        }

        // 绑定事件
        $field.on('input keyup paste', function() {
          setTimeout(updateValidation, 0);
        });

        // 初始验证
        updateValidation();
      });
    }
  };

  /**
   * 添加CSS样式。
   */
  Drupal.behaviors.entityTemplateFormStyles = {
    attach: function (context, settings) {
      // 只添加一次样式
      if (!$('#entity-template-form-styles').length) {
        $('head').append(`
          <style id="entity-template-form-styles">
            .entity-name-counter {
              font-size: 0.875rem;
              margin-top: 0.25rem;
              font-weight: 500;
            }
            .entity-name-counter.info {
              color: #666;
            }
            .entity-name-counter.warning {
              color: #f59e0b;
            }
            .entity-name-counter.error {
              color: #dc2626;
            }
            .entity-name-validation {
              font-size: 0.875rem;
              margin-top: 0.25rem;
              font-weight: 500;
            }
            .entity-name-validation.warning {
              color: #f59e0b;
            }
            .entity-name-validation.error {
              color: #dc2626;
            }
            .form-item .form-text.error {
              border-color: #dc2626;
            }
          </style>
        `);
      }
    }
  };

})(jQuery, Drupal, drupalSettings);