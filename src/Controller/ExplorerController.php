<?php

namespace Drupal\graphql\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\graphql\GraphQL\Schema\SchemaLoader;
use Drupal\graphql\GraphQL\Utility\Introspection;
use Drupal\graphql\Plugin\GraphQL\SchemaPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for the GraphiQL query builder IDE.
 */
class ExplorerController implements ContainerInjectionInterface {
  use StringTranslationTrait;

  /**
   * The URL generator service.
   *
   * @var \Drupal\Core\Routing\UrlGeneratorInterface
   */
  protected $urlGenerator;

  /**
   * The introspection service.
   *
   * @var \Drupal\graphql\GraphQL\Utility\Introspection
   */
  protected $introspection;

  /**
   * The schema plugin manager.
   *
   * @var \Drupal\graphql\Plugin\GraphQL\SchemaPluginManager
   */
  protected $pluginManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('url_generator'),
      $container->get('graphql.introspection'),
      $container->get('plugin.manager.graphql.schema')
    );
  }

  /**
   * ExplorerController constructor.
   *
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $urlGenerator
   *   The url generator service.
   * @param \Drupal\graphql\GraphQL\Utility\Introspection $introspection
   *   The introspection service.
   * @param \Drupal\graphql\Plugin\GraphQL\SchemaPluginManager $pluginManager
   *   The schema plugin manager.
   */
  public function __construct(UrlGeneratorInterface $urlGenerator, Introspection $introspection, SchemaPluginManager $pluginManager) {
    $this->urlGenerator = $urlGenerator;
    $this->introspection = $introspection;
    $this->pluginManager = $pluginManager;
  }

  /**
   * Controller for the GraphiQL query builder IDE.
   *
   * @param string $schema
   *   The name of the schema.
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return array The render array.
   *   The render array.
   */
  public function viewExplorer($schema, Request $request) {
    $url = $this->urlGenerator->generate("graphql.query.$schema");
    $introspectionData = $this->introspection->introspect($schema);

    return [
      '#type' => 'page',
      '#theme' => 'page__graphql_explorer',
      '#attached' => [
        'library' => ['graphql/explorer'],
        'drupalSettings' => [
          'graphqlRequestUrl' => $url,
          'graphqlIntrospectionData' => $introspectionData,
          'graphqlQuery' => $request->get('query'),
          'graphqlVariables' => $request->get('variables'),
        ],
      ],
    ];
  }
}
