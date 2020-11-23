<?php

namespace Drupal\graphql_core\Plugin\GraphQL\Fields\Entity;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\graphql\GraphQL\Cache\CacheableValue;
use Drupal\graphql\GraphQL\Execution\ResolveContext;
use Drupal\graphql_core\Plugin\GraphQL\Fields\EntityFieldBase;
use GraphQL\Type\Definition\ResolveInfo;

/**
 * @GraphQLField(
 *   id = "entity_field",
 *   secure = true,
 *   weight = -2,
 *   deriver = "Drupal\graphql_core\Plugin\Deriver\Fields\EntityFieldDeriver",
 * )
 */
class EntityField extends EntityFieldBase {

  /**
   * {@inheritdoc}
   */
  public function resolveValues($value, array $args, ResolveContext $context, ResolveInfo $info) {
    if ($value instanceof FieldableEntityInterface) {
      $definition = $this->getPluginDefinition();
      $name = $definition['field'];

      if ($value->hasField($name)) {
        /** @var \Drupal\Core\Field\FieldItemListInterface $items */
        $items = $value->get($name);
        $access = $items->access('view', NULL, TRUE);

        if ($access->isAllowed()) {
          foreach ($items as $item) {
            $output = !empty($definition['property']) ? $this->resolveItem($item, $args, $context, $info) : $item;
            
            // Do not output NULL values (e.g. in case access to the field item was denied).
            if (!isset($output)) {
              continue;
            }
            
            yield new CacheableValue($output, [$access]);
          }
        }
      }
    }
  }

}
