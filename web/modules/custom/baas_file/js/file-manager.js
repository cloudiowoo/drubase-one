/**
 * BaaS文件管理器JavaScript
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  // 全局实例控制
  if (window.baasFileManagerInstance) {
    return; // 防止创建多个实例
  }

  /**
   * 文件管理器主类
   */
  function BaasFileManager() {
    // 防止多实例化
    if (window.baasFileManagerInstance) {
      return window.baasFileManagerInstance;
    }
    window.baasFileManagerInstance = this;
    
    this.currentView = 'grid';
    this.currentProject = null;
    this.currentTenant = null;
    this.currentPage = 1;
    this.pageSize = 20;
    this.apiEndpoint = drupalSettings.baasFile.apiEndpoint || '/file-manager/api';
    this.permissions = drupalSettings.baasFile.permissions;
    this.isDeleting = false; // 防止重复删除
    this.isUploading = false; // 防止重复上传
    this.eventsInitialized = false; // 防止重复绑定事件
    this.init();
  }

  BaasFileManager.prototype = {
    /**
     * 初始化文件管理器
     */
    init: function() {
      this.bindEvents();
      this.loadAllFiles();
    },

    /**
     * 绑定事件
     */
    bindEvents: function() {
      var self = this;
      
      // 防止重复绑定事件
      if (this.eventsInitialized) {
        return;
      }
      this.eventsInitialized = true;

      // 项目选择（移除租户选择功能）
      $('.project-item').on('click', function() {
        var projectId = $(this).data('project-id');
        var tenantId = $(this).data('tenant-id');
        self.selectProject(projectId, tenantId);
      });

      // 视图切换
      $('.view-grid').on('click', function() {
        self.switchView('grid');
      });

      $('.view-list').on('click', function() {
        self.switchView('list');
      });

      // 上传文件按钮
      $('.upload-file-btn').on('click', function() {
        self.showUploadModal();
      });

      // 上传表单提交
      $('#upload-submit').on('click', function() {
        // 防止重复上传
        if (self.isUploading) {
          return;
        }
        self.uploadFiles();
      });

      // 统计信息按钮
      $('.statistics-btn').on('click', function() {
        self.showStatistics();
      });

      // 文件点击事件（委托）
      $(document).on('click', '.file-item, .file-list-row', function() {
        var fileId = $(this).data('file-id');
        var projectId = $(this).data('project-id');
        var tenantId = $(this).data('tenant-id');
        self.showFileDetail(fileId, projectId, tenantId);
      });

      // 分页事件（委托）
      $(document).on('click', '.page-btn', function() {
        var page = $(this).data('page');
        if (page && !$(this).hasClass('disabled')) {
          self.loadFiles(page);
        }
      });

      // 模态框关闭
      $('.btn-close, [data-bs-dismiss="modal"]').on('click', function() {
        $(this).closest('.modal').removeClass('show');
      });

      // 下载文件
      $(document).on('click', '.download-btn', function() {
        var fileUrl = $(this).data('file-url');
        if (fileUrl) {
          window.open(fileUrl, '_blank');
        }
      });

      // 删除文件
      $(document).on('click', '.delete-btn', function() {
        var fileId = $(this).data('file-id');
        var projectId = $(this).data('project-id');
        var tenantId = $(this).data('tenant-id');
        
        // 防止重复删除
        if (self.isDeleting) {
          return;
        }
        
        if (confirm('确定要删除这个文件吗？')) {
          self.deleteFile(fileId, projectId, tenantId);
        }
      });
    },

    /**
     * 选择项目
     */
    selectProject: function(projectId, tenantId) {
      $('.project-item').removeClass('active');
      $('.project-item[data-project-id="' + projectId + '"]').addClass('active');
      
      this.currentProject = projectId;
      this.currentTenant = tenantId;
      this.updateBreadcrumb(this.getProjectName(projectId));
      this.loadProjectFiles(projectId, tenantId);
    },

    /**
     * 切换视图
     */
    switchView: function(view) {
      this.currentView = view;
      $('.view-grid, .view-list').removeClass('active');
      $('.view-' + view).addClass('active');
      this.renderFiles(this.currentFiles);
    },

    /**
     * 更新面包屑
     */
    updateBreadcrumb: function(text) {
      $('.breadcrumb-item.active').text(text);
    },

    /**
     * 加载所有文件
     */
    loadAllFiles: function() {
      this.showLoading();
      // 显示空状态，提示用户选择项目
      this.showEmptyState('请选择项目查看文件');
    },

    /**
     * 加载项目文件
     */
    loadProjectFiles: function(projectId, tenantId) {
      var self = this;
      this.showLoading();
      
      $.ajax({
        url: this.apiEndpoint + '/' + tenantId + '/projects/' + projectId + '/media',
        method: 'GET',
        headers: this.getAuthHeaders(),
        data: {
          page: this.currentPage,
          limit: this.pageSize
        },
        success: function(response) {
          if (response.success) {
            self.currentFiles = response.data.files || [];
            self.renderFiles(self.currentFiles);
            self.renderPagination(response.data.pagination);
          } else {
            self.showError('加载项目文件失败: ' + response.error);
          }
        },
        error: function() {
          self.showError('网络错误，请重试');
        }
      });
    },

    /**
     * 渲染文件列表
     */
    renderFiles: function(files) {
      var $content = $('#file-content');
      
      if (!files || files.length === 0) {
        this.showEmptyState('暂无文件');
        return;
      }

      var html = '';
      if (this.currentView === 'grid') {
        html = this.renderGridView(files);
      } else {
        html = this.renderListView(files);
      }
      
      $content.html(html);
    },

    /**
     * 渲染网格视图
     */
    renderGridView: function(files) {
      var html = '<div class="file-grid">';
      
      files.forEach(function(file) {
        var icon = this.getFileIcon(file.filemime);
        html += '<div class="file-item" data-file-id="' + file.id + '" data-project-id="' + (file.project_id || '') + '" data-tenant-id="' + (file.tenant_id || '') + '">';
        html += '  <div class="file-icon">' + icon + '</div>';
        html += '  <div class="file-name" title="' + file.filename + '">' + this.truncateString(file.filename, 20) + '</div>';
        html += '  <div class="file-meta">' + file.size_formatted + '</div>';
        html += '</div>';
      }, this);
      
      html += '</div>';
      return html;
    },

    /**
     * 渲染列表视图
     */
    renderListView: function(files) {
      var html = '<div class="file-list">';
      html += '<div class="file-list-header">';
      html += '  <div>文件名</div>';
      html += '  <div>类型</div>';
      html += '  <div class="file-project">项目</div>';
      html += '  <div>大小</div>';
      html += '  <div>修改时间</div>';
      html += '</div>';
      
      files.forEach(function(file) {
        var fileType = this.getFileTypeLabel(file.filemime);
        var projectName = file.project_name || '';
        var modifiedTime = file.created ? this.formatDate(file.created) : '';
        
        html += '<div class="file-list-row" data-file-id="' + file.id + '" data-project-id="' + (file.project_id || '') + '" data-tenant-id="' + (file.tenant_id || '') + '">';
        html += '  <div class="file-name">' + file.filename + '</div>';
        html += '  <div class="file-type">' + fileType + '</div>';
        html += '  <div class="file-project">' + projectName + '</div>';
        html += '  <div>' + file.size_formatted + '</div>';
        html += '  <div>' + modifiedTime + '</div>';
        html += '</div>';
      }, this);
      
      html += '</div>';
      return html;
    },

    /**
     * 渲染分页器
     */
    renderPagination: function(pagination) {
      if (!pagination || pagination.pages <= 1) {
        $('#file-pagination').empty();
        return;
      }

      var html = '';
      var currentPage = pagination.page;
      var totalPages = pagination.pages;

      // 上一页
      html += '<button class="page-btn ' + (currentPage <= 1 ? 'disabled' : '') + '" data-page="' + (currentPage - 1) + '">‹</button>';

      // 页码
      for (var i = Math.max(1, currentPage - 2); i <= Math.min(totalPages, currentPage + 2); i++) {
        html += '<button class="page-btn ' + (i === currentPage ? 'active' : '') + '" data-page="' + i + '">' + i + '</button>';
      }

      // 下一页
      html += '<button class="page-btn ' + (currentPage >= totalPages ? 'disabled' : '') + '" data-page="' + (currentPage + 1) + '">›</button>';

      $('#file-pagination').html(html);
    },

    /**
     * 显示上传模态框
     */
    showUploadModal: function() {
      var $modal = $('#uploadModal');
      
      // 预选项目
      if (this.currentProject) {
        $('#upload-project').val(this.currentProject);
      }
      
      $modal.addClass('show');
    },

    /**
     * 上传文件
     */
    uploadFiles: function() {
      var self = this;
      
      // 防止重复上传
      if (this.isUploading) {
        return;
      }
      
      var projectId = $('#upload-project').val();
      var files = $('#upload-files')[0].files;
      
      if (!projectId) {
        alert('请选择项目');
        return;
      }
      
      if (!files.length) {
        alert('请选择文件');
        return;
      }
      
      // 设置上传状态
      this.isUploading = true;
      
      var formData = new FormData();
      formData.append('title', 'File Upload via Manager');
      formData.append('description', 'Uploaded through file manager');
      
      for (var i = 0; i < files.length; i++) {
        formData.append('file_' + i, files[i]);
      }
      
      $('.upload-progress').show();
      $('#upload-submit').prop('disabled', true).text('上传中...');
      
      // 这里需要调用实际的上传API
      var tenantId = this.getProjectTenantId(projectId);
      
      $.ajax({
        url: this.apiEndpoint + '/' + tenantId + '/projects/' + projectId + '/entities/file_upload/create',
        method: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        headers: this.getAuthHeaders(),
        xhr: function() {
          var xhr = new window.XMLHttpRequest();
          xhr.upload.addEventListener('progress', function(evt) {
            if (evt.lengthComputable) {
              var percentComplete = evt.loaded / evt.total * 100;
              $('.progress-bar').css('width', percentComplete + '%');
            }
          }, false);
          return xhr;
        },
        success: function(response) {
          if (response.success) {
            $('#uploadModal').removeClass('show');
            self.showSuccess('文件上传成功');
            
            // 清空文件选择器
            $('#upload-files').val('');
            
            // 重新加载当前项目文件
            if (self.currentProject) {
              self.loadProjectFiles(self.currentProject, self.currentTenant);
            }
          } else {
            self.showError('上传失败: ' + (response.error || '未知错误'));
          }
        },
        error: function(xhr) {
          if (xhr.status === 429) {
            self.showError('上传正在进行中，请稍候再试');
          } else {
            self.showError('上传失败，请重试');
          }
        },
        complete: function() {
          // 重置上传状态
          self.isUploading = false;
          $('.upload-progress').hide();
          $('.progress-bar').css('width', '0%');
          $('#upload-submit').prop('disabled', false).text('上传');
        }
      });
    },

    /**
     * 显示文件详情
     */
    showFileDetail: function(fileId, projectId, tenantId) {
      var self = this;
      
      $.ajax({
        url: this.apiEndpoint + '/' + tenantId + '/projects/' + projectId + '/files/' + fileId,
        method: 'GET',
        headers: this.getAuthHeaders(),
        success: function(response) {
          if (response.success) {
            self.renderFileDetail(response.data);
            $('#fileDetailModal').addClass('show');
          } else {
            self.showError('获取文件详情失败: ' + response.error);
          }
        },
        error: function(xhr) {
          if (xhr.status === 401) {
            self.showError('请先登录以查看文件详情');
          } else {
            self.showError('网络错误，请重试');
          }
        }
      });
    },

    /**
     * 渲染文件详情
     */
    renderFileDetail: function(file) {
      var html = '<div class="file-detail">';
      html += '<div class="row">';
      html += '  <div class="col-md-6">';
      html += '    <h6>基本信息</h6>';
      html += '    <p><strong>文件名:</strong> ' + file.filename + '</p>';
      html += '    <p><strong>大小:</strong> ' + file.filesize_formatted + '</p>';
      html += '    <p><strong>类型:</strong> ' + file.mime_type + '</p>';
      html += '    <p><strong>创建时间:</strong> ' + file.created_formatted + '</p>';
      html += '    <p><strong>修改时间:</strong> ' + file.changed_formatted + '</p>';
      html += '  </div>';
      html += '  <div class="col-md-6">';
      html += '    <h6>上传信息</h6>';
      html += '    <p><strong>上传者:</strong> ' + file.uploaded_by + '</p>';
      html += '    <p><strong>上传时间:</strong> ' + file.uploaded_formatted + '</p>';
      html += '    <p><strong>上传IP:</strong> ' + file.upload_ip + '</p>';
      html += '    <p><strong>项目:</strong> ' + file.project_id + '</p>';
      html += '  </div>';
      html += '</div>';
      
      if (file.is_image && file.url) {
        html += '<div class="file-preview mt-3">';
        html += '  <h6>预览</h6>';
        html += '  <img src="' + file.url + '" alt="' + file.filename + '" style="max-width: 100%; max-height: 300px; border: 1px solid #ddd; border-radius: 4px;">';
        html += '</div>';
      }
      
      html += '</div>';
      
      $('#file-detail-content').html(html);
      
      // 设置操作按钮数据
      $('.download-btn').data('file-url', file.url);
      $('.delete-btn').data('file-id', file.id)
                     .data('project-id', file.project_id)
                     .data('tenant-id', file.tenant_id);
    },

    /**
     * 删除文件
     */
    deleteFile: function(fileId, projectId, tenantId) {
      var self = this;
      
      // 设置删除状态，防止重复调用
      if (this.isDeleting) {
        return;
      }
      this.isDeleting = true;
      
      // 禁用删除按钮
      $('.delete-btn').prop('disabled', true).text('删除中...');
      
      $.ajax({
        url: this.apiEndpoint + '/' + tenantId + '/projects/' + projectId + '/files/' + fileId,
        method: 'DELETE',
        headers: this.getAuthHeaders(),
        success: function(response) {
          if (response.success) {
            $('#fileDetailModal').removeClass('show');
            
            // 处理已删除的情况
            var message = response.data && response.data.already_deleted ? 
              '文件已删除' : 
              '文件删除成功: ' + (response.data ? response.data.filename : '');
            
            self.showSuccess(message);
            
            // 重新加载当前项目文件
            if (self.currentProject) {
              self.loadProjectFiles(self.currentProject, self.currentTenant);
            }
          } else {
            self.showError('删除失败: ' + response.error);
          }
        },
        error: function(xhr) {
          if (xhr.status === 401) {
            self.showError('请先登录以删除文件');
          } else if (xhr.status === 403) {
            self.showError('权限不足：需要编辑者或管理员权限');
          } else if (xhr.status === 404) {
            // 文件不存在时也算成功（幂等性）
            $('#fileDetailModal').removeClass('show');
            self.showSuccess('文件已删除');
            if (self.currentProject) {
              self.loadProjectFiles(self.currentProject, self.currentTenant);
            }
          } else {
            self.showError('删除失败，请重试');
          }
        },
        complete: function() {
          // 重置删除状态
          self.isDeleting = false;
          $('.delete-btn').prop('disabled', false).text('删除');
        }
      });
    },

    /**
     * 显示统计信息
     */
    showStatistics: function() {
      // 这里可以显示详细的统计信息模态框
      alert('统计功能开发中...');
    },

    /**
     * 获取认证头
     */
    getAuthHeaders: function() {
      // 对于文件管理器API，使用session认证，不需要JWT token
      return {
        'X-Requested-With': 'XMLHttpRequest'
      };
    },

    /**
     * 获取JWT Token
     */
    getJwtToken: function() {
      // 优先从Drupal设置获取token
      if (drupalSettings.baasFile && drupalSettings.baasFile.jwtToken) {
        return drupalSettings.baasFile.jwtToken;
      }
      // 备选：从localStorage获取token
      return localStorage.getItem('baas_jwt_token') || '';
    },

    /**
     * 获取文件图标
     */
    getFileIcon: function(mimeType) {
      if (mimeType.startsWith('image/')) return '🖼️';
      if (mimeType.startsWith('video/')) return '🎥';
      if (mimeType.startsWith('audio/')) return '🎵';
      if (mimeType.includes('pdf')) return '📄';
      if (mimeType.includes('word')) return '📝';
      if (mimeType.includes('excel')) return '📊';
      if (mimeType.includes('zip') || mimeType.includes('rar')) return '📦';
      return '📄';
    },

    /**
     * 获取文件类型标签
     */
    getFileTypeLabel: function(mimeType) {
      if (mimeType.startsWith('image/')) return '图片';
      if (mimeType.startsWith('video/')) return '视频';
      if (mimeType.startsWith('audio/')) return '音频';
      if (mimeType.includes('pdf')) return 'PDF';
      if (mimeType.includes('word')) return 'Word';
      if (mimeType.includes('excel')) return 'Excel';
      if (mimeType.includes('zip') || mimeType.includes('rar')) return '压缩包';
      return '其他';
    },

    /**
     * 格式化日期
     */
    formatDate: function(timestamp) {
      var date = new Date(timestamp * 1000);
      return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
    },

    /**
     * 截断字符串
     */
    truncateString: function(str, length) {
      if (str.length <= length) return str;
      return str.substring(0, length) + '...';
    },

    /**
     * 获取租户名称
     */
    getTenantName: function(tenantId) {
      var $tenant = $('.tenant-item[data-tenant-id="' + tenantId + '"]');
      return $tenant.find('.tenant-name').text() || tenantId;
    },

    /**
     * 获取项目名称
     */
    getProjectName: function(projectId) {
      var $project = $('.project-item[data-project-id="' + projectId + '"]');
      return $project.find('.project-name').text() || projectId;
    },

    /**
     * 获取项目所属租户ID
     */
    getProjectTenantId: function(projectId) {
      var $project = $('.project-item[data-project-id="' + projectId + '"]');
      return $project.data('tenant-id');
    },

    /**
     * 显示加载状态
     */
    showLoading: function() {
      $('#file-content').html('<div class="loading-state"><div class="spinner"></div><p>正在加载文件...</p></div>');
    },

    /**
     * 显示空状态
     */
    showEmptyState: function(message) {
      $('#file-content').html('<div class="empty-state"><p>' + message + '</p></div>');
    },

    /**
     * 显示错误信息
     */
    showError: function(message) {
      // 这里可以使用Toast或者其他通知组件
      alert('错误: ' + message);
    },

    /**
     * 显示成功信息
     */
    showSuccess: function(message) {
      // 这里可以使用Toast或者其他通知组件
      alert('成功: ' + message);
    }
  };

  /**
   * Drupal行为
   */
  Drupal.behaviors.baasFileManager = {
    attach: function (context, settings) {
      // 使用数据属性确保只初始化一次
      $('.baas-file-manager', context).each(function() {
        var $this = $(this);
        if (!$this.data('baas-file-manager-initialized')) {
          $this.data('baas-file-manager-initialized', true);
          new BaasFileManager();
        }
      });
    }
  };

})(jQuery, Drupal, drupalSettings);