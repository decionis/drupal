<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Sandbox;

use Decionis\Client\Client;
use Decionis\Client\Config;
use Decionis\Client\Decision\Decision;
use Decionis\Client\Decision\Request;
use Decionis\Client\Http\CurlClient;
use Decionis\Client\Http\Retry;
use Decionis\Client\Http\Transport;
use Drupal\decionis\Gate\Authority;
use GuzzleHttp\Psr7\HttpFactory;

/**
 * The hosted Decionis sandbox.
 *
 * The real evaluator on synthetic data. Its shared credential is never
 * committed or bundled; it is read at run time from the environment
 * (DECIONIS_SANDBOX_API_KEY, DECIONIS_SANDBOX_ORG_ID), else from the Key
 * entries decionis_sandbox_api_key and decionis_sandbox_org_id.
 */
final class Hosted implements Authority {

  public const KEY = 'DECIONIS_SANDBOX_API_KEY';
  public const ORG = 'DECIONIS_SANDBOX_ORG_ID';

  /**
   * The Key entries read when the environment has no value.
   */
  public const KEYS = [self::KEY => 'decionis_sandbox_api_key', self::ORG => 'decionis_sandbox_org_id'];

  /**
   * The client configured for the hosted sandbox workspace.
   */
  private readonly Client $client;

  public function __construct(string $apiKey, string $orgId, string $baseUrl) {
    $factory = new HttpFactory();
    $this->client = new Client(new Config($apiKey, $orgId, $baseUrl), new Transport(new CurlClient($factory, $factory, 2000, 10000), $factory, $factory, 'drupal-decionis-sandbox/1.0'));
  }

  /**
   * Loads the hosted sandbox credentials from the environment or Key module.
   *
   * A missing credential returns NULL with the names of the missing settings.
   *
   * @param string $baseUrl
   *   The Decionis API base URL used by the hosted sandbox.
   * @param callable(string): ?string|null $keys
   *   Reads a Key entry's value by id.
   *
   * @return array
   *   The configured authority or NULL, and the missing setting names.
   *
   * @phpstan-return array{?self, list<string>}
   */
  public static function fromEnvironment(string $baseUrl, ?callable $keys = NULL): array {
    $values = [];
    $missing = [];
    foreach (self::KEYS as $variable => $id) {
      $value = getenv($variable);
      if (!is_string($value) || $value === '') {
        $value = $keys === NULL ? NULL : $keys($id);
      }
      if (is_string($value) && $value !== '') {
        $values[$variable] = $value;
      }
      else {
        $missing[] = $variable;
      }
    }
    return [$missing === [] ? new self($values[self::KEY], $values[self::ORG], $baseUrl) : NULL, $missing];
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'hosted sandbox';
  }

  /**
   * {@inheritdoc}
   */
  public function available(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(Request $request, bool $checkout, ?int $budgetMs = NULL): Decision {
    return $this->client->evaluate($request, new Retry(2, 100, 500, 5000));
  }

  /**
   * Returns the client for reading the sandbox Dossiers.
   */
  public function client(): Client {
    return $this->client;
  }

}
