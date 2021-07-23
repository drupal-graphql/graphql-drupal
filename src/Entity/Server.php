<?php

namespace Drupal\graphql\Entity;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\graphql\GraphQL\Execution\ExecutionResult as CacheableExecutionResult;
use Drupal\graphql\GraphQL\Execution\FieldContext;
use Drupal\graphql\Plugin\PersistedQueryPluginInterface;
use GraphQL\Error\DebugFlag;
use GraphQL\Server\OperationParams;
use Drupal\graphql\GraphQL\Execution\ResolveContext;
use GraphQL\Server\ServerConfig;
use Drupal\graphql\GraphQL\ResolverRegistryInterface;
use Drupal\graphql\GraphQL\Utility\DeferredUtility;
use Drupal\graphql\Plugin\SchemaPluginInterface;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Executor\Executor;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Server\Helper;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Validator\DocumentValidator;

/**
 * The main GraphQL configuration and request entry point.
 *
 * Multiple GraphQL servers can be defined on different routing paths with
 * different GraphQL schemas.
 *
 * @ConfigEntityType(
 *   id = "graphql_server",
 *   label = @Translation("Server"),
 *   handlers = {
 *     "list_builder" = "Drupal\graphql\Controller\ServerListBuilder",
 *     "form" = {
 *       "edit" = "Drupal\graphql\Form\ServerForm",
 *       "create" = "Drupal\graphql\Form\ServerForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *       "persisted_queries" = "Drupal\graphql\Form\PersistedQueriesForm"
 *     }
 *   },
 *   config_prefix = "graphql_servers",
 *   admin_permission = "administer graphql configuration",
 *   entity_keys = {
 *     "id" = "name",
 *     "label" = "label"
 *   },
 *   config_export = {
 *     "name",
 *     "label",
 *     "schema",
 *     "schema_configuration",
 *     "persisted_queries_settings",
 *     "endpoint",
 *     "debug_flag",
 *     "caching",
 *     "batching"
 *   },
 *   links = {
 *     "collection" = "/admin/config/graphql/servers",
 *     "create-form" = "/admin/config/graphql/servers/create",
 *     "edit-form" = "/admin/config/graphql/servers/manage/{graphql_server}",
 *     "delete-form" = "/admin/config/graphql/servers/manage/{graphql_server}/delete",
 *     "persisted_queries-form" = "/admin/config/graphql/servers/manage/{graphql_server}/persisted_queries",
 *   }
 * )
 */
class Server extends ConfigEntityBase implements ServerInterface {
  use DependencySerializationTrait;

  /**
   * The server's machine-readable name.
   *
   * @var string
   */
  public $name;

  /**
   * The server's human-readable name.
   *
   * @var string
   */
  public $label;

  /**
   * The server's schema.
   *
   * @var string
   */
  public $schema;

  /**
   * Schema configuration.
   *
   * @var array
   */
  public $schema_configuration = [];

  /**
   * The debug settings for this server.
   *
   * @var int
   * @see \GraphQL\Error\DebugFlag
   */
  public $debug_flag = DebugFlag::NONE;

  /**
   * Whether the server should cache its results.
   *
   * @var bool
   */
  public $caching = TRUE;

  /**
   * Whether the server allows query batching.
   *
   * @var bool
   */
  public $batching = TRUE;

  /**
   * The server's endpoint.
   *
   * @var string
   */
  public $endpoint;

  /**
   * Persisted query plugins configuration.
   *
   * @var array
   */
  public $persisted_queries_settings = [];


  /**
   * Persisted query plugin instances available on this server.
   *
   * @var array|null
   */
  protected $persisted_query_instances = NULL;

  /**
   * The sorted persisted query plugin instances available on this server.
   *
   * @var array|null
   */
  protected $sorted_persisted_query_instances = NULL;

  /**
   * {@inheritdoc}
   */
  public function id() {
    return $this->name;
  }

