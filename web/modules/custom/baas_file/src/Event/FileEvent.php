<?php

declare(strict_types=1);

namespace Drupal\baas_file\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * 文件事件类。
 */
class FileEvent extends Event
{

  /**
   * 事件类型。
   */
  protected string $eventType;

  /**
   * 事件数据。
   */
  protected array $data;

  /**
   * 构造函数。
   */
  public function __construct(string $event_type, array $data = [])
  {
    $this->eventType = $event_type;
    $this->data = $data;
  }

  /**
   * 获取事件类型。
   */
  public function getEventType(): string
  {
    return $this->eventType;
  }

  /**
   * 获取事件数据。
   */
  public function getData(): array
  {
    return $this->data;
  }

  /**
   * 设置事件数据。
   */
  public function setData(array $data): void
  {
    $this->data = $data;
  }
}