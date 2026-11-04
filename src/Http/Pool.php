<?php

declare(strict_types=1);

namespace Drupal\decionis\Http;

use Decionis\Client\Http\NetworkError;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Bounded cURL multi transport for the synchronous client's public PSR-18 seam.
 *
 * Fibers yield only at HTTP and retry waits. A slot belongs to the complete
 * logical request, whose deadline includes every attempt and retry delay.
 * Responses are bounded, redirects forbidden, and errors contain no bodies.
 */
final class Pool implements ClientInterface {

  /**
   * The cURL scheduler while a batch is running.
   */
  private ?\CurlMultiHandle $multi = NULL;

  /**
   * Active logical requests indexed by fiber object ID.
   *
   * @var array<int, Transfer>
   */
  private array $active = [];

  /**
   * In-flight HTTP attempts indexed by cURL handle object ID.
   *
   * @var array<int, Transfer>
   */
  private array $handles = [];

  /**
   * Creates a scheduler with bounded concurrency, timeouts, and responses.
   *
   * @param int $concurrency
   *   Maximum number of active logical requests, including retry waits.
   * @param int $connectMs
   *   Maximum milliseconds allowed to establish an HTTP connection.
   * @param int $totalMs
   *   Milliseconds available for each logical request and all its retries.
   * @param int $maxBytes
   *   Maximum response body size in bytes for an HTTP attempt.
   * @param \Closure|null $beforeRequest
   *   Revalidates the connection immediately before each attempt, including
   *   retries. The callback must never log the request or credentials.
   */
  public function __construct(
    private readonly int $concurrency = 4,
    private readonly int $connectMs = 2000,
    private readonly int $totalMs = 5000,
    private readonly int $maxBytes = 2097152,
    private readonly ?\Closure $beforeRequest = NULL,
  ) {
    if ($concurrency < 1 || $concurrency > 8 || $connectMs < 1 || $connectMs > $totalMs || $totalMs > 15000 || $maxBytes < 1) {
      throw new \InvalidArgumentException('REPLAY_POOL_LIMITS_INVALID');
    }
  }

