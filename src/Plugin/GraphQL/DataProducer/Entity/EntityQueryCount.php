<?php

namespace Drupal\graphql\Plugin\GraphQL\DataProducer\Entity;

use Drupal\graphql\GraphQL\Execution\FieldContext;

/**
 * Builds and executes Drupal entity query count.
 *
 * It supposed to be used along the entity query to get the total amount of
 * items. This is important for pagination when the total amount needs to be
 * known to derive the number of available pages. Otherwise the query works the
 * same way as entity query. Same filters are applied, just skips the offset and
 * limit, and turns the query into count query.
 *
 * @DataProducer(
 *   id = "entity_query_count",
 *   name = @Translation("Load entities"),
 *   description = @Translation("Loads entities."),
 *   produces = @ContextDefinition("integer",
 *     label = @Translation("Total count of items queried by entity query."),
 *   ),
 *   consumes = {
 *     "type" = @ContextDefinition("string",
 *       label = @Translation("Entity type")
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
 *       label = @Translation("Entity languages"),
 *       multiple = TRUE,
 *       required = FALSE,
 *       default_value = {}
 *     ),
 *     "bundles" = @ContextDefinition("any",
 *       label = @Translation("Entity bundles"),
 *       multiple = TRUE,
 *       required = FALSE,
 *       default_value = {}
 *     ),
 *     "access" = @ContextDefinition("boolean",
 *       label = @Translation("Check access"),
 *       required = FALSE,
 *       default_value = TRUE
 *     )
 *   }
 * )
 */
class EntityQueryCount extends EntityQueryBase {

  /**
   * Resolves the entity query count.
   *
   * @param string $type
   *   Entity type.
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
   * @param bool $access
   *   Whether entity query should check access.
   * @param \Drupal\graphql\GraphQL\Execution\FieldContext $context
   *   The caching context related to the current field.
   *
   * @return int
   *   Total count of items queried by entity query.
   */
  public function resolve(string $type, bool $ownedOnly, array $conditions, array $allowedFilters, array $languages, array $bundles, bool $access, FieldContext $context): int {
    $query = $this->buildBaseEntityQuery(
      $type,
      $ownedOnly,
      $conditions,
      $allowedFilters,
      $languages,
      $bundles,
      $access,
      $context
    );

    return $query->count()->execute();
  }

}
