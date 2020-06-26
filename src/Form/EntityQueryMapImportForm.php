<?php

namespace Drupal\graphql\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\graphql\Entity\QueryMap;

/**
 * Form controller for GraphQL query map forms.
 */
class EntityQueryMapImportForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $formStaet) {
    $form = parent::form($form, $formStaet);

    $form['query_map_json'] = [
      '#type' => 'file',
      '#title' => $this->t('Query map'),
      '#description' => $this->t('Upload a query map .json file.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Submit'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $formState) {
    $files = file_save_upload('query_map_json', [
      'file_validate_extensions' => ['json'], // Validate extensions.
    ]);

    /** @var \Drupal\file\FileInterface $file */
    if (empty($files) || !($file = reset($files)) || !($file instanceof FileInterface)) {
      $formState->setError($form['query_map_json'], $this->t('No file was uploaded.'));
    }
    else {
      // Save the file for use in the submit handler.
      $formState->set('file', $file);
      $map = file_get_contents($file->getFileUri());
      $version = sha1($map);

      if (QueryMap::exists($version)) {
        $formState->setError($form['query_map_json'], $this->t('A query map with the same version @version already exists.', ['@version' => $version]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $formState) {
    $file = $formState->get('file');
    $json = file_get_contents($file->getFileUri());

    /** @var \Drupal\graphql\Entity\QueryMapInterface $entity */
    $entity = $this->entity;
    $entity->set('version', sha1($json));
    $entity->set('map', array_flip((array) json_decode($json)));
    $entity->save();

    $this->messenger()->addStatus($this->t('Saved the query map version %id.', [
      '%id' => $entity->id(),
    ]));

    $formState->setRedirect('entity.graphql_query_map.collection');
  }

}