  /**
   * Runs jobs in input order, returning their values or uncaught exceptions.
   *
   * @param list<callable> $jobs
   *   Work items whose HTTP calls and retry waits share the scheduler.
   * @param float|null $deadline
   *   An optional absolute monotonic deadline, including caller setup.
   *
   * @return list<mixed>
   *   Each job's return value or uncaught exception, in input order.
   */
  public function run(array $jobs, ?float $deadline = NULL): array {
    if ($this->multi !== NULL) {
      throw new \LogicException('REPLAY_POOL_ALREADY_RUNNING');
    }
    $this->multi = curl_multi_init();
    $next = 0;
    $results = [];
    try {
      while ($next < count($jobs) || $this->active !== []) {
        while ($next < count($jobs) && count($this->active) < $this->concurrency) {
          $remaining = $deadline === NULL ? $this->totalMs : min($this->totalMs, max(0, (int) floor($deadline - hrtime(TRUE) / 1e6)));
          $task = new Transfer($next, $jobs[$next], $remaining);
          $this->active[spl_object_id($task->fiber)] = $task;
          $next++;
          $this->advance($task);
        }
        $this->expire();
        do {
          $status = curl_multi_exec($this->multi, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        if ($status !== CURLM_OK) {
          throw new \RuntimeException('REPLAY_POOL_UNAVAILABLE');
        }
        while (($info = curl_multi_info_read($this->multi)) !== FALSE) {
          $handle = $info['handle'];
          $task = $this->handles[spl_object_id($handle)];
          $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
          $this->remove($task);
          $expired = hrtime(TRUE) / 1e6 >= $task->deadline;
          if ($expired || $info['result'] !== CURLE_OK || $status === 0) {
            $this->advance($task, error: new NetworkError($task->request, $expired ? CURLE_OPERATION_TIMEDOUT : (int) $info['result']));
          }
          else {
            $factory = new HttpFactory();
            $response = $factory->createResponse($status)->withBody($factory->createStream($task->body));
            $this->advance($task, $response);
          }
        }
        $this->expire();
        $now = hrtime(TRUE) / 1e6;
        foreach ($this->active as $id => $task) {
          if (!$task->finished && $task->handle === NULL && $task->wakeAt <= $now) {
            $this->advance($task);
          }
          if ($task->finished) {
            $results[$task->index] = $task->result;
            unset($this->active[$id]);
          }
        }
        if ($this->active !== []) {
          // Wait at most 10 ms: a retry in another slot may become due.
          $nextDeadline = min(array_map(static fn (Transfer $task): float => $task->deadline, $this->active));
          $wait = min(0.01, max(0, ($nextDeadline - hrtime(TRUE) / 1e6) / 1000));
          if ($this->handles === [] || curl_multi_select($this->multi, $wait) === -1) {
            usleep(1000);
          }
        }
      }
    }
    finally {
      foreach ($this->active as $task) {
        $this->remove($task);
      }
      curl_multi_close($this->multi);
      $this->multi = NULL;
      $this->active = $this->handles = [];
    }
    ksort($results);
    return array_values($results);
  }

  /**
   * Cancels overdue sockets before starting or polling further network work.
   */
  private function expire(): void {
    $now = hrtime(TRUE) / 1e6;
    foreach ($this->active as $task) {
      if (!$task->finished && $task->handle !== NULL && $task->deadline <= $now) {
        // Do not depend on the installed libcurl noticing its own timer
        // promptly. Remove the socket before resuming the failed job.
        $this->remove($task);
        $this->advance($task, error: new NetworkError($task->request, CURLE_OPERATION_TIMEDOUT));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function sendRequest(RequestInterface $request): ResponseInterface {
    $this->current();
    return \Fiber::suspend($request);
  }

  /**
   * The client's retry sleeper: other requests keep progressing meanwhile.
   */
  public function pause(int $milliseconds): void {
    $this->current();
    \Fiber::suspend(max(0, $milliseconds));
  }

  /**
   * Refuses accidental use outside a scheduled request.
   */
  private function current(): void {
    $fiber = \Fiber::getCurrent();
    if ($fiber === NULL || !isset($this->active[spl_object_id($fiber)])) {
      throw new \LogicException('REPLAY_POOL_NOT_RUNNING');
    }
  }

  /**
   * Advances one job until its next request, retry delay, or return value.
   */
  private function advance(Transfer $task, mixed $value = NULL, ?\Throwable $error = NULL): void {
    try {
      $yielded = !$task->fiber->isStarted()
        ? $task->fiber->start()
        : ($error === NULL ? $task->fiber->resume($value) : $task->fiber->throw($error));
      if ($task->fiber->isTerminated()) {
        $task->result = $task->fiber->getReturn();
        $task->finished = TRUE;
      }
      elseif ($yielded instanceof RequestInterface) {
        $this->request($task, $yielded);
      }
      elseif (is_int($yielded)) {
        $task->wakeAt = min($task->deadline, hrtime(TRUE) / 1e6 + $yielded);
      }
      elseif ($yielded === NULL || ($yielded instanceof \UnitEnum && $yielded::class === 'Drupal\\Core\\Utility\\FiberResumeType' && $yielded->name === 'Immediate')) {
        // Drupal 11 entity loading yields to coalesce database reads. Resume
        // that work immediately, so a ledger save's transaction cannot be
        // interleaved with another job. Drupal 10 has no such enum.
        $this->advance($task);
      }
      else {
        throw new \LogicException('REPLAY_POOL_YIELD_INVALID');
      }
    }
    catch (\Throwable $failure) {
      if ($task->fiber->isSuspended()) {
        $this->advance($task, error: $failure);
      }
      else {
        $task->result = $failure;
        $task->finished = TRUE;
      }
    }
  }

  /**
   * Starts one attempt, using only the logical request's remaining budget.
   */
  private function request(Transfer $task, RequestInterface $request): void {
    if ($this->beforeRequest !== NULL) {
      ($this->beforeRequest)();
    }
    $remaining = (int) floor($task->deadline - hrtime(TRUE) / 1e6);
    if ($remaining < 1) {
      throw new NetworkError($request, CURLE_OPERATION_TIMEDOUT);
    }
    $handle = curl_init();
    $task->request = $request;
    $task->body = '';
    $headers = [];
    foreach ($request->getHeaders() as $name => $values) {
      $headers[] = $name . ': ' . implode(', ', $values);
    }
    $ok = curl_setopt_array($handle, [
      CURLOPT_URL => (string) $request->getUri(),
      CURLOPT_CUSTOMREQUEST => $request->getMethod(),
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_POSTFIELDS => (string) $request->getBody(),
      CURLOPT_FOLLOWLOCATION => FALSE,
      CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
      CURLOPT_CONNECTTIMEOUT_MS => min($this->connectMs, $remaining),
      CURLOPT_TIMEOUT_MS => $remaining,
      CURLOPT_NOSIGNAL => TRUE,
      CURLOPT_WRITEFUNCTION => function (\CurlHandle $curl, string $chunk) use ($task): int {
        if (strlen($task->body) + strlen($chunk) > $this->maxBytes) {
          return 0;
        }
        $task->body .= $chunk;
        return strlen($chunk);
      },
    ]);
    if ($request->getMethod() === 'GET') {
      $ok = $ok && curl_setopt($handle, CURLOPT_HTTPGET, TRUE);
    }
    if (!$ok || curl_multi_add_handle($this->multi, $handle) !== CURLM_OK) {
      curl_close($handle);
      throw new NetworkError($request, CURLE_FAILED_INIT);
    }
    $task->handle = $handle;
    $this->handles[spl_object_id($handle)] = $task;
  }

  /**
   * Releases an attempt's socket, including on exceptions.
   */
  private function remove(Transfer $task): void {
    if ($task->handle !== NULL) {
      unset($this->handles[spl_object_id($task->handle)]);
      curl_multi_remove_handle($this->multi, $task->handle);
      curl_close($task->handle);
      $task->handle = NULL;
    }
  }

}
