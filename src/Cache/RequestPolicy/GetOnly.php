<?php

namespace Drupal\graphql\Cache\RequestPolicy;

use Drupal\Core\PageCache\RequestPolicyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contains a request policy that prevents caching of non GET GraphQL requests.
 */
class GetOnly implements RequestPolicyInterface {

  /**
   * {@inheritdoc}
   */
  public function check(Request $request): ?string {
    if ($request->attributes->has('_graphql') && $request->getMethod() !== Request::METHOD_GET) {
      return static::DENY;
    }
    return NULL;
  }

}
