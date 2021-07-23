<?php

namespace Drupal\graphql\Plugin\GraphQL\Schema;

use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\graphql\GraphQL\ResolverRegistryInterface;
use Drupal\graphql\Plugin\SchemaExtensionPluginInterface;
use Drupal\graphql\Plugin\SchemaExtensionPluginManager;
use Drupal\graphql\Plugin\SchemaPluginInterface;
use GraphQL\Language\AST\InterfaceTypeDefinitionNode;
use GraphQL\Language\AST\TypeDefinitionNode;
use GraphQL\Language\AST\UnionTypeDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Utils\BuildSchema;
use GraphQL\Utils\SchemaExtender;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class that can be used by schema plugins.
 */
abstract class SdlSchemaPluginBase extends PluginBase implements SchemaPluginInterface, ContainerFactoryPluginInterface, CacheableDependencyInterface {
  use RefinableCacheableDependencyTrait;

  /**
   * The cache bin for caching the parsed SDL.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $astCache;

  /**
   * Whether the system is currently in development mode.
   *
   * @var bool
   */
  protected $inDevelopment;

  /**
   * The ID of the server using this plugin.
   *
   * @var string $serverId
   */
  protected $serverId;

  /**
   * The schema extension plugin manager.
   *
   * @var \Drupal\graphql\Plugin\SchemaExtensionPluginManager
   */
  protected $extensionManager;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * {@inheritdoc}
   *
   * @codeCoverageIgnore
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('cache.graphql.ast'),
      $container->get('module_handler'),
      $container->get('plugin.manager.graphql.schema_extension'),
      $container->getParameter('graphql.config')
    );
  }

  /**
   * SdlSchemaPluginBase constructor.
   *
   * @param array $configuration
   *   The plugin configuration array.
   * @param string $pluginId
   *   The plugin id.
   * @param array $pluginDefinition
   *   The plugin definition array.
   * @param \Drupal\Core\Cache\CacheBackendInterface $astCache
   *   The cache bin for caching the parsed SDL.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler service.
   * @param \Drupal\graphql\Plugin\SchemaExtensionPluginManager $extensionManager
   *   The schema extension plugin manager.
   * @param array $config
   *   The service configuration.
   *
   * @codeCoverageIgnore
   */
  public function __construct(
    array $configuration,
    $pluginId,
    array $pluginDefinition,
    CacheBackendInterface $astCache,
    ModuleHandlerInterface $moduleHandler,
    SchemaExtensionPluginManager $extensionManager,
    array $config
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->inDevelopment = !empty($config['development']);
    $this->astCache = $astCache;
    $this->extensionManager = $extensionManager;
    $this->moduleHandler = $moduleHandler;
  }

  /**
   * {@inheritdoc}
   */
  public function setServerId(string $serverId): void {
    $this->serverId = $serverId;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \GraphQL\Error\SyntaxError
   * @throws \GraphQL\Error\Error
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  public function getSchema(ResolverRegistryInterface $registry) {
    $extensions = $this->getExtensions();
    $resolver = [$registry, 'resolveType'];
    $document = $this->getSchemaDocument($extensions);
    $schema = BuildSchema::build($document, function ($config, TypeDefinitionNode $type) use ($resolver) {
      if ($type instanceof InterfaceTypeDefinitionNode || $type instanceof UnionTypeDefinitionNode) {
        $config['resolveType'] = $resolver;
      }

      return $config;
    });

    if (empty($extensions)) {
      return $schema;
    }

    foreach ($extensions as $extension) {
      $extension->registerResolvers($registry);
    }

    if ($extendSchema = $this->getExtensionDocument($extensions)) {
      return SchemaExtender::extend($schema, $extendSchema);
    }

    return $schema;
  }

  /**
   * @return \Drupal\graphql\Plugin\SchemaExtensionPluginInterface[]
   */
  protected function getExtensions() {
    return $this->extensionManager->getExtensions($this->getPluginId());
  }

  /**
   * Retrieves the parsed AST of the schema definition.
   *
   * @param array $extensions
   *
   * @return \GraphQL\Language\AST\DocumentNode
   *   The parsed schema document.
   *
   * @throws \GraphQL\Error\SyntaxError
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  protected function getSchemaDocument(array $extensions = []) {
    // Only use caching of the parsed document if we aren't in development mode.
    $cid = "server:{$this->serverId}:schema:{$this->getPluginId()}";
    if (empty($this->inDevelopment) && $cache = $this->astCache->get($cid)) {
      return $cache->data;
    }

    $extensions = array_filter(array_map(function (SchemaExtensionPluginInterface $extension) {
      return $extension->getBaseDefinition();
    }, $extensions), function ($definition) {
      return !empty($definition);
    });

    $schema = array_merge([$this->getSchemaDefinition()], $extensions);
    $ast = Parser::parse(implode("\n\n", $schema));
    if (empty($this->inDevelopment)) {
      $this->astCache->set($cid, $ast, CacheBackendInterface::CACHE_PERMANENT, ['graphql']);
    }

    return $ast;
  }

  /**
   * Retrieves the parsed AST of the schema extension definitions.
   *
   * @param array $extensions
   *
   * @return \GraphQL\Language\AST\DocumentNode|null
   *   The parsed schema document.
   *
   * @throws \GraphQL\Error\SyntaxError
   */
  protected function getExtensionDocument(array $extensions = []) {
    // Only use caching of the parsed document if we aren't in development mode.
    $cid = "server:{$this->serverId}:extension:{$this->getPluginId()}";
    if (empty($this->inDevelopment) && $cache = $this->astCache->get($cid)) {
      return $cache->data;
    }

    $extensions = array_filter(array_map(function (SchemaExtensionPluginInterface $extension) {
      return $extension->getExtensionDefinition();
    }, $extensions), function ($definition) {
      return !empty($definition);
    });

    $ast = !empty($extensions) ? Parser::parse(implode("\n\n", $extensions)) : NULL;
    if (empty($this->inDevelopment)) {
      $this->astCache->set($cid, $ast, CacheBackendInterface::CACHE_PERMANENT, ['graphql']);
    }

    return $ast;
  }

  /**
   * Retrieves the raw schema definition string.
   *
   * @return string
   *   The schema definition.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   */
  protected function getSchemaDefinition() {
    $id = $this->getPluginId();
    $definition = $this->getPluginDefinition();
    $module = $this->moduleHandler->getModule($definition['provider']);
    $path = 'graphql/' . $id . '.graphqls';
    $file = $module->getPath() . '/' . $path;

    if (!file_exists($file)) {
      throw new InvalidPluginDefinitionException(
        $id,
        sprintf(
          'The module "%s" needs to have a schema definition "%s" in its folder for "%s" to be valid.',
          $module->getName(), $path, $definition['class']));
    }

    return file_get_contents($file) ?: NULL;
  }

}
