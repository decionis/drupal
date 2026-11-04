<?php

declare(strict_types=1);

namespace Drupal\decionis\Report;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\decionis\Gate\Settings;

/**
 * Displays the store's Decionis plan as the server reported it.
 *
 * The settings page and Decisions dashboard show the days remaining in a trial
 * or grace period and the link to subscribe.
 */
final class Plan {

  use StringTranslationTrait;

  public function __construct(private readonly Settings $settings) {}

  /**
   * The plan as one line of markup.
   */
  public function notice(): string {
    $entitlement = $this->settings->entitlement();
    if ($entitlement === []) {
      return (string) $this->t('Decionis has not reported an enforcement entitlement for this workspace, so every action runs in Shadow.');
    }
    $state = (string) ($entitlement['state'] ?? 'unknown');
    $ends = $entitlement['ends_at'] ?? NULL;
    $days = is_string($ends) && strtotime($ends) !== FALSE ? max(0, (int) ceil((strtotime($ends) - time()) / 86400)) : NULL;
    $available = $this->settings->trialAvailable();
    $active = $this->settings->enforcementAllowed();
    $text = match ($available ? 'trial_available' : ($active ? $state : 'expired')) {
      'trial_available' => $this->t('Your free 14-day enforcement trial starts when you choose “Enforce this one”. Actions currently run in Shadow.'),
      'trial' => $this->t('Free enforcement trial: @days days left.', ['@days' => $days ?? '?']),
      'grace' => $this->t('Payment grace period: @days days left before Shadow.', ['@days' => $days ?? '?']),
      'paid' => $this->t('Build plan: enforcement active.'),
      default => $this->t('Enforcement inactive: actions run in Shadow.'),
    };
    $subscribe = $entitlement['subscribe_url'] ?? NULL;
    if (is_string($subscribe) && str_starts_with($subscribe, 'https://')) {
      $text .= ' <a href="' . htmlspecialchars($subscribe) . '">' . $this->t('Subscribe') . '</a>';
    }
    return (string) $text;
  }

}
