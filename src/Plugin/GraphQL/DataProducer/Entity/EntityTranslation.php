<?php

namespace Drupal\graphql\Plugin\GraphQL\DataProducer\Entity;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Returns the entity in a given language.
 *
 * @DataProducer(
 *   id = "entity_translation",
 *   name = @Translation("Entity translation"),
 *   description = @Translation("Returns the translated entity."),
 *   produces = @ContextDefinition("entity",
 *     label = @Translation("Translated entity")
 *   ),
 *   consumes = {
 *     "entity" = @ContextDefinition("entity",
 *       label = @Translation("Entity")
 *     ),
 *     "language" = @ContextDefinition("string",
 *       label = @Translation("Language")
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
class EntityTranslation extends DataProducerPluginBase implements ContainerFactoryPluginInterface {
  use DependencySerializationTrait;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * {@inheritdoc}
   *
   * @codeCoverageIgnore
   */
  public static function create(ContainerInterface $container, array $configuration, $pluginId, $pluginDefinition) {
    return new static(
      $configuration,
      $pluginId,
      $pluginDefinition,
      $container->get('entity.repository')
    );
  }

  /**
   * EntityTranslation constructor.
   *
   * @param array $configuration
   *   The plugin configuration array.
   * @param string $pluginId
   *   The plugin id.
   * @param mixed $pluginDefinition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository service.
   *
   * @codeCoverageIgnore
   */
  public function __construct(array $configuration, $pluginId, $pluginDefinition, EntityRepositoryInterface $entityRepository) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->entityRepository = $entityRepository;
  }

  /**
   * Resolver.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   * @param string $language
   * @param bool|null $access
   * @param \Drupal\Core\Session\AccountInterface|null $accessUser
   * @param string|null $accessOperation
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   */
  public function resolve(EntityInterface $entity, $language, ?bool $access, ?AccountInterface $accessUser, ?string $accessOperation) {
    if ($entity instanceof TranslatableInterface && $entity->isTranslatable() && $entity->hasTranslation($language)) {
      $entity = $entity->getTranslation($language);
      $entity->addCacheContexts(["static:language:{$language}"]);
      // Check if the passed user (or current user if none is passed) has access
      // to the entity, if not return NULL.
      if ($access) {
        /** @var \Drupal\Core\Access\AccessResultInterface $accessResult */
        $accessResult = $entity->access($accessOperation, $accessUser, TRUE);
        if (!$accessResult->isAllowed()) {
          return NULL;
        }
      }
      return $entity;
    }

    return NULL;
  }

}
