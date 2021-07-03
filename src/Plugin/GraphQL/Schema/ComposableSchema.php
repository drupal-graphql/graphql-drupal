<?php

namespace Drupal\graphql\Plugin\GraphQL\Schema;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\graphql\GraphQL\DirectiveProviderExtensionInterface;
use Drupal\graphql\GraphQL\ParentAwareSchemaExtensionInterface;
use Drupal\graphql\GraphQL\ResolverRegistry;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * @Schema(
 *   id = "composable",
 *   name = "Composable schema"
 * )
 */
class ComposableSchema extends SdlSchemaPluginBase implements ConfigurableInterface, PluginFormInterface {
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getResolverRegistry() {
    return new ResolverRegistry();
  }

  /**
   * {@inheritdoc}
   */
  protected function getExtensions() {
    $extensions = array_map(function ($id) {
      return $this->extensionManager->createInstance($id);
    }, array_filter($this->getConfiguration()['extensions']));

    $schemaDocument = $this->getSchemaDocument($extensions);
    // Iterate through all extensions and pass them the current schema, so they
    // can act on it.
    foreach ($extensions as $extension) {
      if ($extension instanceof ParentAwareSchemaExtensionInterface) {
        $extension->setParentSchemaDocument($schemaDocument);
      }
    }

    return $extensions;
  }

  /**
   * {@inheritdoc}
   */
  protected function getSchemaDefinition() {
    $extensions = parent::getExtensions();

    // Get all extensions and prepend any defined directives to the schema.
    $schema = [];
    foreach ($extensions as $extension) {
      if ($extension instanceof DirectiveProviderExtensionInterface) {
        $schema[] = $extension->getDirectiveDefinitions();
      }
    }

    // Attempt to load a schema file and return it instead of the hardcoded
    // empty schema.
    $id = $this->getPluginId();
    $definition = $this->getPluginDefinition();
    $module = $this->moduleHandler->getModule($definition['provider']);
    $path = 'graphql/' . $id . '.graphqls';
    $file = $module->getPath() . '/' . $path;

    if (!file_exists($file)) {
      $schema[] = <<<GQL
        type Schema {
          query: Query
        }

        type Query
GQL;
    }
    else {
      $schema[] = file_get_contents($file);
    }

    return implode("\n", $schema);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration): void {
    $this->configuration = NestedArray::mergeDeep($this->defaultConfiguration(), $configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $extensions = $this->extensionManager->getDefinitions();

    $form['extensions'] = [
      '#type' => 'checkboxes',
      '#required' => FALSE,
      '#title' => $this->t('Enabled extensions'),
      '#options' => [],
      '#default_value' => $this->configuration['extensions'] ?? [],
    ];

    foreach ($extensions as $key => $extension) {
      $form['extensions']['#options'][$key] = $extension['name'] ?? $extension['id'];

      if (!empty($extension['description'])) {
        $form['extensions'][$key]['#description'] = $extension['description'];
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $formState): void {
    // @todo Validate dependencies between extensions.
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $formState): void {
    // Nothing to do here.
  }

}
