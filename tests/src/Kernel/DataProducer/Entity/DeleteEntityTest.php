<?php

namespace Drupal\Tests\graphql\Kernel\DataProducer\Entity;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\graphql\Kernel\GraphQLTestBase;
use Drupal\Tests\graphql\Traits\DataProducerExecutionTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Test the DeleteEntity producer.
 *
 * @group graphql
 */
class DeleteEntityTest extends GraphQLTestBase {

  use DataProducerExecutionTrait;
  use UserCreationTrait;

  /**
   * The plugin ID.
   *
   * @var string
   */
  protected $pluginId = 'delete_entity';

  /**
   * Test deleting entities.
   */
  public function testDeleteEntity() {
    $content_type = NodeType::create([
      'type' => 'lorem',
      'name' => 'ipsum',
    ]);
    $content_type->save();

    $node = Node::create([
      'type' => 'lorem',
      'title' => 'foo',
    ]);
    $node->save();

    $result = $this->executeDataProducer($this->pluginId, [
      'entity' => $node,
    ]);
    $this->assertSame("The 'delete any lorem content' permission is required.", $result['errors'][0]);

    $account = $this->createUser(['bypass node access']);
    $this->setCurrentUser($account);

    $result = $this->executeDataProducer($this->pluginId, [
      'entity' => $node,
    ]);
    $this->assertTrue($result['was_successful']);
    $this->assertNull(Node::load($node->id()));
  }

}
