<?php

declare(strict_types=1);

namespace Drupal\decionis\Gate;

use Decionis\Client\Client;
use Decionis\Client\Config;
use Decionis\Client\Decision\Decision;
use Decionis\Client\Decision\Request;
use Decionis\Client\Error\TransportException;
use Decionis\Client\Http\CurlClient;
use Decionis\Client\Http\Retry;
use Decionis\Client\Http\Transport;
use Drupal\decionis\Http\Bound;
use Drupal\decionis\Http\Pool;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The store's Commerce Gate workspace, reached with its site-scoped key.
 *
 * Checkout gets one attempt inside the latency budget; admin actions get
 * bounded retries inside five seconds.
 */
final class Remote implements Authority {

  /**
   * Milliseconds of the checkout budget kept for the gate's own work. */
  public const LOCAL_MS = 20;

  /**
   * The shared transport while a concurrent replay batch is running.
   */
  private ?Pool $pool = NULL;

  /**
   * Connection identity pinned for the active replay batch.
   */
  private ?string $replayIdentity = NULL;

  public function __construct(
    private readonly Settings $settings,
    private readonly Credentials $credentials,
    private readonly string $userAgent = 'drupal-decionis/1.0',
  ) {}

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'account';
  }

  /**
   * {@inheritdoc}
   */
  public function available(): bool {
    return $this->settings->connected() && $this->credentials->apiKey() !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(Request $request, bool $checkout, ?int $budgetMs = NULL, ?string $expectedIdentity = NULL): Decision {
    // On checkout the call gets what is left of the budget once the gate has
    // done its own work. Credential reads, encoding and connection guards
    // consume the same deadline; they must not restart the HTTP timeout.
    $budget = $budgetMs ?? $this->settings->budgetMs() - self::LOCAL_MS;
    if ($checkout && $budget < 1) {
      throw new TransportException('CHECKOUT_BUDGET_EXHAUSTED');
    }
    $deadline = $checkout ? hrtime(TRUE) / 1e6 + $budget : NULL;
    $client = $this->client($checkout ? min(100, $budget) : 2000, $checkout ? $budget : 5000, $expectedIdentity ?? $this->replayIdentity, $deadline);
    $answer = $client->evaluate($request, $checkout ? Retry::once($budget) : new Retry(3, 100, 1000, 5000));
    if ($this->pool !== NULL && $this->settings->connectionIdentity() !== $this->replayIdentity) {
      throw new \LogicException('REPLAY_CONNECTION_CHANGED');
    }
    return $answer;
  }

  /**
   * Evaluates replay work concurrently, using the existing client contract.
   *
   * @param list<callable> $jobs
   *   Replay work items evaluated within the shared transport pool.
   * @param int $concurrency
   *   Maximum number of active logical requests, including retries.
   * @param string|null $identity
   *   Expected connection identity; NULL captures the current connection.
   *
   * @return list<mixed>
   *   Each job's return value or uncaught exception, in input order.
   */
  public function concurrent(array $jobs, int $concurrency = 4, ?string $identity = NULL): array {
    if ($this->pool !== NULL) {
      throw new \LogicException('REPLAY_POOL_ALREADY_RUNNING');
    }
    $identity ??= $this->settings->connectionIdentity();
    $this->replayIdentity = $identity;
    $this->pool = new Pool($concurrency, beforeRequest: function () use ($identity): void {
      if ($identity === NULL || $this->settings->connectionIdentity() !== $identity) {
        throw new \LogicException('REPLAY_CONNECTION_CHANGED');
      }
    });
    try {
      return $this->pool->run($jobs);
    }
    finally {
      $this->pool = NULL;
      $this->replayIdentity = NULL;
    }
  }

  /**
   * The client for reads (Dossiers, Shadow reports, verification).
   */
  public function client(int $connectMs = 2000, int $totalMs = 15000, ?string $expectedIdentity = NULL, ?float $deadline = NULL): Client {
    $expectedIdentity ??= $this->settings->connectionIdentity();
    $key = $this->credentials->apiKey();
    if ($key === NULL || !$this->settings->connected()) {
      throw new \LogicException('DECIONIS_NOT_CONNECTED');
    }
    $factory = new HttpFactory();
    $config = new Config($key, $this->settings->get('org_id'), $this->settings->get('api_base_url'), $this->settings->get('jwks_url'), $this->userAgent);
    $guard = function () use ($expectedIdentity): void {
      if ($expectedIdentity === NULL || $this->settings->connectionIdentity() !== $expectedIdentity) {
        throw new \LogicException('DECIONIS_CONNECTION_CHANGED');
      }
    };
    $http = $deadline === NULL ? $this->pool : NULL;
    $http ??= static function (RequestInterface $request, ?int $remaining) use ($factory, $connectMs, $totalMs, $deadline, $guard): ResponseInterface {
      if ($remaining === NULL) {
        return (new CurlClient($factory, $factory, $connectMs, $totalMs))->sendRequest($request);
      }
      // cURL's blocking poll can outlast a short timeout on some builds.
      // The same bounded scheduler used for replay cancels this single
      // checkout attempt at its original deadline, including setup/guards.
      $timeout = min($remaining, $totalMs);
      $pool = new Pool(1, min($connectMs, $timeout), $timeout, beforeRequest: $guard);
      $result = $pool->run([fn () => $pool->sendRequest($request)], $deadline)[0];
      if ($result instanceof \Throwable) {
        throw $result;
      }
      if (!$result instanceof ResponseInterface) {
        throw new \LogicException('CHECKOUT_RESPONSE_INVALID');
      }
      return $result;
    };
    $http = new Bound($http, $guard, $deadline);
    return new Client($config, new Transport($http, $factory, $factory, $this->userAgent, $this->pool === NULL ? NULL : $this->pool->pause(...)));
  }

}
