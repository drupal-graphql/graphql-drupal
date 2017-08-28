<?php

namespace Drupal\graphql_query_map_entity\Controller;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Provides a listing of GraphQLQueryMap.
 */
class GraphQLQueryMapListBuilder extends ConfigEntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader() {
    return [
      'version' => $this->t('Query maps'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity) {
    return [
      'version' => $entity->id(),
    ] + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity) {
    /** @var \Drupal\field\FieldConfigInterface $entity */
    $operations = parent::getDefaultOperations($entity);

    $operations['inspect'] = [
      'title' => $this->t('Inspect'),
      'weight' => 10,
      'url' => $entity->toUrl('inspect-form'),
    ];

    return $operations;
  }

}
