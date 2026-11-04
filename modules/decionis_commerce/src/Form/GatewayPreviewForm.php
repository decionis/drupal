<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\decionis_commerce\Gateway\Setup;
use Drupal\decionis_commerce\Gateway\Tier;

/**
 * Displays the redacted Drush dry-run plan. It never applies or moves a key.
 */
final class GatewayPreviewForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'decionis_commerce_gateway_preview';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['intro'] = ['#markup' => '<p>' . $this->t('Preview the same steps as drush decionis:gateway-setup --dry-run. Setup remains a host/Drush operation. This form only describes the plan; it does not write files, start services or move payment keys. All secrets are redacted.') . '</p>'];
    $form['tier'] = ['#type' => 'select', '#title' => $this->t('Host installation tier'), '#options' => ['root' => $this->t('Root / sudo system service'), 'separate' => $this->t('Separate AgentSafe user'), 'web' => $this->t('Drupal’s own user (limited isolation)')], '#default_value' => 'separate'];
    $form['gateway'] = ['#type' => 'textfield', '#title' => $this->t('Fincra gateway id'), '#description' => $this->t('Optional if this site has only one Fincra gateway.'), '#maxlength' => 128];
    $form['user'] = ['#type' => 'textfield', '#title' => $this->t('AgentSafe system user'), '#default_value' => 'agentsafe', '#required' => TRUE, '#maxlength' => 32];
    $form['home'] = ['#type' => 'textfield', '#title' => $this->t('AgentSafe user’s home directory'), '#default_value' => '/home/agentsafe', '#required' => TRUE, '#maxlength' => 200];
    $form['port'] = ['#type' => 'number', '#title' => $this->t('Loopback port'), '#default_value' => 18080, '#min' => 1024, '#max' => 65535, '#required' => TRUE];
    $form['actions']['preview'] = ['#type' => 'submit', '#value' => $this->t('Preview dry-run plan')];
    $plan = $form_state->get('plan');
    if (is_string($plan)) {
      $form['plan'] = ['#type' => 'html_tag', '#tag' => 'pre', '#value' => htmlspecialchars($plan, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')];
    }
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D', (string) $form_state->getValue('user')) !== 1) {
      $form_state->setErrorByName('user', $this->t('Enter a valid system user name.'));
    }
    $home = (string) $form_state->getValue('home');
    if (preg_match('#^/[a-zA-Z0-9_./-]+$#D', $home) !== 1 || str_contains($home, '..')) {
      $form_state->setErrorByName('home', $this->t('Enter an absolute home path without spaces or parent-directory segments.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      $plan = \Drupal::service(Setup::class)->plan(Tier::from($form_state->getValue('tier')), [
        'gateway' => trim((string) $form_state->getValue('gateway')) ?: NULL,
        'user' => (string) $form_state->getValue('user'),
        'home' => rtrim((string) $form_state->getValue('home'), '/'),
        'port' => (int) $form_state->getValue('port'),
        'files' => '',
        'mode' => 'shadow',
        'version' => '0.7.0',
      ]);
      $form_state->set('plan', implode("\n\n", array_map(static fn ($step): string => $step->describe(), $plan)));
    }
    catch (\Throwable) {
      $this->messenger()->addError($this->t('The plan is unavailable. Connect this site, configure a patched Fincra gateway with its payment key, and confirm the host supports the selected tier. The Drush dry-run gives host-specific diagnostics.'));
    }
    $form_state->setRebuild();
  }

}