  /**
   * {@inheritdoc}
   */
  public function executeOperation(OperationParams $operation) {
    $previous = Executor::getImplementationFactory();
    Executor::setImplementationFactory([
      \Drupal::service('graphql.executor'),
      'create',
    ]);

    try {
      $config = $this->configuration();
      $result = (new Helper())->executeOperation($config, $operation);

      // In case execution fails before the execution stage, we have to wrap the
      // result object here.
      if (!($result instanceof CacheableExecutionResult)) {
        $result = new CacheableExecutionResult($result->data, $result->errors, $result->extensions);
        $result->mergeCacheMaxAge(0);
      }
    }
    finally {
      Executor::setImplementationFactory($previous);
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function executeBatch($operations) {
    // We can't leverage parallel processing of batched queries because of the
    // contextual properties of Drupal (e.g. language manager, current user).
    return array_map(function (OperationParams $operation) {
      return $this->executeOperation($operation);
    }, $operations);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function configuration() {
    $params = \Drupal::getContainer()->getParameter('graphql.config');
    /** @var \Drupal\graphql\Plugin\SchemaPluginManager $manager */
    $manager = \Drupal::service('plugin.manager.graphql.schema');
    $schema = $this->get('schema');

    /** @var \Drupal\graphql\Plugin\SchemaPluginInterface $plugin */
    $plugin = $manager->createInstance($schema);
    $plugin->setServerId($this->name);
    if ($plugin instanceof ConfigurableInterface && $config = $this->get('schema_configuration')) {
      $plugin->setConfiguration($config[$schema] ?? []);
    }

    // Create the server config.
    $registry = $plugin->getResolverRegistry();
    $server = ServerConfig::create();
    $server->setDebugFlag($this->get('debug_flag'));
    $server->setQueryBatching(!!$this->get('batching'));
    $server->setValidationRules($this->getValidationRules());
    $server->setPersistentQueryLoader($this->getPersistedQueryLoader());
    $server->setContext($this->getContext($plugin, $params));
    $server->setFieldResolver($this->getFieldResolver($registry));
    $server->setSchema($plugin->getSchema($registry));
    $server->setPromiseAdapter(new SyncPromiseAdapter());

    return $server;
  }

  /**
   * Returns to root value to use when resolving queries against the schema.
   *
   * @todo Handle this through configuration (e.g. a context value).
   *
   * May return a callable to resolve the root value at run-time based on the
   * provided query parameters / operation.
   *
   * @code
   *
   * public function getRootValue() {
   *   return function (OperationParams $params, DocumentNode $document, $operation) {
   *     // Dynamically return a root value based on the current query.
   *   };
   * }
   *
   * @endcode
   *
   * @return mixed|callable
   *   The root value for query execution or a callable factory.
   */
  protected function getRootValue() {
    return NULL;
  }

  /**
   * Returns the context object to use during query execution.
   *
   * May return a callable to instantiate a context object for each individual
   * query instead of a shared context. This may be useful e.g. when running
   * batched queries where each query operation within the same request should
   * use a separate context object.
   *
   * The returned value will be passed as an argument to every type and field
   * resolver during execution.
   *
   * @code
   *
   * public function getContext() {
   *   $shared = ['foo' => 'bar'];
   *
   *   return function (OperationParams $params, DocumentNode $document, $operation) use ($shared) {
   *     $private = ['bar' => 'baz'];
   *
   *     return new MyContext($shared, $private);
   *   };
   * }
   *
   * @endcode
   *
   * @param \Drupal\graphql\Plugin\SchemaPluginInterface $schema
   *   The schema plugin instance.
   * @param array $config
   *
   * @return mixed|callable
   *   The context object for query execution or a callable factory.
   */
  protected function getContext(SchemaPluginInterface $schema, array $config) {
    // Each document (e.g. in a batch query) gets its own resolve context. This
    // allows us to collect the cache metadata and contextual values (e.g.
    // inheritance for language) for each query separately.
    return function (OperationParams $params, DocumentNode $document, $type) use ($schema, $config) {
      $context = new ResolveContext($this, $params, $document, $type, $config);
      $context->addCacheTags(['graphql_response']);
      if ($this instanceof CacheableDependencyInterface) {
        $context->addCacheableDependency($this);
      }

      if ($schema instanceof CacheableDependencyInterface) {
        $context->addCacheableDependency($schema);
      }

      return $context;
    };
  }

  /**
   * Returns the default field resolver.
   *
   * @todo Handle this through configuration on the server.
   *
   * Fields that don't explicitly declare a field resolver will use this one
   * as a fallback.
   *
   * @param \Drupal\graphql\GraphQL\ResolverRegistryInterface $registry
   *   The resolver registry.
   *
   * @return null|callable
   *   The default field resolver.
   */
  protected function getFieldResolver(ResolverRegistryInterface $registry) {
    return function ($value, $args, ResolveContext $context, ResolveInfo $info) use ($registry) {
      $field = new FieldContext($context, $info);
      $result = $registry->resolveField($value, $args, $context, $info, $field);
      return DeferredUtility::applyFinally($result, function ($result) use ($field, $context) {
        if ($result instanceof CacheableDependencyInterface) {
          $field->addCacheableDependency($result);
        }

        $context->addCacheableDependency($field);
      });
    };
  }

  /**
   * Returns the error formatter.
   *
   * Allows to replace the default error formatter with a custom one. It is
   * essential when there is a need to adjust error format, for instance
   * to add an additional fields or remove some of the default ones.
   *
   * @return mixed|callable
   *   The error formatter.
   *
   * @see \GraphQL\Error\FormattedError::prepareFormatter
   */
  protected function getErrorFormatter() {
    return function (Error $error) {
      return FormattedError::createFromException($error);
    };
  }

  /**
   * Returns the error handler.
   *
   * @todo Handle this through configurable plugins on the server.
   *
   * Allows to replace the default error handler with a custom one. For example
   * when there is a need to handle specific errors differently.
   *
   * @return mixed|callable
   *   The error handler.
   *
   * @see \GraphQL\Executor\ExecutionResult::toArray
   */
  protected function getErrorHandler() {
    return function (array $errors, callable $formatter) {
      return array_map($formatter, $errors);
    };
  }

  /**
   * {@inheritDoc}
   */
  public function addPersistedQueryInstance(PersistedQueryPluginInterface $queryPlugin): void {
    // Make sure the persistedQueryInstances are loaded before trying to add a
    // plugin to them.
    if (is_null($this->persisted_query_instances)) {
      $this->getPersistedQueryInstances();
    }
    $this->persisted_query_instances[$queryPlugin->getPluginId()] = $queryPlugin;
  }

  /**
   * {@inheritdoc}
   */
  public function removePersistedQueryInstance($queryPluginId): void {
    // Make sure the persistedQueryInstances are loaded before trying to remove
    // a plugin from them.
    if (is_null($this->persisted_query_instances)) {
      $this->getPersistedQueryInstances();
    }
    unset($this->persisted_query_instances[$queryPluginId]);
  }

  /**
   * {@inheritDoc}
   */
  public function removeAllPersistedQueryInstances(): void {
    $this->persisted_query_instances = NULL;
    $this->sorted_persisted_query_instances = NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getPersistedQueryInstances() {
    if (!is_null($this->persisted_query_instances)) {
      return $this->persisted_query_instances;
    }

    /** @var \Drupal\graphql\Plugin\PersistedQueryPluginManager $plugin_manager */
    $plugin_manager = \Drupal::service('plugin.manager.graphql.persisted_query');
    $definitions = $plugin_manager->getDefinitions();
    $persisted_queries_settings = $this->get('persisted_queries_settings');
    foreach ($definitions as $id => $definition) {
      if (isset($persisted_queries_settings[$id])) {
        $configuration = !empty($persisted_queries_settings[$id]) ? $persisted_queries_settings[$id] : [];
        $this->persisted_query_instances[$id] = $plugin_manager->createInstance($id, $configuration);
      }
    }

    return $this->persisted_query_instances;
  }

  /**
   * {@inheritDoc}
   */
  public function getSortedPersistedQueryInstances() {
    if (!is_null($this->sorted_persisted_query_instances)) {
      return $this->sorted_persisted_query_instances;
    }
    $this->sorted_persisted_query_instances = $this->getPersistedQueryInstances();
    if (!empty($this->sorted_persisted_query_instances)) {
      uasort($this->sorted_persisted_query_instances, function ($a, $b) {
        return $a->getWeight() <= $b->getWeight() ? -1 : 1;
      });
    }
    return $this->sorted_persisted_query_instances;
  }

  /**
   * Returns a callable for loading persisted queries.
   *
   * @return callable
   *   The persisted query loader.
   */
  protected function getPersistedQueryLoader() {
    return function ($id, OperationParams $params) {
      $sortedPersistedQueryInstances = $this->getSortedPersistedQueryInstances();
      if (!empty($sortedPersistedQueryInstances)) {
        foreach ($sortedPersistedQueryInstances as $persistedQueryInstance) {
          $query = $persistedQueryInstance->getQuery($id, $params);
          if (!is_null($query)) {
            return $query;
          }
        }
      }
    };
  }

  /**
   * Returns the validation rules to use for the query.
   *
   * @todo Handle this through configurable plugins on the server.
   *
   * May return a callable to allow the server to decide the validation rules
   * independently for each query operation.
   *
   * @code
   *
   * public function getValidationRules() {
   *   return function (OperationParams $params, DocumentNode $document, $operation) {
   *     if (isset($params->queryId)) {
   *       // Assume that pre-parsed documents are already validated. This allows
   *       // us to store pre-validated query documents e.g. for persisted queries
   *       // effectively improving performance by skipping run-time validation.
   *       return [];
   *     }
   *
   *     return array_values(DocumentValidator::defaultRules());
   *   };
   * }
   *
   * @endcode
   *
   * @return array|callable
   *   The validation rules or a callable factory.
   */
  protected function getValidationRules() {
    return function (OperationParams $params, DocumentNode $document, $operation) {
      if (isset($params->queryId)) {
        // Assume that pre-parsed documents are already validated. This allows
        // us to store pre-validated query documents e.g. for persisted queries
        // effectively improving performance by skipping run-time validation.
        return [];
      }

      return array_values(DocumentValidator::defaultRules());
    };
  }

  /**
   * {@inheritDoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    // Write all the persisted queries configuration.
    $persistedQueryInstances = $this->getPersistedQueryInstances();
    // Reset settings array after getting instances as it might be used when
    // obtaining them. This would break a config import containing persisted
    // queries settings as it would end up empty.
    $this->persisted_queries_settings = [];
    if (!empty($persistedQueryInstances)) {
      foreach ($persistedQueryInstances as $plugin_id => $plugin) {
        $this->persisted_queries_settings[$plugin_id] = $plugin->getConfiguration();
      }
    }

    parent::preSave($storage);
  }

  /**
   * {@inheritdoc}
   *
   * @codeCoverageIgnore
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    \Drupal::service('router.builder')->setRebuildNeeded();
  }

  /**
   * {@inheritdoc}
   *
   * @codeCoverageIgnore
   */
  public static function postDelete(EntityStorageInterface $storage, array $entities): void {
    parent::postDelete($storage, $entities);
    \Drupal::service('router.builder')->setRebuildNeeded();
  }

}
