<?php

namespace Drupal\graphql_core\Plugin\Deriver\Fields;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\graphql\Utility\StringHelper;
use Drupal\graphql_core\Plugin\Deriver\EntityFieldDeriverBase;

class EntityFieldDeriver extends EntityFieldDeriverBase {

  /**
   * {@inheritdoc}
   */
  protected function getDerivativeDefinitionsFromFieldDefinition(FieldDefinitionInterface $fieldDefinition, array $basePluginDefinition) {
    $itemDefinition = $fieldDefinition->getItemDefinition();
    if (!($itemDefinition instanceof ComplexDataDefinitionInterface) || !$propertyDefinitions = $itemDefinition->getPropertyDefinitions()) {
      return [];
    }

    $tags = array_merge($fieldDefinition->getCacheTags(), ['entity_field_info']);
    $maxAge = $fieldDefinition->getCacheMaxAge();
    $contexts = $fieldDefinition->getCacheContexts();

    $entityTypeId = $fieldDefinition->getTargetEntityTypeId();
    $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
    $supportsBundles = $entityType->hasKey('bundle');
    $fieldName = $fieldDefinition->getName();
    $fieldBundle = $fieldDefinition->getTargetBundle() ?: '';

    $derivative = [
      'parents' => [StringHelper::camelCase($entityTypeId, $supportsBundles ? $fieldBundle : '')],
      'name' => StringHelper::propCase($fieldName),
      'description' => $fieldDefinition->getDescription(),
      'field' => $fieldName,
      'schema_cache_tags' => $tags,
      'schema_cache_contexts' => $contexts,
      'schema_cache_max_age' => $maxAge,
    ] + $basePluginDefinition;

    if (count($propertyDefinitions) === 1) {
      $propertyDefinition = reset($propertyDefinitions);
      $derivative['type'] = $propertyDefinition->getDataType();

      // Workaround for BigInt field types (integer with size = big)
      // Treat as String instead of Integer in GraphQL, because of lack support for 64 bit integers
      // More info: https://github.com/graphql/graphql-spec/issues/73
      if($propertyDefinition->getDataType() == 'integer' && $itemDefinition->getSetting('size') == 'big') {
        $derivative['type'] = 'string';
      }
      $derivative['property'] = key($propertyDefinitions);
    }
    else {
      $derivative['type'] = StringHelper::camelCase('field', $entityTypeId, $supportsBundles ? $fieldBundle : '', $fieldName);
    }

    // Fields are usually multi-value. Simplify them for the schema if they are
    // configured for cardinality 1 (only works for configured fields).
    if (!(($storageDefinition = $fieldDefinition->getFieldStorageDefinition()) && !$storageDefinition->isMultiple())) {
      $derivative['type'] = StringHelper::listType($derivative['type']);
    }

    return ["$entityTypeId-$fieldName-$fieldBundle" => $derivative];
  }
}
