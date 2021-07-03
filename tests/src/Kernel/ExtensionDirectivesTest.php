<?php

namespace Drupal\Tests\graphql\Kernel;

/**
 * Test the entity_definition data producer and friends.
 *
 * @group graphql
 */
class ExtensionDirectivesTest extends GraphQLTestBase {

  /**
   * {@inheritdoc}
   */
  protected $strictConfigSchema = FALSE;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'graphql_extension_directives_test',
  ];

  /**
   * {@inheritdoc}
   */
  public function setUp(): void {
    parent::setUp();

    $this->createTestServer(
      'composable',
      '/extension-directives-test',
      [
        'schema_configuration' => [
          'composable' => [
            'extensions' => [
              'extension_directives_test' => 'extension_directives_test',
            ],
          ],
        ],
      ]
    );
  }

  /**
   * Tests that retrieving an entity definition works.
   */
  public function testFields(): void {
    $query = <<<GQL
      query {
        cars {
          brand
          model
          width
          height
          depth
        }
      }
GQL;

    $this->assertResults($query, [],
      [
        'cars' =>
          [
            [
              'brand' => 'Brand',
              'model' => 'Model',
              'width' => '1',
              'height' => '1',
              'depth' => '1',
            ],
          ],
      ]
    );
  }

}
