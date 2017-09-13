<?php

namespace Drupal\graphql_views\Plugin\GraphQL\Fields;

use Drupal\graphql_core\GraphQL\FieldPluginBase;
use Drupal\views\ViewExecutable;
use Youshido\GraphQL\Execution\ResolveInfo;

/**
 * Expose results of a view.
 *
 * @GraphQLField(
 *   id = "view_result",
 *   name = "results",
 *   secure = true,
 *   multi = true,
 *   deriver = "Drupal\graphql_views\Plugin\Deriver\ViewResultListDeriver"
 * )
 */
class ViewResultList extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  protected function resolveValues($value, array $args, ResolveInfo $info) {
    if ($value instanceof ViewExecutable) {
      foreach ($value->result as $row) {
        if (isset($row->_entity)) {
          yield $row->_entity;
        }
      }
    }
  }

}
