<?php

declare(strict_types=1);

namespace Drupal\decionis\Connect;

use Decionis\Client\Canonical\SortedJson;
use Drupal\decionis\Gate\Credentials;

/**
 * The site's Ed25519 key pair.
 *
 * Its public half is registered at connect, so the workspace is bound to this
 * site key; the secret half stays in the Key module and signs connect requests.
 */
final class SiteKey {

  public function __construct(private readonly Credentials $credentials) {}

  /**
   * Creates and stores a fresh key pair, replacing any previous one.
   */
  public function generate(): void {
    $pair = sodium_crypto_sign_keypair();
    $this->credentials->store('site_key', Credentials::SITE_KEY, 'Decionis site key', base64_encode(sodium_crypto_sign_secretkey($pair)));
    sodium_memzero($pair);
  }

  /**
   * The public key, base64url without padding, or NULL when none exists.
   */
  public function publicKey(): ?string {
    $secret = $this->credentials->siteSecret();
    return $secret === NULL ? NULL : sodium_bin2base64(sodium_crypto_sign_publickey_from_secretkey($secret), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
  }

  /**
   * Signs the canonical JSON of a message, base64url without padding.
   *
   * @param array<string, string> $message
   *   Message fields to canonicalize and bind to this site's public key.
   */
  public function sign(array $message): string {
    $secret = $this->credentials->siteSecret();
    if ($secret === NULL) {
      throw new \LogicException('DECIONIS_SITE_KEY_MISSING');
    }
    return sodium_bin2base64(sodium_crypto_sign_detached((new SortedJson())->encode($message), $secret), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
  }

}
