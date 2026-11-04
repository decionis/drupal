<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Support;

use Decionis\Client\Canonical\SortedJson;
use Drupal\decionis\Controller\ChallengeController;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A double of the Decionis connect API.
 *
 * On verify it fetches the site's challenge the way Decionis does, through the
 * site's own controller, and checks the site-key signature before
 * "provisioning".
 */
final class ConnectApi implements ClientInterface {

  public const API_KEY = 'dcy_aaaaaaaa_synthetic-site-scoped-test-key';
  public const ORG = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';

  /**
   * Request paths and decoded bodies received by the API double.
   *
   * @var list<array{string, array<string, mixed>}>
   */
  public array $calls = [];

  /**
   * The site public key captured by the connect-start request.
   */
  private ?string $publicKey = NULL;

  /**
   * The synthetic challenge returned when connection starts.
   */
  private string $challenge = 'synthetic-challenge-0001';

  /**
   * The proof returned by the site's challenge controller, when available.
   *
   * @var array<string, mixed>|null
   */
  public ?array $served = NULL;

  /**
   * What the status route reports as the workspace's entitlement.
   *
   * @var array<string, mixed>|null
   */
  public ?array $entitlement = ['enforcement' => TRUE, 'state' => 'trial'];

  /**
   * When set, what verify answers instead of checking the challenge.
   */
  public ?int $verifyStatus = NULL;

  /**
   * The organization identifier returned when connection succeeds.
   */
  public string $org = self::ORG;

  /**
   * The synthetic site key returned when connection succeeds.
   */
  public string $apiKey = self::API_KEY;

  /**
   * The HTTP status returned by the workspace-status endpoint.
   */
  public int $statusCode = 200;

  /**
   * Simulates reconnect while an old status response is already in flight.
   */
  public ?\Closure $duringStatus = NULL;

  public function __construct(private readonly ChallengeController $challenges, private readonly bool $reachable = TRUE) {}

  /**
   * {@inheritdoc}
   */
  public function sendRequest(RequestInterface $request): ResponseInterface {
    $path = $request->getUri()->getPath();
    $body = json_decode((string) $request->getBody(), TRUE) ?: [];
    $this->calls[] = [$path, $body];
    return match ($path) {
      '/v1/drupal/connect/start' => $this->start($body),
      '/v1/drupal/connect/verify' => $this->verify($body),
      '/v1/drupal/connect/code' => $this->code($body),
      '/v1/drupal/status' => $this->status(),
      '/v1/drupal/disconnect' => self::json(200, ['disconnected' => TRUE]),
      default => self::json(404, ['error' => 'NOT_FOUND']),
    };
  }

  /**
   * Captures the site's public key and returns the connection challenge.
   *
   * @param array<string, mixed> $body
   *   The decoded connect-start request.
   */
  private function start(array $body): ResponseInterface {
    $this->publicKey = (string) $body['public_key'];
    return self::json(201, ['connect_id' => 'c0nnect-0001', 'challenge' => $this->challenge, 'expires_in_seconds' => 600]);
  }

  /**
   * Checks the signed request and the site's challenge response.
   *
   * @param array<string, mixed> $body
   *   The decoded connection-verification request.
   */
  private function verify(array $body): ResponseInterface {
    if (!$this->signed($body)) {
      return self::json(401, ['error' => 'SIGNATURE_INVALID']);
    }
    if ($this->verifyStatus !== NULL) {
      return self::json($this->verifyStatus, ['error' => 'CHALLENGE_FAILED']);
    }
    if (!$this->reachable) {
      return self::json(202, ['status' => 'code_sent']);
    }
    $response = $this->challenges->serve();
    $proof = json_decode((string) $response->getContent(), TRUE);
    $this->served = is_array($proof) ? $proof : NULL;
    if ($response->getStatusCode() !== 200 || ($proof['challenge'] ?? NULL) !== $this->challenge || !$this->signed($proof)) {
      return self::json(403, ['error' => 'CHALLENGE_FAILED']);
    }
    return $this->connected();
  }

  /**
   * Completes connection when the signed verification code is valid.
   *
   * @param array<string, mixed> $body
   *   The decoded code-verification request.
   */
  private function code(array $body): ResponseInterface {
    return $this->signed($body) && ($body['code'] ?? NULL) === '123456' ? $this->connected() : self::json(400, ['error' => 'CODE_INVALID']);
  }

  /**
   * Answers as Decionis does once the site is provisioned.
   */
  private function connected(): ResponseInterface {
    return self::json(200, ['status' => 'connected', 'org_id' => $this->org, 'api_key' => $this->apiKey, 'mode' => 'shadow']);
  }

  /**
   * Captures the old response before a second request changes the connection.
   */
  private function status(): ResponseInterface {
    $response = self::json($this->statusCode, ['org_id' => $this->org, 'entitlement' => $this->entitlement]);
    ($this->duringStatus)?->__invoke();
    return $response;
  }

  /**
   * Verifies the message signature against the captured site public key.
   *
   * @param array<string, mixed> $message
   *   The signed request or challenge proof.
   */
  private function signed(array $message): bool {
    $signature = $message['signature'] ?? NULL;
    unset($message['signature']);
    if (!is_string($signature) || $this->publicKey === NULL) {
      return FALSE;
    }
    return sodium_crypto_sign_verify_detached(
      sodium_base642bin($signature, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
      (new SortedJson())->encode($message),
      sodium_base642bin($this->publicKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
    );
  }

  /**
   * Encodes a synthetic API response with the supplied status.
   *
   * @param int $status
   *   The response's HTTP status code.
   * @param array<string, mixed> $body
   *   The response fields to encode as JSON.
   */
  private static function json(int $status, array $body): ResponseInterface {
    return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($body));
  }

}
