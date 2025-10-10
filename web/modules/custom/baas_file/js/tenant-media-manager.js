/**
 * 租户媒体管理界面JavaScript
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.baasFileTenantMediaManager = {
    attach: function (context, settings) {
      const $manager = $('.baas-file-tenant-media-manager', context);
      if ($manager.length === 0) return;

      const tenantId = $manager.data('tenant-id');
      const apiEndpoints = settings.baasFile?.apiEndpoints || {};

      // 初始化媒体管理器
      const mediaManager = new TenantMediaManager(tenantId, apiEndpoints);
      mediaManager.init();
    }
  };

  /**
   * 租户媒体管理器类
   */
  class TenantMediaManager {
    constructor(tenantId, apiEndpoints) {
      this.tenantId = tenantId;
      this.apiEndpoints = apiEndpoints;
      this.currentPage = 1;
      this.currentFilters = {
        type: 'all',
        project_id: ''
      };
    }

    init() {
      this.bindEvents();
      this.loadMediaList();
    }

    bindEvents() {
      // 类型过滤器变化
      $('#media-type-filter').on('change', (e) => {
        this.currentFilters.type = e.target.value;
        this.currentPage = 1;
        this.loadMediaList();
      });

      // 项目过滤器变化
      $('#project-filter').on('change', (e) => {
        this.currentFilters.project_id = e.target.value;
        this.currentPage = 1;
        this.loadMediaList();
      });

      // 刷新按钮
      $('#refresh-media-list').on('click', () => {
        this.loadMediaList();
      });

      // 媒体项点击
      $(document).on('click', '.media-item', (e) => {
        const fileId = $(e.currentTarget).data('file-id');
        this.showMediaDetail(fileId);
      });

      // 关闭模态框
      $(document).on('click', '.close-modal, .modal', (e) => {
        if (e.target === e.currentTarget) {
          $('#media-detail-modal').hide();
        }
      });

      // 快速操作
      $('#export-media-list').on('click', () => {
        this.exportMediaList();
      });

      $('#cleanup-unused-files').on('click', () => {
        this.cleanupUnusedFiles();
      });
    }

    async loadMediaList() {
      $('#media-loading').show();
      $('#media-list').empty();

      try {
        const params = new URLSearchParams({
          page: this.currentPage,
          limit: 20,
          ...this.currentFilters
        });

        const response = await fetch(`${this.apiEndpoints.mediaList}?${params}`);
        const data = await response.json();

        if (data.success) {
          this.renderMediaList(data.data.files);
          this.renderPagination(data.data.pagination);
        } else {
          this.showError('加载媒体列表失败: ' + data.error);
        }
      } catch (error) {
        this.showError('网络错误: ' + error.message);
      } finally {
        $('#media-loading').hide();
      }
    }

    renderMediaList(files) {
      const $list = $('#media-list');
      
      if (files.length === 0) {
        $list.html('<div class="no-media">没有找到媒体文件</div>');
        return;
      }

      files.forEach(file => {
        const $item = this.createMediaItem(file);
        $list.append($item);
      });
    }

    createMediaItem(file) {
      const isImage = file.is_image || file.filemime?.startsWith('image/');
      const thumbnail = isImage 
        ? `<img src="${file.url || file.uri}" alt="${file.filename}" />`
        : `<div class="file-icon">${this.getFileIcon(file.filemime)}</div>`;

      return $(`
        <div class="media-item" data-file-id="${file.id}">
          <div class="media-item-thumbnail">
            ${thumbnail}
          </div>
          <div class="media-item-info">
            <div class="media-item-name" title="${file.filename}">
              ${this.truncateText(file.filename, 25)}
            </div>
            <div class="media-item-meta">
              ${file.size_formatted || this.formatFileSize(file.filesize)}
            </div>
          </div>
        </div>
      `);
    }

    renderPagination(pagination) {
      const $container = $('#media-pagination');
      $container.empty();

      if (pagination.pages <= 1) return;

      // 上一页
      if (pagination.page > 1) {
        $container.append(`
          <button class="btn btn-sm btn-secondary pagination-btn" data-page="${pagination.page - 1}">
            上一页
          </button>
        `);
      }

      // 页码
      for (let i = Math.max(1, pagination.page - 2); i <= Math.min(pagination.pages, pagination.page + 2); i++) {
        const isActive = i === pagination.page;
        $container.append(`
          <button class="btn btn-sm ${isActive ? 'btn-primary' : 'btn-secondary'} pagination-btn" 
                  data-page="${i}" ${isActive ? 'disabled' : ''}>
            ${i}
          </button>
        `);
      }

      // 下一页
      if (pagination.page < pagination.pages) {
        $container.append(`
          <button class="btn btn-sm btn-secondary pagination-btn" data-page="${pagination.page + 1}">
            下一页
          </button>
        `);
      }

      // 绑定分页事件
      $('.pagination-btn').on('click', (e) => {
        this.currentPage = parseInt($(e.target).data('page'));
        this.loadMediaList();
      });
    }

    async showMediaDetail(fileId) {
      try {
        const response = await fetch(`${this.apiEndpoints.mediaDelete.replace('{file_id}', fileId)}`);
        const data = await response.json();

        if (data.success) {
          this.renderMediaDetail(data.data.file);
          $('#media-detail-modal').show();
        }
      } catch (error) {
        this.showError('加载文件详情失败: ' + error.message);
      }
    }

    renderMediaDetail(file) {
      const $body = $('#media-detail-modal .modal-body');
      const isImage = file.is_image || file.filemime?.startsWith('image/');

      $body.html(`
        <div class="file-detail">
          ${isImage ? `<img src="${file.url}" style="max-width: 100%; margin-bottom: 1rem;" />` : ''}
          <table class="table">
            <tr><td>文件名</td><td>${file.filename}</td></tr>
            <tr><td>文件大小</td><td>${file.size_formatted}</td></tr>
            <tr><td>文件类型</td><td>${file.filemime}</td></tr>
            <tr><td>创建时间</td><td>${new Date(file.created * 1000).toLocaleString()}</td></tr>
            <tr><td>下载地址</td><td><a href="${file.url}" target="_blank">点击下载</a></td></tr>
          </table>
          <div class="file-actions">
            <button class="btn btn-danger delete-file-btn" data-file-id="${file.id}">删除文件</button>
          </div>
        </div>
      `);

      // 绑定删除事件
      $('.delete-file-btn').on('click', (e) => {
        if (confirm('确定要删除这个文件吗？')) {
          this.deleteFile($(e.target).data('file-id'));
        }
      });
    }

    async deleteFile(fileId) {
      try {
        const response = await fetch(this.apiEndpoints.mediaDelete.replace('{file_id}', fileId), {
          method: 'DELETE'
        });
        const data = await response.json();

        if (data.success) {
          $('#media-detail-modal').hide();
          this.loadMediaList();
          this.showSuccess('文件删除成功');
        } else {
          this.showError('删除失败: ' + data.error);
        }
      } catch (error) {
        this.showError('删除失败: ' + error.message);
      }
    }

    exportMediaList() {
      // 简单实现：打开新窗口显示导出功能
      window.open(`${this.apiEndpoints.mediaList}?export=csv`, '_blank');
    }

    cleanupUnusedFiles() {
      if (confirm('确定要清理未使用的文件吗？这个操作不可撤销。')) {
        // 实现清理功能
        this.showInfo('清理功能正在开发中...');
      }
    }

    getFileIcon(mimeType) {
      if (mimeType?.includes('image')) return '🖼️';
      if (mimeType?.includes('pdf')) return '📄';
      if (mimeType?.includes('video')) return '🎥';
      if (mimeType?.includes('audio')) return '🎵';
      return '📁';
    }

    truncateText(text, length) {
      return text.length > length ? text.substring(0, length) + '...' : text;
    }

    formatFileSize(bytes) {
      if (bytes === 0) return '0 Bytes';
      const k = 1024;
      const sizes = ['Bytes', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }

    showError(message) {
      console.error(message);
      // 可以集成更好的通知系统
      alert('错误: ' + message);
    }

    showSuccess(message) {
      console.log(message);
      alert('成功: ' + message);
    }

    showInfo(message) {
      console.info(message);
      alert('信息: ' + message);
    }
  }

})(jQuery, Drupal, drupalSettings);