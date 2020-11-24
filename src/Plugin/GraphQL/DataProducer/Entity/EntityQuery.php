<?php

namespace Drupal\graphql\Plugin\GraphQL\DataProducer\Entity;

use Drupal\graphql\GraphQL\Execution\FieldContext;

/**
 * Builds and executes Drupal entity query.
 *
 * @DataProducer(
 *   id = "entity_query",
 *   name = @Translation("Load entities"),
 *   description = @Translation("Loads entities."),
 *   produces = @ContextDefinition("string",
 *     label = @Translation("Entity IDs"),
 *     multiple = TRUE
 *   ),
 *   consumes = {
 *     "type" = @ContextDefinition("string",
 *       label = @Translation("Entity type")
 *     ),
 *     "limit" = @ContextDefinition("integer",
 *       label = @Translation("Limit"),
 *       required = FALSE,
 *       default_value = 10
 *     ),
 *     "offset" = @ContextDefinition("integer",
 *       label = @Translation("Offset"),
 *       required = FALSE,
 *       default_value = 0
 *     ),
 *     "owned_only" = @ContextDefinition("boolean",
 *       label = @Translation("Query only owned entities"),
 *       required = FALSE,
 *       default_value = FALSE
 *     ),
 *     "conditions" = @ContextDefinition("any",
 *       label = @Translation("Conditions"),
 *       multiple = TRUE,
 *       required = FALSE,
 *       default_value = {}
 *     ),
 *     "allowed_filters" = @ContextDefinition("string",
 *       label = @Translation("Allowed filters"),
 *       multiple = TRUE,
 *       required = FALSE,
 *       default_value = {}
 *     ),
 *     "languages" = @ContextDefinition("string",
 *       label = @Translation("Entity languages(s)"),
 *       multiple = TRUE,
 *       required = FALSE,
 *       default_value = {}
 *     ),
 *     "bundles" = @ContextDefinition("any",
 *       label = @Translation("Entity bundle(s)"),
 *       multiple = TRUE,
 *       required = FALSE,
 *       default_value = {}
 *     ),
 *     "sorts" = @ContextDefinition("any",
 *       label = @Translation("Sorts"),
 *       multiple = TRUE,
 *       default_value = {},
 *       required = FALSE
 *     )
 *   }
 * )
 */
class EntityQuery extends EntityQueryBase {

  /**
   * Resolves the entity query.
   *
   * @param string $type
   *   Entity type.
   * @param int $limit
   *   Maximum number of queried entities.
   * @param int $offset
   *   Offset to start with.
   * @param bool $ownedOnly
   *   Query only entities owned by current user.
   * @param array $conditions
   *   List of conditions to filter the entities.
   * @param array $allowedFilters
   *   List of fields to be used in conditions to restrict access to data.
   * @param string[] $languages
   *   Languages for queried entities.
   * @param string[] $bundles
   *   List of bundles to be filtered.
   * @param array $sorts
   *   List of sorts.
   * @param \Drupal\graphql\GraphQL\Execution\FieldContext $context
   *   The caching context related to the current field.
   *
   * @return array
   *   The list of ids that match this query.
   *
   * @throws \GraphQL\Error\UserError
   *   No bundles defined for given entity type.
   */
  public function resolve(string $type, int $limit, int $offset, bool $ownedOnly, array $conditions, array $allowedFilters, array $languages, array $bundles, array $sorts, FieldContext $context): array {
    $query = $this->buildBaseEntityQuery(
      $type,
      $ownedOnly,
      $conditions,
      $allowedFilters,
      $languages,
      $bundles,
      $context
    );

    // Make sure offset is zero or positive.
    $offset = max($offset, 0);
    $query->range($offset, $limit);

    // Add sorts.
    foreach ($sorts as $sort) {
      if (!empty($sort['field'])) {
        if (!empty($sort['direction']) && strtolower($sort['direction']) == 'desc') {
          $direction = 'DESC';
        }
        else {
          $direction = 'ASC';
        }
        $query->sort($sort['field'], $direction);
      }
    }

    $ids = $query->execute();

    return $ids;
  }

}
