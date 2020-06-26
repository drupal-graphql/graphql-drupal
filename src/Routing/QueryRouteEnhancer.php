<?php

namespace Drupal\graphql\Routing;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Routing\EnhancerInterface;
use Drupal\graphql\GraphQL\QueryProvider\QueryProviderInterface;
use Drupal\graphql\Utility\JsonHelper;
use GraphQL\Server\Helper;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Route;

class QueryRouteEnhancer implements EnhancerInterface {

  /**
   * {@inheritdoc}
   */
  public function enhance(array $defaults, Request $request) {
    $route = $defaults[RouteObjectInterface::ROUTE_OBJECT];
    if ($route->hasDefault('_graphql')) {
      $helper = new Helper();
      $method = $request->getMethod();
      $body = $this->extractBody($request);
      $query = $this->extractQuery($request);
      $operations = $helper->parseRequestParams($method, $body, $query);

      // By default we assume a 'single' request. This is going to fail in the
      // graphql processor due to a missing query string but at least provides
      // the right format for the client to act upon.
      return $defaults + [
        '_controller' => $defaults['_graphql']['single'],
        'operations' => $operations,
      ];
    }
  }

  /**
   * Extracts the query parameters from a request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The http request object.
   *
   * @return array
   *   The normalized query parameters.
   */
  protected function extractQuery(Request $request) {
    return JsonHelper::decodeParams($request->query->all());
  }

  /**
   * Extracts the body parameters from a request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The http request object.
   *
   * @return array
   *   The normalized body parameters.
   */
  protected function extractBody(Request $request) {
    $values = [];

    // Extract the request content.
    if ($content = json_decode($request->getContent(), TRUE)) {
      $values = array_merge($values, JsonHelper::decodeParams($content));
    }

    if (stripos($request->headers->get('content-type'), 'multipart/form-data') !== FALSE) {
      return $this->extractMultipart($request, $values);
    }

    return $values;
  }

  /**
   * Handles file uploads from multipart/form-data requests.
   *
   * @return array
   *   The query parameters with added file uploads.
   */
  protected function extractMultipart(Request $request, $values) {
    // The request body parameters might contain file upload mutations. We treat
    // them according to the graphql multipart request specification.
    //
    // @see https://github.com/jaydenseric/graphql-multipart-request-spec#server
    if ($body = JsonHelper::decodeParams($request->request->all())) {
      // Flatten the operations array if it exists.
      $operations = isset($body['operations']) && is_array($body['operations']) ? $body['operations'] : [];
      $values = array_merge($values, $body, $operations);
    }

    // According to the graphql multipart request specification, uploaded files
    // are referenced to variable placeholders in a map. Here, we resolve this
    // map by assigning the uploaded files to the corresponding variables.
    if (!empty($values['map']) && is_array($values['map']) && $files = $request->files->all()) {
      foreach ($files as $key => $file) {
        if (!isset($values['map'][$key])) {
          continue;
        }

        $paths = (array) $values['map'][$key];
        foreach ($paths as $path) {
          $path = explode('.', $path);

          if (NestedArray::keyExists($values, $path)) {
            NestedArray::setValue($values, $path, $file);
          }
        }
      }
    }

    return $values;
  }


}
