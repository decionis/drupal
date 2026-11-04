<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Unit;

use Decionis\Client\Error\TransportException;
use Decionis\Client\Http\CurlClient;
use Decionis\Client\Http\Retry;
use Decionis\Client\Http\Transport;
use Drupal\decionis\Http\Bound;
use Drupal\decionis\Http\Pool;
use Drupal\Tests\decionis\Support\Loopback;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Captured non-pooled requests keep the same connection on every attempt.
 *
 * @group decionis
 */
final class BoundTest extends UnitTestCase {

  /**
   * Slow pre-send checks consume the HTTP allocation, not extra checkout time.
   */
  public function testGuardTimeReducesTheNetworkTimeout(): void {
    $server = new Loopback();
    $factory = new HttpFactory();
    $started = hrtime(TRUE) / 1e6;
    $http = new Bound(static function (RequestInterface $request, ?int $remaining): ResponseInterface {
      self::assertNotNull($remaining);
      self::assertGreaterThan(0, $remaining);
      self::assertLessThanOrEqual(40, $remaining);
      $pool = new Pool(1, $remaining, $remaining);
      $result = $pool->run([fn () => $pool->sendRequest($request)])[0];
      if ($result instanceof \Throwable) {
        throw $result;
      }
      return $result;
    }, static function (): void {
      usleep(60000);
    }, $started + 100);
    $transport = new Transport($http, $factory, $factory, 'synthetic');
    try {
      $transport->send('GET', $server->url . '/hang', [], NULL, Retry::once(100));
      $this->fail('A slow authority answered within the checkout deadline.');
    }
    catch (TransportException $error) {
      $elapsed = hrtime(TRUE) / 1e6 - $started;
      $this->assertSame('UPSTREAM_UNREACHABLE', $error->getMessage());
      $this->assertLessThan(120, $elapsed, sprintf('guard and HTTP took %.1f ms', $elapsed));
      $this->assertCount(1, $server->stats()['calls']);
    }
    finally {
      $server->stop();
    }
  }

  /**
   * An exhausted allocation never starts a request or receives a new minimum.
   */
  public function testExpiredBudgetDoesNotSend(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->expects($this->never())->method('sendRequest');
    $http = new Bound($client, static function (): void {}, hrtime(TRUE) / 1e6 - 1);
    $this->expectException(TransportException::class);
    $this->expectExceptionMessage('CHECKOUT_BUDGET_EXHAUSTED');
    $http->sendRequest((new HttpFactory())->createRequest('GET', 'http://127.0.0.1/fixture'));
  }

  /**
   * A reconnect during retry delay never sends the old payload again.
   */
  public function testSynchronousRetriesRecheckTheConnection(): void {
    $server = new Loopback();
    $connection = (object) ['valid' => TRUE];
    $factory = new HttpFactory();
    $http = new Bound(new CurlClient($factory, $factory), static function () use ($connection): void {
      self::guard($connection);
    });
    $transport = new Transport($http, $factory, $factory, 'synthetic', static function () use ($connection): void {
      $connection->valid = FALSE;
    });
    try {
      $transport->send('POST', $server->url . '/retry', ['Idempotency-Key' => 'synthetic-stale'], '{}', new Retry(3, 1, 1, 5000));
      $this->fail('A stale capture was retried.');
    }
    catch (\LogicException $error) {
      $this->assertSame('DECIONIS_CONNECTION_CHANGED', $error->getMessage());
      $this->assertCount(1, $server->stats()['calls']);
    }
    finally {
      $server->stop();
    }
  }

  /**
   * An in-flight answer from a former connection is never accepted.
   */
  public function testResponseFromChangedConnectionIsRejected(): void {
    $connection = (object) ['valid' => TRUE];
    $client = new class($connection) implements ClientInterface {

      public function __construct(private readonly \stdClass $connection) {}

      /**
       * {@inheritdoc}
       */
      public function sendRequest(RequestInterface $request): ResponseInterface {
        $this->connection->valid = FALSE;
        return new Response(200, [], '{"synthetic":true}');
      }

    };
    $http = new Bound($client, static function () use ($connection): void {
      self::guard($connection);
    });
    $this->expectExceptionMessage('DECIONIS_CONNECTION_CHANGED');
    $http->sendRequest((new HttpFactory())->createRequest('GET', 'http://127.0.0.1/fixture'));
  }

  /**
   * Checks the mutable connection fixture at the time of each callback.
   */
  private static function guard(\stdClass $connection): void {
    if (!$connection->valid) {
      throw new \LogicException('DECIONIS_CONNECTION_CHANGED');
    }
  }

}
