<?php

namespace Drupal\graphql_extension_directives_test\Plugin\GraphQL\SchemaExtension;

use Drupal\graphql\GraphQL\DirectiveProviderExtensionInterface;
use Drupal\graphql\GraphQL\ParentAwareSchemaExtensionInterface;
use Drupal\graphql\GraphQL\ResolverBuilder;
use Drupal\graphql\GraphQL\ResolverRegistryInterface;
use Drupal\graphql\Plugin\GraphQL\SchemaExtension\SdlSchemaExtensionPluginBase;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;

/**
 * Schema extension plugin.
 *
 * @SchemaExtension(
 *   id = "extension_directives_test",
 *   name = "Extension Directives Test",
 *   description = "Provides resolvers for testing extension directives.",
 *   schema = "composable"
 * )
 */
class ExtensionDirectivesTestSchemaExtension extends SdlSchemaExtensionPluginBase implements ParentAwareSchemaExtensionInterface, DirectiveProviderExtensionInterface {

  /**
   * The parent schema's AST.
   *
   * @var \GraphQL\Language\AST\DocumentNode
   */
  protected $parentAst;

  /**
   * Stores found types with the dimensions directive.
   *
   * @var array
   */
  protected $typesWithDimensions = [];

  /**
   * {@inheritDoc}
   */
  public function setParentSchemaDocument($document): void {
    $this->parentAst = $document;
  }

  /**
   * {@inheritDoc}
   */
  public function getDirectiveDefinitions(): string {
    return <<<GQL
      directive @dimensions(
        includeDepth: Boolean!
      ) on OBJECT
GQL;
  }

  /**
   * {@inheritDoc}
   */
  public function getExtensionDefinition() {
    $schema = [];
    $typesWithDimensions = $this->getTypesWithDimensions();

    foreach ($typesWithDimensions as $typeWithDimensions) {
      $schema[] = "extend type $typeWithDimensions[type_name] {";
      $schema[] = "  width: String!";
      $schema[] = "  height: String!";
      if (isset($typeWithDimensions['args']['includeDepth']) && $typeWithDimensions['args']['includeDepth']) {
        $schema[] = "  depth: String!";
      }
      $schema[] = "}";
    }

    array_unshift($schema, parent::getExtensionDefinition());
    return implode("\n", $schema);
  }

  /**
   * {@inheritDoc}
   */
  public function registerResolvers(ResolverRegistryInterface $registry): void {
    $builder = new ResolverBuilder();
    $registry->addFieldResolver('Query', 'cars', $builder->callback(function () {
      return [(object) ['brand' => 'Brand', 'model' => 'Model']];
    }));
    foreach ($this->getTypesWithDimensions() as $typeWithDimensions) {
      $registry->addFieldResolver($typeWithDimensions['type_name'], 'width', $builder->callback(function () {
        return '1';
      }));
      $registry->addFieldResolver($typeWithDimensions['type_name'], 'height', $builder->callback(function () {
        return '1';
      }));
      if (isset($typeWithDimensions['args']['includeDepth']) && $typeWithDimensions['args']['includeDepth']) {
        $registry->addFieldResolver($typeWithDimensions['type_name'], 'depth', $builder->callback(function () {
          return '1';
        }));
      }
    }
  }

  /**
   * Retrieve all directive calls in the host schema.
   *
   * @throws \Drupal\Component\Plugin\Exception\PluginException
   */
  public function getTypesWithDimensions(): array {
    if (count($this->typesWithDimensions) === 0) {
      // Search for object type definitions ...
      foreach ($this->parentAst->definitions->getIterator() as $definition) {
        // ... that have directives.
        if ($definition instanceof ObjectTypeDefinitionNode) {
          foreach ($definition->directives->getIterator() as $directive) {
            /** @var \GraphQL\Language\AST\DirectiveNode $directive */
            $directiveName = $directive->name->value;
            if ($directiveName != 'dimensions') {
              continue;
            }
            $typeName = $definition->name->value;
            $args = [];
            foreach ($directive->arguments->getIterator() as $arg) {
              /** @var \GraphQL\Language\AST\ArgumentNode $arg */
              $args[$arg->name->value] = $arg->value->value;
            }
            $this->typesWithDimensions[] = [
              'directive_name' => $directiveName,
              'type_name' => $typeName,
              'args' => $args,
            ];
          }
        }
      }
    }
    return $this->typesWithDimensions;
  }

}
