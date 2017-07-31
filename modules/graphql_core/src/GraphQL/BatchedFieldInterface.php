<?php

namespace Drupal\graphql_core\GraphQL;

/**
 * Interface for fields that can be evaluated in optimized batches.
 */
interface BatchedFieldInterface {

  /**
   * Retrieve the instance of a batched field resolver.
   *
   * @return \Drupal\graphql_core\BatchedFieldResolver
   *   The field resolver instance.
   */
  public function getBatchedFieldResolver();

  /**
   * Returns string identifying the batch.
   *
   * Only fields with the same batch key will be grouped for evaluation.
   *
   * @param mixed $parent
   *   The parent value in the result tree.
   * @param array $arguments
   *   The list of arguments.
   *
   * @return string
   *   The batch key.
   */
  public function getBatchId($parent, array $arguments);

  /**
   * Prepare multiple field values at once.
   *
   * The `$batch` input argument is a list of associative arrays with "value"
   * and "arguments", reflecting the parent value and argument parameters
   * of the distinct field invocations.
   *
   * @param array $batch
   *   The list of items in the batch.
   *
   * @return array
   *   The prepared values for each batch item.
   */
  public function prepareBatch(array $batch);

}
