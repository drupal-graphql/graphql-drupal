<?php

namespace Drupal\graphql\GraphQL;

use GraphQL\Language\AST\DocumentNode;

/**
 * Interface for schema extensions that need to inspect the host schema.
 *
 * @package Drupal\graphql\GraphQL
 */
interface ParentAwareSchemaExtensionInterface {

  /**
   * Pass the parent schema document to the extension.
   *
   * @param \GraphQL\Language\AST\DocumentNode $document
   *   The parent schema document.
   */
  public function setParentSchemaDocument(DocumentNode $document): void;

}
