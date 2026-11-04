<?php

declare(strict_types=1);

namespace Drupal\decionis\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\decionis\Connect\Connector;

/**
 * Explicit, CSRF-protected disconnect; copied credentials are cleared locally.
 */
final class DisconnectForm extends ConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'decionis_disconnect';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): mixed {
    return $this->t('Disconnect this site from Commerce Gate?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): mixed {
    return $this->t('This site will run in Shadow. A copied site clears only its local connection and does not revoke the original site’s credentials.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('decionis.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): mixed {
    return $this->t('Disconnect this site');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      \Drupal::service(Connector::class)->disconnect();
      $this->messenger()->addStatus($this->t('This site is disconnected.'));
      $form_state->setRedirect('decionis.settings');
    }
    catch (\Throwable) {
      $this->messenger()->addError($this->t('Commerce Gate is unavailable. The disconnect could not finish.'));
    }
  }

  /**
   * Shared copied-site banner, with explicit reconnect and disconnect links.
   */
  public static function copyNotice(): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['messages', 'messages--warning'], 'role' => 'alert'],
      'message' => ['#markup' => '<p>' . t('This looks like a copy of a connected site. Actions run in Shadow; reconnect or disconnect this copy.') . '</p>'],
      'reconnect' => ['#type' => 'link', '#title' => t('Reconnect this copy'), '#url' => Url::fromRoute('decionis.connect')],
      'disconnect' => ['#type' => 'link', '#title' => t('Disconnect this copy'), '#url' => Url::fromRoute('decionis.disconnect')],
    ];
  }

}
