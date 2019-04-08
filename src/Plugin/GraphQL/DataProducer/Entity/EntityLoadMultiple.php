<?php

namespace Drupal\graphql\Plugin\GraphQL\DataProducer\Entity;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\graphql\GraphQL\Buffers\EntityBuffer;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;
use GraphQL\Deferred;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * @DataProducer(
 *   id = "entity_load_multiple",
 *   name = @Translation("Load multiple entities"),
 *   description = @Translation("Loads multiple entities."),
 *   produces = @ContextDefinition("entities",
 *     label = @Translation("Entities")
 *   ),
 *   consumes = {
 *     "entity_type" = @ContextDefinition("string",
 *       label = @Translation("Entity type")
 *     ),
 *     "entity_ids" = @ContextDefinition("array",
 *       label = @Translation("Identifier")
 *     ),
 *     "entity_language" = @ContextDefinition("string",
 *       label = @Translation("Entity languages(s)"),
 *       multiple = TRUE,
 *       required = FALSE
 *     ),
 *     "entity_bundle" = @ContextDefinition("string",
 *       label = @Translation("Entity bundle(s)"),
 *       multiple = TRUE,
 *       required = FALSE
 *     )
 *   }
 * )
 */
class EntityLoadMultiple extends DataProducerPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

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
      $container->get('entity.repository'),
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
   * @param \Drupal\Core\Entity\EntityTypeManager $entityTypeManager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository service.
   * @param \Drupal\graphql\GraphQL\Buffers\EntityBuffer $entityBuffer
   *   The entity buffer service.
   *
   * @codeCoverageIgnore
   */
  public function __construct(
    $configuration,
    $pluginId,
    $pluginDefinition,
    EntityTypeManager $entityTypeManager,
    EntityRepositoryInterface $entityRepository,
    EntityBuffer $entityBuffer
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->entityTypeManager = $entityTypeManager;
    $this->entityRepository = $entityRepository;
    $this->entityBuffer = $entityBuffer;
  }

  /**
   * @param string $type
   * @param int[] $ids
   * @param string $language
   * @param string[] $bundles
   * @param \Drupal\Core\Cache\RefinableCacheableDependencyInterface $metadata
   *
   * @return \GraphQL\Deferred
   */
  public function resolve(string $type, array $ids, string $language = NULL, array $bundles = NULL, RefinableCacheableDependencyInterface $metadata) {
    $resolver = $this->entityBuffer->add($type, $ids);

    return new Deferred(function () use ($type, $ids, $language, $bundles, $resolver, $metadata) {
      if (!$entities = $resolver()) {
        // If there is no entity with this id, add the list cache tags so that the
        // cache entry is purged whenever a new entity of this type is saved.
        $type = $this->entityTypeManager->getDefinition($type);
        /** @var \Drupal\Core\Entity\EntityTypeInterface $type */
        $tags = $type->getListCacheTags();
        $metadata->addCacheTags($tags);
        return [];
      }

      foreach ($entities as $id => $entity) {
        if (isset($bundles) && !in_array($entities[$id]->bundle(), $bundles)) {
          // If the entity is not among the allowed bundles, don't return it.
          $metadata->addCacheableDependency($entities[$id]);
          unset($entities[$id]);
        }

        if (isset($language) && $language != $entities[$id]->language()
            ->getId() && $entities[$id] instanceof TranslatableInterface) {
          $entities[$id] = $entities[$id]->getTranslation($language);
        }

        $access = $entity->access('view', NULL, TRUE);
        if (!$access->isAllowed()) {
          // Do not return the entity if access is denied.
          $metadata->addCacheableDependency($entities[$id]);
          $metadata->addCacheableDependency($access);
          unset($entities[$id]);
        }

      }

      return $entities;
    });
  }
}
