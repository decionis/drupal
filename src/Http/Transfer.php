<?php

declare(strict_types=1);

namespace Drupal\decionis\Http;

use Psr\Http\Message\RequestInterface;

/**
 * One logical request, including its retries, inside the replay pool.
 *
 * Only the scheduler resumes the fiber, so Drupal writes remain serial.
 */
final class Transfer {

  /**
   * The job's fiber, resumed only by the scheduler.
   */
  public readonly \Fiber $fiber;

  /**
   * Absolute monotonic deadline in milliseconds for all attempts and waits.
   */
  public readonly float $deadline;

  /**
   * The current HTTP attempt's cURL handle, or NULL between attempts.
   */
  public ?\CurlHandle $handle = NULL;

  /**
   * The most recent request, retained for transport errors.
   */
  public ?RequestInterface $request = NULL;

  /**
   * Response bytes collected for the current HTTP attempt.
   */
  public string $body = '';

  /**
   * Absolute monotonic milliseconds when a retry wait ends.
   */
  public float $wakeAt = 0;

  /**
   * Whether the job returned or failed with an uncaught exception.
   */
  public bool $finished = FALSE;

  /**
   * The completed job's return value or uncaught exception.
   */
  public mixed $result = NULL;

  public function __construct(public readonly int $index, callable $work, int $totalMs) {
    $this->fiber = new \Fiber($work);
    $this->deadline = hrtime(TRUE) / 1e6 + $totalMs;
  }

}
