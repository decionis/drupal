<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Gateway;

/**
 * What the account running the setup may do, strongest first.
 */
enum Tier: string {

  // Root or sudo: a systemd service as a dynamic user, the key a systemd
  // credential. The key is out of Drupal's reach.
  case Root = 'root';

  // A separate system user for AgentSafe: a user-level install under it. The
  // key is out of Drupal's reach while PHP runs as another user.
  case Separate = 'separate';

  // Only the account Drupal runs under: a user-level install there. Refund and
  // payout mistakes are still stopped; a fully compromised site is not.
  case Web = 'web';

  /**
   * The protection level the settings page shows.
   */
  public function protection(): string {
    return match ($this) {
      self::Root => 'The Fincra key is held by AgentSafe as a systemd credential, out of Drupal\'s reach.',
      self::Separate => 'The Fincra key is held by AgentSafe under its own system user, out of Drupal\'s reach.',
      self::Web => 'Amber: AgentSafe runs under Drupal\'s own account, so a fully compromised site could read the Fincra key. Ask your host for a separate system user for AgentSafe.',
    };
  }

}
