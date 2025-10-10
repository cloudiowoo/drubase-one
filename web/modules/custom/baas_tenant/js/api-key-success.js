/**
 * API密钥创建成功页面JavaScript功能
 */

(function ($, Drupal) {
  'use strict';

  /**
   * API密钥成功页面行为
   */
  Drupal.behaviors.baasApiKeySuccess = {
    attach: function (context, settings) {
      // 初始化复制API密钥功能
      $('#copy-api-key', context).not('.copy-api-key-processed').each(function () {
        $(this).addClass('copy-api-key-processed');
        
        $(this).on('click', function (e) {
          e.preventDefault();
          
          const $button = $(this);
          const $keyElement = $('#api-key-value');
          const apiKey = $keyElement.text().trim();
          
          // 使用现代剪贴板API
          if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(apiKey).then(function () {
              showCopySuccess($button, $keyElement);
            }).catch(function () {
              fallbackCopyTextToClipboard(apiKey, $button, $keyElement);
            });
          } else {
            fallbackCopyTextToClipboard(apiKey, $button, $keyElement);
          }
        });
      });

      // 页面加载完成后自动选中API密钥文本
      setTimeout(function () {
        const keyElement = document.getElementById('api-key-value');
        if (keyElement) {
          // 创建文本选择范围
          if (window.getSelection && document.createRange) {
            const range = document.createRange();
            range.selectNodeContents(keyElement);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
          }
        }
      }, 500);

      // 添加键盘快捷键 Ctrl+C 复制功能
      $(document).on('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'c') {
          const selection = window.getSelection();
          if (selection.toString() && selection.anchorNode && 
              $(selection.anchorNode).closest('#api-key-value').length > 0) {
            // 如果选中了API密钥，触发复制按钮
            $('#copy-api-key').trigger('click');
          }
        }
      });
    }
  };

  /**
   * 显示复制成功反馈
   */
  function showCopySuccess($button, $keyElement) {
    const originalText = $button.html();
    const originalClass = $keyElement.attr('class');
    
    // 更新按钮文本和样式
    $button.html('<i class="fas fa-check"></i> ' + Drupal.t('已复制！'));
    $button.removeClass('btn-primary').addClass('btn-success');
    $keyElement.addClass('copy-success');
    
    // 显示成功消息
    const $successMessage = $('<div class="alert alert-success mt-2">' + 
      '<i class="fas fa-check-circle"></i> ' + 
      Drupal.t('API密钥已成功复制到剪贴板！') + 
      '</div>');
    
    $keyElement.after($successMessage);
    
    // 3秒后恢复原状
    setTimeout(function () {
      $button.html(originalText);
      $button.removeClass('btn-success').addClass('btn-primary');
      $keyElement.attr('class', originalClass);
      $successMessage.fadeOut(function () {
        $successMessage.remove();
      });
    }, 3000);
  }

  /**
   * 后备的复制到剪贴板方法（适用于旧浏览器）
   */
  function fallbackCopyTextToClipboard(text, $button, $keyElement) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    
    // 避免滚动到底部
    textArea.style.top = '0';
    textArea.style.left = '0';
    textArea.style.position = 'fixed';
    textArea.style.opacity = '0';
    
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
      const successful = document.execCommand('copy');
      if (successful) {
        showCopySuccess($button, $keyElement);
      } else {
        showCopyError();
      }
    } catch (err) {
      console.warn('复制功能不支持:', err);
      showCopyError();
    }
    
    document.body.removeChild(textArea);
  }

  /**
   * 显示复制错误信息
   */
  function showCopyError() {
    const $errorMessage = $('<div class="alert alert-warning mt-2">' + 
      '<i class="fas fa-exclamation-triangle"></i> ' + 
      Drupal.t('无法自动复制，请手动选择并复制API密钥。') + 
      '</div>');
    
    $('#api-key-value').after($errorMessage);
    
    // 5秒后自动隐藏错误消息
    setTimeout(function () {
      $errorMessage.fadeOut(function () {
        $errorMessage.remove();
      });
    }, 5000);
  }

})(jQuery, Drupal);