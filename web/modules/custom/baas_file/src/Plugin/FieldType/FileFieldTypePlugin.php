<?php

declare(strict_types=1);

namespace Drupal\baas_file\Plugin\FieldType;

use Drupal\baas_entity\Plugin\FieldType\BaseFieldTypePlugin;
use Drupal\Core\Form\FormStateInterface;

/**
 * 文件字段类型插件。
 *
 * 为动态实体提供文件上传和管理功能。
 *
 * @FieldTypePlugin(
 *   id = "file",
 *   label = @Translation("文件"),
 *   description = @Translation("允许上传和管理文件，支持多种文件类型"),
 *   category = "media",
 *   weight = 10
 * )
 */
class FileFieldTypePlugin extends BaseFieldTypePlugin
{

  /**
   * {@inheritdoc}
   */
  public function getFieldType(): string
  {
    return 'file';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): string
  {
    return $this->t('文件');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string
  {
    return $this->t('允许上传和管理文件，支持多种文件类型（PDF、DOC、ZIP等）');
  }

  /**
   * {@inheritdoc}
   */
  public function getStorageSchema(): array
  {
    return [
      'type' => 'int',
      'unsigned' => TRUE,
      'not null' => FALSE,
      'description' => 'The file entity ID.',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getDrupalFieldType(): string
  {
    return 'file';
  }

  /**
   * {@inheritdoc}
   */
  public function getWidgetType(): string
  {
    return 'file_generic';
  }

  /**
   * {@inheritdoc}
   */
  public function getFormatterType(): string
  {
    return 'file_default';
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultSettings(): array
  {
    return [
      'file_extensions' => 'txt pdf doc docx xls xlsx ppt pptx zip rar',
      'max_filesize' => '10MB',
      'description_field' => TRUE,
      'file_directory' => '[tenant:id]/[project:id]/files/[current-date:custom:Y-m]',
      'uri_scheme' => 'public',
      'required' => FALSE,
      'multiple' => FALSE,
      'max_files' => 1,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getSettingsForm(array $settings, array $form, $form_state): array
  {
    $element = [];

    $element['file_extensions'] = [
      '#type' => 'textfield',
      '#title' => $this->t('允许的文件扩展名'),
      '#default_value' => $settings['file_extensions'] ?? $this->getDefaultSettings()['file_extensions'],
      '#description' => $this->t('用空格分隔多个扩展名，如：txt pdf doc'),
      '#required' => TRUE,
    ];

    $element['max_filesize'] = [
      '#type' => 'textfield',
      '#title' => $this->t('最大文件大小'),
      '#default_value' => $settings['max_filesize'] ?? $this->getDefaultSettings()['max_filesize'],
      '#description' => $this->t('如：10MB, 2GB。留空使用系统默认值。'),
    ];

    $element['file_directory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('文件目录'),
      '#default_value' => $settings['file_directory'] ?? $this->getDefaultSettings()['file_directory'],
      '#description' => $this->t('文件上传目录，支持token。如：[tenant:id]/[project:id]/files'),
    ];

    $element['description_field'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用描述字段'),
      '#default_value' => $settings['description_field'] ?? $this->getDefaultSettings()['description_field'],
      '#description' => $this->t('允许用户为上传的文件添加描述'),
    ];

    $element['multiple'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('允许多个文件'),
      '#default_value' => $settings['multiple'] ?? $this->getDefaultSettings()['multiple'],
      '#description' => $this->t('允许上传多个文件'),
    ];

    $element['max_files'] = [
      '#type' => 'number',
      '#title' => $this->t('最大文件数量'),
      '#default_value' => $settings['max_files'] ?? $this->getDefaultSettings()['max_files'],
      '#min' => 1,
      '#max' => 100,
      '#description' => $this->t('允许上传的最大文件数量（仅在启用多文件时有效）'),
      '#states' => [
        'visible' => [
          ':input[name="settings[multiple]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function validateValue($value, array $settings, array $context = []): array
  {
    $errors = [];

    if (empty($value)) {
      if ($settings['required'] ?? FALSE) {
        $errors[] = $this->t('文件字段是必填的。');
      }
      return $errors;
    }

    // 如果是多文件，验证文件数量
    if ($settings['multiple'] ?? FALSE) {
      $file_count = is_array($value) ? count($value) : 1;
      $max_files = $settings['max_files'] ?? 1;
      
      if ($file_count > $max_files) {
        $errors[] = $this->t('文件数量不能超过 @max 个。', ['@max' => $max_files]);
      }
    }

    // 验证文件存在性和权限
    $file_ids = is_array($value) ? $value : [$value];
    foreach ($file_ids as $file_id) {
      if (!empty($file_id)) {
        $file = \Drupal::entityTypeManager()->getStorage('file')->load($file_id);
        if (!$file) {
          $errors[] = $this->t('文件 @id 不存在。', ['@id' => $file_id]);
          continue;
        }

        // 验证文件访问权限
        if (!$this->validateFileAccess($file, $context)) {
          $errors[] = $this->t('您没有访问文件 @filename 的权限。', ['@filename' => $file->getFilename()]);
        }

        // 验证文件扩展名
        if (!$this->validateFileExtension($file, $settings)) {
          $errors[] = $this->t('文件 @filename 的类型不被允许。', ['@filename' => $file->getFilename()]);
        }

        // 验证文件大小
        if (!$this->validateFileSize($file, $settings)) {
          $max_size = $settings['max_filesize'] ?? '10MB';
          $errors[] = $this->t('文件 @filename 超过了最大大小限制 @max。', [
            '@filename' => $file->getFilename(),
            '@max' => $max_size,
          ]);
        }
      }
    }

    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function processValue($value, array $settings): mixed
  {
    if (empty($value)) {
      return NULL;
    }

    // 处理多文件情况
    if ($settings['multiple'] ?? FALSE) {
      return is_array($value) ? $value : [$value];
    }

    // 单文件情况
    return is_array($value) ? reset($value) : $value;
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
      if (!$file) {
        continue;
      }

      switch ($format) {
        case 'url':
          $output[] = $file->createFileUrl();
          break;

        case 'filename':
          $output[] = $file->getFilename();
          break;

        case 'size':
          $output[] = format_size($file->getSize());
          break;

        case 'full':
          $output[] = [
            'filename' => $file->getFilename(),
            'url' => $file->createFileUrl(),
            'size' => format_size($file->getSize()),
            'mime_type' => $file->getMimeType(),
          ];
          break;

        default:
          // 默认格式：返回文件链接
          $url = $file->createFileUrl();
          $filename = $file->getFilename();
          $output[] = "<a href=\"$url\" target=\"_blank\">$filename</a>";
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
  public function supportsMultiple(): bool
  {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function needsIndex(): bool
  {
    return TRUE; // 文件字段通常需要索引来优化查询
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int
  {
    return 10;
  }

  /**
   * 验证文件访问权限。
   *
   * @param \Drupal\file\FileInterface $file
   *   文件实体。
   * @param array $context
   *   验证上下文。
   *
   * @return bool
   *   是否有权限访问。
   */
  protected function validateFileAccess($file, array $context): bool
  {
    // 检查文件是否属于当前项目
    $project_id = $context['project_id'] ?? NULL;
    if (!$project_id) {
      return FALSE;
    }

    // 检查文件URI是否包含项目ID
    $file_uri = $file->getFileUri();
    return strpos($file_uri, $project_id) !== FALSE;
  }

  /**
   * 验证文件扩展名。
   *
   * @param \Drupal\file\FileInterface $file
   *   文件实体。
   * @param array $settings
   *   字段设置。
   *
   * @return bool
   *   是否允许的扩展名。
   */
  protected function validateFileExtension($file, array $settings): bool
  {
    $allowed_extensions = $settings['file_extensions'] ?? '';
    if (empty($allowed_extensions)) {
      return TRUE; // 如果没有限制，允许所有类型
    }

    $allowed_extensions = explode(' ', strtolower($allowed_extensions));
    $file_extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));

    return in_array($file_extension, $allowed_extensions);
  }

  /**
   * 验证文件大小。
   *
   * @param \Drupal\file\FileInterface $file
   *   文件实体。
   * @param array $settings
   *   字段设置。
   *
   * @return bool
   *   是否符合大小限制。
   */
  protected function validateFileSize($file, array $settings): bool
  {
    $max_filesize = $settings['max_filesize'] ?? '';
    if (empty($max_filesize)) {
      return TRUE; // 如果没有限制，允许任何大小
    }

    // 解析大小限制（如 10MB, 2GB）
    $max_bytes = $this->parseFileSize($max_filesize);
    if ($max_bytes === FALSE) {
      return TRUE; // 解析失败，不限制
    }

    return $file->getSize() <= $max_bytes;
  }

  /**
   * 解析文件大小字符串为字节数。
   *
   * @param string $size
   *   大小字符串（如 "10MB", "2GB"）。
   *
   * @return int|false
   *   字节数，解析失败返回FALSE。
   */
  protected function parseFileSize(string $size)
  {
    $size = trim($size);
    $number = (float) $size;
    $unit = strtoupper(substr($size, -2));

    $multipliers = [
      'KB' => 1024,
      'MB' => 1024 * 1024,
      'GB' => 1024 * 1024 * 1024,
      'TB' => 1024 * 1024 * 1024 * 1024,
    ];

    if (isset($multipliers[$unit])) {
      return (int) ($number * $multipliers[$unit]);
    }

    // 如果没有单位，假设是字节
    return (int) $number;
  }
}