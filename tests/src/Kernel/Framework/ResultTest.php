<?php

namespace Drupal\Tests\graphql\Kernel\Framework;

use Drupal\Tests\graphql\Kernel\GraphQLTestBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test the whole query result pipeline.
 *
 * @group graphql
 */
class ResultTest extends GraphQLTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $schema = <<<GQL
      schema {
        query: Query
      }
      type Query {
        root: String
      }
GQL;

    $this->setUpSchema($schema);

    $this->mockResolver('Query', 'root', 'test');

    $this->configureCachePolicy(900);
  }

  /**
   * Test a simple query result.
   *
   * @coversClass \Drupal\graphql\Cache\RequestPolicy\DenyPost
   */
  public function testQuery(): void {
    $result = $this->query('query { root }');

    $this->assertSame(200, $result->getStatusCode());
    $this->assertSame([
      'data' => [
        'root' => 'test',
      ],
    ], json_decode($result->getContent(), TRUE));
    $this->assertTrue($result->isCacheable());
    $this->assertEquals('max-age=900, public', $result->headers->get('Cache-Control'));
  }

  /**
   * Test a simple POST query result.
   *
   * @coversClass \Drupal\graphql\Cache\RequestPolicy\DenyPost
   */
  public function testPostQuery(): void {
    $result = $this->query('query { root }', NULL, [], NULL, FALSE, Request::METHOD_POST);
    $this->assertSame(200, $result->getStatusCode());
    $this->assertSame([
      'data' => [
        'root' => 'test',
      ],
    ], json_decode($result->getContent(), TRUE));
    $this->assertFalse($result->isCacheable());
  }

  /**
   * Test a batched query result.
   */
  public function testBatchedQueries(): void {
    $result = $this->batchedQueries([
      ['query' => 'query { root } '],
      ['query' => 'query { root }'],
    ]);

    $this->assertSame(200, $result->getStatusCode());
    $this->assertSame([
      [
        'data' => [
          'root' => 'test',
        ],
      ],
      [
        'data' => [
          'root' => 'test',
        ],
      ],
    ], json_decode($result->getContent(), TRUE));
    $this->assertFalse($result->isCacheable());
  }

}
