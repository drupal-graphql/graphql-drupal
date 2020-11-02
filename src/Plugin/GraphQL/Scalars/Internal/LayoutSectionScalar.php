<?php

namespace Drupal\graphql\Plugin\GraphQL\Scalars\Internal;


/**
 * Layout Builder module defines a custom data type that essentially is a
 * string, but not called string.
 *
 * @GraphQLScalar(
 *   id = "layout_section",
 *   name = "layout_section"
 * )
 */
class LayoutSectionScalar extends StringScalar {
}
