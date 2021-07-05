<?php

namespace Drupal\graphql\Plugin\GraphQL\DataProducer\Entity;

use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;
use Drupal\graphql\Plugin\GraphQL\DataProducer\EntityValidationTrait;

/**
 * Updates entity values.
 *
 * @DataProducer(
 *   id = "update_entity",
 *   name = @Translation("Update Entity"),
 *   produces = @ContextDefinition("entities",
 *     label = @Translation("Entities")
 *   ),
 *   consumes = {
 *     "entity" = @ContextDefinition("entity",
 *       label = @Translation("Entity")
 *     ),
 *     "values" = @ContextDefinition("any",
 *       label = @Translation("Values to update"),
 *       required = TRUE
 *     ),
 *     "entity_return_key" = @ContextDefinition("string",
 *       label = @Translation("Entity Return Key"),
 *       required = TRUE
 *     ),
 *   }
 * )
 */
class UpdateEntity extends DataProducerPluginBase {

  use EntityValidationTrait;

  /**
   * Resolve the values for this producer.
   */
  public function resolve(ContentEntityInterface $entity, array $values, string $entity_return_key, $context) {
    // Ensure the user has access to perform an update.
    $access = $entity->access('update', NULL, TRUE);
    $context->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      return [
        'errors' => [$access instanceof AccessResultReasonInterface ? $access->getReason() : 'Access was forbidden.'],
      ];
    }

    // Filter out keys the user does not have access to update, this may include
    // things such as the owner of the entity or the ID of the entity.
    $update_fields = array_filter($values, function (string $field_name) use ($entity, $context) {
      if (!$entity->hasField($field_name)) {
        throw new \Exception("Could not update '$field_name' field, since it does not exist on the given entity.");
      }
      $access = $entity->{$field_name}->access('edit', NULL, TRUE);
      $context->addCacheableDependency($access);
      return $access->isAllowed();
    }, ARRAY_FILTER_USE_KEY);

    // Hydrate the entity with the values.
    foreach ($update_fields as $field_name => $field_value) {
      $entity->set($field_name, $field_value);
    }

    if ($violation_messages = $this->getViolationMessages($entity)) {
      return [
        'errors' => $violation_messages,
      ];
    }

    // Once access has been granted, the save can be committed and the entity
    // can be returned to the client.
    $entity->save();
    return [
      $entity_return_key => $entity,
    ];
  }

}
