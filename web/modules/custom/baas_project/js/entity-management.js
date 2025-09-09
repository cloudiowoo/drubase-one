/**
 * 实体管理界面JavaScript功能
 */

(function ($, Drupal) {
  'use strict';

  /**
   * 实体管理行为
   */
  Drupal.behaviors.entityManagement = {
    attach: function (context, settings) {
      // 实体卡片悬停效果
      $('.entity-card', context).once('entity-card').each(function() {
        var $card = $(this);
        
        $card.hover(
          function() {
            $(this).addClass('entity-card--hover');
          },
          function() {
            $(this).removeClass('entity-card--hover');
          }
        );
      });

      // 确认删除操作
      $('.button--danger', context).once('confirm-delete').click(function(e) {
        var $button = $(this);
        var action = $button.text().trim();
        
        if (action.indexOf('删除') !== -1) {
          if (!confirm(Drupal.t('确定要删除吗？此操作不可撤销。'))) {
            e.preventDefault();
            return false;
          }
        }
      });

      // 批量操作复选框
      $('.entity-data-table input[type="checkbox"]', context).once('bulk-select').change(function() {
        var $table = $(this).closest('table');
        var $checkboxes = $table.find('tbody input[type="checkbox"]');
        var $selectAll = $table.find('thead input[type="checkbox"]');
        var $bulkActions = $('.bulk-actions');
        
        // 更新全选状态
        if ($(this).is($selectAll)) {
          $checkboxes.prop('checked', $(this).prop('checked'));
        } else {
          var allChecked = $checkboxes.length === $checkboxes.filter(':checked').length;
          $selectAll.prop('checked', allChecked);
        }
        
        // 显示/隐藏批量操作
        var selectedCount = $checkboxes.filter(':checked').length;
        if (selectedCount > 0) {
          $bulkActions.show().find('.selected-count').text(selectedCount);
        } else {
          $bulkActions.hide();
        }
      });

      // 快速搜索功能
      $('.entity-search', context).once('entity-search').on('input', function() {
        var searchTerm = $(this).val().toLowerCase();
        var $entityCards = $('.entity-card');
        
        if (searchTerm === '') {
          $entityCards.show();
        } else {
          $entityCards.each(function() {
            var $card = $(this);
            var entityName = $card.find('h3').text().toLowerCase();
            var entityDesc = $card.find('.entity-description').text().toLowerCase();
            
            if (entityName.indexOf(searchTerm) !== -1 || entityDesc.indexOf(searchTerm) !== -1) {
              $card.show();
            } else {
              $card.hide();
            }
          });
        }
      });

      // 实体统计信息刷新
      $('.refresh-stats', context).once('refresh-stats').click(function(e) {
        e.preventDefault();
        var $button = $(this);
        var $statsContainer = $('.entity-statistics');
        
        $button.addClass('button--loading').prop('disabled', true);
        
        // 模拟API调用刷新统计
        setTimeout(function() {
          $button.removeClass('button--loading').prop('disabled', false);
          Drupal.announce(Drupal.t('统计信息已刷新'));
        }, 1000);
      });

      // 工具提示
      $('[data-tooltip]', context).once('tooltip').each(function() {
        var $element = $(this);
        var tooltipText = $element.data('tooltip');
        
        $element.attr('title', tooltipText);
      });
    }
  };

  /**
   * 实体数据管理功能
   */
  Drupal.behaviors.entityDataManagement = {
    attach: function (context, settings) {
      // 数据表格排序
      $('.entity-data-table th[data-sort]', context).once('table-sort').click(function() {
        var $th = $(this);
        var $table = $th.closest('table');
        var columnIndex = $th.index();
        var sortDirection = $th.hasClass('sort-asc') ? 'desc' : 'asc';
        
        // 清除其他列的排序状态
        $table.find('th').removeClass('sort-asc sort-desc');
        $th.addClass('sort-' + sortDirection);
        
        // 排序表格行
        var $rows = $table.find('tbody tr').toArray();
        $rows.sort(function(a, b) {
          var aText = $(a).find('td').eq(columnIndex).text().trim();
          var bText = $(b).find('td').eq(columnIndex).text().trim();
          
          // 尝试数字比较
          if (!isNaN(aText) && !isNaN(bText)) {
            return sortDirection === 'asc' ? aText - bText : bText - aText;
          }
          
          // 文本比较
          return sortDirection === 'asc' 
            ? aText.localeCompare(bText)
            : bText.localeCompare(aText);
        });
        
        $table.find('tbody').empty().append($rows);
      });

      // 行内编辑功能
      $('.entity-data-table .editable', context).once('inline-edit').dblclick(function() {
        var $cell = $(this);
        var originalValue = $cell.text().trim();
        var $input = $('<input type="text" class="inline-edit-input">')
          .val(originalValue)
          .width($cell.width());
        
        $cell.html($input);
        $input.focus().select();
        
        // 保存编辑
        function saveEdit() {
          var newValue = $input.val();
          if (newValue !== originalValue) {
            // 这里应该发送AJAX请求保存数据
            console.log('Save:', originalValue, '->', newValue);
            Drupal.announce(Drupal.t('数据已保存'));
          }
          $cell.text(newValue);
        }
        
        // 取消编辑
        function cancelEdit() {
          $cell.text(originalValue);
        }
        
        $input.on('blur', saveEdit)
          .on('keydown', function(e) {
            if (e.which === 13) { // Enter
              saveEdit();
            } else if (e.which === 27) { // Escape
              cancelEdit();
            }
          });
      });
    }
  };

  /**
   * 实用工具函数
   */
  Drupal.entityManagement = {
    /**
     * 显示加载状态
     */
    showLoading: function($container) {
      $container.addClass('entity-loading')
        .html('<div class="loading-text">' + Drupal.t('加载中...') + '</div>');
    },

    /**
     * 隐藏加载状态
     */
    hideLoading: function($container) {
      $container.removeClass('entity-loading');
    },

    /**
     * 显示错误消息
     */
    showError: function(message) {
      var $error = $('<div class="messages messages--error">')
        .text(message)
        .hide()
        .fadeIn();
      
      $('.entity-header').after($error);
      
      setTimeout(function() {
        $error.fadeOut(function() {
          $(this).remove();
        });
      }, 5000);
    },

    /**
     * 显示成功消息
     */
    showSuccess: function(message) {
      var $success = $('<div class="messages messages--status">')
        .text(message)
        .hide()
        .fadeIn();
      
      $('.entity-header').after($success);
      
      setTimeout(function() {
        $success.fadeOut(function() {
          $(this).remove();
        });
      }, 3000);
    }
  };

})(jQuery, Drupal);