<?php

namespace Drupal\graphql\Plugin\GraphQL\DataProducer;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Trait for entity validation.
 *
 * Ensure the entity passes validation, any violations will be reported back
 * to the client. Validation will catch issues like invalid referenced entities,
 * incorrect text formats, required fields etc. Additional validation of input
 * should not be put here, but instead should be built into the entity
 * validation system, so the same constraints are applied in the Drupal admin.
 */
trait EntityValidationTrait {

  /**
   * Get violation messages from an entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   An entity to validate.
   *
   * @return array
   *   Get a list of violations.
   */
  public function getViolationMessages(ContentEntityInterface $entity): array {
    $violations = $entity->validate();

    // Remove violations of inaccessible fields as they cannot stem from our
    // changes.
    $violations->filterByFieldAccess();

    if ($violations->count() > 0) {
      $violation_messages = [];
      foreach ($violations as $violation) {
        $violation_messages[] = sprintf('%s: %s', $violation->getPropertyPath(), strip_tags($violation->getMessage()));
      }
      return $violation_messages;
    }
    return [];
  }

}
