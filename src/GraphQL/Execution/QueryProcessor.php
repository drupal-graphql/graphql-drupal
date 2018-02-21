<?php

namespace Drupal\graphql\GraphQL\Execution;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\graphql\GraphQL\Visitors\CacheMetadataCollector;
use Drupal\graphql\Plugin\SchemaPluginManager;
use Drupal\graphql\GraphQL\QueryProvider\QueryProviderInterface;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Executor;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Server\Helper;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use GraphQL\Server\ServerConfig;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Utils\Utils;
use GraphQL\Validator\DocumentValidator;

class QueryProcessor {

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The schema plugin manager.
   *
   * @var \Drupal\graphql\Plugin\SchemaPluginManager
   */
  protected $pluginManager;

  /**
   * The query provider service.
   *
   * @var \Drupal\graphql\GraphQL\QueryProvider\QueryProviderInterface
   */
  protected $queryProvider;

  /**
   * Processor constructor.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\graphql\Plugin\SchemaPluginManager $pluginManager
   *   The schema plugin manager.
   * @param \Drupal\graphql\GraphQL\QueryProvider\QueryProviderInterface $queryProvider
   *   The query provider service.
   */
  public function __construct(
    AccountProxyInterface $currentUser,
    SchemaPluginManager $pluginManager,
    QueryProviderInterface $queryProvider
  ) {
    $this->currentUser = $currentUser;
    $this->pluginManager = $pluginManager;
    $this->queryProvider = $queryProvider;
  }

  /**
   * Processes one or multiple graphql operations.
   *
   * @param string $schema
   *   The plugin id of the schema to use.
   * @param \GraphQL\Server\OperationParams|\GraphQL\Server\OperationParams[] $params
   *   The graphql operation(s) to execute.
   * @param array $globals
   *   The query context.
   *
   * @return \Drupal\graphql\GraphQL\Execution\QueryResult|\Drupal\graphql\GraphQL\Execution\QueryResult[]
   *   The query result.
   *
   */
  public function processQuery($schema, $params, array $globals = []) {
    // Load the plugin from the schema manager.
    $plugin = $this->pluginManager->createInstance($schema);
    $schema = $plugin->getSchema();

    // Build the resolve context which is handed to each field resolver.
    $context = new ResolveContext($globals);
    $debug = $context->getGlobal('development', FALSE);

    // Create the server config.
    $config = ServerConfig::create();
    $config->setDebug($debug);
    $config->setSchema($schema);
    $config->setQueryBatching(TRUE);
    $config->setContext($context);
    $config->setPersistentQueryLoader(function ($id, OperationParams $params) {
      if ($query = $this->queryProvider->getQuery($id, $params)) {
        return $query;
      }

      throw new RequestError(sprintf("Failed to load query map for id '%s'.", $id));
    });

    if (is_array($params)) {
      return $this->executeBatch($config, $params);
    }

    return $this->executeSingle($config, $params);
  }

  /**
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   *
   * @return mixed
   */
  public function executeSingle(ServerConfig $config, OperationParams $params) {
    $adapter = new SyncPromiseAdapter();
    $result = $this->promiseToExecuteOperation($adapter, $config, $params, FALSE);
    return $adapter->wait($result);
  }

  /**
   * @param \GraphQL\Server\ServerConfig $config
   * @param array $params
   *
   * @return mixed
   */
  public function executeBatch(ServerConfig $config, array $params) {
    $adapter = new SyncPromiseAdapter();
    $result = array_map(function ($params) use ($adapter, $config) {
      return $this->promiseToExecuteOperation($adapter, $config, $params, TRUE);
    }, $params);

    $result = $adapter->all($result);
    return $adapter->wait($result);
  }

