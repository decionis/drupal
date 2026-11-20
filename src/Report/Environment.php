<?php

declare(strict_types=1);

namespace Drupal\decionis\Report;

/**
 * PHP environment checks for install, update and the status report.
 *
 * These run before this module's namespace is registered during installation,
 * so they use no services and only Severity from this module.
 */
final class Environment {

  /**
   * Checks libsodium, the PHP client and libcurl's resolver.
   *
   * @return array<string, array<string, mixed>>
   *   Requirements keyed by name.
   */
  public static function requirements(): array {
    $sodium = function_exists('sodium_crypto_sign_verify_detached');
    $client = class_exists('Decionis\Client\Client');
    $requirements['decionis_dependencies'] = [
      'title' => 'Decionis Commerce',
      'value' => $sodium && $client ? 'PHP client and libsodium available' : 'Missing dependencies',
      'severity' => $sodium && $client ? Severity::ok() : Severity::error(),
      'description' => $sodium && $client ? '' : 'Decionis Commerce needs ext-sodium and the decionis/php-client library (install the module with Composer).',
    ];
    // Millisecond timeouts do not bound a synchronous DNS lookup: without an
    // asynchronous resolver, a slow lookup can hold checkout past its budget.
    $features = function_exists('curl_version') ? (int) (curl_version()['features'] ?? 0) : 0;
    if (defined('CURL_VERSION_ASYNCHDNS') && ($features & CURL_VERSION_ASYNCHDNS) === 0) {
      $requirements['decionis_dns'] = [
        'title' => 'Decionis Commerce checkout budget',
        'value' => 'libcurl resolves host names synchronously',
        'severity' => Severity::warning(),
        'description' => 'A slow DNS lookup is not bounded by the checkout budget. Use a libcurl built with the threaded or c-ares resolver, or a local caching resolver.',
      ];
    }
    return $requirements;
  }

}
