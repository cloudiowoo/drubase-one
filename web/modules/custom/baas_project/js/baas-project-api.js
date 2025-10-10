/**
 * BaaS Project API Client
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * BaaS Project API Client.
   */
  Drupal.baasProjectApi = {
    
    /**
     * API基础URL。
     */
    baseUrl: '/api/v1/projects',
    
    /**
     * 默认请求选项。
     */
    defaultOptions: {
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    },

    /**
     * 获取项目列表。
     */
    getProjects: function (filters, pagination) {
      var params = $.extend({}, filters, pagination);
      var url = this.baseUrl + '?' + $.param(params);
      
      return this.request('GET', url);
    },

    /**
     * 获取项目详情。
     */
    getProject: function (projectId) {
      var url = this.baseUrl + '/' + encodeURIComponent(projectId);
      return this.request('GET', url);
    },

    /**
     * 创建项目。
     */
    createProject: function (projectData) {
      return this.request('POST', this.baseUrl, projectData);
    },

    /**
     * 更新项目。
     */
    updateProject: function (projectId, projectData) {
      var url = this.baseUrl + '/' + encodeURIComponent(projectId);
      return this.request('PUT', url, projectData);
    },

    /**
     * 删除项目。
     */
    deleteProject: function (projectId) {
      var url = this.baseUrl + '/' + encodeURIComponent(projectId);
      return this.request('DELETE', url);
    },

    /**
     * 获取项目成员列表。
     */
    getProjectMembers: function (projectId, filters, pagination) {
      var params = $.extend({}, filters, pagination);
      var url = this.baseUrl + '/' + encodeURIComponent(projectId) + '/members?' + $.param(params);
      
      return this.request('GET', url);
    },

    /**
     * 添加项目成员。
     */
    addProjectMember: function (projectId, memberData) {
      var url = this.baseUrl + '/' + encodeURIComponent(projectId) + '/members';
      return this.request('POST', url, memberData);
    },

    /**
     * 更新项目成员角色。
     */
    updateProjectMember: function (projectId, userId, memberData) {
      var url = this.baseUrl + '/' + encodeURIComponent(projectId) + '/members/' + encodeURIComponent(userId);
      return this.request('PUT', url, memberData);
    },

    /**
     * 移除项目成员。
     */
    removeProjectMember: function (projectId, userId) {
      var url = this.baseUrl + '/' + encodeURIComponent(projectId) + '/members/' + encodeURIComponent(userId);
      return this.request('DELETE', url);
    },

    /**
     * 转移项目所有权。
     */
    transferOwnership: function (projectId, newOwnerId) {
      var url = this.baseUrl + '/' + encodeURIComponent(projectId) + '/transfer-ownership';
      return this.request('POST', url, { new_owner_id: newOwnerId });
    },

    /**
     * 获取项目使用统计。
     */
    getProjectUsage: function (projectId, filters) {
      var params = filters || {};
      var url = this.baseUrl + '/' + encodeURIComponent(projectId) + '/usage?' + $.param(params);
      
      return this.request('GET', url);
    },

    /**
     * 执行HTTP请求。
     */
    request: function (method, url, data) {
      var options = $.extend(true, {}, this.defaultOptions, {
        method: method,
        url: url
      });

      if (data) {
        options.data = JSON.stringify(data);
      }

      // 添加CSRF令牌（如果可用）
      if (drupalSettings.baas_auth && drupalSettings.baas_auth.csrf_token) {
        options.headers['X-CSRF-Token'] = drupalSettings.baas_auth.csrf_token;
      }

      // 添加JWT令牌（如果可用）
      if (drupalSettings.baas_auth && drupalSettings.baas_auth.jwt_token) {
        options.headers['Authorization'] = 'Bearer ' + drupalSettings.baas_auth.jwt_token;
      }

      return $.ajax(options)
        .fail(function (xhr, status, error) {
          console.error('BaaS Project API Error:', {
            method: method,
            url: url,
            status: xhr.status,
            error: error,
            response: xhr.responseJSON
          });
        });
    },

    /**
     * 处理API响应。
     */
    handleResponse: function (response, successCallback, errorCallback) {
      if (response.success) {
        if (typeof successCallback === 'function') {
          successCallback(response.data, response);
        }
      } else {
        if (typeof errorCallback === 'function') {
          errorCallback(response.error, response);
        } else {
          console.error('BaaS Project API Error:', response.error);
        }
      }
    },

    /**
     * 显示错误消息。
     */
    showError: function (message, container) {
      var $container = container ? $(container) : $('body');
      var $error = $('<div class="baas-project-message error">' + message + '</div>');
      
      $container.find('.baas-project-message').remove();
      $container.prepend($error);
      
      setTimeout(function () {
        $error.fadeOut();
      }, 5000);
    },

    /**
     * 显示成功消息。
     */
    showSuccess: function (message, container) {
      var $container = container ? $(container) : $('body');
      var $success = $('<div class="baas-project-message success">' + message + '</div>');
      
      $container.find('.baas-project-message').remove();
      $container.prepend($success);
      
      setTimeout(function () {
        $success.fadeOut();
      }, 3000);
    },

    /**
     * 格式化错误消息。
     */
    formatError: function (error) {
      if (typeof error === 'string') {
        return error;
      }
      
      if (error.message) {
        return error.message;
      }
      
      if (error.error) {
        return error.error;
      }
      
      return Drupal.t('An unknown error occurred.');
    },

    /**
     * 验证项目数据。
     */
    validateProjectData: function (data) {
      var errors = [];
      
      if (!data.name || data.name.trim() === '') {
        errors.push(Drupal.t('Project name is required.'));
      }
      
      if (!data.machine_name || data.machine_name.trim() === '') {
        errors.push(Drupal.t('Project machine name is required.'));
      }
      
      if (data.machine_name && !/^[a-z0-9_]+$/.test(data.machine_name)) {
        errors.push(Drupal.t('Project machine name can only contain lowercase letters, numbers, and underscores.'));
      }
      
      return errors;
    },

    /**
     * 验证成员数据。
     */
    validateMemberData: function (data) {
      var errors = [];
      
      if (!data.user_id) {
        errors.push(Drupal.t('User ID is required.'));
      }
      
      if (!data.role) {
        errors.push(Drupal.t('Role is required.'));
      }
      
      var validRoles = ['owner', 'admin', 'editor', 'viewer'];
      if (data.role && validRoles.indexOf(data.role) === -1) {
        errors.push(Drupal.t('Invalid role. Must be one of: @roles', {
          '@roles': validRoles.join(', ')
        }));
      }
      
      return errors;
    },

    /**
     * 获取可用角色列表。
     */
    getAvailableRoles: function () {
      return [
        { value: 'owner', label: Drupal.t('Owner') },
        { value: 'admin', label: Drupal.t('Administrator') },
        { value: 'editor', label: Drupal.t('Editor') },
        { value: 'viewer', label: Drupal.t('Viewer') }
      ];
    },

    /**
     * 格式化日期。
     */
    formatDate: function (timestamp) {
      var date = new Date(timestamp * 1000);
      return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
    },

    /**
     * 格式化文件大小。
     */
    formatFileSize: function (bytes) {
      if (bytes === 0) return '0 Bytes';
      
      var k = 1024;
      var sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
      var i = Math.floor(Math.log(bytes) / Math.log(k));
      
      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    },

    /**
     * 防抖函数。
     */
    debounce: function (func, wait, immediate) {
      var timeout;
      return function () {
        var context = this, args = arguments;
        var later = function () {
          timeout = null;
          if (!immediate) func.apply(context, args);
        };
        var callNow = immediate && !timeout;
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
        if (callNow) func.apply(context, args);
      };
    }
  };

  // 将API客户端暴露给全局
  window.BaasProjectApi = Drupal.baasProjectApi;

})(jQuery, Drupal, drupalSettings);