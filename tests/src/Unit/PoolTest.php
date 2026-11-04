<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Unit;

use Decionis\Client\Error\HttpException;
use Decionis\Client\Error\TransportException;
use Decionis\Client\Http\Retry;
use Decionis\Client\Http\Transport;
use Drupal\decionis\Http\Pool;
use Drupal\Tests\decionis\Support\Loopback;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\HttpFactory;

/**
 * Real slow sockets prove the replay transport's concurrency and deadlines.
 *
 * @group decionis
 */
final class PoolTest extends UnitTestCase {

  /**
   * The loopback API used to exercise concurrent requests.
   */
  private Loopback $server;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->server = new Loopback();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->server->stop();
    parent::tearDown();
  }

  /**
   * The whole logical request owns one slot, including waits between retries.
   */
  public function testSlowRequestsRespectConcurrencyAndKeepRetryKeys(): void {
    $pool = new Pool(4);
    $transport = $this->transport($pool);
    $jobs = [];
    for ($index = 0; $index < 12; $index++) {
      $jobs[] = fn () => $transport->send('POST', $this->server->url . '/retry', ['Idempotency-Key' => 'fixture-' . $index], '{"synthetic":true}', new Retry(3, 5, 10, 5000));
    }
    $results = $pool->run($jobs);
    $this->assertSame(array_fill(0, 12, '{"ok":true}'), $results);
    $stats = $this->server->stats();
    $this->assertSame(4, $stats['peak'], 'real HTTP work overlapped without exceeding the configured limit');
    $this->assertCount(24, $stats['calls']);
    foreach (array_unique(array_column($stats['calls'], 'key')) as $key) {
      $attempts = array_values(array_filter($stats['calls'], static fn (array $call): bool => $call['key'] === $key));
      $this->assertCount(2, $attempts);
      $this->assertSame($attempts[0]['digest'], $attempts[1]['digest'], 'a retry preserves the exact body as well as its key');
    }
  }

  /**
   * A retry has only the time left in its logical request, not a fresh budget.
   */
  public function testRetriesShareOneHardDeadline(): void {
    $pool = new Pool(1, 50, 180);
    $transport = $this->transport($pool);
    $started = microtime(TRUE);
    $result = $pool->run([fn () => $transport->send('POST', $this->server->url . '/retry?ms=120', ['Idempotency-Key' => 'deadline-fixture'], '{}', new Retry(3, 0, 0, 180))]);
    $this->assertInstanceOf(TransportException::class, $result[0]);
    $this->assertLessThan(0.5, microtime(TRUE) - $started);
    $this->assertLessThanOrEqual(2, count($this->server->stats()['calls']));
  }

  /**
   * One checkout attempt obeys the caller's deadline even on a silent socket.
   */
  public function testCheckoutCancelsAtTheOriginalDeadline(): void {
    $pool = new Pool(1, 100, 100);
    $transport = $this->transport($pool);
    $started = hrtime(TRUE) / 1e6;
    // Setup before the scheduler starts still belongs to this checkout.
    usleep(40000);
    $result = $pool->run([fn () => $transport->send('GET', $this->server->url . '/hang', [], NULL, Retry::once(100))], $started + 100);
    $elapsed = hrtime(TRUE) / 1e6 - $started;
    $this->assertInstanceOf(TransportException::class, $result[0]);
    $this->assertLessThan(120, $elapsed, sprintf('setup and silent socket took %.1f ms', $elapsed));
    $this->assertCount(1, $this->server->stats()['calls']);
  }

  /**
   * Setup that consumed the entire deadline leaves no request to send.
   */
  public function testExpiredCallerDeadlineDoesNotSend(): void {
    $pool = new Pool(1, 100, 100);
    $transport = $this->transport($pool);
    $result = $pool->run([fn () => $transport->send('GET', $this->server->url . '/hang', [], NULL, Retry::once(100))], hrtime(TRUE) / 1e6 - 1);
    $this->assertInstanceOf(TransportException::class, $result[0]);
    $this->assertSame([], $this->server->stats()['calls']);
  }

  /**
   * A response cannot grow beyond the cap or redirect a bearer to another URL.
   */
  public function testResponseCapAndNoRedirects(): void {
    $pool = new Pool(2, maxBytes: 1024);
    $transport = $this->transport($pool);
    $result = $pool->run([
      fn () => $transport->send('GET', $this->server->url . '/oversize', [], NULL, Retry::once(5000)),
      fn () => $transport->send('GET', $this->server->url . '/redirect', [], NULL, Retry::once(5000)),
    ]);
    $this->assertInstanceOf(TransportException::class, $result[0]);
    $this->assertInstanceOf(HttpException::class, $result[1]);
    $this->assertSame(302, $result[1]->status);
    $this->assertNotContains('/reached', array_column($this->server->stats()['calls'], 'path'));
  }

  /**
   * Reconnection during a retry delay prevents sending with a new workspace.
   */
  public function testIdentityGuardRunsImmediatelyBeforeEveryAttempt(): void {
    $attempts = 0;
    $pool = new Pool(1, beforeRequest: static function () use (&$attempts): void {
      if (++$attempts > 1) {
        throw new \LogicException('REPLAY_CONNECTION_CHANGED');
      }
    });
    $transport = $this->transport($pool);
    $result = $pool->run([fn () => $transport->send('POST', $this->server->url . '/retry', ['Idempotency-Key' => 'connection-fixture'], '{}', new Retry(3, 1, 1, 5000))]);
    $this->assertInstanceOf(\LogicException::class, $result[0]);
    $this->assertSame('REPLAY_CONNECTION_CHANGED', $result[0]->getMessage());
    $this->assertCount(1, $this->server->stats()['calls']);
  }

  /**
   * Drupal's cooperative entity loads can yield without an HTTP request.
   */
  public function testCooperativeEntityLoadYieldsAreResumed(): void {
    $pool = new Pool(1);
    $transport = $this->transport($pool);
    $result = $pool->run([
      function () use ($transport): string {
        \Fiber::suspend();
        return $transport->send('POST', $this->server->url . '/slow', [], '{}', Retry::once(5000));
      },
    ]);
    $this->assertSame(['{"ok":true}'], $result);
  }

  /**
   * Uses the same public client and retry seam as Remote.
   */
  private function transport(Pool $pool): Transport {
    $factory = new HttpFactory();
    return new Transport($pool, $factory, $factory, 'synthetic-replay-test', $pool->pause(...));
  }

}
