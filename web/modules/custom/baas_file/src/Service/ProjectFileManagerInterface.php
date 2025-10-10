<?php

declare(strict_types=1);

namespace Drupal\baas_file\Service;

/**
 * 项目文件管理器接口。
 *
 * 定义项目级文件管理的核心功能。
 */
interface ProjectFileManagerInterface
{

  /**
   * 获取项目文件目录路径。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return string
   *   项目文件目录路径。
   *
   * @throws \InvalidArgumentException
   *   当项目不存在时抛出异常。
   */
  public function getProjectFileDirectory(string $project_id): string;

  /**
   * 为项目创建文件目录结构。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return bool
   *   创建是否成功。
   */
  public function createProjectFileDirectory(string $project_id): bool;

  /**
   * 上传文件到项目。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $file_data
   *   文件数据。
   * @param string $field_name
   *   上传字段名称。
   *
   * @return int|null
   *   成功返回文件ID，失败返回NULL。
   */
  public function uploadFile(string $project_id, array $file_data, string $field_name = 'file'): ?int;

  /**
   * 上传文件到项目（兼容性方法）。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $file_data
   *   文件数据。
   * @param string $field_name
   *   上传字段名称。
   *
   * @return int|null
   *   成功返回文件ID，失败返回NULL。
   */
  public function uploadFileData(string $project_id, array $file_data, string $field_name = 'file'): ?int;

  /**
   * 删除项目文件。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $file_id
   *   文件ID。
   *
   * @return bool
   *   删除是否成功。
   */
  public function deleteProjectFile(string $project_id, int $file_id): bool;

  /**
   * 获取项目文件列表。
   *
   * @param string $project_id
   *   项目ID。
   * @param array $filters
   *   过滤条件，支持：
   *   - mime_type: MIME类型过滤
   *   - extension: 文件扩展名过滤
   *   - date_from: 起始日期
   *   - date_to: 结束日期
   *   - sort: 排序字段
   *   - direction: 排序方向
   *   - limit: 限制数量
   *   - offset: 偏移量
   *
   * @return array
   *   文件信息数组。
   */
  public function getProjectFiles(string $project_id, array $filters = []): array;

  /**
   * 获取项目文件使用统计。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   包含以下信息的数组：
   *   - file_count: 文件总数
   *   - total_size: 总大小（字节）
   *   - image_count: 图片数量
   *   - document_count: 文档数量
   *   - last_updated: 最后更新时间
   */
  public function getFileUsageStats(string $project_id): array;

  /**
   * 重新计算项目文件统计（基于实际存在的文件）。
   *
   * @param string $project_id
   *   项目ID。
   *
   * @return array
   *   更新后的统计数据。
   */
  public function recalculateProjectFileUsage(string $project_id): array;

  /**
   * 获取租户媒体文件列表。
   *
   * @param string $tenant_id
   *   租户ID。
   * @param int $page
   *   页码。
   * @param int $limit
   *   每页限制。
   * @param array $filters
   *   过滤条件。
   *
   * @return array
   *   包含files和total的数组。
   */
  public function getTenantMediaList(string $tenant_id, int $page, int $limit, array $filters = []): array;

  /**
   * 获取项目媒体文件列表。
   *
   * @param string $project_id
   *   项目ID。
   * @param int $page
   *   页码。
   * @param int $limit
   *   每页限制。
   * @param array $filters
   *   过滤条件。
   *
   * @return array
   *   包含files和total的数组。
   */
  public function getProjectMediaList(string $project_id, int $page, int $limit, array $filters = []): array;

  /**
   * 获取文件详细信息。
   *
   * @param int $file_id
   *   文件ID。
   *
   * @return array|null
   *   文件详细信息或NULL。
   */
  public function getFileDetail(int $file_id): ?array;

  /**
   * 上传文件（支持上传对象）。
   *
   * @param string $project_id
   *   项目ID。
   * @param \Symfony\Component\HttpFoundation\File\UploadedFile $uploaded_file
   *   上传的文件对象。
   * @param array $options
   *   上传选项。
   *
   * @return array
   *   上传结果。
   */
  public function uploadFileFromRequest(string $project_id, $uploaded_file, array $options = []): array;

  /**
   * 删除文件。
   *
   * @param int $file_id
   *   文件ID。
   *
   * @return bool
   *   删除是否成功。
   */
  public function deleteFile(int $file_id): bool;
}