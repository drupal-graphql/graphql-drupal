<?php

namespace Drupal\graphql\GraphQL\Buffers;

use Drupal\Core\Entity\EntityTypeManagerInterface;

class EntityBuffer extends BufferBase {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * EntityBuffer constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Add an item to the buffer.
   *
   * @param string $type
   *   The entity type of the given entity ids.
   * @param array|int $id
   *   The entity id(s) to load.
   *
   * @return \Closure
   *   The callback to invoke to load the result for this buffer item.
   */
  public function add($type, $id) {
    $item = new \ArrayObject([
      'type' => $type,
      'id' => $id,
    ]);

    return $this->createBufferResolver($item);
  }

  /**
   * {@inheritdoc}
   */
  protected function getBufferId($item) {
    return $item['type'];
  }

  /**
   * {@inheritdoc}
   */
  public function resolveBufferArray(array $buffer) {
    $type = reset($buffer)['type'];
    $ids = array_map(function (\ArrayObject $item) {
      return (array) $item['id'];
    }, $buffer);

    $ids = call_user_func_array('array_merge', $ids);
    $ids = array_values(array_unique($ids));

    // Load the buffered entities.
    $entities = $this->entityTypeManager
      ->getStorage($type)
      ->loadMultiple($ids);


    $result = [];
    foreach ($buffer as $item) {
      if (is_array($item['id'])) {
        foreach ($item['id'] as $id) {
          $result[] = $entities[$id];
        }
      } else {
        $result = $entities[$item['id']];
      }
    }
    return [$result];
  }

}
