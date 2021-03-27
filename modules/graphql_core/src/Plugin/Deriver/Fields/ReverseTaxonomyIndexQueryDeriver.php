<?php

namespace Drupal\graphql_core\Plugin\Deriver\Fields;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\graphql\Utility\StringHelper;
use Drupal\graphql_core\Plugin\Deriver\EntityTypeDeriverBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class ReverseTaxonomyIndexQueryDeriver.
 *
 * @package Drupal\graphql_core\Plugin\Deriver\Fields
 */
class ReverseTaxonomyIndexQueryDeriver extends EntityTypeDeriverBase {

  /**
   * Entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityTypeBundleInfoInterface $entityTypeBundleInfo, EntityFieldManagerInterface $entityFieldManager) {
    $this->entityFieldManager = $entityFieldManager;
    parent::__construct($entityTypeManager, $entityTypeBundleInfo);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $basePluginId) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $bundles = $this->entityTypeBundleInfo->getAllBundleInfo();

    $parents = array_merge([StringHelper::camelCase('taxonomy_term')], array_map(function ($a) {
      return StringHelper::camelCase('taxonomy_term', $a);
    }, array_keys($bundles['taxonomy_term'])));

    $entity_fields = [];
    $tags = [];
    $contexts = [];

    $entity_reference_fields = $this->entityFieldManager->getFieldMapByFieldType('entity_reference');
    foreach ($entity_reference_fields['node'] as $field_name => $details) {
      foreach ($details['bundles'] as $bundle) {
        $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);

        if (isset($field_definitions[$field_name]) && $field_definitions[$field_name]->getFieldStorageDefinition()
          ->getSetting('target_type') === 'taxonomy_term'
        ) {
          $fieldDefinition = $field_definitions[$field_name]->getFieldStorageDefinition();
          $entity_fields[$field_name] = $field_name;

          $tags = array_merge($fieldDefinition->getCacheTags(), ['entity_field_info']);
          $contexts = $fieldDefinition->getCacheContexts();
          $maxAge = $fieldDefinition->getCacheMaxAge();

          continue 2;
        }
      }
    }

    $derivative = [
      'parents' => $parents,
      'fields' => $entity_fields,
      'entity_type' => 'node',
      'schema_cache_tags' => $tags,
      'schema_cache_contexts' => $contexts,
      'schema_cache_max_age' => $maxAge,
    ] + $base_plugin_definition;

    $this->derivatives['rmitonline_reverse_taxonomy_index_query'] = $derivative;

    return parent::getDerivativeDefinitions($base_plugin_definition);
  }

}
