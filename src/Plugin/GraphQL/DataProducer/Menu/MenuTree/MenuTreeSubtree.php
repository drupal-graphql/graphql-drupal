<?php

namespace Drupal\graphql\Plugin\GraphQL\DataProducer\Menu\MenuTree;

use Drupal\Core\Menu\MenuLinkTreeElement;
use Drupal\graphql\Plugin\GraphQL\DataProducer\DataProducerPluginBase;

/**
 * Returns a list of menu links under the menu tree element.
 *
 * @todo Fix input and output context type.
 *
 * @DataProducer(
 *   id = "menu_tree_subtree",
 *   name = @Translation("Menu tree subtree"),
 *   description = @Translation("Returns the subtree of a menu tree element."),
 *   produces = @ContextDefinition("any",
 *     label = @Translation("Menu link tree element"),
 *     multiple = TRUE
 *   ),
 *   consumes = {
 *     "element" = @ContextDefinition("any",
 *       label = @Translation("Menu link tree element")
 *     )
 *   }
 * )
 */
class MenuTreeSubtree extends DataProducerPluginBase {

  /**
   * Resolver.
   *
   * @param \Drupal\Core\Menu\MenuLinkTreeElement $element
   *
   * @return \Drupal\Core\Menu\MenuLinkTreeElement[]
   */
  public function resolve(MenuLinkTreeElement $element) {
    return array_filter($element->subtree, function (MenuLinkTreeElement $item) {
      return $item->link->isEnabled() && $item->access->isAllowed();
    });
  }

}
