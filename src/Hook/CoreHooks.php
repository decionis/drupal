<?php

declare(strict_types=1);

namespace Drupal\decionis\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\decionis\Connect\Connector;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis\Gate\Deferred;
use Drupal\decionis\Gate\SafeMode;
use Drupal\decionis\Gate\Background;
use Drupal\decionis\Notification\Digest;
use Drupal\decionis\Report\Environment;
use Drupal\decionis\Report\Pending;
use Drupal\decionis\Report\Severity;
use Psr\Log\LoggerInterface;

/**
 * Implements core hooks for Drupal 10 and 11.
 *
 * Drupal 11.1+ uses object-oriented hooks; decionis.module calls the same
 * methods on Drupal 10.
 */
final class CoreHooks {

  use StringTranslationTrait;

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
    private readonly ?SafeMode $safeMode = NULL,
  ) {}

  /**
   * Refreshes entitlement and dispatches deferred work during background cron.
   *
   * The hourly entitlement refresh applies trial ends and grace periods
   * without anyone opening the settings page. The same status read carries
   * Decionis Commerce safe mode, so it runs at least once a minute of cron
   * while the stored posture is older than SafeMode::TTL.
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
    $safeModeDue = $this->safeMode !== NULL && $this->safeMode->due() && (int) $this->state->get('decionis.safe_mode_checked', 0) <= time() - SafeMode::TTL;
    if (!$this->settings->connected() || (max($fetched, $last) > time() - self::REFRESH && !$safeModeDue)) {
      return;
    }
    $this->state->set('decionis.entitlement_checked', time());
    $this->state->set('decionis.safe_mode_checked', time());
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

  /**
   * Status report entries.
   *
   * Drupal 11.2+ calls this hook; decionis_requirements() calls it before.
   *
   * @return array<string, array<string, mixed>>
   *   Requirements keyed by name.
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $requirements = Environment::requirements();
    $connected = $this->settings->connected();
    $requirements['decionis_connection'] = [
      'title' => 'Decionis Commerce connection',
      'value' => $connected ? 'Connected' : 'Not connected: actions are logged as ungoverned',
      'severity' => $connected ? Severity::ok() : Severity::warning(),
    ];
    $pending = (new Pending(\Drupal::database(), \Drupal::time()))->read();
    if ($pending !== NULL && $pending['count'] > 0) {
      $requirements['decionis_shadow_pending'] = [
        'title' => 'Decionis Commerce Shadow checks',
        'value' => $this->formatPlural($pending['count'], '1 overdue Shadow check; oldest queued @age seconds ago.', '@count overdue Shadow checks; oldest queued @age seconds ago.', ['@age' => $pending['oldest_age']]),
        'severity' => Severity::warning(),
        'description' => $this->t('Shadow checks have waited more than an hour. Schedule <code>drush cron</code> for this site to process queued Shadow checks and replays. On PHP-FPM, up to four checks run after each response; the rest need scheduled cron.'),
      ];
    }
    return $requirements;
  }

  /**
   * Requirements checked before database updates run.
   *
   * Drupal 11.2+ calls this hook; decionis_requirements() calls it before.
   *
   * @return array<string, array<string, mixed>>
   *   Requirements keyed by name.
   */
  #[Hook('update_requirements')]
  public function updateRequirements(): array {
    return Environment::requirements();
  }

}
