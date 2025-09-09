/**
 * @file
 * JavaScript for entity data table.
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * 实体数据表格行为。
   */
  Drupal.behaviors.entityDataTable = {
    attach: function (context, settings) {
      // 确保表单提交时保留排序和分页参数
      $('.entity-data-filters form', context).once('entity-data-filters').each(function () {
        var $form = $(this);

        // 获取当前URL查询参数
        var urlParams = new URLSearchParams(window.location.search);

        // 添加隐藏字段保留排序和分页参数
        if (urlParams.has('sort')) {
          $form.append('<input type="hidden" name="sort" value="' + urlParams.get('sort') + '">');
        }
        if (urlParams.has('direction')) {
          $form.append('<input type="hidden" name="direction" value="' + urlParams.get('direction') + '">');
        }
        if (urlParams.has('page')) {
          $form.append('<input type="hidden" name="page" value="' + urlParams.get('page') + '">');
        }
        if (urlParams.has('limit')) {
          $form.append('<input type="hidden" name="limit" value="' + urlParams.get('limit') + '">');
        }

        // 表单提交处理
        $form.on('submit', function (e) {
          // 如果没有填写任何过滤条件，阻止提交
          var hasFilters = false;
          $('input[type="text"]', $form).each(function() {
            if ($(this).val().trim() !== '') {
              hasFilters = true;
              return false; // 跳出循环
            }
          });

          if (!hasFilters) {
            e.preventDefault();
            alert(Drupal.t('请至少填写一个过滤条件'));
          }
        });
      });

      // 高亮当前排序列
      $('.entity-data-table th.sortable', context).once('entity-data-table-sort').each(function () {
        var urlParams = new URLSearchParams(window.location.search);
        var currentSort = urlParams.get('sort');

        if (currentSort && $(this).find('a').attr('href').indexOf('sort=' + currentSort) !== -1) {
          $(this).addClass('is-active');
        }
      });

      // 表格行悬停效果
      $('.entity-data-table tbody tr', context).once('entity-data-table-hover').hover(
        function () {
          $(this).addClass('is-hovered');
        },
        function () {
          $(this).removeClass('is-hovered');
        }
      );

      // 响应式表格处理
      $(window).once('entity-data-table-responsive').on('resize', function() {
        adjustTableResponsiveness();
      });

      // 初始调整
      adjustTableResponsiveness();

      /**
       * 调整表格响应式显示
       */
      function adjustTableResponsiveness() {
        $('.entity-data-table.responsive-enabled', context).each(function() {
          var $table = $(this);
          var tableWidth = $table.width();
          var containerWidth = $table.parent().width();

          if (tableWidth > containerWidth) {
            $table.addClass('is-responsive');
          } else {
            $table.removeClass('is-responsive');
          }
        });
      }
    }
  };

})(jQuery, Drupal, drupalSettings);
