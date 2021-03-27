<?php

namespace Drupal\graphql\Plugin\GraphQL\Fields\Taxonomy;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\graphql\GraphQL\Execution\ResolveContext;
use Drupal\graphql_core\Plugin\GraphQL\Fields\EntityQuery\EntityQuery;
use GraphQL\Type\Definition\ResolveInfo;

/**
 * Class reverseTaxonomyIndexQuery.
 *
 * @GraphQLField(
 *   id = "taxonomy_term_reverse_taxonomy_index_query",
 *   name = "reverseTaxonomyIndexQuery",
 *   secure = true,
 *   type = "EntityQueryResult!",
 *   arguments = {
 *     "filter" = "EntityQueryFilterInput",
 *     "sort" = "[EntityQuerySortInput]",
 *     "offset" = {
 *       "type" = "Int",
 *       "default" = 0
 *     },
 *     "limit" = {
 *       "type" = "Int",
 *       "default" = 10
 *     },
 *     "revisions" = {
 *       "type" = "EntityQueryRevisionMode",
 *       "default" = "default"
 *     },
 *     "limit_fields" = "[String]"
 *   },
 *   deriver = "Drupal\graphql_core\Plugin\Deriver\Fields\ReverseTaxonomyIndexQueryDeriver"
 * );
 */
class ReverseTaxonomyIndexQuery extends EntityQuery {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getBaseQuery($value, array $args, ResolveContext $context, ResolveInfo $info) {
    if ($value instanceof ContentEntityInterface) {
      $query = parent::getBaseQuery($value, $args, $context, $info);

      // Add the target field condition to the query.
      $definition = $this->getPluginDefinition();

      if (empty($args['limit_fields'])) {
        $fields = $definition['fields'];
      }
      elseif ($unknown = array_diff($args['limit_fields'], $definition['fields'])) {
        throw new \Exception($this->formatPlural(count($unknown), "Unknown field '@fields'", "Unknown field '@fields'", ['@fields' => implode("', '", $unknown)]));
      }
      else {
        $fields = $args['limit_fields'];
      }

      $group = $query->orConditionGroup();

      foreach ($fields as $field) {
        $group->condition($field, $value->id());
      }
      $query->condition($group);

      return $query;
    }
  }

}
