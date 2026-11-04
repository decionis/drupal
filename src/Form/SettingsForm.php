<?php

declare(strict_types=1);

namespace Drupal\decionis\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis\Notification\Digest;
use Drupal\decionis\Report\Plan;
use Drupal\decionis\Report\Protection;
use Drupal\decionis_commerce\Form\LimitsForm;
use Drupal\decionis_commerce\Report\Coverage;

/**
 * Shadow or Enforce, store-wide and per action; failure policy; budget.
 */
final class SettingsForm extends ConfigFormBase {

  public const ACTIONS = [
    'refund_request' => 'Refunds',
    'price_change' => 'Price changes',
    'promotion_change' => 'Promotions and coupons',
    'order_acceptance' => 'Order placement (checkout)',
    'inventory_mutation' => 'Stock adjustments',
    'agent_tool' => 'Agent tool calls',
  ];

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'decionis_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['decionis.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('decionis.settings');
    /** @var \Drupal\decionis\Gate\Settings $settings */
    $settings = \Drupal::service(Settings::class);
    if (!$form_state->has('decionis_modes')) {
      $form_state->set('decionis_modes', ['connection' => $settings->connectionIdentity(), 'active' => $settings->enforcementAllowed()]);
    }
    $form['mode_token'] = ['#type' => 'hidden', '#default_value' => \Drupal::csrfToken()->get($this->getFormId() . ':' . (string) $settings->connectionIdentity() . ':' . ($settings->enforcementAllowed() ? 'active' : 'shadow'))];
    $form['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Connection'),
      '#markup' => $settings->connected()
        ? $this->t('Connected to workspace @org.', ['@org' => $settings->get('org_id')])
        : $this->t('Not connected. <a href=":url">Connect to Commerce Gate</a> after exploring your local Store X-ray.', [':url' => Url::fromRoute('decionis.connect')->toString()]),
    ];
    if ($settings->copied()) {
      $form['copy'] = DisconnectForm::copyNotice();
    }
    if ($settings->connected() || $settings->copied()) {
      $form['disconnect'] = ['#type' => 'link', '#title' => $this->t('Disconnect this site'), '#url' => Url::fromRoute('decionis.disconnect')];
    }
    $form['entitlement'] = [
      '#type' => 'item',
      '#title' => $this->t('Enforcement'),
      '#markup' => \Drupal::service(Plan::class)->notice(),
    ];
    $protection = \Drupal::state()->get('decionis.protection');
    if (is_array($protection)) {
      $form['protection'] = [
        '#type' => 'item',
        '#title' => $this->t('Payment API protection (AgentSafe)'),
        '#markup' => '<span class="' . (($protection['tier'] ?? '') === 'web' ? 'color-warning' : 'color-success') . '">' . htmlspecialchars((string) ($protection['protection'] ?? '')) . '</span>',
      ];
    }
    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Store-wide mode'),
      '#options' => ['shadow' => $this->t('Shadow: log what the policy would do, run everything'), 'enforce' => $this->t('Enforce: act on the verdict')],
      '#default_value' => $settings->selectionsRequired() ? 'shadow' : ($config->get('mode') ?: 'shadow'),
      '#disabled' => !$settings->enforcementAllowed(),
      '#description' => $settings->trialAvailable() ? $this->t('Start your free enforcement trial by choosing “Enforce this one” on the Decisions page.') : '',
    ];
    $form['actions_mode'] = ['#type' => 'details', '#title' => $this->t('Per action'), '#open' => TRUE, '#tree' => TRUE];
    foreach (self::ACTIONS as $key => $label) {
      $form['actions_mode'][$key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['container-inline']],
        'mode' => [
          '#type' => 'select',
          '#title' => $label,
          '#options' => ['inherit' => $this->t('Store-wide'), 'shadow' => $this->t('Shadow'), 'enforce' => $this->t('Enforce')],
          '#default_value' => $settings->selectionsRequired() ? $settings->mode($key) : ($config->get('actions.' . $key) ?: 'inherit'),
          '#disabled' => !$settings->enforcementAllowed(),
        ],
        'failure' => [
          '#type' => 'select',
          '#title' => $this->t('If Commerce Gate cannot decide'),
          '#options' => ['closed' => $this->t('Hold the action (fail closed)'), 'open' => $this->t('Run it, marked ungoverned (fail open)')],
          '#default_value' => $config->get('failure.' . $key) ?: 'closed',
          '#disabled' => $key === 'order_acceptance' || $key === 'agent_tool',
          '#description' => $key === 'order_acceptance' ? $this->t('Checkout always fails open, so a shopper is never held.') : ($key === 'agent_tool' ? $this->t('Agent tool calls always fail closed in Enforce.') : ''),
        ],
      ];
    }
    $form['budget_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('Checkout latency budget (ms)'),
      '#min' => 50,
      '#max' => 1000,
      '#default_value' => $config->get('budget_ms') ?: 120,
      '#description' => $this->t('After this, checkout proceeds and the order is marked ungoverned.'),
    ];
    $form['policy_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Pinned policy version'),
      '#default_value' => $config->get('policy_version'),
      '#description' => $this->t('Empty uses your active policy.'),
    ];
    if (\Drupal::hasService(LimitsForm::class)) {
      \Drupal::service(LimitsForm::class)->build($form, $form_state);
      $coverage = \Drupal::service(Coverage::class)->totals();
      $form['cost_coverage'] = ['#type' => 'item', '#title' => $this->t('Cost coverage'), '#plain_text' => Protection::coverageText($coverage)];
      $form['replay_concurrency'] = ['#type' => 'number', '#title' => $this->t('Replay requests at once'), '#default_value' => \Drupal::config('decionis_commerce.settings')->get('replay_concurrency') ?? 4, '#min' => 1, '#max' => 8, '#required' => TRUE, '#description' => $this->t('Limits background replay concurrency. Checkout keeps its own latency budget.')];
      $form['tools'] = ['#type' => 'details', '#title' => $this->t('Local first-run tools'), '#open' => TRUE];
      foreach (['decionis_commerce.xray' => 'Store X-ray', 'decionis.sandbox' => 'Local sandbox', 'decionis.cost_import' => 'Import variation costs', 'decionis.gateway_preview' => 'AgentSafe dry-run preview', 'decionis.checklist' => 'Protection checklist'] as $route => $label) {
        $form['tools'][$route] = ['#type' => 'link', '#title' => $label, '#url' => Url::fromRoute($route), '#prefix' => '<p>', '#suffix' => '</p>'];
      }
    }
    $form['#cache']['max-age'] = 0;
    return parent::buildForm(Digest::form($form), $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if (\Drupal::hasService(LimitsForm::class)) {
      \Drupal::service(LimitsForm::class)->validate($form, $form_state);
    }
    Digest::validate($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('decionis.settings');
    /** @var \Drupal\decionis\Gate\Settings $settings */
    $settings = \Drupal::service(Settings::class);
    $built = $form_state->get('decionis_modes');
    $identity = $settings->connectionIdentity();
    $token = $form_state->getValue('mode_token');
    $canChoose = is_array($built) && ($built['active'] ?? FALSE) === TRUE && $identity !== NULL && ($built['connection'] ?? NULL) === $identity && $settings->enforcementAllowed() && is_string($token) && \Drupal::csrfToken()->validate($token, $this->getFormId() . ':' . $identity . ':active');
    if ($canChoose) {
      $config->set('mode', $form_state->getValue('mode') === 'enforce' ? 'enforce' : 'shadow');
    }
    foreach (array_keys(self::ACTIONS) as $key) {
      $mode = (string) $form_state->getValue(['actions_mode', $key, 'mode']);
      if ($canChoose) {
        $config->set('actions.' . $key, in_array($mode, ['inherit', 'shadow', 'enforce'], TRUE) ? $mode : 'inherit');
      }
      $failure = (string) $form_state->getValue(['actions_mode', $key, 'failure']);
      $fixed = ['order_acceptance' => 'open', 'agent_tool' => 'closed'];
      $config->set('failure.' . $key, $fixed[$key] ?? ($failure === 'open' ? 'open' : 'closed'));
    }
    $config->set('budget_ms', max(50, min(1000, (int) $form_state->getValue('budget_ms'))));
    $config->set('policy_version', mb_substr(trim((string) $form_state->getValue('policy_version')), 0, 120));
    $config->save();
    if ($canChoose) {
      foreach (array_keys(self::ACTIONS) as $key) {
        $settings->selectEnforcement($key, $settings->chosen($key) === 'enforce', $identity);
      }
    }
    if (\Drupal::hasService(LimitsForm::class)) {
      \Drupal::service(LimitsForm::class)->submit($form, $form_state);
      $this->configFactory()->getEditable('decionis_commerce.settings')->set('replay_concurrency', max(1, min(8, (int) $form_state->getValue('replay_concurrency'))))->save();
    }
    Digest::submit($form_state);
    parent::submitForm($form, $form_state);
  }

}
