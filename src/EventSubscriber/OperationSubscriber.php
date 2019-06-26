<?php

namespace Drupal\graphql\EventSubscriber;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\Translator\TranslatorInterface;
use Drupal\graphql\Event\OperationEvent;
use Drupal\graphql\Plugin\LanguageNegotiation\QueryLanguageNegotiation;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\language\LanguageNegotiatorInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class OperationSubscriber implements EventSubscriberInterface {

  use CurrentLanguageResetTrait;

  /**
   * Constructs a OperationSubscriber object.
   *
   * @param \Drupal\language\ConfigurableLanguageManagerInterface $languageManager
   * @param \Drupal\Core\StringTranslation\Translator\TranslatorInterface $translator
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   * @param \Drupal\language\LanguageNegotiatorInterface $languageNegotiator
   */
  public function __construct(ConfigurableLanguageManagerInterface $languageManager, TranslatorInterface $translator, AccountInterface $currentUser, LanguageNegotiatorInterface $languageNegotiator = NULL) {
    $this->languageManager = $languageManager;
    $this->translator = $translator;
    $this->currentUser = $currentUser;
    $this->languageNegotiator = $languageNegotiator;
  }

  /**
   * Handle operation start events.
   *
   * @param \Drupal\graphql\Event\OperationEvent $event
   *   The kernel event object.
   */
  public function onBeforeOperation(OperationEvent $event) {
    if (!empty($this->languageNegotiator)) {
      $method = QueryLanguageNegotiation::METHOD_ID;
      $instance = $this->languageNegotiator->getNegotiationMethodInstance($method);

      if ($instance instanceof QueryLanguageNegotiation) {
        $instance::setContext($event->getParams(), $event->getConfig());
      }
    }

    $this->resetLanguageContext();
  }

  /**
   * Handle operation end events.
   *
   * @param \Drupal\graphql\Event\OperationEvent $event
   *   The kernel event object.
   */
  public function onAfterOperation(OperationEvent $event) {
    if (!empty($this->languageNegotiator)) {
      $method = QueryLanguageNegotiation::METHOD_ID;
      $instance = $this->languageNegotiator->getNegotiationMethodInstance($method);

      if ($instance instanceof QueryLanguageNegotiation) {
        $instance::resetContext();
      }
    }

    $this->resetLanguageContext();
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      OperationEvent::GRAPHQL_OPERATION_BEFORE => 'onBeforeOperation',
      OperationEvent::GRAPHQL_OPERATION_AFTER=> 'onAfterOperation',
    ];
  }

}