  /**
   * @param \GraphQL\Executor\Promise\PromiseAdapter $adapter
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   * @param bool $batching
   *
   * @return \GraphQL\Executor\Promise\Promise
   */
  protected function promiseToExecuteOperation(PromiseAdapter $adapter, ServerConfig $config, OperationParams $params, $batching = FALSE) {
    try {
      if (!$config->getSchema()) {
        throw new \LogicException('Missing schema for query execution.');
      }

      if ($batching && !$config->getQueryBatching()) {
        throw new RequestError('Batched queries are not supported by this server.');
      }

      if ($errors = (new Helper())->validateOperationParams($params)) {
        $errors = Utils::map($errors, function (RequestError $err) {
          return Error::createLocatedError($err, NULL, NULL);
        });

        return $adapter->createFulfilled(new QueryResult(NULL, $errors));
      }

      $variables = $params->variables;
      $operation = $params->operation;
      $document = $params->queryId ? $this->loadPersistedQuery($config, $params) : $params->query;
      if (!$document instanceof DocumentNode) {
        $document = Parser::parse($document);
      }

      if ($params->isReadOnly() && AST::getOperation($document, $operation) !== 'query') {
        throw new RequestError('GET requests are only supported for query operations.');
      }

      $schema = $config->getSchema();
      $resolver = $config->getFieldResolver();
      $root = $this->resolveRootValue($config, $params, $document, $operation);
      $context = $this->resolveContextValue($config, $params, $document, $operation);
      $rules = $this->resolveValidationRules($config, $params, $document, $operation);
      $result = $this->promiseToExecute(
        $adapter,
        $schema,
        $document,
        $root,
        $context,
        $variables,
        $operation,
        $resolver,
        $rules
      );
    }
    catch (RequestError $exception) {
      $result = $adapter->createFulfilled(new QueryResult(NULL, [Error::createLocatedError($exception)]));
    }
    catch (Error $exception) {
      $result = $adapter->createFulfilled(new QueryResult(NULL, [$exception]));
    }

    return $result->then(function(QueryResult $result) use ($config) {
      if ($config->getErrorsHandler()) {
        $result->setErrorsHandler($config->getErrorsHandler());
      }

      if ($config->getErrorFormatter() || $config->getDebug()) {
        $result->setErrorFormatter(FormattedError::prepareFormatter($config->getErrorFormatter(), $config->getDebug()));
      }

      return $result;
    });
  }

  /**
   * @param \GraphQL\Executor\Promise\PromiseAdapter $adapter
   * @param \GraphQL\Type\Schema $schema
   * @param \GraphQL\Language\AST\DocumentNode $document
   * @param null $root
   * @param null $context
   * @param null $variables
   * @param null $operation
   * @param callable|NULL $resolver
   * @param array|NULL $rules
   *
   * @return \GraphQL\Executor\Promise\Promise
   */
  protected function promiseToExecute(
    PromiseAdapter $adapter,
    Schema $schema,
    DocumentNode $document,
    $root = NULL,
    $context = NULL,
    $variables = NULL,
    $operation = NULL,
    callable $resolver = NULL,
    array $rules = NULL
  ) {
    try {
      $metadata = new CacheableMetadata();
      $visitor = new CacheMetadataCollector($metadata, $variables);
      $rules = array_merge($rules ?: DocumentValidator::allRules(), [$visitor]);
      if ($errors = DocumentValidator::validate($schema, $document, $rules)) {
        return $adapter->createFulfilled(new QueryResult(NULL, $errors));
      }

      // TODO: Implement cache backend lookup with collected cache metadata.

      return (Executor::promiseToExecute(
        $adapter,
        $schema,
        $document,
        $root,
        $context,
        $variables,
        $operation,
        $resolver
      ))->then(function (ExecutionResult $result) use ($metadata) {
        return new QueryResult($result->data, $result->errors, $result->extensions, $metadata);
      });
    }
    catch (Error $exception) {
      return $adapter->createFulfilled(new QueryResult(NULL, [$exception]));
    }
  }

  /**
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   * @param \GraphQL\Language\AST\DocumentNode $document
   * @param $operation
   *
   * @return callable|mixed
   */
  protected function resolveRootValue(ServerConfig $config, OperationParams $params, DocumentNode $document, $operation) {
    $root = $config->getRootValue();
    if (is_callable($root)) {
      $root = $root($params, $document, $operation);
    }

    return $root;
  }

  /**
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   * @param \GraphQL\Language\AST\DocumentNode $document
   * @param $operation
   *
   * @return callable|mixed
   */
  protected function resolveContextValue(ServerConfig $config, OperationParams $params, DocumentNode $document, $operation) {
    $context = $config->getContext();
    if (is_callable($context)) {
      $context = $context($params, $document, $operation);
    }

    return $context;
  }

  /**
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   * @param \GraphQL\Language\AST\DocumentNode $document
   * @param $operation
   *
   * @return array|callable
   */
  protected function resolveValidationRules(ServerConfig $config, OperationParams $params, DocumentNode $document, $operation) {
    // Allow customizing validation rules per operation:
    $rules = $config->getValidationRules();
    if (is_callable($rules)) {
      $rules = $rules($params, $document, $operation);
      if (!is_array($rules)) {
        throw new \LogicException(sprintf("Expecting validation rules to be array or callable returning array, but got: %s", Utils::printSafe($rules)));
      }
    }

    return $rules;
  }

  /**
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   *
   * @return mixed
   * @throws \GraphQL\Server\RequestError
   */
  protected function loadPersistedQuery(ServerConfig $config, OperationParams $params) {
    if (!$loader = $config->getPersistentQueryLoader()) {
      throw new RequestError('Persisted queries are not supported by this server.');
    }

    $source = $loader($params->queryId, $params);
    if (!is_string($source) && !$source instanceof DocumentNode) {
      throw new \LogicException(sprintf('The persisted query loader must return query string or instance of %s but got: %s.', DocumentNode::class, Utils::printSafe($source)));
    }

    return $source;
  }

}
