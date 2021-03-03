<?php

namespace Drupal\graphql\Plugin\GraphQL\DataProducer\Taxonomy;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\graphql\GraphQL\Buffers\EntityBuffer;
use Drupal\graphql\GraphQL\Execution\FieldContext;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;
use GraphQL\Deferred;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Loads the taxonomy tree.
 *
 * @DataProducer(
 *   id = "taxonomy_load_tree",
 *   name = @Translation("Load multiple taxonomy terms"),
 *   description = @Translation("Loads Taxonomy terms as a tree"),
 *   produces = @ContextDefinition("taxonomy tree",
 *     label = @Translation("Taxonomy tree")
 *   ),
 *   consumes = {
 *     "vid" = @ContextDefinition("string",
 *       label = @Translation("Vocabulary id")
 *     ),
 *     "parent" = @ContextDefinition("integer",
 *       label = @Translation("The term ID under which to generate the tree"),
 *       required = FALSE
 *     ),
 *     "max_depth" = @ContextDefinition("integer",
 *       label = @Translation("Maximum tree depth"),
 *       required = FALSE
 *     ),
 *     "language" = @ContextDefinition("string",
 *       label = @Translation("Language"),
 *       required = FALSE
 *     ),
 *     "access" = @ContextDefinition("boolean",
 *       label = @Translation("Check access"),
 *       required = FALSE,
 *       default_value = TRUE
 *     ),
 *     "access_user" = @ContextDefinition("entity:user",
 *       label = @Translation("User"),
 *       required = FALSE,
 *       default_value = NULL
 *     ),
 *     "access_operation" = @ContextDefinition("string",
 *       label = @Translation("Operation"),
 *       required = FALSE,
 *       default_value = "view"
 *     )
 *   }
 * )
 *   }
 * )
 */
class TaxonomyLoadTree extends DataProducerPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The default max depth to search in taxonomy tree if it is not set.
   */
  const MAX_DEPTH = 10;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity buffer service.
   *
   * @var \Drupal\graphql\GraphQL\Buffers\EntityBuffer
   */
  protected $entityBuffer;

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
      $container->get('entity_type.manager'),
      $container->get('graphql.buffer.entity')
    );
  }

  /**
   * EntityLoad constructor.
   *
   * @param array $configuration
   *   The plugin configuration array.
   * @param string $pluginId
   *   The plugin id.
   * @param array $pluginDefinition
   *   The plugin definition array.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\graphql\GraphQL\Buffers\EntityBuffer $entityBuffer
   *   The entity buffer service.
   *
   * @codeCoverageIgnore
   */
  public function __construct(
    array $configuration,
    string $pluginId,
    array $pluginDefinition,
    EntityTypeManagerInterface $entityTypeManager,
    EntityBuffer $entityBuffer
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->entityTypeManager = $entityTypeManager;
    $this->entityBuffer = $entityBuffer;
  }

  /**
   * Resolves the taxonomy tree for given vocabulary.
   *
   * @param string $vid
   *   The vocanulary ID.
   * @param int $parent
   *   The ID of the parent's term to load the tree for.
   * @param int|null $max_depth
   *   Max depth to search in.
   * @param string|null $language
   *   Optional. Language to be respected for retrieved entities.
   * @param bool $access
   *   Whether check for access or not. Default is true.
   * @param \Drupal\Core\Session\AccountInterface|null $accessUser
   *   User entity to check access for. Default is null.
   * @param string $accessOperation
   *   Operation to check access for. Default is view.
   * @param \Drupal\graphql\GraphQL\Execution\FieldContext $context
   *   The caching context related to the current field.
   *
   * @return \GraphQL\Deferred|null
   *   A promise that will return entities or NULL if there aren't any.
   */
  public function resolve(string $vid, int $parent, ?int $max_depth, ?string $language, bool $access, ?AccountInterface $accessUser, string $accessOperation, FieldContext $context): ?Deferred {
    if (!isset($max_depth)) {
      $max_depth = self::MAX_DEPTH;
    }

    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadTree($vid, $parent, $max_depth);

    $term_ids = array_column($terms, 'tid');
    $resolver = $this->entityBuffer->add('taxonomy_term', $term_ids);

    return new Deferred(function () use ($language, $resolver, $context, $access, $accessUser, $accessOperation) {
      /** @var \Drupal\Core\Entity\EntityInterface[] $entities */
      $entities = $resolver();
      if (!$entities) {
        // If there is no entity with this id, add the list cache tags so that
        // the cache entry is purged whenever a new entity of this type is
        // saved.
        $type = $this->entityTypeManager->getDefinition('taxonomy_term');
        /** @var \Drupal\Core\Entity\EntityTypeInterface $type */
        $tags = $type->getListCacheTags();
        $context->addCacheTags($tags);
        return [];
      }

      foreach ($entities as $id => $entity) {
        $context->addCacheableDependency($entities[$id]);

        if (isset($language) && $language !== $entities[$id]->language()->getId() && $entities[$id] instanceof TranslatableInterface && $entities[$id]->isTranslatable()) {
          if($entities[$id]->hasTranslation($language)){
            $entities[$id] = $entities[$id]->getTranslation($language);
            $entities[$id]->addCacheContexts(["static:language:{$language}"]);
          }
        }

        if ($access) {
          /** @var \Drupal\Core\Access\AccessResultInterface $accessResult */
          $accessResult = $entity->access($accessOperation, $accessUser, TRUE);
          $context->addCacheableDependency($accessResult);
          if (!$accessResult->isAllowed()) {
            unset($entities[$id]);
            continue;
          }
        }
      }

      return $entities;
    });
  }

}
