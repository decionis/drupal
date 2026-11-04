<?php

declare(strict_types=1);

namespace Drupal\decionis\Http;

use Decionis\Client\Error\TransportException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Rejects a stale connection before sending and before accepting an answer.
 *
 * The client calls this on every retry. No old capture can inherit a newly
 * connected workspace, including when a key provider or response was slow.
 */
final class Bound implements ClientInterface {

  /**
   * Wraps a transport with connection checks and an optional deadline.
   *
   * @param \Psr\Http\Client\ClientInterface|\Closure $client
   *   A client or sender receiving the request and milliseconds left.
   * @param \Closure $guard
   *   Rejects a changed connection before and after every attempt.
   * @param float|null $deadline
   *   Absolute monotonic milliseconds, including setup and guards.
   *
   * @phpstan-param ClientInterface|\Closure(RequestInterface, ?int): ResponseInterface $client
   */
  public function __construct(
    private readonly ClientInterface|\Closure $client,
    private readonly \Closure $guard,
    private readonly ?float $deadline = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function sendRequest(RequestInterface $request): ResponseInterface {
    ($this->guard)();
    $remaining = $this->remaining();
    $response = $this->client instanceof \Closure
      ? ($this->client)($request, $remaining)
      : $this->client->sendRequest($request);
    ($this->guard)();
    $this->remaining();
    return $response;
  }

  /**
   * No extra attempt or answer is accepted after checkout's deadline.
   */
  private function remaining(): ?int {
    if ($this->deadline === NULL) {
      return NULL;
    }
    $remaining = (int) floor($this->deadline - hrtime(TRUE) / 1e6);
    if ($remaining < 1) {
      throw new TransportException('CHECKOUT_BUDGET_EXHAUSTED');
    }
    return $remaining;
  }

}
