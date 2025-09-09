/**
 * @file
 * 实体关系图JavaScript功能 - 交互版
 */

(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.entitySchema = {
    attach: function (context, settings) {
      $('.entity-schema-container', context).once('entity-schema').each(function() {
        const container = $(this);
        const diagram = container.find('.entity-diagram');
        const indicators = container.find('.relationship-indicators');
        
        if (diagram.length && indicators.length) {
          // 添加交互效果
          initializeInteractions();
        }
        
        function initializeInteractions() {
          // 实体悬停时高亮相关关系指示器
          diagram.find('.entity-table').hover(
            function() {
              const entityName = $(this).data('entity');
              // 高亮相关关系指示器
              indicators.find('.relation-indicator[data-source="' + entityName + '"], .relation-indicator[data-target="' + entityName + '"]')
                .addClass('highlighted');
              
              // 显示提示信息
              $(this).attr('title', '实体: ' + entityName + ' - 悬停查看相关关系');
            },
            function() {
              // 移除高亮
              indicators.find('.relation-indicator').removeClass('highlighted');
              $(this).removeAttr('title');
            }
          );
          
          // 关系指示器悬停效果
          indicators.find('.relation-indicator').hover(
            function() {
              const sourceEntity = $(this).data('source');
              const targetEntity = $(this).data('target');
              
              // 高亮相关实体
              diagram.find('.entity-table[data-entity="' + sourceEntity + '"], .entity-table[data-entity="' + targetEntity + '"]')
                .addClass('relation-highlight');
              
              $(this).addClass('active');
            },
            function() {
              // 移除高亮
              diagram.find('.entity-table').removeClass('relation-highlight');
              $(this).removeClass('active');
            }
          );
        }
      });
    }
  };
  
})(jQuery, Drupal);