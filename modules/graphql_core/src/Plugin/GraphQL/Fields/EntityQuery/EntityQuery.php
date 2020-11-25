<?php

namespace Drupal\graphql_core\Plugin\GraphQL\Fields\EntityQuery;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\graphql\GraphQL\Execution\ResolveContext;
use Drupal\graphql\Plugin\GraphQL\Fields\FieldPluginBase;
use GraphQL\Error\Error;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GraphQL\Type\Definition\ResolveInfo;

/**
 * @GraphQLField(
 *   id = "entity_query",
 *   secure = false,
 *   type = "EntityQueryResult",
 *   arguments = {
 *     "filter" = "EntityQueryFilterInput",
 *     "sort" = "[EntityQuerySortInput]",
 *     "offset" = {
 *       "type" = "Int",
 *       "default" = 0
 *     },
 *     "limit" = {
 *       "type" = "Int",
 *       "default" = 10
 *     },
 *     "revisions" = {
 *       "type" = "EntityQueryRevisionMode",
 *       "default" = "default"
 *     }
 *   },
 *   deriver = "Drupal\graphql_core\Plugin\Deriver\Fields\EntityQueryDeriver"
 * )
 *
 * This field is marked as not secure because it does not enforce entity field
 * access over a chain of filters. For example node.uid.pass could be used as
 * filter input which would disclose information about Drupal's password hashes.
 */
