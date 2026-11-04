<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce;

use Decionis\Client\Canonical\SortedJson;
use Decionis\Client\Error\HttpException;
use Decionis\Client\Http\CurlClient;
use Decionis\Client\Http\Retry;
use Decionis\Client\Http\Transport;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Gate\Settings;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;

/**
 * Publishes bounded local limits and remembers only confirmed policy receipts.
 */
final class Policy {

  public const RECEIPT = 'decionis_commerce.policy';
  public const PENDING = 'decionis_commerce.policy_pending';
  private const PATH = '/v1/drupal/policy/limits';
  private const LOCK = 'decionis_commerce.policy_publish';

  public function __construct(
    private readonly Settings $settings,
    private readonly Credentials $credentials,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly LockBackendInterface $lock,
    private readonly ?ClientInterface $http = NULL,
  ) {}

  /**
   * Returns only the four permitted numeric limits in canonical key order.
   *
   * @param array $limits
   *   Local currency limits, including optional local presentation metadata.
   *
   * @return array
   *   The bounded currency map sent to the service.
   *
   * @phpstan-return array<string, array<string, int|float>>
   */
  public static function normalize(array $limits): array {
    if ($limits === [] || count($limits) > 32) {
      throw new \InvalidArgumentException('DECIONIS_LIMITS_INVALID');
    }
    $normalized = [];
    foreach ($limits as $currency => $values) {
      if (!is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1 || !is_array($values)) {
        throw new \InvalidArgumentException('DECIONIS_LIMITS_INVALID');
      }
      $row = [];
      foreach (['margin_floor' => 100, 'refund_limit' => 1.0E12, 'repeat_limit' => 1000000000, 'stacking_ceiling' => 100] as $field => $maximum) {
        $value = $values[$field] ?? NULL;
        if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || $value < ($field === 'repeat_limit' ? 1 : 0) || $value > $maximum || ($field === 'repeat_limit' && floor((float) $value) !== (float) $value)) {
          throw new \InvalidArgumentException('DECIONIS_LIMITS_INVALID');
        }
        $row[$field] = $field === 'repeat_limit' ? (int) $value : ($value == 0 ? 0 : $value);
      }
      $normalized[$currency] = $row;
    }
    ksort($normalized, SORT_STRING);
    return $normalized;
  }

  /**
   * Computes the same sorted JSON digest as the bounded server contract.
   *
   * @param array $limits
   *   Normalized currency values.
   *
   * @return string
   *   A prefixed SHA-256 digest, without rounding any numeric value.
   */
  public static function digest(array $limits): string {
    return 'sha256:' . hash('sha256', (new SortedJson())->encode(self::normalize($limits)));
  }

  /**
   * Reads the currently bound connection without reading a credential.
   */
  public function identity(): ?string {
    return $this->settings->connectionIdentity();
  }

  /**
   * Returns a published version only when this currency and policy still match.
   *
   * @param string $currency
   *   The displayed currency.
   * @param array $values
   *   The current four limits, with optional local presentation metadata.
   *
   * @return string|null
   *   Confirmed version, or NULL for an unpublished/stale/local-only choice.
   */
  public function version(string $currency, array $values): ?string {
    $receipt = $this->keyValue->get('state')->get(self::RECEIPT);
    if (!is_array($receipt) || ($receipt['stale'] ?? FALSE) === TRUE || !is_string($receipt['policy_version'] ?? NULL) || $this->identity() === NULL || ($receipt['connection_id'] ?? NULL) !== $this->identity() || $receipt['policy_version'] !== $this->settings->get('policy_version')) {
      return NULL;
    }
    try {
      $current = self::normalize([$currency => $values]);
      $published = self::normalize([$currency => $receipt['limits'][$currency] ?? []]);
      return self::digest($current) === self::digest($published) ? $receipt['policy_version'] : NULL;
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * Resolves an uncertain prior request before publishing the saved choices.
   *
   * @param array $limits
   *   Current local choices, saved before this call.
   * @param string|null $expectedIdentity
   *   Connection captured when the administrator opened the form.
   *
   * @return array
   *   A small result for human status copy; no transport detail or credentials.
   *
   * @phpstan-return array{status: string, version?: string}
   */
  public function publish(array $limits, ?string $expectedIdentity): array {
    $identity = $this->identity();
    if ($identity === NULL) {
      return ['status' => 'local'];
    }
    if ($identity !== $expectedIdentity) {
      return ['status' => 'stale'];
    }
    try {
      $normalized = self::normalize($limits);
    }
    catch (\InvalidArgumentException) {
      return ['status' => 'invalid'];
    }
    if (!$this->lock->acquire(self::LOCK, 60.0)) {
      return ['status' => 'busy'];
    }
    try {
      $key = $this->credentials->apiKey();
      if ($identity !== $this->identity() || $key === NULL || !$this->current($normalized)) {
        return ['status' => 'stale'];
      }
      $store = $this->keyValue->get('state');
      $pending = $store->get(self::PENDING);
      $confirmed = FALSE;
      if (is_array($pending) && ($pending['connection_id'] ?? NULL) === $identity) {
        $this->complete($pending, $key);
        $confirmed = TRUE;
      }
      if ($identity !== $this->identity() || !$this->current($normalized)) {
        return ['status' => 'stale'];
      }
      $receipt = $store->get(self::RECEIPT);
      $bound = is_array($receipt) && ($receipt['connection_id'] ?? NULL) === $identity;
      $pin = $this->settings->get('policy_version');
      $same = $bound && ($receipt['limits_digest'] ?? NULL) === self::digest($normalized);
      $matchingPin = $bound && ($pin === $receipt['policy_version'] || $pin === ($receipt['prior_pin'] ?? NULL));
      if ($confirmed && $same && $matchingPin) {
        $this->configFactory->getEditable('decionis.settings')->set('policy_version', $receipt['policy_version'])->save();
        return ['status' => 'published', 'version' => $receipt['policy_version']];
      }
      // A manually reviewed current pin can reconcile another site's update.
      // Core still enforces current-version, template and tightening guards.
      $expectedVersion = $matchingPin ? $receipt['policy_version'] : ($pin === '' ? NULL : $pin);
      $body = ['org_id' => $this->settings->get('org_id'), 'limits_by_currency' => $normalized, 'expected_policy_version' => $expectedVersion];
      $pending = [
        'connection_id' => $identity,
        'url' => rtrim($this->settings->get('connect_url'), '/') . self::PATH,
        'body' => $body,
        'prior_pin' => $pin,
        'idempotency_key' => 'drupal-limits-' . hash('sha256', $identity . (new SortedJson())->encode($body)),
      ];
      if ($same && $matchingPin && is_array($receipt['operation'] ?? NULL)) {
        // Reconfirm the same operation: another site may have retired it.
        $pending = $receipt['operation'];
      }
      $store->set(self::PENDING, $pending);
      $receipt = $this->complete($pending, $key);
      if ($identity !== $this->identity() || !$this->current($normalized)) {
        return ['status' => 'stale'];
      }
      $this->configFactory->reset('decionis.settings');
      if ($this->settings->get('policy_version') !== $pin) {
        return ['status' => 'conflict'];
      }
      $this->configFactory->getEditable('decionis.settings')->set('policy_version', $receipt['policy_version'])->save();
      return ['status' => 'published', 'version' => $receipt['policy_version']];
    }
    catch (HttpException $error) {
      if ($error->status === 409) {
        $store = $this->keyValue->get('state');
        $receipt = $store->get(self::RECEIPT);
        if (is_array($receipt) && ($receipt['connection_id'] ?? NULL) === $identity) {
          $receipt['stale'] = TRUE;
          $store->set(self::RECEIPT, $receipt);
        }
      }
      if ($error->status >= 400 && $error->status < 500) {
        $this->keyValue->get('state')->delete(self::PENDING);
      }
      return [
        'status' => match ($error->status) {
        403, 404 => 'unavailable',
        409 => 'conflict',
        429 => 'busy',
        default => 'failed',
        },
      ];
    }
    catch (\Throwable) {
      // An uncertain response keeps its replay identity for the next save.
      return ['status' => 'failed'];
    }
    finally {
      $this->lock->release(self::LOCK);
    }
  }

  /**
   * Confirms a response before retaining its remote policy receipt.
   *
   * @param array $pending
   *   The immutable pending operation; contains no credential.
   * @param string $key
   *   Site credential used only for the outgoing authorization header.
   *
   * @return array
   *   A verified, connection-bound publication receipt.
   */
  private function complete(array $pending, string $key): array {
    $identity = $pending['connection_id'];
    $body = $pending['body'];
    $url = rtrim($this->settings->get('connect_url'), '/') . self::PATH;
    if ($this->identity() !== $identity || ($pending['url'] ?? NULL) !== $url) {
      throw new \UnexpectedValueException('DECIONIS_POLICY_CONNECTION_CHANGED');
    }
    $factory = new HttpFactory();
    $transport = new Transport($this->http ?? new CurlClient($factory, $factory, 5000, 10000), $factory, $factory, 'drupal-decionis/1.0');
    $text = $transport->send('POST', $url, ['Authorization' => 'Bearer ' . $key, 'Idempotency-Key' => $pending['idempotency_key']], json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), Retry::once(10000));
    $result = json_decode($text, TRUE, 32, JSON_THROW_ON_ERROR);
    $version = is_array($result) ? ($result['policy_version'] ?? NULL) : NULL;
    $bundle = is_array($result) ? ($result['bundle_id'] ?? NULL) : NULL;
    $digest = self::digest($body['limits_by_currency']);
    if (!is_array($result) || ($result['org_id'] ?? NULL) !== $body['org_id'] || !is_string($version) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,119}$/D', $version) !== 1 || !is_string($bundle) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $bundle) !== 1 || ($result['limits_digest'] ?? NULL) !== $digest || $identity !== $this->identity()) {
      throw new \UnexpectedValueException('DECIONIS_POLICY_UNEXPECTED');
    }
    $receipt = ['connection_id' => $identity, 'policy_version' => $version, 'bundle_id' => $bundle, 'limits_digest' => $digest, 'limits' => $body['limits_by_currency'], 'prior_pin' => $pending['prior_pin'], 'operation' => $pending];
    $store = $this->keyValue->get('state');
    $store->set(self::RECEIPT, $receipt);
    $store->delete(self::PENDING);
    return $receipt;
  }

  /**
   * Refuses to pin a response for limits superseded by another save or import.
   *
   * @param array $expected
   *   The normalized values this operation is publishing.
   *
   * @phpstan-impure
   */
  private function current(array $expected): bool {
    $this->configFactory->reset('decionis_commerce.settings');
    $current = $this->configFactory->get('decionis_commerce.settings')->get('limits');
    try {
      return is_array($current) && self::digest($current) === self::digest($expected);
    }
    catch (\InvalidArgumentException) {
      return FALSE;
    }
  }

}
