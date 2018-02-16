<?php

namespace Drupal\graphql_core\Plugin\GraphQL\Fields;

use Drupal\Core\Field\FieldItemInterface;
use Drupal\graphql\Plugin\GraphQL\Fields\FieldPluginBase;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\ScalarType;
use GraphQL\Type\Definition\WrappingType;

/**
 * Base class for entity field plugins.
 */
class EntityFieldBase extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  protected function resolveItem($item, array $args, ResolveInfo $info) {
    if ($item instanceof FieldItemInterface) {
      $definition = $this->getPluginDefinition();
      $property = $definition['property'];
      $result = $item->get($property)->getValue();

      $type = $info->returnType;
      $type = $type instanceof WrappingType ? $type->getWrappedType(TRUE) : $type;
      if ($type instanceof ScalarType) {
        $result = $type->serialize($result);
      }

      return $result;
    }
  }

}
