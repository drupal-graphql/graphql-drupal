<?php

namespace Drupal\graphql;

use Drupal\Core\Authentication\AuthenticationCollectorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\Routing\Route;

/**
 * Provides Symfony routing information for each defined GraphQL server.
 */
class RouteProvider {

  /**
   * The authentication collector service.
   *
   * @var \Drupal\Core\Authentication\AuthenticationCollectorInterface
   */
  protected $authenticationCollector;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * RouteProvider constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Authentication\AuthenticationCollectorInterface $authenticationCollector
   *   The authentication collector service.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, AuthenticationCollectorInterface $authenticationCollector) {
    $this->authenticationCollector = $authenticationCollector;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Collects routes for the server endpoints.
   */
  public function routes(): array {
    $storage = $this->entityTypeManager->getStorage('graphql_server');
    /** @var \Drupal\graphql\Entity\ServerInterface[] $servers */
    $servers = $storage->loadMultiple();
    $routes = [];

    // Allow all authentication providers by default.
    $auth = array_keys($this->authenticationCollector->getSortedProviders());

    foreach ($servers as $id => $server) {
      $path = $server->get('endpoint');

      $routes["graphql.query.$id"] = (new Route($path))
        ->addDefaults([
          'graphql_server' => $id,
          '_graphql' => TRUE,
          '_controller' => '\Drupal\graphql\Controller\RequestController::handleRequest',
          '_disable_route_normalizer' => TRUE,
        ])
        ->addRequirements([
          '_graphql_query_access' => 'graphql_server:{graphql_server}',
          '_format' => 'json',
        ])
        ->addOptions([
          '_auth' => $auth,
          'default_url_options' => ['path_processing' => FALSE],
          'parameters' => ['graphql_server' => ['type' => 'entity:graphql_server']],
        ]);
    }

    return $routes;
  }

}
