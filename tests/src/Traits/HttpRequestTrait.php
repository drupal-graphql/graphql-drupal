<?php

namespace Drupal\Tests\graphql\Traits;

use Drupal\graphql\Entity\Server;
use Drupal\graphql\Entity\ServerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test trait for the GraphQL HTTP interface.
 */
trait HttpRequestTrait {

  /**
   * The default server.
   *
   * @var \Drupal\graphql\Entity\Server
   */
  protected $server;

  /**
   * Issue a simple query over http.
   *
   * @param string $query
   *   The query string.
   * @param \Drupal\graphql\Entity\Server|null $server
   *   The server instance.
   * @param array $variables
   *   Query variables.
   * @param array|null $extensions
   *   The query extensions.
   * @param bool $persisted
   *   Flag if the query is actually the identifier of a persisted query.
   * @param string $method
   *   Method, GET or POST.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The http response object.
   */
  protected function query($query, $server = NULL, array $variables = [], array $extensions = NULL, $persisted = FALSE, string $method = Request::METHOD_GET) {
    $server = $server ?: $this->server;
    if (!($server instanceof Server)) {
      throw new \LogicException('Invalid server.');
    }

    $endpoint = $this->server->get('endpoint');
    $extensions = !empty($extensions) ? ['extensions' => $extensions] : [];
    // If the persisted flag is true, then instead of sending the full query to
    // the server we only send the query id.
    $query_key = $persisted ? 'queryId' : 'query';
    $data = [
      $query_key => $query,
      'variables' => $variables,
    ] + $extensions;
    if ($method === Request::METHOD_GET) {
      $request = Request::create($endpoint, $method, $data);
    }
    else {
      $request = Request::create($endpoint, $method, [], [], [], [], json_encode($data));
    }

    return $this->container->get('http_kernel')->handle($request);
  }

  /**
   * Simulate batched queries over http.
   *
   * @param array[] $queries
   *   A set of queries to be executed in one go.
   * @param \Drupal\graphql\Entity\ServerInterface $server
   *   The server instance.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The http response object.
   */
  protected function batchedQueries(array $queries, ServerInterface $server = NULL) {
    $server = $server ?: $this->server;
    if (!($server instanceof Server)) {
      throw new \LogicException('Invalid server.');
    }

    $queries = json_encode($queries);
    $endpoint = $this->server->get('endpoint');
    $request = Request::create($endpoint, 'POST', [], [], [], [], $queries);
    return $this->container->get('http_kernel')->handle($request);
  }

}
