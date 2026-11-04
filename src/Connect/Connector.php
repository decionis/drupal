<?php

declare(strict_types=1);

namespace Drupal\decionis\Connect;

use Decionis\Client\Error\DecionisException;
use Decionis\Client\Error\HttpException;
use Decionis\Client\Http\CurlClient;
use Decionis\Client\Http\Retry;
use Decionis\Client\Http\Transport;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Gate\Settings;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;

/**
 * One-step connect.
 *
 * Business details plus proof of site control in one submit, provisioning
 * through Decionis Core, credentials into the Key module.
 *
 * 1. start: details, base URL, site UUID and the site public key.
 * 2. The site serves a signed challenge at /.well-known/decionis-challenge.
 * 3. verify: Decionis fetches it, provisions the workspace bound to the site
 * key and returns the org id and a site-scoped key, once.
 * 4. When Decionis cannot reach the site it emails a 6-digit code instead,
 * entered on the same page (code).
 */
final class Connector {

  public const STATE = 'decionis.connect';
  private const PROTOCOL = 'decionis-drupal-connect/1';

  public function __construct(
    private readonly Settings $settings,
    private readonly Credentials $credentials,
    private readonly SiteKey $siteKey,
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly ?ClientInterface $http = NULL,
    private readonly ?Binding $binding = NULL,
  ) {}

  /**
   * Starts connecting and, when the site is reachable, completes it.
   *
   * @return string
   *   'connected', or 'code_sent' when the billing email got a code.
   */
  public function connect(string $siteUrl, string $siteUuid, string $business, string $email): string {
    Binding::normalize($siteUrl);
    if ($this->settings->connected() || $this->settings->copied()) {
      $this->clearLocal();
    }
    $this->state->delete(Settings::ENTITLEMENT);
    // Protect the signing key while connect is pending, including email-code
    // confirmation after a database has been copied to another host.
    ($this->binding ?? \Drupal::service(Binding::class))->bind($siteUrl);
    $this->siteKey->generate();
    $start = $this->post('/v1/drupal/connect/start', [
      'site_url' => $siteUrl,
      'site_uuid' => $siteUuid,
      'business_name' => $business,
      'billing_email' => $email,
      'public_key' => (string) $this->siteKey->publicKey(),
      'terms_accepted' => TRUE,
    ]);
    $connectId = self::text($start, 'connect_id');
    $challenge = self::text($start, 'challenge');
    $proof = ['protocol' => self::PROTOCOL, 'purpose' => 'challenge', 'connect_id' => $connectId, 'challenge' => $challenge, 'site_url' => $siteUrl];
    $this->state->set(self::STATE, [
      'connect_id' => $connectId,
      'site_url' => $siteUrl,
      'expires' => time() + 600,
      'proof' => $proof + ['signature' => $this->siteKey->sign($proof)],
    ]);
    try {
      $status = $this->finish($this->post('/v1/drupal/connect/verify', $this->signed(['purpose' => 'verify', 'connect_id' => $connectId])));
    }
    catch (\Throwable $error) {
      // No code is on its way, so the next try starts over.
      $this->cancel();
      throw $error;
    }
    // The challenge is single-use. What stays is what the code step needs.
    $pending = $this->state->get(self::STATE);
    if (is_array($pending)) {
      unset($pending['proof']);
      $this->state->set(self::STATE, $pending);
    }
    return $status;
  }

  /**
   * Forgets a connect in progress: its challenge, or the wait for its code.
   */
  public function cancel(): void {
    $this->state->delete(self::STATE);
  }

  /**
   * Completes a connect with the code sent to the billing email.
   */
  public function confirm(string $code): string {
    $pending = $this->state->get(self::STATE);
    if (!is_array($pending) || (int) ($pending['expires'] ?? 0) < time() || preg_match('/^\d{6}$/', $code) !== 1) {
      throw new \InvalidArgumentException('DECIONIS_CONNECT_CODE_INVALID');
    }
    return $this->finish($this->post('/v1/drupal/connect/code', $this->signed(['purpose' => 'code', 'connect_id' => (string) $pending['connect_id'], 'code' => $code])));
  }

  /**
   * Asks Decionis to revoke the site key, then forgets the credentials.
   */
  public function disconnect(): void {
    $key = $this->credentials->apiKey();
    if ($key !== NULL) {
      try {
        $this->post('/v1/drupal/disconnect', ['org_id' => $this->settings->get('org_id')], $key);
      }
      catch (HttpException $error) {
        if ($error->status !== 404 && $error->status !== 401) {
          throw $error;
        }
      }
    }
    $this->clearLocal();
  }

  /**
   * Forgets this copy without contacting or revoking the original site's key.
   */
  private function clearLocal(): void {
    $this->credentials->forget('api_key', Credentials::API_KEY);
    $this->credentials->forget('site_key', Credentials::SITE_KEY);
    $this->state->set(Binding::STATE, ['org_id' => '', 'api_key' => '', 'site_key' => '', 'generation' => bin2hex(random_bytes(16))]);
    $this->configFactory->getEditable('decionis.settings')->clear('org_id')->save();
    $this->state->delete(self::STATE);
    $this->state->delete(Settings::ENTITLEMENT);
  }

