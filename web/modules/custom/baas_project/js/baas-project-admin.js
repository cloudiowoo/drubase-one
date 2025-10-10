/**
 * BaaS Project Admin JavaScript
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * BaaS Project Admin behavior.
   */
  Drupal.behaviors.baasProjectAdmin = {
    attach: function (context, settings) {
      // 初始化迁移按钮
      $('.js-migrate-button', context).once('baas-project-migrate').on('click', function (e) {
        e.preventDefault();
        var $button = $(this);
        var url = $button.data('url');
        
        if (confirm(Drupal.t('Are you sure you want to execute the project migration? This action cannot be undone.'))) {
          executeMigration(url, $button);
        }
      });

      // 初始化回滚按钮
      $('.js-rollback-button', context).once('baas-project-rollback').on('click', function (e) {
        e.preventDefault();
        var $button = $(this);
        var url = $button.data('url');
        
        if (confirm(Drupal.t('Are you sure you want to rollback the project migration? This will remove all project data.'))) {
          executeRollback(url, $button);
        }
      });

      // 初始化确认回滚按钮
      $('.js-rollback-confirm-button', context).once('baas-project-rollback-confirm').on('click', function (e) {
        e.preventDefault();
        var $button = $(this);
        var url = $button.data('url');
        
        var confirmText = Drupal.t('Type "ROLLBACK" to confirm the migration rollback:');
        var userInput = prompt(confirmText);
        
        if (userInput === 'ROLLBACK') {
          executeRollback(url, $button);
        } else if (userInput !== null) {
          alert(Drupal.t('Rollback cancelled. You must type "ROLLBACK" exactly to confirm.'));
        }
      });

      // 初始化状态刷新
      $('.js-refresh-status', context).once('baas-project-refresh').on('click', function (e) {
        e.preventDefault();
        location.reload();
      });
    }
  };

  /**
   * 执行迁移。
   */
  function executeMigration(url, $button) {
    var $container = $button.closest('.baas-project-admin');
    var originalText = $button.text();
    
    // 显示加载状态
    $button.prop('disabled', true).text(Drupal.t('Executing...'));
    showLoadingMessage($container, Drupal.t('Executing migration, please wait...'));
    
    $.ajax({
      url: url,
      method: 'POST',
      dataType: 'json',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    .done(function (response) {
      hideLoadingMessage($container);
      
      if (response.success) {
        showMessage($container, 'success', response.message || Drupal.t('Migration executed successfully.'));
        
        // 显示迁移结果
        if (response.data) {
          showMigrationResult($container, response.data);
        }
        
        // 刷新页面以显示新状态
        setTimeout(function () {
          location.reload();
        }, 3000);
      } else {
        showMessage($container, 'error', response.error || Drupal.t('Migration failed.'));
        $button.prop('disabled', false).text(originalText);
      }
    })
    .fail(function (xhr, status, error) {
      hideLoadingMessage($container);
      var errorMessage = Drupal.t('Migration failed: @error', {'@error': error});
      
      if (xhr.responseJSON && xhr.responseJSON.error) {
        errorMessage = xhr.responseJSON.error;
      }
      
      showMessage($container, 'error', errorMessage);
      $button.prop('disabled', false).text(originalText);
    });
  }

  /**
   * 执行回滚。
   */
  function executeRollback(url, $button) {
    var $container = $button.closest('.baas-project-admin');
    var originalText = $button.text();
    
    // 显示加载状态
    $button.prop('disabled', true).text(Drupal.t('Rolling back...'));
    showLoadingMessage($container, Drupal.t('Rolling back migration, please wait...'));
    
    $.ajax({
      url: url,
      method: 'POST',
      dataType: 'json',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
    .done(function (response) {
      hideLoadingMessage($container);
      
      if (response.success) {
        showMessage($container, 'success', response.message || Drupal.t('Migration rollback completed successfully.'));
        
        // 显示回滚结果
        if (response.data) {
          showRollbackResult($container, response.data);
        }
        
        // 刷新页面以显示新状态
        setTimeout(function () {
          location.reload();
        }, 3000);
      } else {
        showMessage($container, 'error', response.error || Drupal.t('Rollback failed.'));
        $button.prop('disabled', false).text(originalText);
      }
    })
    .fail(function (xhr, status, error) {
      hideLoadingMessage($container);
      var errorMessage = Drupal.t('Rollback failed: @error', {'@error': error});
      
      if (xhr.responseJSON && xhr.responseJSON.error) {
        errorMessage = xhr.responseJSON.error;
      }
      
      showMessage($container, 'error', errorMessage);
      $button.prop('disabled', false).text(originalText);
    });
  }

  /**
   * 显示加载消息。
   */
  function showLoadingMessage($container, message) {
    var $loading = $('<div class="baas-project-loading">' +
      '<div class="baas-project-spinner"></div>' +
      '<div class="loading-message">' + message + '</div>' +
      '</div>');
    
    $container.prepend($loading);
  }

  /**
   * 隐藏加载消息。
   */
  function hideLoadingMessage($container) {
    $container.find('.baas-project-loading').remove();
  }

  /**
   * 显示消息。
   */
  function showMessage($container, type, message) {
    var $message = $('<div class="baas-project-message ' + type + '">' + message + '</div>');
    
    // 移除现有消息
    $container.find('.baas-project-message').remove();
    
    // 添加新消息
    $container.prepend($message);
    
    // 自动隐藏成功消息
    if (type === 'success') {
      setTimeout(function () {
        $message.fadeOut();
      }, 5000);
    }
  }

  /**
   * 显示迁移结果。
   */
  function showMigrationResult($container, data) {
    var html = '<div class="baas-project-migration-result">' +
      '<h3>' + Drupal.t('Migration Results') + '</h3>' +
      '<ul>';
    
    if (data.projects_created) {
      html += '<li>' + Drupal.t('Projects created: @count', {'@count': data.projects_created}) + '</li>';
    }
    
    if (data.templates_migrated) {
      html += '<li>' + Drupal.t('Entity templates migrated: @count', {'@count': data.templates_migrated}) + '</li>';
    }
    
    if (data.fields_migrated) {
      html += '<li>' + Drupal.t('Entity fields migrated: @count', {'@count': data.fields_migrated}) + '</li>';
    }
    
    if (data.tenants_updated) {
      html += '<li>' + Drupal.t('Tenants updated: @count', {'@count': data.tenants_updated}) + '</li>';
    }
    
    html += '</ul></div>';
    
    $container.find('.baas-project-message').after(html);
  }

  /**
   * 显示回滚结果。
   */
  function showRollbackResult($container, data) {
    var html = '<div class="baas-project-rollback-result">' +
      '<h3>' + Drupal.t('Rollback Results') + '</h3>' +
      '<ul>';
    
    if (data.projects_removed) {
      html += '<li>' + Drupal.t('Projects removed: @count', {'@count': data.projects_removed}) + '</li>';
    }
    
    if (data.templates_reverted) {
      html += '<li>' + Drupal.t('Entity templates reverted: @count', {'@count': data.templates_reverted}) + '</li>';
    }
    
    if (data.fields_reverted) {
      html += '<li>' + Drupal.t('Entity fields reverted: @count', {'@count': data.fields_reverted}) + '</li>';
    }
    
    html += '</ul></div>';
    
    $container.find('.baas-project-message').after(html);
  }

  /**
   * 格式化数字。
   */
  function formatNumber(num) {
    return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  /**
   * 格式化日期。
   */
  function formatDate(timestamp) {
    var date = new Date(timestamp * 1000);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
  }

})(jQuery, Drupal, drupalSettings);