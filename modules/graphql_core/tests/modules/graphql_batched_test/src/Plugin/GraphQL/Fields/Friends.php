<?php

namespace Drupal\graphql_batched_test\Plugin\GraphQL\Fields;

/**
 * A list of friends.
 *
 * @GraphQLField(
 *   id = "friends",
 *   secure = true,
 *   name = "friends",
 *   types = {"User"},
 *   type = "User",
 *   multi = true
 * )
 */
class Friends extends Users {

}
