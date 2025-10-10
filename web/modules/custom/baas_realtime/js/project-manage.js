/**
 * @file
 * 项目实时功能管理JavaScript
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * 项目实时管理行为
   */
  Drupal.behaviors.baasRealtimeProjectManage = {
    attach: function (context, settings) {
      const $context = $(context);
      const realtimeSettings = settings.baasRealtime || {};
      
      // 确保只初始化一次
      const $container = $('.baas-realtime-project-manage', context).not('.realtime-manage-processed');
      if ($container.length === 0) {
        return;
      }
      $container.addClass('realtime-manage-processed');

      const projectId = realtimeSettings.projectId;
      const tenantId = realtimeSettings.tenantId;
      const apiEndpoint = realtimeSettings.apiEndpoint;

      // UI元素
      const $loadingOverlay = $('#loading-overlay');
      const $saveButton = $('#save-config');
      const $refreshButton = $('#refresh-status');
      const $selectAllButton = $('#select-all');
      const $selectNoneButton = $('#select-none');
      const $entityCheckboxes = $('.entity-checkbox');

      /**
       * 显示加载状态
       */
      function showLoading() {
        $loadingOverlay.show();
      }

      /**
       * 隐藏加载状态
       */
      function hideLoading() {
        $loadingOverlay.hide();
      }

      /**
       * 显示消息
       */
      function showMessage(message, type = 'status') {
        const $message = $('<div class="messages messages--' + type + '">' + message + '</div>');
        $('.baas-realtime-project-manage').prepend($message);
        
        // 3秒后自动隐藏
        setTimeout(function() {
          $message.fadeOut(500, function() {
            $(this).remove();
          });
        }, 3000);
      }

      /**
       * 更新状态指示器
       */
      function updateStatusIndicator() {
        const checkedCount = $entityCheckboxes.filter(':checked').length;
        const $statusIndicator = $('.status-indicator');
        const $statusText = $statusIndicator.find('.status-text');
        
        if (checkedCount > 0) {
          $statusIndicator.removeClass('disabled').addClass('enabled');
          $statusText.text('已启用');
        } else {
          $statusIndicator.removeClass('enabled').addClass('disabled');
          $statusText.text('已禁用');
        }

        // 更新计数
        $('.card-body p').html(checkedCount > 0 ? 
          '已为 <strong>' + checkedCount + '</strong> 个实体启用实时功能' :
          '当前项目未启用实时功能'
        );
      }

      /**
       * 切换实体实时状态
       */
      function toggleEntityRealtime(entityName, enabled) {
        showLoading();

        const url = `/admin/baas/realtime/project/${tenantId}/${projectId}/entity/${entityName}/toggle`;
        
        return $.ajax({
          url: url,
          method: 'POST',
          contentType: 'application/json',
          data: JSON.stringify({
            enabled: enabled
          })
        }).done(function(response) {
          if (response.success) {
            showMessage(response.message || '状态已更新', 'status');
            updateEntityStatus(entityName, enabled);
          } else {
            showMessage(response.error || '操作失败', 'error');
            // 恢复复选框状态
            $(`[data-entity="${entityName}"]`).prop('checked', !enabled);
          }
        }).fail(function(xhr) {
          const response = xhr.responseJSON || {};
          showMessage(response.error || '网络错误', 'error');
          // 恢复复选框状态
          $(`[data-entity="${entityName}"]`).prop('checked', !enabled);
        }).always(function() {
          hideLoading();
        });
      }

      /**
       * 更新实体状态显示
       */
      function updateEntityStatus(entityName, enabled) {
        const $entityCard = $(`.entity-card[data-entity="${entityName}"]`);
        const $triggerStatus = $entityCard.find('.trigger-status');
        
        if (enabled) {
          $triggerStatus.removeClass('inactive').addClass('active').text('触发器: 已创建');
        } else {
          $triggerStatus.removeClass('active').addClass('inactive').text('触发器: 未创建');
        }
      }

      /**
       * 刷新状态
       */
      function refreshStatus() {
        showLoading();

        const url = `/admin/baas/realtime/project/${tenantId}/${projectId}/config`;
        
        $.ajax({
          url: url,
          method: 'GET'
        }).done(function(response) {
          if (response.success) {
            // 更新复选框状态
            $entityCheckboxes.prop('checked', false);
            
            response.data.entities.forEach(function(entity) {
              if (entity.has_trigger) {
                $(`[data-entity="${entity.name}"]`).prop('checked', true);
                updateEntityStatus(entity.name, true);
              } else {
                updateEntityStatus(entity.name, false);
              }
            });

            updateStatusIndicator();
            showMessage('状态已刷新', 'status');
          } else {
            showMessage(response.error || '刷新失败', 'error');
          }
        }).fail(function(xhr) {
          const response = xhr.responseJSON || {};
          showMessage(response.error || '网络错误', 'error');
        }).always(function() {
          hideLoading();
        });
      }

      // 事件绑定
      
      // 实体复选框变更
      $entityCheckboxes.on('change', function() {
        const $checkbox = $(this);
        const entityName = $checkbox.data('entity');
        const enabled = $checkbox.is(':checked');
        
        // 防止重复点击
        $checkbox.prop('disabled', true);
        
        toggleEntityRealtime(entityName, enabled).always(function() {
          $checkbox.prop('disabled', false);
          updateStatusIndicator();
        });
      });

      // 全选按钮
      $selectAllButton.on('click', function() {
        $entityCheckboxes.not(':checked').each(function() {
          $(this).trigger('click');
        });
      });

      // 全不选按钮
      $selectNoneButton.on('click', function() {
        $entityCheckboxes.filter(':checked').each(function() {
          $(this).trigger('click');
        });
      });

      // 保存配置按钮（当前由单个切换处理，这里可以添加批量保存逻辑）
      $saveButton.on('click', function() {
        showMessage('配置已自动保存', 'status');
      });

      // 刷新状态按钮
      $refreshButton.on('click', function() {
        refreshStatus();
      });

      // 初始化状态指示器
      updateStatusIndicator();
    }
  };

})(jQuery, Drupal, drupalSettings);