  /**
   * Reads the workspace status and the server's entitlement for this site.
   */
  public function refresh(): void {
    $identity = $this->settings->connectionIdentity();
    $key = $this->credentials->apiKey();
    if ($identity !== $this->settings->connectionIdentity()) {
      return;
    }
    if ($identity === NULL || $key === NULL) {
      // Without a key nothing can be enforced: forget the last entitlement.
      $this->state->delete(Settings::ENTITLEMENT);
      return;
    }
    try {
      $status = $this->post('/v1/drupal/status', ['org_id' => $this->settings->get('org_id')], $key);
    }
    catch (\Throwable $error) {
      if ($identity === $this->settings->connectionIdentity()) {
        throw $error;
      }
      return;
    }
    if ($identity !== $this->settings->connectionIdentity()) {
      return;
    }
    $entitlement = $status['entitlement'] ?? NULL;
    if (is_array($entitlement)) {
      $entitlement['connection_id'] = $identity;
      $entitlement['fetched_at'] = time();
      $this->state->set(Settings::ENTITLEMENT, $entitlement);
    }
    else {
      $this->state->delete(Settings::ENTITLEMENT);
    }
  }

  /**
   * Validates a connect response and stores the issued site connection.
   *
   * @param array<string, mixed> $result
   *   Response from the connect service, including its status.
   */
  private function finish(array $result): string {
    $status = $result['status'] ?? NULL;
    if ($status === 'code_sent') {
      return 'code_sent';
    }
    if ($status !== 'connected') {
      throw new \UnexpectedValueException('DECIONIS_CONNECT_UNEXPECTED');
    }
    $orgId = self::text($result, 'org_id');
    if (preg_match('/^[0-9a-f-]{36}$/i', $orgId) !== 1) {
      throw new \UnexpectedValueException('DECIONIS_CONNECT_UNEXPECTED');
    }
    $pending = $this->state->get(self::STATE, []);
    $url = is_array($pending) ? ($pending['site_url'] ?? NULL) : NULL;
    if (!is_string($url)) {
      throw new \UnexpectedValueException('DECIONIS_CONNECT_UNEXPECTED');
    }
    Binding::normalize($url);
    $this->credentials->store('api_key', Credentials::API_KEY, 'Decionis API key', self::text($result, 'api_key'));
    $connection = $this->state->get(Binding::STATE, []);
    $connection = is_array($connection) ? $connection : [];
    $connection['org_id'] = strtolower($orgId);
    $this->state->set(Binding::STATE, $connection);
    ($this->binding ?? \Drupal::service(Binding::class))->bind($url);
    $this->configFactory->getEditable('decionis.settings')->clear('org_id')->set('mode', 'shadow')->save();
    $this->state->delete(self::STATE);
    if (is_array($result['entitlement'] ?? NULL)) {
      $entitlement = $result['entitlement'];
      $entitlement['connection_id'] = $this->settings->connectionIdentity();
      $entitlement['fetched_at'] = time();
      $this->state->set(Settings::ENTITLEMENT, $entitlement);
    }
    $this->moduleHandler->invokeAll('decionis_connected', [$orgId]);
    return 'connected';
  }

  /**
   * Adds the protocol identifier and site signature to a connect message.
   *
   * @param array<string, string> $message
   *   Unsigned message fields to bind to this site.
   *
   * @return array<string, string>
   *   Message fields with the protocol identifier and detached signature.
   */
  private function signed(array $message): array {
    $message['protocol'] = self::PROTOCOL;
    return $message + ['signature' => $this->siteKey->sign($message)];
  }

  /**
   * Sends a bounded JSON request to the configured connect service.
   *
   * @param string $path
   *   Service path appended to the configured connect URL.
   * @param array<string, mixed> $body
   *   Request fields serialized as JSON.
   * @param string|null $bearer
   *   Optional credential used only for the outgoing authorization header.
   *
   * @return array<string, mixed>
   *   The decoded JSON response object.
   */
  private function post(string $path, array $body, ?string $bearer = NULL): array {
    $factory = new HttpFactory();
    $transport = new Transport($this->http ?? new CurlClient($factory, $factory, 5000, 15000), $factory, $factory, 'drupal-decionis/1.0');
    $headers = $bearer === NULL ? [] : ['Authorization' => 'Bearer ' . $bearer];
    $url = rtrim($this->settings->get('connect_url'), '/') . $path;
    try {
      $text = $transport->send('POST', $url, $headers, json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), Retry::once(15000));
    }
    catch (DecionisException $error) {
      throw $error;
    }
    $decoded = json_decode($text, TRUE);
    if (!is_array($decoded)) {
      throw new \UnexpectedValueException('DECIONIS_CONNECT_UNEXPECTED');
    }
    return $decoded;
  }

  /**
   * Reads a required, non-empty, bounded string from a connect response.
   *
   * @param array<string, mixed> $data
   *   Response fields to validate.
   * @param string $key
   *   Name of the required string field.
   */
  private static function text(array $data, string $key): string {
    $value = $data[$key] ?? NULL;
    if (!is_string($value) || $value === '' || strlen($value) > 512) {
      throw new \UnexpectedValueException('DECIONIS_CONNECT_UNEXPECTED');
    }
    return $value;
  }

}
