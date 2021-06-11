<?php

namespace Drupal\graphql\Plugin\GraphQL\DataProducer\Field;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\graphql\GraphQL\Execution\FieldContext;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;
use Drupal\graphql\GraphQL\Buffers\EntityRevisionBuffer;
use GraphQL\Deferred;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Loads the entity reference revisions.
 *
 * @DataProducer(
 *   id = "entity_reference_revisions",
 *   name = @Translation("Entity reference revisions"),
 *   description = @Translation("Loads entities from an entity reference revisions field."),
 *   provider = "entity_reference_revisions",
 *   produces = @ContextDefinition("entity",
 *     label = @Translation("Entity"),
 *     multiple = TRUE
 *   ),
 *   consumes = {
 *     "entity" = @ContextDefinition("entity",
 *       label = @Translation("Parent entity")
 *     ),
 *     "field" = @ContextDefinition("string",
 *       label = @Translation("Field name")
 *     ),
 *     "language" = @ContextDefinition("string",
 *       label = @Translation("Language"),
 *       multiple = TRUE,
 *       required = FALSE
 *     ),
 *     "bundle" = @ContextDefinition("string",
 *       label = @Translation("Entity bundle(s)"),
 *       multiple = TRUE,
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
 */
class EntityReferenceRevisions extends DataProducerPluginBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * The entity revision buffer service.
   *
   * @var \Drupal\graphql\GraphQL\Buffers\EntityRevisionBuffer
   */
  protected $entityRevisionBuffer;

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
      $container->get('graphql.buffer.entity_revision')
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
   * @param \Drupal\graphql\GraphQL\Buffers\EntityRevisionBuffer $entityRevisionBuffer
   *   The entity revision buffer service.
   *
   * @codeCoverageIgnore
   */
  public function __construct(
    array $configuration,
    string $pluginId,
    array $pluginDefinition,
    EntityTypeManager $entityTypeManager,
    EntityRevisionBuffer $entityRevisionBuffer
  ) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->entityTypeManager = $entityTypeManager;
    $this->entityRevisionBuffer = $entityRevisionBuffer;
  }

  /**
   * Resolves entity reference revisions for a given field of a given entity.
   *
   * May optionally respect the entity bundles and language.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $field
   *   The field of a given entity to get entity reference revisions for.
   * @param string|null $language
   *   Optional. Language to be respected for retrieved entities.
   * @param array|null $bundles
   *   Optional. List of bundles to be respected for retrieved entities.
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
  public function resolve(EntityInterface $entity, string $field, ?string $language, ?array $bundles, bool $access, ?AccountInterface $accessUser, string $accessOperation, FieldContext $context): ?Deferred {
    if (!$entity instanceof FieldableEntityInterface || !$entity->hasField($field)) {
      return NULL;
    }

    $definition = $entity->getFieldDefinition($field);
    if ($definition->getType() !== 'entity_reference_revisions') {
      return NULL;
    }

    $definition = $entity->getFieldDefinition($field);
    $type = $definition->getSetting('target_type');
    $values = $entity->get($field);
    if ($values instanceof EntityReferenceFieldItemListInterface) {
      $vids = array_map(function ($value) {
        return $value['target_revision_id'];
      }, $values->getValue());

      $resolver = $this->entityRevisionBuffer->add($type, $vids);
      return new Deferred(function () use ($type, $language, $bundles, $access, $accessUser, $accessOperation, $resolver, $context) {
        $entities = $resolver() ?: [];

        $entities = array_filter($entities, function (EntityInterface $entity) use ($language, $bundles, $access, $accessOperation, $accessUser, $context) {
          if (isset($bundles) && !in_array($entity->bundle(), $bundles)) {
            return FALSE;
          }

          // Get the correct translation.
          if (isset($language) && $language != $entity->language()->getId() && $entity instanceof TranslatableInterface) {
            $entity = $entity->getTranslation($language);
            $entity->addCacheContexts(["static:language:{$language}"]);
          }

          // Check if the passed user (or current user if none is passed) has
          // access to the entity, if not return NULL.
          if ($access) {
            /** @var \Drupal\Core\Access\AccessResultInterface $accessResult */
            $accessResult = $entity->access($accessOperation, $accessUser, TRUE);
            $context->addCacheableDependency($accessResult);
            if (!$accessResult->isAllowed()) {
              return FALSE;
            }
          }

          return TRUE;
        });

        if (empty($entities)) {
          $type = $this->entityTypeManager->getDefinition($type);
          /** @var \Drupal\Core\Entity\EntityTypeInterface $type */
          $tags = $type->getListCacheTags();
          $context->addCacheTags($tags);
          return $entities;
        }

        return $entities;
      });
    }

    return NULL;
  }

}
