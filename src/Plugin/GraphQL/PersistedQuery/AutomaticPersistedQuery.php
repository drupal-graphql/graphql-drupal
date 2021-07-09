<?php

namespace Drupal\graphql\Plugin\GraphQL\PersistedQuery;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\graphql\PersistedQuery\PersistedQueryPluginBase;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Load persisted queries from the cache.
 *
 * @PersistedQuery(
 *   id = "automatic_persisted_query",
 *   label = "Automatic Persisted Query",
 *   description = "Load persisted queries from the cache."
 * )
 */
class AutomaticPersistedQuery extends PersistedQueryPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The cache to store persisted queries.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, CacheBackendInterface $cache) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->cache = $cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition, $container->get('cache.graphql.apq'));
  }

  /**
   * {@inheritdoc}
   */
  public function getQuery($id, OperationParams $operation) {
    if ($query = $this->cache->get($id)) {
      return $query->data;
    }
    throw new RequestError('PersistedQueryNotFound');
  }

}
