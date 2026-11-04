<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Support;

use Drupal\decionis_commerce\Policy;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A local policy publisher double with controlled failures and concurrent work.
 */
final class PolicyApi implements ClientInterface {

  /**
   * Synthetic requests received by the double.
   *
   * @var list<\Psr\Http\Message\RequestInterface>
   */
  public array $requests = [];

  /**
   * Overrides a response or mutates the local test site during a request.
   */
  public ?\Closure $during = NULL;

  /**
   * The next synthetic publication version.
   */
  public string $version = 'drupal-limits-test-v1';

  /**
   * Reads requests after the publication service has run.
   *
   * @return list<\Psr\Http\Message\RequestInterface>
   *   The received requests at the time this method is called.
   */
  public function received(): array {
    return $this->requests;
  }

  /**
   * {@inheritdoc}
   */
  public function sendRequest(RequestInterface $request): ResponseInterface {
    $this->requests[] = $request;
    if ($this->during !== NULL) {
      $response = ($this->during)($request);
      if ($response instanceof ResponseInterface) {
        return $response;
      }
    }
    $body = json_decode((string) $request->getBody(), TRUE, 32, JSON_THROW_ON_ERROR);
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
      'org_id' => $body['org_id'],
      'policy_version' => $this->version,
      'bundle_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2',
      'limits_digest' => Policy::digest($body['limits_by_currency']),
    ], JSON_THROW_ON_ERROR));
  }

}
