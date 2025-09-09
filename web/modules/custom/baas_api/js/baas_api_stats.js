/**
 * @file
 * BaaS API统计页面的JavaScript增强.
 */

(function ($, Drupal, drupalSettings) {
  'use strict';

  /**
   * API统计相关行为.
   *
   * @type {Drupal~behavior}
   */
  Drupal.behaviors.baasApiStats = {
    attach: function (context, settings) {
      // 初始化图表
      this.initChart(context, settings);

      // 增强过滤表单
      this.enhanceFilterForm(context, settings);
    },

    /**
     * 初始化请求趋势图表.
     */
    initChart: function (context, settings) {
      if (typeof Chart === 'undefined') {
        return;
      }

      // 确保只执行一次
      $('#api-requests-chart', context).once('baas-api-stats-chart').each(function () {
        if (!drupalSettings.baas_api || !drupalSettings.baas_api.chart_data) {
          return;
        }

        var data = drupalSettings.baas_api.chart_data;
        var ctx = this.getContext('2d');

        new Chart(ctx, {
          type: 'line',
          data: {
            labels: data.labels,
            datasets: data.datasets
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              title: {
                display: true,
                text: Drupal.t('API请求历史趋势')
              },
              legend: {
                position: 'top',
              }
            },
            scales: {
              y: {
                beginAtZero: true,
                title: {
                  display: true,
                  text: Drupal.t('请求数量')
                }
              },
              x: {
                title: {
                  display: true,
                  text: Drupal.t('日期')
                }
              }
            }
          }
        });
      });
    },

    /**
     * 增强过滤表单.
     */
    enhanceFilterForm: function (context, settings) {
      // 日期选择器增强
      $('.baas-api-stats__filters .form-date', context).once('baas-api-stats-date').each(function () {
        // 为日期选择器添加快捷链接
        var $dateContainer = $(this).closest('.form-item');
        if ($dateContainer.length === 0) {
          return;
        }

        var $quickLinks = $('<div class="date-quick-links"></div>');

        // 添加预设日期范围
        $quickLinks.append('<a href="#" data-days="7">' + Drupal.t('最近7天') + '</a>');
        $quickLinks.append('<a href="#" data-days="30">' + Drupal.t('最近30天') + '</a>');
        $quickLinks.append('<a href="#" data-days="90">' + Drupal.t('最近3个月') + '</a>');

        $dateContainer.append($quickLinks);

        // 添加点击事件
        $quickLinks.find('a').on('click', function (e) {
          e.preventDefault();
          var days = $(this).data('days');

          var endDate = new Date();
          var startDate = new Date();
          startDate.setDate(endDate.getDate() - days);

          // 格式化日期为YYYY-MM-DD
          var formatDate = function (date) {
            var year = date.getFullYear();
            var month = (date.getMonth() + 1).toString().padStart(2, '0');
            var day = date.getDate().toString().padStart(2, '0');
            return year + '-' + month + '-' + day;
          };

          // 更新日期选择器
          $('.baas-api-stats__filters [name="start_date"]').val(formatDate(startDate));
          $('.baas-api-stats__filters [name="end_date"]').val(formatDate(endDate));
        });
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
