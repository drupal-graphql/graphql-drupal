<?php

namespace Drupal\graphql\Plugin\GraphQL\DataProducer\Entity;

use Drupal\Core\Access\AccessResultReasonInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;
use Drupal\graphql\Plugin\GraphQL\DataProducer\EntityValidationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates an entity.
 *
 * @DataProducer(
 *   id = "create_entity",
 *   name = @Translation("Create Entity"),
 *   produces = @ContextDefinition("entity",
 *     label = @Translation("Entity")
 *   ),
 *   consumes = {
 *     "entity_type" = @ContextDefinition("string",
 *       label = @Translation("Entity Type"),
 *       required = TRUE
 *     ),
 *     "values" = @ContextDefinition("any",
 *       label = @Translation("Field values for creating the entity"),
 *       required = TRUE
 *     ),
 *     "entity_return_key" = @ContextDefinition("string",
 *       label = @Translation("Key name in the returned array where the entity will be placed"),
 *       required = TRUE
 *     ),
 *     "save" = @ContextDefinition("boolean",
 *       label = @Translation("Save entity"),
 *       required = FALSE,
 *       default_value = TRUE,
 *     ),
 *   }
 * )
 */
class CreateEntity extends DataProducerPluginBase implements ContainerFactoryPluginInterface {

  use EntityValidationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManager
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * Resolve the values for this producer.
   */
  public function resolve(string $entity_type, array $values, string $entity_return_key, ?bool $save, $context) {
    $storage = $this->entityTypeManager->getStorage($entity_type);
    $accessHandler = $this->entityTypeManager->getAccessControlHandler($entity_type);

    // Infer the bundle type from the response and return an error if the entity
    // type expects one, but one is not present.
    $entity_type = $this->entityTypeManager->getDefinition($entity_type);
    $bundle = $entity_type->getKey('bundle') && !empty($values[$entity_type->getKey('bundle')]) ? $values[$entity_type->getKey('bundle')] : NULL;
    if ($entity_type->getKey('bundle') && !$bundle) {
      return [
        'errors' => [$this->t('Entity type being created requried a bundle, but none was present.')],
      ];
    }

    // Ensure the user has access to create this kind of entity.
    $access = $accessHandler->createAccess($bundle, NULL, [], TRUE);
    $context->addCacheableDependency($access);
    if (!$access->isAllowed()) {
      return [
        'errors' => [$access instanceof AccessResultReasonInterface && $access->getReason() ? $access->getReason() : $this->t('Access was forbidden.')],
      ];
    }

    $entity = $storage->create($values);

    // Core does not have a concept of create access for fields, so edit access
    // is used instead. This is consistent with how other Drupal APIs handle
    // field based create access.
    $field_access_errors = [];
    foreach ($values as $field_name => $value) {
      $create_access = $entity->get($field_name)->access('edit', NULL, TRUE);
      if (!$create_access->isALlowed()) {
        $field_access_errors[] = sprintf('%s: %s', $field_name, $create_access instanceof AccessResultReasonInterface ? $create_access->getReason() : $this->t('Access was forbidden.'));
      }
    }
    if (!empty($field_access_errors)) {
      return ['errors' => $field_access_errors];
    }

    if ($violation_messages = $this->getViolationMessages($entity)) {
      return ['errors' => $violation_messages];
    }

    if ($save) {
      $entity->save();
    }
    return [
      $entity_return_key => $entity,
    ];
  }

}
