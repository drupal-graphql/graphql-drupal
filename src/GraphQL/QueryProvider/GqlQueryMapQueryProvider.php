<?php

namespace Drupal\graphql\GraphQL\QueryProvider;

use Drupal\Component\FileSystem\RegexDirectoryIterator;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GraphQL\Server\OperationParams;

class GqlQueryMapQueryProvider implements QueryProviderInterface {

  /**
   * The cache backend for storing query map file paths.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheBackend;

  /**
   * The paths to use for finding query maps.
   *
   * @var string[]
   */
  protected $lookupPaths;

  /**
   * QueryProvider constructor.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   The cache backend for storing query map file paths.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(CacheBackendInterface $cacheBackend, ConfigFactoryInterface $configFactory) {
    $this->lookupPaths = $configFactory->get('graphql.query_map_json.config')->get('lookup_paths') ?: [];
    $this->cacheBackend = $cacheBackend;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuery($id, OperationParams $operation) {
    // Check that the id is properly formatted.
    if (empty($id)) {
      return NULL;
    }

    if (!(($cache = $this->cacheBackend->get('graphql_query_map_gql_versions')) && ($versions = $cache->data) !== NULL)) {
      $this->cacheBackend->set('graphql_query_map_gql_versions', $versions = $this->discoverQueryMaps());
    }

    if (isset($versions) && isset($versions[$id]) && file_exists($versions[$id])) {
      return file_get_contents($versions[$id]);
    }

    return NULL;
  }

  /**
   * Discovers the available query maps within the configured lookup paths.
   *
   * @return array
   *   An associative array of query maps with the query map versions as keys.
   */
  protected function discoverQueryMaps() {
    $maps = [];
    foreach ($this->lookupPaths as $path) {
      if (is_dir($path)) {
        $iterator = new RegexDirectoryIterator($path, '/\.gql/i');

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
          $filename = $file->getBasename('.gql');
          $maps[$filename] = $file->getPathname();
        }
      }

      if (is_file($path)) {
        $file = new \SplFileInfo($path);
        $filename = $file->getBasename('.gql');
        $maps[$filename] = $file->getPathname();
      }
    }

    return $maps;
  }
}
