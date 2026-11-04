<?php

declare(strict_types=1);

namespace Drupal\decionis\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\decionis\Connect\Connector;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis\Gate\Deferred;
use Drupal\decionis\Gate\Background;
use Drupal\decionis\Notification\Digest;
use Psr\Log\LoggerInterface;

/**
 * Implements core hooks for Drupal 10 and 11.
 *
 * Drupal 11.1+ uses object-oriented hooks; decionis.module calls the same
 * methods on Drupal 10.
 */
final class CoreHooks {

  /**
   * How often the server's entitlement is read again. */
  public const REFRESH = 3600;

  public function __construct(
    private readonly Settings $settings,
    private readonly Connector $connector,
    private readonly StateInterface $state,
    private readonly LoggerInterface $logger,
    private readonly Deferred $deferred,
    private readonly Digest $digest,
  ) {}

  /**
   * Refreshes entitlement and dispatches deferred work during background cron.
   *
   * The hourly entitlement refresh applies trial ends and grace periods
   * without anyone opening the settings page.
   */
  #[Hook('cron')]
  public function cron(): void {
    if (!Background::allowed()) {
      return;
    }
    $this->deferred->dispatch();
    $this->digest->sendDue();
    $fetched = (int) ($this->settings->entitlement()['fetched_at'] ?? 0);
    $last = (int) $this->state->get('decionis.entitlement_checked', 0);
    if (!$this->settings->connected() || max($fetched, $last) > time() - self::REFRESH) {
      return;
    }
    $this->state->set('decionis.entitlement_checked', time());
    try {
      $this->connector->refresh();
    }
    catch (\Throwable $error) {
      $this->logger->warning('Decionis entitlement refresh failed (@class); the last known state stays.', ['@class' => get_class($error)]);
    }
  }

  /**
   * Qualifies a deferred nudge only when its target has actually been saved.
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    $this->deferred->saved($entity);
  }

  /**
   * Qualifies an updated target inside the same successful save transaction.
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    $this->deferred->saved($entity);
  }

  /**
   * Runs network maintenance only for the actual manual cron submit button.
   */
  #[Hook('form_system_cron_settings_alter')]
  public function cronForm(array &$form, FormStateInterface $formState): void {
    Background::alterCronForm($form, $formState);
  }

  /**
   * Builds site-local mail with no customer information.
   */
  #[Hook('mail')]
  public function mail(string $key, array &$message, array $params): void {
    Digest::mail($key, $message, $params);
  }

}
