<?php

namespace Drupal\graphql\GraphQL\Field;

use Drupal\graphql\GraphQL\CacheableLeafValue;
use Youshido\GraphQL\Execution\ResolveInfo;
use Youshido\GraphQL\Field\AbstractField;

abstract class FieldBase extends AbstractField {
  /**
   * The type that this field resolves to.
   *
   * @var \Youshido\GraphQL\Type\TypeInterface;
   */
  protected $typeCache;

  /**
   * The name of this field.
   *
   * @var string
   */
  protected $nameCache;

  /**
   * {@inheritdoc}
   */
  public function getType() {
    return $this->typeCache ?: ($this->typeCache = $this->getConfigValue('type'));
  }

  /**
   * {@inheritdoc}
   */
  public function getName() {
    return $this->nameCache ?: ($this->nameCache = $this->getConfigValue('name'));
  }

  /**
   * Resolve function for this field.
   * @param $value
   * @param array $args
   * @param \Youshido\GraphQL\Execution\ResolveInfo $info
   * @return \Drupal\graphql\GraphQL\CacheableLeafValue
   */
  public function resolve($value, array $args, ResolveInfo $info) {
    return new CacheableLeafValue($value);
  }
}