class EntityQuery extends FieldPluginBase implements ContainerFactoryPluginInterface {
  use DependencySerializationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Service config.factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $config;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $pluginId, $pluginDefinition) {
    return new static(
      $configuration,
      $pluginId,
      $pluginDefinition,
      $container->get('entity_type.manager'),
      $container->get('config.factory')
    );
  }

  /**
   * EntityQuery constructor.
   *
   * @param array $configuration
   *   The plugin configuration array.
   * @param string $pluginId
   *   The plugin id.
   * @param mixed $pluginDefinition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   Service config.factory.
   */
  public function __construct(array $configuration, $pluginId, $pluginDefinition, EntityTypeManagerInterface $entityTypeManager, ConfigFactoryInterface $config) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->entityTypeManager = $entityTypeManager;
    $this->config = $config;
  }

  /**
   * {@inheritdoc}
   */
  protected function getCacheDependencies(array $result, $value, array $args, ResolveContext $context, ResolveInfo $info) {
    $entityType = $this->getEntityType($value, $args, $context, $info);
    $type = $this->entityTypeManager->getDefinition($entityType);

    $metadata = new CacheableMetadata();
    $metadata->addCacheTags($type->getListCacheTags());
    $metadata->addCacheContexts($type->getListCacheContexts());

    return [$metadata];
  }

  /**
   * {@inheritdoc}
   */
  public function resolveValues($value, array $args, ResolveContext $context, ResolveInfo $info) {
    yield $this->getQuery($value, $args, $context, $info);
  }

  /**
   * Retrieve the target entity type of this plugin.
   *
   * @param mixed $value
   *   The parent value.
   * @param array $args
   *   The field arguments array.
   * @param \Drupal\graphql\GraphQL\Execution\ResolveContext $context
   *   The resolve context.
   * @param \GraphQL\Type\Definition\ResolveInfo $info
   *   The resolve info object.
   *
   * @return null|string
   *   The entity type object or NULL if none could be derived.
   */
  protected function getEntityType($value, array $args, ResolveContext $context, ResolveInfo $info) {
    $definition = $this->getPluginDefinition();
    if (isset($definition['entity_type'])) {
      return $definition['entity_type'];
    }

    if ($value instanceof EntityInterface) {
      return $value->getEntityType()->id();
    }

    return NULL;
  }

  /**
   * Create the full entity query for the plugin's entity type.
   *
   * @param mixed $value
   *   The parent entity type.
   * @param array $args
   *   The field arguments array.
   * @param \Drupal\graphql\GraphQL\Execution\ResolveContext $context
   *   The resolve context.
   * @param \GraphQL\Type\Definition\ResolveInfo $info
   *   The resolve info object.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface|null
   *   The entity query object.
   */
  protected function getQuery($value, array $args, ResolveContext $context, ResolveInfo $info) {
    if (!$query = $this->getBaseQuery($value, $args, $context, $info)) {
      return NULL;
    }

    $graphql_settings = $this->config->get('graphql.settings');
    if ($graphql_settings) {
      $limit_max = $graphql_settings->get('entity_query_limit_max');
      if ($limit_max && $args['limit'] > $limit_max) {
        throw new \InvalidArgumentException("EntityQuery with too big limit parameter, max is {$limit_max}, requested was {$args['limit']}.");
      }
    }

    $query->range($args['offset'], $args['limit']);

    if (array_key_exists('revisions', $args)) {
      $query = $this->applyRevisionsMode($query, $args['revisions']);
    }

    if (array_key_exists('filter', $args)) {
      $query = $this->applyFilter($query, $args['filter']);
    }

    if (array_key_exists('sort', $args)) {
      $query = $this->applySort($query, $args['sort']);
    }

    return $query;
  }

  /**
   * Create the basic entity query for the plugin's entity type.
   *
   * @param mixed $value
   *   The parent entity type.
   * @param array $args
   *   The field arguments array.
   * @param \Drupal\graphql\GraphQL\Execution\ResolveContext $context
   *   The resolve context.
   * @param \GraphQL\Type\Definition\ResolveInfo $info
   *   The resolve info object.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface|null
   *   The entity query object.
   */
  protected function getBaseQuery($value, array $args, ResolveContext $context, ResolveInfo $info) {
    $entityType = $this->getEntityType($value, $args, $context, $info);
    $entityStorage = $this->entityTypeManager->getStorage($entityType);
    $query = $entityStorage->getQuery();
    $query->accessCheck(TRUE);

    // The context object can e.g. transport the parent entity language.
    $query->addMetaData('graphql_context', $this->getQueryContext($value, $args, $context, $info));

    return $query;
  }

  /**
   * Retrieves an arbitrary value to write into the query metadata.
   *
   * @param mixed $value
   *   The parent value.
   * @param array $args
   *   The field arguments array.
   * @param \Drupal\graphql\GraphQL\Execution\ResolveContext $context
   *   The resolve context.
   * @param \GraphQL\Type\Definition\ResolveInfo $info
   *   The resolve info object.
   *
   * @return mixed
   *   The query context.
   */
  protected function getQueryContext($value, array $args, ResolveContext $context, ResolveInfo $info) {
    // Forward the whole set of arguments by default.
    return [
      'parent' => $value,
      'args' => $args,
      'info' => $info,
    ];
  }

  /**
   * Apply the specified revision filtering mode to the query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The entity query object.
   * @param mixed $mode
   *   The revision query mode.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The entity query object.
   */
  protected function applyRevisionsMode(QueryInterface $query, $mode) {
    if ($mode === 'all') {
      // Mark the query as such and sort by the revision id too.
      $query->allRevisions();
      $query->addTag('revisions');
    }
    else if ($mode === 'latest') {
      // Mark the query to only include latest revision and sort by revision id.
      $query->latestRevision();
      $query->addTag('revisions');
    }

    return $query;
  }

  /**
   * Apply the specified sort directives to the query.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The entity query object.
   * @param mixed $sort
   *   The sort definitions from the field arguments.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The entity query object.
   */
  protected function applySort(QueryInterface $query, $sort) {
    if (!empty($sort) && is_array($sort)) {
      foreach ($sort as $item) {
        $direction = !empty($item['direction']) ? $item['direction'] : 'DESC';
        $language = !empty($item['language']) ? $item['language'] : null;
        $query->sort($item['field'], $direction, $language);
      }
    }

    return $query;
  }

  /**
   * Apply the specified filter conditions to the query.
   *
   * Recursively picks up all filters and aggregates them into condition groups
   * according to the nested structure of the filter argument.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The entity query object.
   * @param mixed $filter
   *   The filter definitions from the field arguments.
   *
   * @return \Drupal\Core\Entity\Query\QueryInterface
   *   The entity query object.
   */
  protected function applyFilter(QueryInterface $query, $filter) {
    if (!empty($filter) && is_array($filter)) {
      //Conditions can be disabled. Check we are not adding an empty condition group.
      $filterConditions = $this->buildFilterConditions($query, $filter);
      if (count($filterConditions->conditions())) {
        $query->condition($filterConditions);
      }
    }

    return $query;
  }

  /**
   * Recursively builds the filter condition groups.
   *
   * @param \Drupal\Core\Entity\Query\QueryInterface $query
   *   The entity query object.
   * @param array $filter
   *   The filter definitions from the field arguments.
   *
   * @return \Drupal\Core\Entity\Query\ConditionInterface
   *   The generated condition group according to the given filter definitions.
   *
   * @throws \GraphQL\Error\Error
   *   If the given operator and value for a filter are invalid.
   */
  protected function buildFilterConditions(QueryInterface $query, array $filter) {
    $conjunction = !empty($filter['conjunction']) ? $filter['conjunction'] : 'AND';
    $group = $conjunction === 'AND' ? $query->andConditionGroup() : $query->orConditionGroup();

    // Apply filter conditions.
    $conditions = !empty($filter['conditions']) ? $filter['conditions'] : [];
    foreach ($conditions as $condition) {
      // Check if we need to disable this condition.
      if (isset($condition['enabled']) && empty($condition['enabled'])) {
        continue;
      }

      $field = $condition['field'];
      $value = !empty($condition['value']) ? $condition['value'] : NULL;
      $operator = !empty($condition['operator']) ? $condition['operator'] : NULL;
      $language = !empty($condition['language']) ? $condition['language'] : NULL;

      // We need at least a value or an operator.
      if (empty($operator) && empty($value)) {
        throw new Error(sprintf("Missing value and operator in filter for '%s'.", $field));
      }
      // Unary operators need a single value.
      else if (!empty($operator) && $this->isUnaryOperator($operator)) {
        if (empty($value) || count($value) > 1) {
          throw new Error(sprintf("Unary operators must be associated with a single value (field '%s').", $field));
        }

        // Pick the first item from the values.
        $value = reset($value);
      }
      // Range operators need exactly two values.
      else if (!empty($operator) && $this->isRangeOperator($operator)) {
        if (empty($value) || count($value) !== 2) {
          throw new Error(sprintf("Range operators must require exactly two values (field '%s').", $field));
        }
      }
      // Null operators can't have a value set.
      else if (!empty($operator) && $this->isNullOperator($operator)) {
        if (!empty($value)) {
          throw new Error(sprintf("Null operators must not be associated with a filter value (field '%s').", $field));
        }
      }

      // If no operator is set, however, we default to EQUALS or IN, depending
      // on whether the given value is an array with one or more than one items.
      if (empty($operator)) {
        $value = count($value) === 1 ? reset($value) : $value;
        $operator = is_array($value) ? 'IN' : '=';
      }

      // Add the condition for the current field.
      $group->condition($field, $value, $operator, $language);
    }

    // Apply nested filter group conditions.
    $groups = !empty($filter['groups']) ? $filter['groups'] : [];
    foreach ($groups as $args) {
      // By default, we use AND condition groups.
      // Conditions can be disabled. Check we are not adding an empty condition group.
      $filterConditions = $this->buildFilterConditions($query, $args);
      if (count($filterConditions->conditions())) {
        $group->condition($filterConditions);
      }
    }

    return $group;
  }

  /**
   * Checks if an operator is a unary operator.
   *
   * @param string $operator
   *   The query operator to check against.
   *
   * @return bool
   *   TRUE if the given operator is unary, FALSE otherwise.
   */
  protected function isUnaryOperator($operator) {
    $unary = ["=", "<>", "<", "<=", ">", ">=", "LIKE", "NOT LIKE"];
    return in_array($operator, $unary);
  }

  /**
   * Checks if an operator is a null operator.
   *
   * @param string $operator
   *   The query operator to check against.
   *
   * @return bool
   *   TRUE if the given operator is a null operator, FALSE otherwise.
   */
  protected function isNullOperator($operator) {
    $null = ["IS NULL", "IS NOT NULL"];
    return in_array($operator, $null);
  }

  /**
   * Checks if an operator is a range operator.
   *
   * @param string $operator
   *   The query operator to check against.
   *
   * @return bool
   *   TRUE if the given operator is a range operator, FALSE otherwise.
   */
  protected function isRangeOperator($operator) {
    $null = ["BETWEEN", "NOT BETWEEN"];
    return in_array($operator, $null);
  }

}
