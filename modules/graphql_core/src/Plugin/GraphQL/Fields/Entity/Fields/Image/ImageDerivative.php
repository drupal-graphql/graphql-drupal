<?php

namespace Drupal\graphql_core\Plugin\GraphQL\Fields\Entity\Fields\Image;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\file\Entity\File;
use Drupal\graphql\GraphQL\Cache\CacheableValue;
use Drupal\graphql\GraphQL\Execution\ResolveContext;
use Drupal\graphql\Plugin\GraphQL\Fields\FieldPluginBase;
use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GraphQL\Type\Definition\ResolveInfo;
use Drupal\image\Entity\ImageStyle;

/**
 * Retrieve the image field derivative (image style).
 *
 * @GraphQLField(
 *   id = "image_derivative",
 *   secure = true,
 *   name = "derivative",
 *   type = "ImageResource",
 *   arguments = {
 *     "style" = "ImageStyleId!"
 *   },
 *   field_types = {"image"},
 *   provider = "image",
 *   deriver = "Drupal\graphql_core\Plugin\Deriver\Fields\EntityFieldPropertyDeriver"
 * )
 */
class ImageDerivative extends FieldPluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The image factory.
   *
   * @var \Drupal\Core\Image\ImageFactory
   */
  protected $imageFactory;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $pluginId, $pluginDefinition) {
    return new static($configuration, $pluginId, $pluginDefinition, $container->get('image.factory'));
  }

  /**
   * ImageDerivative constructor.
   *
   * @param array $configuration
   *   The plugin configuration array.
   * @param string $pluginId
   *   The plugin id.
   * @param mixed $pluginDefinition
   *   The plugin definition.
   * @param \Drupal\Core\Image\ImageFactory $imageFactory
   *   The image factory service.
   */
  public function __construct(array $configuration, $pluginId, $pluginDefinition, ImageFactory $imageFactory) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->imageFactory = $imageFactory;
  }

  /**
   * {@inheritdoc}
   */
  protected function resolveValues($value, array $args, ResolveContext $context, ResolveInfo $info) {
    if ($value instanceof ImageItem && $value->entity && $value->entity->access('view') && $style = ImageStyle::load($args['style'])) {
      assert($style instanceof ImageStyle);
      $file = $value->entity;
      assert($file instanceof File);

      // Determine the dimensions of the styled image.
      $dimensions = [
        'width' => $value->width,
        'height' => $value->height,
      ];

      if ($style->supportsUri($file->getFileUri())) {
        $style->transformDimensions($dimensions, $file->getFileUri());
        $url = $style->buildUrl($file->getFileUri());
      }
      else {
        $url = $file->toUrl();
      }

      yield new CacheableValue([
        'url' => $url,
        'width' => $dimensions['width'],
        'height' => $dimensions['height'],
      ], [$style]);
    }
  }

}
