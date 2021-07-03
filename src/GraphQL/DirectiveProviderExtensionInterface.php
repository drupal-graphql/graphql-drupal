<?php

namespace Drupal\graphql\GraphQL;

/**
 * Interface for Schema extensions that provide new directives.
 *
 * @package Drupal\graphql\GraphQL
 */
interface DirectiveProviderExtensionInterface {

  /**
   * Retrieve all directive definitions as a string.
   *
   * @return string
   *   Directive definitions in SDL.
   */
  public function getDirectiveDefinitions() : string;

}
