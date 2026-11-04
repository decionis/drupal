<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Gateway;

use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\State\StateInterface;
use Drupal\decionis\Connect\Binding;

/**
 * Keeps custody and rollback credentials out of exported gateway config.
 */
final class Custody implements ConfigFactoryOverrideInterface {

  /**
   * Whether a gateway config read is already resolving custody overrides.
   */
  private bool $reading = FALSE;

  public function __construct(
    private readonly StateInterface $state,
    private readonly Binding $binding,
  ) {}

  /**
   * The immutable site connection and host still own this gateway key.
   */
  public function valid(array $record): bool {
    $connection = $this->binding->record();
    $identity = $this->binding->identity((string) ($connection['org_id'] ?? $record['org_id'] ?? ''), (string) ($connection['api_key'] ?? $record['api_key_id'] ?? ''), (string) \Drupal::config('decionis.settings')->get('api_base_url'));
    return $this->owned($record) && $identity !== NULL && is_string($record['connection_id'] ?? NULL) && hash_equals($record['connection_id'], $identity);
  }

  /**
   * Local key ownership survives a legitimate reconnect on the same site.
   */
  public function owned(array $record): bool {
    if (!is_string($record['host'] ?? NULL) || !hash_equals($record['host'], $this->binding->host()) || $this->binding->copied()) {
      return FALSE;
    }
    $url = $record['site_url'] ?? NULL;
    if (!is_string($url) || $url === '') {
      // Legacy CLI baselines cannot reconstruct an unrecorded original URL.
      return TRUE;
    }
    $bound = $this->binding->record()['binding']['url'] ?? NULL;
    $current = $this->binding->currentUrl();
    try {
      return (!is_string($bound) || hash_equals($url, Binding::normalize($bound))) && ($current === NULL || hash_equals($url, Binding::normalize($current)));
    }
    catch (\InvalidArgumentException) {
      return FALSE;
    }
  }

  /**
   * Reusing a deleted gateway's machine name cannot reuse its payment key.
   */
  public static function matches(array $record, PaymentGatewayInterface $gateway): bool {
    return ($record['gateway'] ?? NULL) === (string) $gateway->id() && (!isset($record['gateway_uuid']) || $record['gateway_uuid'] === $gateway->uuid());
  }

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names): array {
    $record = $this->state->get(Setup::STATE, []);
    if ($this->reading || !is_array($record) || empty($record['gateway'])) {
      return [];
    }
    $name = 'commerce_payment.commerce_payment_gateway.' . $record['gateway'];
    if (!in_array($name, $names, TRUE)) {
      return [];
    }
    $raw = \Drupal::service('config.storage')->read($name);
    if (isset($record['gateway_uuid']) && (!is_array($raw) || ($raw['uuid'] ?? NULL) !== $record['gateway_uuid'])) {
      return [];
    }
    // Pre-R12 setups did not retain enough metadata for file recovery. Keep a
    // running original site's existing config until its host re-onboards it.
    if (!array_key_exists('custody', $record) && empty($record['rollback_key']) && !$this->binding->copied()) {
      return [];
    }
    $configuration = ['secret_key_test' => '', 'secret_key_live' => ''];
    $this->reading = TRUE;
    try {
      if (!$this->owned($record)) {
        // A same-host staging copy must not reach production AgentSafe either.
        return [$name => ['status' => FALSE, 'configuration' => $configuration + ['api_base_url' => 'http://127.0.0.1:1', 'secret_key_held_by_gateway' => FALSE]]];
      }
      $mode = (string) ($record['mode'] ?? '');
      if (!in_array($mode, ['test', 'live'], TRUE)) {
        return [$name => ['status' => FALSE, 'configuration' => $configuration]];
      }
      if (!empty($record['custody'])) {
        if (!$this->valid($record)) {
          return [$name => ['status' => FALSE, 'configuration' => $configuration + ['api_base_url' => 'http://127.0.0.1:1', 'secret_key_held_by_gateway' => FALSE]]];
        }
        return [$name => ['configuration' => $configuration + ['mode' => $mode, 'api_base_url' => 'http://127.0.0.1:' . (int) $record['port'], 'secret_key_held_by_gateway' => TRUE]]];
      }
      $file = (string) ($record['rollback_file'] ?? '');
      if (!self::protectedFile($file)) {
        return [$name => ['status' => FALSE, 'configuration' => $configuration]];
      }
      // Resolve lazily: config overrides are constructed before entity storage.
      $key = \Drupal::service('key.repository')->getKey((string) ($record['rollback_key'] ?? ''));
      $value = $key?->getKeyValue();
      if (!is_string($value) || $value === '') {
        return [$name => ['status' => FALSE, 'configuration' => $configuration]];
      }
      $configuration['secret_key_' . $mode] = $value;
      return [$name => ['configuration' => $configuration + ['mode' => $mode, 'api_base_url' => (string) $record['original_url'], 'secret_key_held_by_gateway' => FALSE]]];
    }
    finally {
      $this->reading = FALSE;
    }
  }

  /**
   * Strips runtime secrets before every gateway save, including config import.
   */
  public function presave(PaymentGatewayInterface $gateway): void {
    $record = $this->state->get(Setup::STATE, []);
    if (!is_array($record) || !self::matches($record, $gateway)) {
      return;
    }
    if (!array_key_exists('custody', $record) && empty($record['rollback_key']) && !$this->binding->copied()) {
      return;
    }
    $configuration = $gateway->getPlugin()->getConfiguration();
    if (!$this->owned($record)) {
      throw new \RuntimeException('This payment key belongs to another site or host. A copied site cannot change its production gateway custody.');
    }
    if (($configuration['mode'] ?? 'test') !== ($record['mode'] ?? NULL)) {
      throw new \RuntimeException('The Fincra key is bound to its test or live environment. Configure a separate Fincra gateway for a different environment.');
    }
    $configuration['secret_key_test'] = '';
    $configuration['secret_key_live'] = '';
    $gateway->setPluginConfiguration($configuration);
  }

  /**
   * Only local, private regular files may supply a returned payment key.
   */
  public static function protectedFile(string $path): bool {
    clearstatcache(TRUE, $path);
    $mode = @fileperms($path);
    return str_starts_with($path, '/') && !is_link($path) && is_file($path) && is_readable($path) && $mode !== FALSE && ($mode & 0077) === 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix(): string {
    return 'decionis_custody';
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name): CacheableMetadata {
    return (new CacheableMetadata())->setCacheMaxAge(0);
  }

}
