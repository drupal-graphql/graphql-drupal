<?php
namespace Drupal\graphql_json\Plugin\GraphQL\Types;


use Drupal\graphql_core\GraphQL\TypePluginBase;

/**
 * GraphQL type for json list nodes.
 *
 * @GraphQLType(
 *   id = "json_leaf",
 *   name = "JsonLeaf",
 *   interfaces = {"JsonNode"}
 * )
 */
class JsonLeaf extends TypePluginBase {

  /**
   * {@inheritdoc}
   */
  public function isValidValue($value) {
    return !(is_object($value) || is_array($value));
  }

}