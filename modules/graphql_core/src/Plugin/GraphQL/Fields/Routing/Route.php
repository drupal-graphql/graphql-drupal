<?php

namespace Drupal\graphql_core\Plugin\GraphQL\Fields\Routing;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Drupal\graphql\GraphQL\Cache\CacheableValue;
use Drupal\graphql\GraphQL\Execution\ResolveContext;
use Drupal\graphql\Plugin\GraphQL\Fields\FieldPluginBase;
use Drupal\redirect\RedirectRepository;
use GraphQL\Type\Definition\ResolveInfo;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Retrieve a route object based on a path.
 *
 * @GraphQLField(
 *   id = "url_route",
 *   secure = true,
 *   name = "route",
 *   description = @Translation("Loads a route by its path."),
 *   type = "Url",
 *   arguments = {
 *     "path" = "String!"
 *   }
 * )
 */
class Route extends FieldPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The path validator service.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected $pathValidator;

  /**
   * The language negotiator service.
   *
   * @var \Drupal\language\LanguageNegotiator
   */
  protected $languageNegotiator;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * @var \Drupal\redirect\RedirectRepository
   */
  protected $redirectRepository;

  /**
   * @var InboundPathProcessorInterface
   */
  protected $pathProcessor;

  /**
   * The Redirect config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('path.validator'),
      $container->get('language_negotiator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
      $container->get('language_manager'),
      $container->get('redirect.repository', ContainerInterface::NULL_ON_INVALID_REFERENCE),
      $container->get('path_processor_manager'),
      $container->get('config.factory')
    );
  }

  /**
   * Route constructor.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $pluginId
   *   The plugin id.
   * @param mixed $pluginDefinition
   *   The plugin definition.
   * @param \Drupal\Core\Path\PathValidatorInterface $pathValidator
   *   The path validator service.
   * @param \Drupal\language\LanguageNegotiator|null $languageNegotiator
   *   The language negotiator.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\redirect\RedirectRepository $redirectRepository
   *   The redirect repository, if redirect module is active.
   * @param \Drupal\Core\PathProcessor\InboundPathProcessorInterface
   *   An inbound path processor, to clean paths before redirect lookups.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    array $configuration,
    $pluginId,
    $pluginDefinition,
    PathValidatorInterface $pathValidator,
    $languageNegotiator,
    $languageManager,
    $redirectRepository,
    $pathProcessor,
    ConfigFactoryInterface $configFactory
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->redirectRepository = $redirectRepository;
    $this->pathProcessor = $pathProcessor;
    $this->pathValidator = $pathValidator;
    $this->languageNegotiator = $languageNegotiator;
    $this->languageManager = $languageManager;
    $this->config = $configFactory->get('redirect.settings');
  }

  /**
   * {@inheritdoc}
   *
   * Execute routing in language context.
   *
   * Language context has to be inferred from the path prefix, but set before
   * `resolveValues` is invoked.
   */
  public function resolve($value, array $args, ResolveContext $context, ResolveInfo $info) {
    // For now we just take the "url" negotiator into account.
    if ($this->languageManager->isMultilingual() && $this->languageNegotiator) {
      if ($negotiator = $this->languageNegotiator->getNegotiationMethodInstance('language-url')) {
        $context->setContext('language', $negotiator->getLangcode(Request::create($args['path'])), $info);
      }
      else {
        $context->setContext('language', $this->languageManager->getDefaultLanguage()->getId(), $info);
      }
    }

    return parent::resolve($value, $args, $context, $info);
  }

  /**
   * {@inheritdoc}
   *
   * Route field is always language aware since it sets it's context from
   * the prefix.
   */
  protected function isLanguageAwareField() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveValues($value, array $args, ResolveContext $context, ResolveInfo $info) {
    if ($this->redirectRepository) {
      $currentLanguage = $this->languageManager->getCurrentLanguage()->getId();

      $processedPath = $this->pathProcessor
        ->processInbound($args['path'], Request::create($args['path']));

      // Create new Request from path to split pathname & query.
      /** @var Request $sourceRequest */
      $sourceRequest = Request::create($processedPath);

      // Get pathname from request path without leading /.
      /** @var RedirectRepository $redirect_service */
      $sourcePath = trim($sourceRequest->getPathInfo(), '/');

      // Get query from request path.
      $sourceQuery = $sourceRequest->query->all();

      // Get the redirect entity by the path (without query string) and the query string separately.
      if ($redirectEntity =
        $this->redirectRepository->findMatchingRedirect($sourcePath, $sourceQuery, $currentLanguage)) {
        $passthroughQueryString = $this->config->get('passthrough_querystring');

        // Check whether to retain query parameters.
        if ($passthroughQueryString) {
          // Get URL from redirect destination.
          $redirectUrl = Url::fromUri($redirectEntity->getRedirect()['uri']);

          // Merge the query string from the current query (requested path) with the query string configured in the
          // redirect entity.
          $mergedQuery = ($redirectUrl->getOption('query') ?? []) + $sourceQuery;

          // Delete the query string from the url object since we want to pass that separately later.
          $redirectUrl->setOption('query', []);

          // Replace the original redirect based on the source URL, but add the query object this time and overwrite
          // those params with those from the redirect entity.
          $redirectEntity->setRedirect($redirectUrl->toString(), $mergedQuery);
        }

        yield $redirectEntity;
        return;
      }
    }

    if (($url = $this->pathValidator->getUrlIfValidWithoutAccessCheck($args['path'])) && $url->access()) {
      yield $url;
    }
    else {
      yield (new CacheableValue(NULL))->addCacheTags(['4xx-response']);
    }
  }

}
