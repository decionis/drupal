<?php

declare(strict_types=1);

namespace Drupal\decionis\Form;

use Drupal\decionis_commerce\Replay\Batch;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Decionis\Client\Error\DecionisException;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\decionis\Connect\Connector;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Connect to Commerce Gate: one form, one submit, without pasting a key.
 */
final class ConnectForm extends FormBase {

  public function __construct(protected Connector $connector) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get(Connector::class));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'decionis_connect_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $pending = $this->state()->get(Connector::STATE);
    if (is_array($pending) && (int) ($pending['expires'] ?? 0) > time() && !isset($pending['proof'])) {
      $form['code'] = [
        '#type' => 'textfield',
        '#title' => $this->t('6-digit code'),
        '#description' => $this->t('Commerce Gate could not reach this site, so it sent a code to the billing email. It expires in 5 minutes.'),
        '#maxlength' => 6,
        '#size' => 8,
        '#required' => TRUE,
        '#attributes' => ['autocomplete' => 'one-time-code', 'inputmode' => 'numeric'],
      ];
      $form['actions']['confirm'] = ['#type' => 'submit', '#value' => $this->t('Confirm and connect')];
      $form['actions']['restart'] = [
        '#type' => 'submit',
        '#value' => $this->t('Start over'),
        '#submit' => ['::restart'],
        '#limit_validation_errors' => [],
      ];
      return $form;
    }
    $form['intro'] = ['#markup' => '<p>' . $this->t('Connecting creates your Commerce Gate workspace in Shadow Mode: decisions are logged and every action still runs. Choose when to enforce your policy.') . '</p>'];
    $form['business_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Business name'),
      '#default_value' => $this->config('system.site')->get('name'),
      '#required' => TRUE,
      '#maxlength' => 200,
    ];
    $form['billing_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Billing email'),
      '#default_value' => $this->currentUser()->getEmail(),
      '#required' => TRUE,
    ];
    $form['site_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Site URL'),
      '#default_value' => $this->getRequest()->getSchemeAndHttpHost() . $this->getRequest()->getBasePath(),
      '#disabled' => TRUE,
    ];
    $form['terms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I accept the <a href=":terms">Commerce Gate terms of service</a>.', [':terms' => 'https://decionis.com/terms']),
      '#required' => TRUE,
    ];
    $form['actions']['connect'] = ['#type' => 'submit', '#value' => $this->t('Connect to Commerce Gate')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    try {
      if ($form_state->hasValue('code')) {
        $status = $this->connector->confirm(trim((string) $form_state->getValue('code')));
      }
      else {
        $request = $this->getRequest();
        $status = $this->connector->connect(
          $request->getSchemeAndHttpHost() . $request->getBasePath(),
          (string) $this->config('system.site')->get('uuid'),
          trim((string) $form_state->getValue('business_name')),
          trim((string) $form_state->getValue('billing_email')),
        );
      }
    }
    catch (DecionisException | \InvalidArgumentException | \UnexpectedValueException $error) {
      $this->messenger()->addError($form_state->hasValue('code') ? $this->t('Could not confirm that code (@code). Try again, or start over for a new one.', ['@code' => $error->getMessage()]) : $this->failure($error->getMessage()));
      return;
    }
    if ($status === 'code_sent') {
      $this->messenger()->addStatus($this->t('Commerce Gate could not reach this site. Enter the code sent to the billing email.'));
      return;
    }
    $this->messenger()->addStatus($this->t('Connected in Shadow Mode.'));
    // Commerce is an optional submodule; the base module also connects on
    // ordinary Drupal sites. Its initial replay uses the form's Batch API
    // progress page before the normal Decisions redirect.
    if (\Drupal::hasService('Drupal\\decionis_commerce\\Replay\\Runs')) {
      Batch::attach();
    }
    $form_state->setRedirectUrl(Url::fromRoute('decionis.decisions'));
  }

  /**
   * Drops the connect in progress, so the form starts again.
   */
  public function restart(array &$form, FormStateInterface $form_state): void {
    $this->connector->cancel();
  }

  /**
   * What the person connecting can do about a failure.
   */
  private function failure(string $code): TranslatableMarkup {
    $site = $this->getRequest()->getSchemeAndHttpHost() . $this->getRequest()->getBasePath();
    return match ($code) {
      'AUTHORIZATION_FAILED' => $this->t('Commerce Gate reached this site but could not read its one-time challenge at @site/.well-known/decionis-challenge or @site/decionis/challenge. Check that HTTP authentication, a firewall or a web server rule is not blocking those addresses, then try again.', ['@site' => $site]),
      'REQUEST_REJECTED' => $this->t("Commerce Gate could not use @site as this site's address. A public site must be on HTTPS, on the default port. Behind a proxy or load balancer, set reverse_proxy in settings.php so Drupal reports its public address, then try again.", ['@site' => $site]),
      'UPSTREAM_UNAVAILABLE' => $this->t('Commerce Gate is unavailable, or could not reach this site and could not email a code. Try again shortly. If this site is not public, contact Commerce Gate support.'),
      default => $this->t('Could not connect (@code). Try again.', ['@code' => $code]),
    };
  }

  /**
   * Returns the state service.
   */
  private function state(): StateInterface {
    return \Drupal::state();
  }

}
