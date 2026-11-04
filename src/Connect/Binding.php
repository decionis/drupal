<?php

declare(strict_types=1);

namespace Drupal\decionis\Connect;

use Drupal\Core\State\StateInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Local connection identity, independent of configuration synchronization.
 *
 * The host fingerprint comes from the operating system and installation path,
 * never from a copied database. HTTP requests also check the public site URL.
 */
class Binding {

  public const STATE = 'decionis.connection';

  public function __construct(
    private readonly StateInterface $state,
    private readonly RequestStack $requestStack,
    private readonly ?KeyValueFactoryInterface $keyValueFactory = NULL,
  ) {}

  /**
   * The non-secret connection record.
   *
   * @phpstan-impure
   */
  public function record(): array {
    // Read through the State storage backend, bypassing its per-request cache.
    // Retries must notice a reconnect performed by another PHP request without
    // invalidating caches or otherwise writing during a local-only preview.
    $record = $this->keyValueFactory !== NULL ? $this->keyValueFactory->get('state')->get(self::STATE) : $this->state->get(self::STATE);
    return is_array($record) ? $record : [];
  }

  /**
   * Records a successful connection and invalidates earlier queued work.
   */
  public function bind(string $url): void {
    $record = $this->record();
    $record['binding'] = ['url' => self::normalize($url), 'host' => $this->host()];
    $record['generation'] = bin2hex(random_bytes(16));
    $this->state->set(self::STATE, $record);
  }

  /**
   * Baselines a legacy installation whose original host was never recorded.
   */
  public function baseline(): void {
    $record = $this->record();
    if (isset($record['binding'])) {
      return;
    }
    $url = $this->currentUrl();
    $record['binding'] = ['url' => $url === NULL ? NULL : self::normalize($url), 'host' => $this->host()];
    $record['generation'] = bin2hex(random_bytes(16));
    $this->state->set(self::STATE, $record);
  }

  /**
   * TRUE for a copied or malformed bound connection.
   */
  public function copied(?array $record = NULL): bool {
    $record ??= $this->record();
    if (!isset($record['binding'])) {
      // Existing sites get a baseline in the explicit update hook. A historic
      // database cannot tell us which host originally received its key.
      return FALSE;
    }
    $binding = $record['binding'];
    if (!is_array($binding) || !is_string($binding['host'] ?? NULL) || !array_key_exists('url', $binding) || (!is_string($binding['url']) && $binding['url'] !== NULL) || !hash_equals($binding['host'], $this->host())) {
      return TRUE;
    }
    $url = $this->currentUrl();
    if ($url === NULL) {
      return FALSE;
    }
    try {
      $url = self::normalize($url);
    }
    catch (\InvalidArgumentException) {
      return TRUE;
    }
    if ($binding['url'] === NULL) {
      // A CLI update knows the host but may not know the public URI. Its first
      // HTTP request supplies that baseline, only on the already bound host.
      $record['binding']['url'] = $url;
      $this->state->set(self::STATE, $record);
      return FALSE;
    }
    return !hash_equals($binding['url'], $url);
  }

  /**
   * A local-only identity for queues and holds; no key provider is read.
   */
  public function identity(string $org, string $keyId, string $authority = ''): ?string {
    $record = $this->record();
    // A single fresh row supplies all binding fields, even across reconnects.
    $org = (string) ($record['org_id'] ?? $org);
    $keyId = (string) ($record['api_key'] ?? $keyId);
    if ($org === '' || $keyId === '' || $this->copied($record)) {
      return NULL;
    }
    // URL mismatches are rejected above. Filling in a legacy CLI baseline's
    // first HTTP URL must not invalidate a gateway installed on that host.
    return hash('sha256', json_encode([$org, $keyId, $record['generation'] ?? 'legacy', $record['binding']['host'] ?? $this->host(), $authority], JSON_THROW_ON_ERROR));
  }

  /**
   * The current request URL. CLI jobs have only a host identity to check.
   */
  public function currentUrl(): ?string {
    if (PHP_SAPI === 'cli') {
      return NULL;
    }
    $request = $this->requestStack->getMainRequest();
    return $request === NULL ? NULL : $request->getSchemeAndHttpHost() . $request->getBasePath();
  }

  /**
   * Operating-system, installation and logical database identity.
   */
  public function host(): string {
    $machine = is_readable('/etc/machine-id') ? trim((string) file_get_contents('/etc/machine-id')) : '';
    $site = (string) \Drupal::getContainer()->getParameter('site.path');
    $connection = \Drupal::database();
    $options = $connection->getConnectionOptions();
    // These come from this host's settings, outside the copied database. CLI
    // copies can share a code root while selecting another DB or table prefix.
    // Authentication fields never take part in the fingerprint.
    $database = array_intersect_key($options, array_flip(['host', 'port', 'unix_socket', 'database', 'prefix']));
    // CLI and HTTP can express this same driver as a class name or short ID.
    // Use the resolved connection's canonical name in both request contexts.
    $database['driver'] = $connection->driver();
    ksort($database);
    return hash('sha256', php_uname('n') . "\0" . $machine . "\0" . (realpath(\Drupal::root()) ?: \Drupal::root()) . "\0" . $site . "\0" . json_encode($database, JSON_THROW_ON_ERROR));
  }

  /**
   * Normalizes the site origin and base path, rejecting credential URLs.
   */
  public static function normalize(string $url): string {
    $parts = parse_url($url);
    if (preg_match('/[\x00-\x20\x7f]/', $url) === 1 || filter_var($url, FILTER_VALIDATE_URL) === FALSE || !is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], TRUE) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
      throw new \InvalidArgumentException('DECIONIS_SITE_URL_INVALID');
    }
    $scheme = $parts['scheme'];
    $port = $parts['port'] ?? NULL;
    $suffix = $port === NULL || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? '' : ':' . $port;
    return $scheme . '://' . strtolower($parts['host']) . $suffix . rtrim($parts['path'] ?? '', '/');
  }

}
