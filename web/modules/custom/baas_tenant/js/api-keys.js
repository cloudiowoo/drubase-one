/**
 * API密钥管理界面JavaScript功能
 */

(function ($, Drupal) {
  'use strict';

  /**
   * API密钥管理行为
   */
  Drupal.behaviors.baasApiKeys = {
    attach: function (context, settings) {
      // 初始化API密钥显示/隐藏功能
      $('.toggle-key-btn', context).not('.toggle-key-processed').each(function () {
        const $button = $(this);
        $button.addClass('toggle-key-processed');
        
        const $keyElement = $button.closest('.api-key-cell').find('.api-key-value');
        const fullKey = $keyElement.data('full-key');
        const maskedKey = $keyElement.text();
        let isRevealed = false;

        $button.on('click', function (e) {
          e.preventDefault();
          
          if (isRevealed) {
            // 隐藏密钥
            $keyElement.text(maskedKey).removeClass('revealed');
            $button.text(Drupal.t('显示'));
            isRevealed = false;
          } else {
            // 显示密钥
            $keyElement.text(fullKey).addClass('revealed');
            $button.text(Drupal.t('隐藏'));
            isRevealed = true;
          }
        });
      });

      // 初始化复制密钥功能
      $('.copy-key-btn', context).not('.copy-key-processed').each(function () {
        const $button = $(this);
        $button.addClass('copy-key-processed');
        
        const $keyElement = $button.closest('.api-key-cell').find('.api-key-value');

        $button.on('click', function (e) {
          e.preventDefault();
          
          const fullKey = $keyElement.data('full-key');
          
          // 使用现代剪贴板API
          if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(fullKey).then(function () {
              showCopySuccess($button, $keyElement);
            }).catch(function () {
              fallbackCopyTextToClipboard(fullKey, $button, $keyElement);
            });
          } else {
            fallbackCopyTextToClipboard(fullKey, $button, $keyElement);
          }
        });
      });
    }
  };

  /**
   * 显示复制成功反馈
   */
  function showCopySuccess($button, $keyElement) {
    const originalText = $button.text();
    const originalClass = $keyElement.attr('class');
    
    // 更新按钮文本和样式
    $button.text(Drupal.t('已复制！'));
    $keyElement.addClass('copy-success');
    
    // 2秒后恢复原状
    setTimeout(function () {
      $button.text(originalText);
      $keyElement.attr('class', originalClass);
    }, 2000);
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
    
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    
    try {
      const successful = document.execCommand('copy');
      if (successful) {
        showCopySuccess($button, $keyElement);
      } else {
        console.warn('复制失败');
        alert(Drupal.t('复制失败，请手动复制API密钥'));
      }
    } catch (err) {
      console.warn('复制功能不支持');
      alert(Drupal.t('您的浏览器不支持自动复制，请手动复制API密钥：\n\n') + text);
    }
    
    document.body.removeChild(textArea);
  }

})(jQuery, Drupal);