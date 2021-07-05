<?php

namespace Drupal\Tests\graphql\Kernel\DataProducer\Entity;

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\graphql\Kernel\GraphQLTestBase;
use Drupal\Tests\graphql\Traits\DataProducerExecutionTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Test the UpdateEntity producer.
 *
 * @group graphql
 */
class UpdateEntityTest extends GraphQLTestBase {

  use DataProducerExecutionTrait;
  use UserCreationTrait;

  /**
   * The plugin ID.
   *
   * @var string
   */
  protected $pluginId = 'update_entity';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $content_type = NodeType::create([
      'type' => 'lorem',
      'name' => 'ipsum',
    ]);
    $content_type->save();
  }

  /**
   * Test updating an entity.
   */
  public function testUpdateEntity() {
    $entity = Node::create([
      'type' => 'lorem',
      'title' => 'foo',
      'uuid' => 'adf834bd-9e70-4c2a-bf8a-3ef2382e1d78',
    ]);
    $entity->save();

    $result = $this->executeDataProducer($this->pluginId, [
      'entity' => $entity,
      'values' => [
        'title' => 'bar',
      ],
      'entity_return_key' => 'foo',
    ]);
    $this->assertSame("The 'edit any lorem content' permission is required.", $result['errors'][0]);

    $this->setCurrentUser($this->createUser(['bypass node access', 'access content']));

    $result = $this->executeDataProducer($this->pluginId, [
      'entity' => $entity,
      'values' => [
        'type' => 'something_wacky',
      ],
      'entity_return_key' => 'foo',
    ]);
    $this->assertSame('type.0.target_id: The referenced entity (node_type: something_wacky) does not exist.', $result['errors'][0]);

    // Reload the article, since the data producer hydrates the passed in entity
    // with values, but does not reset them.
    $entity = Node::load($entity->id());
    $result = $this->executeDataProducer($this->pluginId, [
      'entity' => $entity,
      'values' => [
        'title' => 'bar',
      ],
      'entity_return_key' => 'foo',
    ]);
    $this->assertEquals('bar', $result['foo']->label());

    // Fields which do not pass field-access checks are filtered out.
    $result = $this->executeDataProducer($this->pluginId, [
      'entity' => $entity,
      'values' => [
        'uuid' => '1c41245d-d173-4861-8524-8dd50ef7668d',
      ],
      'entity_return_key' => 'foo',
    ]);
    $this->assertArrayNotHasKey('errors', $result);
    $this->assertEquals('adf834bd-9e70-4c2a-bf8a-3ef2382e1d78', $result['foo']->uuid());
  }

  /**
   * Test updating an entity with an invalid field.
   */
  public function testUpdateEntityInvalidField() {
    $entity = Node::create([
      'type' => 'lorem',
      'title' => 'foo',
      'uuid' => 'adf834bd-9e70-4c2a-bf8a-3ef2382e1d78',
    ]);
    $entity->save();
    $this->setCurrentUser($this->createUser(['bypass node access', 'access content']));

    $this->expectExceptionMessage("Could not update 'not_a_real_field' field, since it does not exist on the given entity.");
    $result = $this->executeDataProducer($this->pluginId, [
      'entity' => $entity,
      'values' => [
        'not_a_real_field' => 'bar',
      ],
      'entity_return_key' => 'foo',
    ]);
    $this->assertSame("The 'edit any lorem content' permission is required.", $result['errors'][0]);
  }

}
