<?php

namespace Drupal\graphql\Plugin\GraphQL\DataProducer\Field;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\TranslatableInterface;

trait EntityReferenceTrait {

  /**
   * Get referenced entities my checking language and access.
   *
   * @param string $type
   * @param string|null $language
   * @param array|null $bundles
   * @param bool $access
   * @param \Drupal\Core\Session\AccountInterface|NULL $accessUser
   * @param string $accessOperation
   * @param \Closure $resolver
   * @param \Drupal\graphql\GraphQL\Execution\FieldContext $context
   *
   * @return array|null
   */
  protected function getReferencedEntities($type, $language, $bundles, $access, $accessUser, $accessOperation, $resolver, $context) {
    $entities = $resolver() ?: [];

    $entities = $this->getTranslated($entities, $language);
    $entities = $this->filterAccessible($entities, $bundles, $access, $accessUser, $accessOperation, $context);

    if (empty($entities)) {
      $type = $this->entityTypeManager->getDefinition($type);
      /** @var \Drupal\Core\Entity\EntityTypeInterface $type */
      $tags = $type->getListCacheTags();
      $context->addCacheTags($tags);
      return NULL;
    }

    return $entities;
  }

  /**
   * Get the referenced entities in the language of the referencer.
   *
   * @param array $entities
   * @param string $language
   *
   * @return array
   */
  private function getTranslated($entities, $language) {
    if ($language) {
      $entities = array_map(function (EntityInterface $entity) use ($language) {
        if ($language !== $entity->language()->getId() && $entity instanceof TranslatableInterface && $entity->hasTranslation($language)) {
          $entity = $entity->getTranslation($language);
        }

        $entity->addCacheContexts(["static:language:{$language}"]);
        return $entity;
      }, $entities);
    }

    return $entities;
  }

  /**
   * Filter out not accessible entities.
   *
   * @param array $entities
   * @param array|null $bundles
   * @param bool $access
   * @param \Drupal\Core\Session\AccountInterface|NULL $accessUser
   * @param string $accessOperation
   * @param \Drupal\graphql\GraphQL\Execution\FieldContext $context
   *
   * @return array
   */
  private function filterAccessible($entities, $bundles, $access, $accessUser, $accessOperation, $context) {
    $entities = array_filter($entities, function (EntityInterface $entity) use ($bundles, $access, $accessOperation, $accessUser, $context) {
      if (isset($bundles) && !in_array($entity->bundle(), $bundles)) {
        return FALSE;
      }

      // Check if the passed user (or current user if none is passed) has
      // access to the entity, if not return NULL.
      if ($access) {
        /* @var $accessResult \Drupal\Core\Access\AccessResultInterface */
        $accessResult = $entity->access($accessOperation, $accessUser, TRUE);
        $context->addCacheableDependency($accessResult);
        if (!$accessResult->isAllowed()) {
          return FALSE;
        }
      }
      return TRUE;
    });

    return $entities;
  }

}
