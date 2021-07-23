<?php

namespace Drupal\graphql\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Component\Plugin\DerivativeInspectionInterface;
use Drupal\graphql\GraphQL\ResolverRegistryInterface;

/**
 * Defines a schema plugin that returns a GraphQL schema part.
 *
 * A schema plugin also defines how that schema is resolved to values with data
 * producers.
 */
interface SchemaPluginInterface extends PluginInspectionInterface, DerivativeInspectionInterface {

  /**
   * Set the serverID, required for cache id generation.
   *
   * @param string
   *   The machine name of the server using this plugin.
   */
  public function setServerId(string $serverId): void;

  /**
   * Retrieves the schema.
   *
   * @param \Drupal\graphql\GraphQL\ResolverRegistryInterface $registry
   *   The resolver registry.
   *
   * @return \GraphQL\Type\Schema
   *   The schema.
   */
  public function getSchema(ResolverRegistryInterface $registry);

  /**
   * Retrieves the resolver registry.
   *
   * @todo Instead, this should be configuration.
   *
   * @return \Drupal\graphql\GraphQL\ResolverRegistryInterface
   *   The resolver registry.
   */
  public function getResolverRegistry();

}
