<?php

namespace Drupal\graphql_core\Plugin\GraphQL\Fields;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\Entity;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\graphql\GraphQL\Execution\ResolveContext;
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
  protected function resolveItem($item, array $args, ResolveContext $context, ResolveInfo $info) {
    if ($item instanceof FieldItemInterface) {
      $definition = $this->getPluginDefinition();
      $property = $definition['property'];
      $itemProperty = $item->get($property);
      $result = $itemProperty->getValue();
      $result = $result instanceof MarkupInterface ? $result->__toString() : $result;

      $type = $info->returnType;
      $type = $type instanceof WrappingType ? $type->getWrappedType(TRUE) : $type;
      if ($type instanceof ScalarType) {
        $result = is_null($result) ? NULL : $type->serialize($result);
      }

      if ($result instanceof ContentEntityInterface && $result->isTranslatable() && $language = $context->getContext('language', $info)) {
        if ($result->hasTranslation($language)) {
          $result = $result->getTranslation($language);
        }
      }

      if ($itemProperty instanceof CacheableDependencyInterface) {
        $context->addCacheTags($itemProperty->getCacheTags());
        $context->addCacheContexts($itemProperty->getCacheContexts());
        $context->mergeCacheMaxAge($itemProperty->getCacheMaxAge());
      }

      return $result;
    }
  }

}
