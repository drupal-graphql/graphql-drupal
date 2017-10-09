<?php

namespace Drupal\graphql_image\Plugin\GraphQL\Types;

use Drupal\graphql\Plugin\GraphQL\Types\TypePluginBase;

/**
 * GraphQL Type for Drupal image derivatives.
 *
 * @GraphQLType(
 *   id = "image_derivative",
 *   name = "ImageDerivative",
 *   interfaces = {"ImageResource"}
 * )
 */
class ImageDerivative extends TypePluginBase {

  /**
   * {@inheritdoc}
   */
  public function applies($value) {
    return is_array($value);
  }

}
