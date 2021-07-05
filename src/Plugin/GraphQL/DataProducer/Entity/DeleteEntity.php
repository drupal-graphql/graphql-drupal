<?php

namespace Drupal\graphql\Plugin\GraphQL\DataProducer\Entity;

use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;

/**
 * Deletes an entity.
 *
 * @DataProducer(
 *   id = "delete_entity",
 *   name = @Translation("Delete Entity"),
 *   produces = @ContextDefinition("entities",
 *     label = @Translation("Entities")
 *   ),
 *   consumes = {
 *     "entity" = @ContextDefinition("entity",
 *       label = @Translation("Entity")
 *     ),
 *   }
 * )
 */
class DeleteEntity extends DataProducerPluginBase {

  /**
   * Resolve the values for this producer.
   */
  public function resolve(ContentEntityInterface $entity, $context) {
    $access = $entity->access('delete', NULL, TRUE);
    $context->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      return [
        'was_successful' => FALSE,
        'errors' => [$access instanceof AccessResultReasonInterface ? $access->getReason() : 'Access was forbidden.'],
      ];
    }

    $entity->delete();
    return [
      'was_successful' => TRUE,
    ];
  }

}
