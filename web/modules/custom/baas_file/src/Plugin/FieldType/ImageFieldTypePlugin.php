<?php

declare(strict_types=1);

namespace Drupal\baas_file\Plugin\FieldType;

use Drupal\Core\Form\FormStateInterface;

/**
 * 图片字段类型插件。
 *
 * 为动态实体提供图片上传和管理功能，支持图片预览和处理。
 *
 * @FieldTypePlugin(
 *   id = "image",
 *   label = @Translation("图片"),
 *   description = @Translation("允许上传和管理图片文件，支持预览和尺寸处理"),
 *   category = "media",
 *   weight = 5
 * )
 */
class ImageFieldTypePlugin extends FileFieldTypePlugin
{

  /**
   * {@inheritdoc}
   */
  public function getFieldType(): string
  {
    return 'image';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string
  {
    return $this->t('图片');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string
  {
    return $this->t('允许上传和管理图片文件，支持预览、尺寸处理和图片样式');
  }

  /**
   * {@inheritdoc}
   */
  public function getDrupalFieldType(): string
  {
    return 'image';
  }

  /**
   * {@inheritdoc}
   */
  public function getWidgetType(): string
  {
    return 'image_image';
  }

  /**
   * {@inheritdoc}
   */
  public function getFormatterType(): string
  {
    return 'image';
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultSettings(): array
  {
    return array_merge(parent::getDefaultSettings(), [
      'file_extensions' => 'png gif jpg jpeg webp',
      'max_filesize' => '5MB',
      'max_resolution' => '3840x2160', // 4K resolution
      'min_resolution' => '100x100',
      'alt_field' => TRUE,
      'alt_field_required' => FALSE,
      'title_field' => FALSE,
      'title_field_required' => FALSE,
      'file_directory' => '[tenant:id]/[project:id]/images/[current-date:custom:Y-m]',
      'default_image' => NULL,
      'image_style_preview' => 'thumbnail', // 预览时使用的图片样式
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getSettingsForm(array $settings, array $form, $form_state): array
  {
    $element = parent::getSettingsForm($settings, $form, $form_state);

    // 重写文件扩展名字段的默认值和描述
    $element['file_extensions']['#title'] = $this->t('允许的图片扩展名');
    $element['file_extensions']['#description'] = $this->t('用空格分隔多个扩展名，如：png jpg gif webp');
    $element['file_extensions']['#default_value'] = $settings['file_extensions'] ?? $this->getDefaultSettings()['file_extensions'];

    // 图片尺寸设置
    $element['max_resolution'] = [
      '#type' => 'textfield',
      '#title' => $this->t('最大分辨率'),
      '#default_value' => $settings['max_resolution'] ?? $this->getDefaultSettings()['max_resolution'],
      '#description' => $this->t('图片的最大分辨率，格式：宽x高（如：1920x1080）。超过此分辨率的图片将被自动缩放。'),
    ];

    $element['min_resolution'] = [
      '#type' => 'textfield',
      '#title' => $this->t('最小分辨率'),
      '#default_value' => $settings['min_resolution'] ?? $this->getDefaultSettings()['min_resolution'],
      '#description' => $this->t('图片的最小分辨率，格式：宽x高（如：100x100）。'),
    ];

    // Alt文本设置
    $element['alt_field'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用Alt文本字段'),
      '#default_value' => $settings['alt_field'] ?? $this->getDefaultSettings()['alt_field'],
      '#description' => $this->t('允许用户为图片添加Alt文本，提高可访问性'),
    ];

    $element['alt_field_required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Alt文本必填'),
      '#default_value' => $settings['alt_field_required'] ?? $this->getDefaultSettings()['alt_field_required'],
      '#description' => $this->t('要求用户必须填写Alt文本'),
      '#states' => [
        'visible' => [
          ':input[name="settings[alt_field]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Title字段设置
    $element['title_field'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用标题字段'),
      '#default_value' => $settings['title_field'] ?? $this->getDefaultSettings()['title_field'],
      '#description' => $this->t('允许用户为图片添加标题'),
    ];

    $element['title_field_required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('标题必填'),
      '#default_value' => $settings['title_field_required'] ?? $this->getDefaultSettings()['title_field_required'],
      '#description' => $this->t('要求用户必须填写图片标题'),
      '#states' => [
        'visible' => [
          ':input[name="settings[title_field]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // 图片样式设置
    $image_styles = $this->getImageStyleOptions();
    $element['image_style_preview'] = [
      '#type' => 'select',
      '#title' => $this->t('预览图片样式'),
      '#default_value' => $settings['image_style_preview'] ?? $this->getDefaultSettings()['image_style_preview'],
      '#options' => $image_styles,
      '#empty_option' => $this->t('原始图片'),
      '#description' => $this->t('在预览时使用的图片样式'),
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function validateValue($value, array $settings, array $context = []): array
  {
    $errors = parent::validateValue($value, $settings, $context);

    if (!empty($value)) {
      $file_ids = is_array($value) ? $value : [$value];
      
      foreach ($file_ids as $file_id) {
        if (!empty($file_id)) {
          $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
          if ($file && $this->isImageFile($file)) {
            
            // 验证图片分辨率
            $resolution_errors = $this->validateImageResolution($file, $settings);
            $errors = array_merge($errors, $resolution_errors);
            
            // 验证图片文件完整性
            if (!$this->validateImageIntegrity($file)) {
              $errors[] = $this->t('图片文件 @filename 已损坏或格式不正确。', [
                '@filename' => $file->getFilename(),
              ]);
            }
          }
        }
      }
    }

    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function formatValue($value, array $settings, string $format = 'default'): string
  {
    if (empty($value)) {
      return '';
    }

    $file_ids = is_array($value) ? $value : [$value];
    $output = [];

    foreach ($file_ids as $file_id) {
      if (empty($file_id)) {
        continue;
      }

      $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
      if (!$file || !$this->isImageFile($file)) {
        continue;
      }

      switch ($format) {
        case 'thumbnail':
          $output[] = $this->generateImageThumbnail($file, $settings);
          break;

        case 'preview':
          $output[] = $this->generateImagePreview($file, $settings);
          break;

        case 'full':
          $image_info = $this->getImageInfo($file);
          $output[] = array_merge([
            'filename' => $file->getFilename(),
            'url' => $file->createFileUrl(),
            'size' => format_size($file->getSize()),
            'mime_type' => $file->getMimeType(),
          ], $image_info);
          break;

        case 'dimensions':
          $image_info = $this->getImageInfo($file);
          $output[] = $image_info['width'] . 'x' . $image_info['height'];
          break;

        default:
          // 默认格式：返回带预览的图片HTML
          $url = $file->createFileUrl();
          $filename = $file->getFilename();
          $thumbnail = $this->generateImageThumbnail($file, $settings);
          $output[] = "<div class=\"image-preview\">$thumbnail<br><a href=\"$url\" target=\"_blank\">$filename</a></div>";
          break;
      }
    }

    if ($settings['multiple'] ?? FALSE) {
      return implode(', ', $output);
    }

    return (string) reset($output);
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int
  {
    return 5; // 图片字段权重比普通文件字段高
  }

  /**
   * 检查文件是否为图片。
   *
   * @param \Drupal\file\FileInterface $file
   *   文件实体。
   *
   * @return bool
   *   是否为图片文件。
   */
  protected function isImageFile($file): bool
  {
    $mime_type = $file->getMimeType();
    return strpos($mime_type, 'image/') === 0;
  }

  /**
   * 验证图片分辨率。
   *
   * @param \Drupal\file\FileInterface $file
   *   文件实体。
   * @param array $settings
   *   字段设置。
   *
   * @return array
   *   验证错误数组。
   */
  protected function validateImageResolution($file, array $settings): array
  {
    $errors = [];
    $image_info = $this->getImageInfo($file);
    
    if (!$image_info) {
      return $errors; // 无法获取图片信息，跳过验证
    }

    $width = $image_info['width'];
    $height = $image_info['height'];

    // 验证最大分辨率
    if (!empty($settings['max_resolution'])) {
      $max_dimensions = $this->parseResolution($settings['max_resolution']);
      if ($max_dimensions && ($width > $max_dimensions['width'] || $height > $max_dimensions['height'])) {
        $errors[] = $this->t('图片 @filename 的分辨率 @resolution 超过了最大限制 @max。', [
          '@filename' => $file->getFilename(),
          '@resolution' => "{$width}x{$height}",
          '@max' => $settings['max_resolution'],
        ]);
      }
    }

    // 验证最小分辨率
    if (!empty($settings['min_resolution'])) {
      $min_dimensions = $this->parseResolution($settings['min_resolution']);
      if ($min_dimensions && ($width < $min_dimensions['width'] || $height < $min_dimensions['height'])) {
        $errors[] = $this->t('图片 @filename 的分辨率 @resolution 低于最小要求 @min。', [
          '@filename' => $file->getFilename(),
          '@resolution' => "{$width}x{$height}",
          '@min' => $settings['min_resolution'],
        ]);
      }
    }

    return $errors;
  }

  /**
   * 验证图片文件完整性。
   *
   * @param \Drupal\file\FileInterface $file
   *   文件实体。
   *
   * @return bool
   *   图片是否完整。
   */
  protected function validateImageIntegrity($file): bool
  {
    $image_info = $this->getImageInfo($file);
    return $image_info !== FALSE;
  }

  /**
   * 获取图片信息。
   *
   * @param \Drupal\file\FileInterface $file
   *   文件实体。
   *
   * @return array|false
   *   图片信息数组或FALSE。
   */
  protected function getImageInfo($file)
  {
    $file_system = \Drupal::service('file_system');
    $image_path = $file_system->realpath($file->getFileUri());
    
    if (!$image_path || !file_exists($image_path)) {
      return FALSE;
    }

    $image_info = getimagesize($image_path);
    if ($image_info === FALSE) {
      return FALSE;
    }

    return [
      'width' => $image_info[0],
      'height' => $image_info[1],
      'type' => $image_info[2],
      'mime_type' => $image_info['mime'],
    ];
  }

  /**
   * 解析分辨率字符串。
   *
   * @param string $resolution
   *   分辨率字符串（如 "1920x1080"）。
   *
   * @return array|false
   *   解析后的尺寸数组或FALSE。
   */
  protected function parseResolution(string $resolution)
  {
    if (!preg_match('/^(\d+)x(\d+)$/', $resolution, $matches)) {
      return FALSE;
    }

    return [
      'width' => (int) $matches[1],
      'height' => (int) $matches[2],
    ];
  }

  /**
   * 生成图片缩略图HTML。
   *
   * @param \Drupal\file\FileInterface $file
   *   文件实体。
   * @param array $settings
   *   字段设置。
   *
   * @return string
   *   缩略图HTML。
   */
  protected function generateImageThumbnail($file, array $settings): string
  {
    $url = $file->createFileUrl();
    $filename = $file->getFilename();
    
    // 如果设置了图片样式，使用图片样式
    if (!empty($settings['image_style_preview'])) {
      $image_style = \Drupal::entityTypeManager()->getStorage('image_style')->load($settings['image_style_preview']);
      if ($image_style) {
        $url = $image_style->buildUrl($file->getFileUri());
      }
    }

    return "<img src=\"$url\" alt=\"$filename\" style=\"max-width: 150px; max-height: 150px;\" />";
  }

  /**
   * 生成图片预览HTML。
   *
   * @param \Drupal\file\FileInterface $file
   *   文件实体。
   * @param array $settings
   *   字段设置。
   *
   * @return string
   *   预览HTML。
   */
  protected function generateImagePreview($file, array $settings): string
  {
    $url = $file->createFileUrl();
    $filename = $file->getFilename();
    $image_info = $this->getImageInfo($file);
    
    $dimensions = '';
    if ($image_info) {
      $dimensions = " ({$image_info['width']}x{$image_info['height']})";
    }

    return "<div class=\"image-preview-large\">
              <img src=\"$url\" alt=\"$filename\" style=\"max-width: 400px; max-height: 400px;\" />
              <div class=\"image-info\">$filename$dimensions</div>
            </div>";
  }

  /**
   * 获取可用的图片样式选项。
   *
   * @return array
   *   图片样式选项数组。
   */
  protected function getImageStyleOptions(): array
  {
    $options = [];
    
    $image_styles = \Drupal::entityTypeManager()->getStorage('image_style')->loadMultiple();
    foreach ($image_styles as $style_id => $style) {
      $options[$style_id] = $style->label();
    }

    return $options;
  }
}