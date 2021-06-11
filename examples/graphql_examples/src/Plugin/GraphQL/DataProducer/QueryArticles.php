<?php

namespace Drupal\graphql_examples\Plugin\GraphQL\DataProducer;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;
use Drupal\graphql_examples\Wrappers\QueryConnection;
use GraphQL\Error\UserError;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @DataProducer(
 *   id = "query_articles",
 *   name = @Translation("Load articles"),
 *   description = @Translation("Loads a list of articles."),
 *   produces = @ContextDefinition("any",
 *     label = @Translation("Article connection")
 *   ),
 *   consumes = {
 *     "offset" = @ContextDefinition("integer",
 *       label = @Translation("Offset"),
 *       required = FALSE
 *     ),
 *     "limit" = @ContextDefinition("integer",
 *       label = @Translation("Limit"),
 *       required = FALSE
 *     )
 *   }
 * )
 */
class QueryArticles extends DataProducerPluginBase implements ContainerFactoryPluginInterface {

  const MAX_LIMIT = 100;

  /**
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   *
   * @codeCoverageIgnore
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager')
    );
  }

  /**
   * Articles constructor.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $pluginId
   *   The plugin id.
   * @param mixed $pluginDefinition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *
   * @codeCoverageIgnore
   */
  public function __construct(
    array $configuration,
    $pluginId,
    $pluginDefinition,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * @param int $offset
   * @param int $limit
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $metadata
   *
   * @return \Drupal\graphql_examples\Wrappers\QueryConnection
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function resolve($offset, $limit, RefinableCacheableDependencyInterface $metadata) {
    if ($limit > static::MAX_LIMIT) {
      throw new UserError(sprintf('Exceeded maximum query limit: %s.', static::MAX_LIMIT));
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $entityType = $storage->getEntityType();
    $query = $storage->getQuery()
      ->currentRevision()
      ->accessCheck();

    $query->condition($entityType->getKey('bundle'), 'article');
    $query->range($offset, $limit);

    $metadata->addCacheTags($entityType->getListCacheTags());
    $metadata->addCacheContexts($entityType->getListCacheContexts());

    return new QueryConnection($query);
  }

}
