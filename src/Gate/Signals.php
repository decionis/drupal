<?php

declare(strict_types=1);

namespace Drupal\decionis\Gate;

use Drupal\Core\Site\Settings as SiteSettings;

/**
 * Turns identifiers into stable, site-specific hashes for abuse signals.
 *
 * Signals can count a customer without sending the original identifier.
 */
final class Signals {

  /**
   * A keyed hash of an identifier: stable on this site, useless elsewhere.
   */
  public static function hash(string $kind, string|int $id): string {
    return substr(hash_hmac('sha256', $kind . ':' . $id, SiteSettings::getHashSalt() . ':decionis'), 0, 32);
  }

}
