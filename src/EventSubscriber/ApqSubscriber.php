<?php

namespace Drupal\graphql\EventSubscriber;

use Drupal\Component\Serialization\Json;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\graphql\Event\OperationEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Save persisted queries to cache.
 */
class ApqSubscriber implements EventSubscriberInterface {

  /**
   * The cache to store persisted queries.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request|null
   */
  protected $request;

  /**
   * Constructs a ApqSubscriber object.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache to store persisted queries.
   * @param \Symfony\Component\HttpFoundation\RequestStack $requestStack
   *   The request stack.
   */
  public function __construct(CacheBackendInterface $cache, RequestStack $requestStack) {
    $this->cache = $cache;
    $this->request = $requestStack->getCurrentRequest();
  }

  /**
   * Handle operation start events.
   *
   * @param \Drupal\graphql\Event\OperationEvent $event
   *   The kernel event object.
   */
  public function onBeforeOperation(OperationEvent $event): void {
    try {
      $json = Json::decode($this->request->getContent());
      if (!empty($json['extensions']['persistedQuery']['sha256Hash']) && !empty($json['query'])) {
        $this->cache->set($json['extensions']['persistedQuery']['sha256Hash'], $json['query']);
      }

    }
    catch (\Exception $exception) {
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      OperationEvent::GRAPHQL_OPERATION_BEFORE => 'onBeforeOperation',
    ];
  }

}
