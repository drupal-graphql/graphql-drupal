<?php

namespace Drupal\Tests\graphql_core\Kernel\Entity;

use Drupal\node\Entity\Node;
use Drupal\Tests\graphql_core\Kernel\GraphQLContentTestBase;

/**
 * Fetch node revisions.
 *
 * @group graphql_core
 */
class EntityRevisionsTest extends GraphQLContentTestBase {

  /**
   * Regression test for unhandled logic exceptions.
   *
   * Leaking cache metadata.
   */
  public function testNodeContext() {
    $node = Node::create([
      'title' => 'Test',
      'type' => 'test',
    ]);

    $nodeId = $node->save();
    $draft = $this->getNewDraft($node);
    $draft->save();


    $query = <<<GQL
query (\$path: String!) {
  route(path: \$path) {
    ... on EntityCanonicalUrl {
      entity {
        ... on EntityRevisionable {
          entityRevisions {
            count
          }
        }
      }
    }
  }
}
GQL;

    $this->query($query, ['path' => '/node/' . $nodeId]);
  }

}
