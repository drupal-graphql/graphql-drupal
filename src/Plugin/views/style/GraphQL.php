<?php

namespace Drupal\graphql\Plugin\views\style;

use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * Provides a style plugin for GraphQL views.
 *
 * @ViewsStyle(
 *   id = "graphql",
 *   title = @Translation("GraphQL Entities"),
 *   help = @Translation("Returns a list of entities."),
 *   display_types = {"graphql"}
 * )
 */
class GraphQL extends StylePluginBase {

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $usesFields = FALSE;

  /**
   * {@inheritdoc}
   */
  protected $usesGrouping = FALSE;

  /**
   * {@inheritdoc}
   */
  public function render() {
    return '';
  }
}
