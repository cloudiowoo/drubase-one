<?php

declare(strict_types=1);

namespace Drupal\baas_project\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Component\Uuid\UuidInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * UUID修复控制器。
 */
class UuidFixController extends ControllerBase
{

  /**
   * 构造函数。
   */
  public function __construct(
    protected readonly Connection $database,
    protected readonly UuidInterface $uuidService
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('database'),
      $container->get('uuid')
    );
  }

  /**
   * 修复UUID字段。
   */
  public function fixUuidFields(): JsonResponse
  {
    $result = ['success' => false, 'message' => '', 'details' => []];

    try {
      // 要修复的表
      $tables_to_fix = ['baas_t9b7661_p7e17ed_web1'];
      
      foreach ($tables_to_fix as $table_name) {
        if (!$this->database->schema()->tableExists($table_name)) {
          $result['details'][] = "表 {$table_name} 不存在，跳过";
          continue;
        }

        // 检查UUID字段是否存在
        if (!$this->database->schema()->fieldExists($table_name, 'uuid')) {
          // 添加UUID字段
          $this->database->schema()->addField($table_name, 'uuid', [
            'type' => 'varchar',
            'length' => 128,
            'not null' => FALSE,
            'description' => 'UUID标识',
          ]);
          $result['details'][] = "在表 {$table_name} 中添加了UUID字段";
        }

        // 为没有UUID的记录生成UUID
        $query = $this->database->select($table_name, 't')
          ->fields('t', ['id'])
          ->isNull('uuid');
        
        $records = $query->execute();
        $updated_count = 0;
        
        foreach ($records as $record) {
          $new_uuid = $this->uuidService->generate();
          $this->database->update($table_name)
            ->fields(['uuid' => $new_uuid])
            ->condition('id', $record->id)
            ->execute();
          $updated_count++;
        }
        
        if ($updated_count > 0) {
          $result['details'][] = "为表 {$table_name} 中的 {$updated_count} 条记录生成了UUID";
        }

        // 将UUID字段设为非空
        if ($updated_count > 0 || !$this->database->schema()->fieldExists($table_name, 'uuid')) {
          $this->database->schema()->changeField($table_name, 'uuid', 'uuid', [
            'type' => 'varchar',
            'length' => 128,
            'not null' => TRUE,
            'description' => 'UUID标识',
          ]);
          $result['details'][] = "将表 {$table_name} 的UUID字段设为非空";
        }

        // 添加唯一索引
        if (!$this->database->schema()->indexExists($table_name, 'uuid')) {
          try {
            $this->database->schema()->addUniqueKey($table_name, 'uuid', ['uuid']);
            $result['details'][] = "为表 {$table_name} 添加了UUID唯一索引";
          } catch (\Exception $e) {
            $result['details'][] = "为表 {$table_name} 添加UUID唯一索引失败: " . $e->getMessage();
          }
        }
      }

      $result['success'] = true;
      $result['message'] = 'UUID字段修复完成';

    } catch (\Exception $e) {
      $result['message'] = '修复失败: ' . $e->getMessage();
      \Drupal::logger('baas_project')->error('UUID修复失败: @error', ['@error' => $e->getMessage()]);
    }

    return new JsonResponse($result);
  }

}