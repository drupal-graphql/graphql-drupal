<?php

namespace Drupal\graphql\Plugin;

use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Component\Plugin\DerivativeInspectionInterface;

interface FieldPluginInterface extends PluginInspectionInterface, DerivativeInspectionInterface {

  /**
   * @param \Drupal\graphql\Plugin\SchemaBuilder $builder
   * @param \Drupal\graphql\Plugin\FieldPluginManager $manager
   * @param $definition
   * @param $id
   *
   * @return mixed
   */
  public static function createInstance(SchemaBuilder $builder, FieldPluginManager $manager, $definition, $id);

  /**
   * Returns the plugin's type or field definition for the schema.
   *
   * @return array
   *   The type or field definition of the plugin.
   */
  public function getDefinition();

}
