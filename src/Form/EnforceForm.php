<?php

declare(strict_types=1);

namespace Drupal\decionis\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\decionis\Connect\Connector;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis\Report\Activity;
use Drupal\decionis\Report\Enforcement;
use Drupal\decionis\Report\Plan;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Enables one action through a permission-checked, CSRF-protected POST.
 */
final class EnforceForm extends ConfirmFormBase {

  /**
   * Action key whose enforcement setting is being confirmed.
   */
  protected string $action = '';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'decionis_enforce_action';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): mixed {
    return $this->t('Enforce @action?', ['@action' => Activity::LABELS[$this->action] ?? $this->action]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('decionis.decisions');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): mixed {
    return $this->t('Enforce this one');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): mixed {
    return $this->t('The connected workspace policy will apply to future actions of this kind. The prediction uses completed decisions in the last 90 days; it is an estimate, not a guarantee. An action needing approval is blocked when no approval path is configured. Other actions keep their current mode.');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $action = ''): array {
    if (!isset(Activity::LABELS[$action])) {
      throw new NotFoundHttpException();
    }
    $this->action = $action;
    $form = parent::buildForm($form, $form_state);
    $activity = \Drupal::service(Activity::class)->window(time() - 90 * 86400);
    $form['impact'] = ['#type' => 'item', '#title' => $this->t('Predicted impact'), '#plain_text' => Enforcement::impact($activity['by_action'][$action] ?? [])];
    if ($action === 'refund_request' && $activity['has_replay']) {
      $form['refund_basis'] = ['#type' => 'item', '#title' => $this->t('Historical refund basis'), '#plain_text' => Activity::REPLAY_CAVEAT];
    }
    $form['plan'] = ['#markup' => '<p>' . \Drupal::service(Plan::class)->notice() . '</p>'];
    if ($action === 'refund_request' && Enforcement::fincraOnly()) {
      $form['fincra'] = ['#markup' => '<p>' . $this->t('Fincra only offers refunds for underpayments and overpayments. Other refund requests require a separate customer payout outside Drupal. These payouts are not automatically reflected as Drupal refunds and cannot be stopped here. Start with orders, prices or promotions.') . '</p>'];
    }
    /** @var \Drupal\decionis\Gate\Settings $settings */
    $settings = \Drupal::service(Settings::class);
    if (!$form_state->has('decionis_connection')) {
      $form_state->set('decionis_connection', $settings->connectionIdentity());
    }
    // Bind this confirmation to its action and connection without persisting
    // form state during a GET. The normal form token still protects the POST.
    $form['connection'] = ['#type' => 'hidden', '#default_value' => \Drupal::csrfToken()->get($this->scope($form_state))];
    if ($settings->trialAvailable()) {
      $form['trial'] = ['#markup' => '<p>' . $this->t('Confirming starts your workspace’s free 14-day enforcement trial and enables this action. Other actions stay in Shadow until you choose them.') . '</p>'];
    }
    $form['actions']['submit']['#disabled'] = !$this->allowed($form_state);
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->allowed($form_state, TRUE)) {
      $form_state->setErrorByName('confirm', $this->t('Enforcement is not available for this action. Check your connection and plan.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Recheck at the write boundary as trial expiry may follow validation.
    if (!$this->allowed($form_state, TRUE)) {
      $this->messenger()->addError($this->t('Enforcement is no longer available. No mode was changed.'));
      return;
    }
    /** @var \Drupal\decionis\Gate\Settings $settings */
    $settings = \Drupal::service(Settings::class);
    $identity = (string) $form_state->get('decionis_connection');
    try {
      if ($settings->trialAvailable() && !\Drupal::service(Connector::class)->startTrial($identity)) {
        throw new \UnexpectedValueException('DECIONIS_TRIAL_UNAVAILABLE');
      }
    }
    catch (\Throwable) {
      $this->messenger()->addError($this->t('Commerce Gate could not activate enforcement. This action remains in Shadow. Check your connection and plan, then try again.'));
      return;
    }
    // A response can arrive after a reconnect, expiry, or coverage change.
    if (!$this->allowed($form_state, TRUE) || !$settings->enforcementAllowed()) {
      $this->messenger()->addError($this->t('Enforcement is no longer available. No mode was changed.'));
      return;
    }
    $this->configFactory()->getEditable('decionis.settings')->set('actions.' . $this->action, 'enforce')->save();
    if (!$settings->selectEnforcement($this->action, TRUE, $identity)) {
      $this->messenger()->addError($this->t('Enforcement is no longer available. This action remains in Shadow.'));
      return;
    }
    $this->messenger()->addStatus($this->t('@action now uses Enforce.', ['@action' => Activity::LABELS[$this->action]]));
    $form_state->setRedirect('decionis.decisions');
  }

  /**
   * The server entitlement, site binding, and actual refund coverage apply.
   *
   * @phpstan-impure
   */
  private function allowed(FormStateInterface $formState, bool $submitted = FALSE): bool {
    /** @var \Drupal\decionis\Gate\Settings $settings */
    $settings = \Drupal::service(Settings::class);
    $token = $formState->getValue('connection');
    return (!$submitted || (is_string($token) && \Drupal::csrfToken()->validate($token, $this->scope($formState)))) && isset(Activity::LABELS[$this->action]) && $this->currentUser()->hasPermission('administer decionis') && $settings->connected() && $formState->get('decionis_connection') === $settings->connectionIdentity() && ($settings->enforcementAllowed() || $settings->trialAvailable()) && !($this->action === 'refund_request' && Enforcement::fincraOnly());
  }

  /**
   * The exact action and local connection confirmed by the administrator.
   */
  private function scope(FormStateInterface $formState): string {
    return $this->getFormId() . ':' . $this->action . ':' . (string) $formState->get('decionis_connection');
  }

}
