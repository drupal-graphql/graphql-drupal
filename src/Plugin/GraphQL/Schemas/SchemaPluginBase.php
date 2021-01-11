<?php

namespace Drupal\graphql\Plugin\GraphQL\Schemas;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\graphql\GraphQL\Execution\ResolveContext;
use Drupal\graphql\GraphQL\QueryProvider\QueryProviderInterface;
use Drupal\graphql\Plugin\FieldPluginManager;
use Drupal\graphql\Plugin\MutationPluginManager;
use Drupal\graphql\Plugin\SubscriptionPluginManager;
use Drupal\graphql\Plugin\SchemaBuilderInterface;
use Drupal\graphql\Plugin\SchemaPluginInterface;
use Drupal\graphql\Plugin\TypePluginManagerAggregator;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Server\OperationParams;
use GraphQL\Server\ServerConfig;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;
use GraphQL\Type\SchemaConfig;
use GraphQL\Validator\DocumentValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class SchemaPluginBase extends PluginBase implements SchemaPluginInterface, SchemaBuilderInterface, ContainerFactoryPluginInterface, CacheableDependencyInterface {

  /**
   * The field plugin manager.
   *
   * @var \Drupal\graphql\Plugin\FieldPluginManager
   */
  protected $fieldManager;

  /**
   * The mutation plugin manager.
   *
   * @var \Drupal\graphql\Plugin\MutationPluginManager
   */
  protected $mutationManager;

  /**
   * The subscription plugin manager.
   *
   * @var \Drupal\graphql\Plugin\SubscriptionPluginManager
   */
  protected $subscriptionManager;

  /**
   * The type manager aggregator service.
   *
   * @var \Drupal\graphql\Plugin\TypePluginManagerAggregator
   */
  protected $typeManagers;

  /**
   * Static cache of field definitions.
   *
   * @var array
   */
  protected $fields = [];

  /**
   * Static cache of mutation definitions.
   *
   * @var array
   */
  protected $mutations = [];

  /**
   * Static cache of subscription definitions.
   *
   * @var array
   */
  protected $subscriptions = [];

  /**
   * Static cache of type instances.
   *
   * @var array
   */
  protected $types = [];

  /**
   * The service parameters
   *
   * @var array
   */
  protected $parameters;

  /**
   * The query provider service.
   *
   * @var \Drupal\graphql\GraphQL\QueryProvider\QueryProviderInterface
   */
  protected $queryProvider;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('plugin.manager.graphql.field'),
      $container->get('plugin.manager.graphql.mutation'),
      $container->get('plugin.manager.graphql.subscription'),
      $container->get('graphql.type_manager_aggregator'),
      $container->get('graphql.query_provider'),
      $container->get('current_user'),
      $container->get('logger.channel.graphql'),
      $container->get('language_manager'),
      $container->getParameter('graphql.config')
    );
  }

  /**
   * SchemaPluginBase constructor.
   *
   * @param array $configuration
   *   The plugin configuration array.
   * @param string $pluginId
   *   The plugin id.
   * @param array $pluginDefinition
   *   The plugin definition array.
   * @param \Drupal\graphql\Plugin\FieldPluginManager $fieldManager
   *   The field plugin manager.
   * @param \Drupal\graphql\Plugin\MutationPluginManager $mutationManager
   *   The mutation plugin manager.
   * @param \Drupal\graphql\Plugin\SubscriptionPluginManager $subscriptionManager
   *   The subscription plugin manager.
   * @param \Drupal\graphql\Plugin\TypePluginManagerAggregator $typeManagers
   *   The type manager aggregator service.
   * @param \Drupal\graphql\GraphQL\QueryProvider\QueryProviderInterface $queryProvider
   *   The query provider service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager service.
   * @param array $parameters
   *   The service parameters.
   */
  public function __construct(
    $configuration,
    $pluginId,
    $pluginDefinition,
    FieldPluginManager $fieldManager,
    MutationPluginManager $mutationManager,
    SubscriptionPluginManager $subscriptionManager,
    TypePluginManagerAggregator $typeManagers,
    QueryProviderInterface $queryProvider,
    AccountProxyInterface $currentUser,
    LoggerInterface $logger,
    LanguageManagerInterface $languageManager,
    array $parameters
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->fieldManager = $fieldManager;
    $this->mutationManager = $mutationManager;
    $this->subscriptionManager = $subscriptionManager;
    $this->typeManagers = $typeManagers;
    $this->queryProvider = $queryProvider;
    $this->currentUser = $currentUser;
    $this->parameters = $parameters;
    $this->logger = $logger;
    $this->languageManager = $languageManager;
  }

  /**
   * {@inheritdoc}
   */
  public function getSchema() {
    $config = new SchemaConfig();

    if ($this->hasMutations()) {
      $config->setMutation(new ObjectType([
        'name' => 'Mutation',
        'fields' => function () {
          return $this->getMutations();
        },
      ]));
    }

    if ($this->hasSubscriptions()) {
      $config->setSubscription(new ObjectType([
        'name' => 'Subscription',
        'fields' => function () {
          return $this->getSubscriptions();
        },
      ]));
    }

    $config->setQuery(new ObjectType([
      'name' => 'Query',
      'fields' => function () {
        return $this->getFields('Root');
      },
    ]));

    $config->setTypes(function () {
      return $this->getTypes();
    });

    $config->setTypeLoader(function ($name) {
      return $this->getType($name);
    });

    return new Schema($config);
  }

  /**
   * {@inheritdoc}
   */
  public function validateSchema() {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getServer() {
    // If the current user has appropriate permissions, allow to bypass
    // the secure fields restriction.
    $globals['bypass field security'] = $this->currentUser->hasPermission('bypass graphql field security');

    // Create the server config.
    $config = ServerConfig::create();

    // Each document (e.g. in a batch query) gets its own resolve context. This
    // allows us to collect the cache metadata and contextual values (e.g.
    // inheritance for language) for each query separately.
    $config->setContext(function ($params, $document, $operation) use ($globals) {
      // Each document (e.g. in a batch query) gets its own resolve context. This
      // allows us to collect the cache metadata and contextual values (e.g.
      // inheritance for language) for each query separately.
      $context = new ResolveContext($globals, [
        'language' => $this->languageManager->getCurrentLanguage()->getId(),
      ]);

      $context->addCacheTags(['graphql']);

      // Always add the language_url cache context.
      $context->addCacheContexts([
        'languages:language_url',
        'languages:language_interface',
        'languages:language_content',
        'user.permissions',
      ]);

      return $context;
    });

    $config->setValidationRules(function (OperationParams $params, DocumentNode $document, $operation) {
      if (isset($params->queryId) && empty($params->getOriginalInput('query'))) {
        // Assume that pre-parsed documents are already validated. This allows
        // us to store pre-validated query documents e.g. for persisted queries
        // effectively improving performance by skipping run-time validation.
        return [];
      }

      return array_values(DocumentValidator::defaultRules());
    });

    $config->setPersistentQueryLoader([$this->queryProvider, 'getQuery']);
    $config->setQueryBatching(TRUE);
    $config->setDebug(!!$this->parameters['development']);
    $config->setSchema($this->getSchema());

    // Always log the errors.
    $config->setErrorsHandler(function (array $errors, callable $formatter) {
      /** @var \GraphQL\Error\Error $error */
      foreach ($errors as $error) {
        $this->logger->error($error->getMessage());
      }

      return array_map($formatter, $errors);
    });

    return $config;
  }

  /**
  /**
   * {@inheritdoc}
   */
  public function hasFields($type) {
    return isset($this->pluginDefinition['field_association_map'][$type]);
  }

  /**
   * {@inheritdoc}
   */
  public function hasMutations() {
    return !empty($this->pluginDefinition['mutation_map']);
  }

  /**
   * {@inheritdoc}
   */
  public function hasSubscriptions() {
    return !empty($this->pluginDefinition['subscription_map']);
  }

  /**
   * {@inheritdoc}
   */
  public function hasType($name) {
    return isset($this->pluginDefinition['type_map'][$name]);
  }

  /**
   * {@inheritdoc}
   */
  public function getFields($type) {
    $association = $this->pluginDefinition['field_association_map'];
    $fields = $this->pluginDefinition['field_map'];

    if (isset($association[$type])) {
      return $this->processFields(array_map(function ($id) use ($fields) {
        return $fields[$id];
      }, $association[$type]));
    }

    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getMutations() {
    return $this->processMutations($this->pluginDefinition['mutation_map']);
  }

  /**
   * {@inheritdoc}
   */
  public function getSubscriptions() {
    return $this->processSubscriptions($this->pluginDefinition['subscription_map']);
  }

  /**
   * {@inheritdoc}
   */
  public function getTypes() {
    return array_map(function ($name) {
      return $this->getType($name);
    }, array_keys($this->pluginDefinition['type_map']));
  }

  /**
   * {@inheritdoc}
   */
  public function getSubTypes($name) {
    $association = $this->pluginDefinition['type_association_map'];
    return isset($association[$name]) ? $association[$name] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function resolveType($name, $value, ResolveContext $context, ResolveInfo $info) {
    $association = $this->pluginDefinition['type_association_map'];
    $types = $this->pluginDefinition['type_map'];
    if (!isset($association[$name])) {
      return NULL;
    }

    foreach ($association[$name] as $type) {
      // TODO: Try to avoid loading the type for the check. Consider to make it static!
      if (isset($types[$type]) && $instance = $this->buildType($types[$type])) {
        if ($instance->isTypeOf($value, $context, $info)) {
          return $instance;
        }
      }
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getType($name) {
    $types = $this->pluginDefinition['type_map'];
    $references = $this->pluginDefinition['type_reference_map'];
    if (isset($types[$name])) {
      return $this->buildType($this->pluginDefinition['type_map'][$name]);
    }

    do {
      if (isset($references[$name])) {
        return $this->buildType($types[$references[$name]]);
      }
    } while (($pos = strpos($name, ':')) !== FALSE && $name = substr($name, 0, $pos));

    throw new \LogicException(sprintf('Missing type %s.', $name));
  }

  /**
   * {@inheritdoc}
   */
  public function processMutations(array $mutations) {
    return array_map([$this, 'buildMutation'], $mutations);
  }

  /**
   * {@inheritdoc}
   */
  public function processSubscriptions(array $subscriptions) {
    return array_map([$this, 'buildSubscription'], $subscriptions);
  }

  /**
   * {@inheritdoc}
   */
  public function processFields(array $fields) {
    $processFields = array_map([$this, 'buildField'], $fields);
    foreach($processFields as $key => $processField) {
      if($processField === false) unset($processFields[$key]);
    }
    return $processFields;
  }

  /**
   * {@inheritdoc}
   */
  public function processArguments(array $args) {
    return array_filter(array_map(function ($arg) {
      try {
        $type = $this->processType($arg['type']);
      }
      catch (\Exception $e) {
        // Allow optional arguments that are removed if the input type is
        // not defined.
        if (empty($arg['optional'])) {
          throw $e;
        }

        return NULL;
      }

      return [
        'type' => $type,
      ] + $arg;
    }, $args));
  }

  /**
   * {@inheritdoc}
   */
  public function processType(array $type) {
    list($type, $decorators) = $type;

    return array_reduce($decorators, function ($type, $decorator) {
      return $decorator($type);
    }, $this->getType($type));
  }

  /**
   * Retrieves the type instance for the given reference.
   *
   * @param array $type
   *   The type reference.
   *
   * @return \GraphQL\Type\Definition\Type
   *   The type instance.
   */
  protected function buildType($type) {
    if (!isset($this->types[$type['id']])) {
      $creator = [$type['class'], 'createInstance'];
      $manager = $this->typeManagers->getTypeManager($type['type']);
      $this->types[$type['id']] = $creator($this, $manager, $type['definition'], $type['id']);
    }

    return $this->types[$type['id']];
  }

  /**
   * Retrieves the field definition for a given field reference.
   *
   * @param array $field
   *   The type reference.
   *
   * @return array
   *   The field definition.
   */
  protected function buildField($field) {
    if($field['definition']['type'][0] == "layout_section") return false;
    if (!isset($this->fields[$field['id']])) {
      $creator = [$field['class'], 'createInstance'];
      $this->fields[$field['id']] = $creator($this, $this->fieldManager, $field['definition'], $field['id']);
    }

    return $this->fields[$field['id']];
  }

  /**
   * Retrieves the mutation definition for a given field reference.
   *
   * @param array $mutation
   *   The mutation reference.
   *
   * @return array
   *   The mutation definition.
   */
  protected function buildMutation($mutation) {
    if (!isset($this->mutations[$mutation['id']])) {
      $creator = [$mutation['class'], 'createInstance'];
      $this->mutations[$mutation['id']] = $creator($this, $this->mutationManager, $mutation['definition'], $mutation['id']);
    }

    return $this->mutations[$mutation['id']];
  }

  /**
   * Retrieves the subscription definition for a given field reference.
   *
   * @param array $mutation
   *   The subscription reference.
   *
   * @return array
   *   The subscription definition.
   */
  protected function buildSubscription($subscription) {
    if (!isset($this->subscriptions[$subscription['id']])) {
      $creator = [$subscription['class'], 'createInstance'];
      $this->subscriptions[$subscription['id']] = $creator($this, $this->subscriptionManager, $subscription['definition'], $subscription['id']);
    }

    return $this->subscriptions[$subscription['id']];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return $this->pluginDefinition['schema_cache_tags'];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return $this->pluginDefinition['schema_cache_max_age'];
  }
}
