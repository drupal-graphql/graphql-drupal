<?php

namespace Drupal\graphql_core\Plugin\GraphQL\Fields\Menu;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Menu\MenuLinkInterface;
use Drupal\Core\Menu\MenuLinkTreeElement;
use Drupal\Core\Menu\MenuLinkTreeInterface;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\graphql\GraphQL\Execution\ResolveContext;
use Drupal\graphql\Plugin\GraphQL\Fields\FieldPluginBase;
use Drupal\system\MenuInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GraphQL\Type\Definition\ResolveInfo;

/**
 * Retrieves a menus links.
 *
 * @GraphQLField(
 *   id = "menu_links",
 *   secure = true,
 *   name = "links",
 *   type = "[MenuLink]",
 *   parents = {"Menu"},
 *   response_cache_contexts = {"languages:language_url"}
 * )
 */
class MenuLinks extends FieldPluginBase implements ContainerFactoryPluginInterface {
  use DependencySerializationTrait;

  /**
   * The menu link tree.
   *
   * @var \Drupal\Core\Menu\MenuLinkTreeInterface
   */
  protected $menuLinkTree;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $pluginId, $pluginDefinition) {
    return new static($configuration, $pluginId, $pluginDefinition, $container->get('menu.link_tree'));
  }

  /**
   * MenuLinks constructor.
   *
   * @param array $configuration
   *   The plugin configuration array.
   * @param string $pluginId
   *   The plugin id.
   * @param mixed $pluginDefinition
   *   The plugin definition.
   * @param \Drupal\Core\Menu\MenuLinkTreeInterface $menuLinkTree
   *   The menu link tree service.
   */
  public function __construct(array $configuration, $pluginId, $pluginDefinition, MenuLinkTreeInterface $menuLinkTree) {
    parent::__construct($configuration, $pluginId, $pluginDefinition);
    $this->menuLinkTree = $menuLinkTree;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveValues($value, array $args, ResolveContext $context, ResolveInfo $info) {
    if ($value instanceof MenuInterface) {
      $tree = $this->menuLinkTree->load($value->id(), new MenuTreeParameters());

      $manipulators = [
        ['callable' => 'menu.default_tree_manipulators:checkAccess'],
        ['callable' => 'menu.default_tree_manipulators:generateIndexAndSort'],
        ['callable' => 'menu.language_tree_manipulator:filterLanguage'],
      ];

      foreach (array_filter($this->menuLinkTree->transform($tree, $manipulators), function (MenuLinkTreeElement $item) {
        return $item->link instanceof MenuLinkInterface && $item->link->isEnabled() && (empty($item->access) || $item->access->isAllowed());
      }) as $branch) {
        yield $branch;
      }
    }
  }

}
