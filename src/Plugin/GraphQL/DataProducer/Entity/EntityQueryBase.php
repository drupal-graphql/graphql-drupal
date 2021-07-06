<?php

namespace Drupal\graphql\Plugin\GraphQL\DataProducer\Entity;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\graphql\GraphQL\Execution\FieldContext;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;
use GraphQL\Error\UserError;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class to share code between entity query and entity query count.
 */
abstract class EntityQueryBase extends DataProducerPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('current_user')
    );
  }

  /**
   * EntityLoad constructor.
   *
   * @param array $configuration
   *   The plugin configuration array.
   * @param string $pluginId
   *   The plugin id.
   * @param array $pluginDefinition
   *   The plugin definition array.
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(
    array $configuration,
    string $pluginId,
    array $pluginDefinition,
    EntityTypeManager $entityTypeManager,
    AccountInterface $current_user
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->entityTypeManager = $entityTypeManager;
    $this->currentUser = $current_user;
  }

  /**
   * Build base entity query which may be reused for count query as well.
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
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   Base entity query.
   *
   * @throws \GraphQL\Error\UserError
   *   No bundles defined for given entity type.
   */
  protected function buildBaseEntityQuery(string $type, bool $ownedOnly, array $conditions, array $allowedFilters, array $languages, array $bundles, bool $access, FieldContext $context): QueryInterface {
    $entity_type = $this->entityTypeManager->getStorage($type);
    $query = $entity_type->getQuery();

    // Query only those entities which are owned by current user, if desired.
    if ($ownedOnly) {
      $query->condition('uid', $this->currentUser->id());
      // Add user cacheable dependencies.
      $account = $this->currentUser->getAccount();
      $context->addCacheableDependency($account);
      // Cache response per user to make sure the user related result is shown.
      $context->addCacheContexts(['user']);
    }

    // Ensure that desired access checking is performed on the query.
    $query->accessCheck($access);

    // Filter entities only of given bundles, if desired.
    if ($bundles) {
      $bundle_key = $entity_type->getEntityType()->getKey('bundle');
      if (!$bundle_key) {
        throw new UserError('No bundles defined for given entity type.');
      }
      $query->condition($bundle_key, $bundles, 'IN');
    }

    // Filter entities by given languages, if desired.
    if ($languages) {
      $query->condition('langcode', $languages, 'IN');
    }

    // Filter by given conditions.
    foreach ($conditions as $condition) {
      if (!in_array($condition['field'], $allowedFilters)) {
        throw new UserError("Field '{$condition['field']}' is not allowed as filter.");
      }
      $operation = isset($condition['operator']) ? $condition['operator'] : NULL;
      $query->condition($condition['field'], $condition['value'], $operation);
    }

    return $query;
  }

}
