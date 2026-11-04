<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis_commerce\Gateway\Probe;
use Drupal\decionis_commerce\Gateway\Setup;
use Drupal\decionis_commerce\Gateway\Tier;
use Drupal\Tests\decionis_commerce\Support\RecordingRunner;

/**
 * Tests AgentSafe using generated gateway configuration and a Fincra double.
 *
 * The real AgentSafe runtime holds the Fincra key: checkout creation passes
 * through with the key AgentSafe adds, and a refund that cannot get a grant
 * (the authority is unreachable) is refused at AgentSafe and never reaches
 * Fincra. Set AGENTSAFE_BIN to an agentsafe executable to run it; CI does.
 *
 * @group decionis
 */
final class AgentSafeTest extends DecionisCommerceTestBase {

  private const SECRET = 'fixture-fincra-held-by-agentsafe';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['decionis_fincra_test'];

  /**
   * The temporary directory holding gateway configuration and logs.
   */
  private string $home;

  /**
   * The Fincra double and AgentSafe.
   *
   * @var list<resource>
   */
  private array $processes = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    if (!is_string(getenv('AGENTSAFE_BIN')) || getenv('AGENTSAFE_BIN') === '') {
      $this->markTestSkipped('Set AGENTSAFE_BIN to an agentsafe executable (npm install @decionis/agentsafe).');
    }
    $this->home = sys_get_temp_dir() . '/decionis-agentsafe-' . bin2hex(random_bytes(4));
    mkdir($this->home, 0700, TRUE);
    // No Decionis answers here: a refund cannot get a grant.
    $this->config('decionis.settings')->set('org_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1')->set('api_base_url', 'http://127.0.0.1:1')->save();
    $this->container->get(Credentials::class)->store('api_key', Credentials::API_KEY, 'Decionis API key', 'dcy_aaaaaaaa_synthetic-agentsafe-test-key');
    PaymentGateway::create([
      'id' => 'fincra',
      'label' => 'Fincra',
      'plugin' => 'fincra_standard',
      'configuration' => ['mode' => 'test', 'secret_key_test' => self::SECRET, 'public_key_test' => 'pk_test_fixture'],
    ])->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->processes as $process) {
      proc_terminate($process);
      proc_close($process);
    }
    if (isset($this->home) && is_dir($this->home)) {
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->home, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
      foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
      }
      rmdir($this->home);
    }
    parent::tearDown();
  }

  /**
   * Tests that AgentSafe passes checkout and refuses refunds without a grant.
   */
  public function testCheckoutPassesAndRefundWithoutGrantIsRefused(): void {
    [$fincra, $log] = $this->fincra();
    $port = self::freePort();
    // This test owns the foreground child process until tearDown. Production
    // setup must prove linger; its refusal is covered with both user tiers.
    $probe = $this->getMockBuilder(Probe::class)->onlyMethods(['linger'])->getMock();
    $probe->method('linger')->willReturn(TRUE);
    $setup = new Setup($this->container->get('entity_type.manager'), $this->container->get('Drupal\decionis\Gate\Settings'), $this->container->get(Credentials::class), $this->container->get('state'), $probe, 15);
    $plan = $setup->plan(Tier::Web, ['user' => 'agentsafe', 'port' => $port, 'mode' => 'enforcement', 'version' => '0.7.0', 'home' => $this->home, 'files' => $this->home]);
    // Writes the configuration and the key files; the install and service
    // steps are recorded, and the test starts AgentSafe itself.
    $runner = new RecordingRunner();
    foreach ($plan as $step) {
      $runner->run($step);
    }
    $etc = $this->home . '/.config/agentsafe';
    $this->agentsafe($etc, $fincra);
    try {
      $setup->apply(Tier::Web, [], $port, $runner);
    }
    catch (\RuntimeException $error) {
      $this->fail($error->getMessage() . "\nAgentSafe said:\n" . @file_get_contents($this->home . '/agentsafe.log'));
    }
    $configuration = $this->container->get('config.factory')->get('commerce_payment.commerce_payment_gateway.fincra')->get('configuration');
    $this->assertSame('', $configuration['secret_key_test'], 'Drupal config holds no Fincra secret key');
    $base = $configuration['api_base_url'];
    $this->assertSame('http://127.0.0.1:' . $port, $base);

    // As Commerce Fincra creates a checkout: no key from Drupal.
    [$status, $headers] = self::post($base . '/checkout/payments', ['amount' => 100, 'currency' => 'NGN', 'reference' => 'order-1']);
    $this->assertSame(200, $status);
    $this->assertStringContainsString('agentsafe-execution: passthrough', strtolower($headers));
    $seen = self::seen($log);
    $this->assertSame(['POST /checkout/payments ' . self::SECRET], $seen, 'Fincra got the checkout with the key AgentSafe holds');

    [$status, $headers, $body] = self::post($base . '/collections/refund', ['reference' => 'order-1', 'amount' => 100]);
    $this->assertSame(503, $status);
    $this->assertStringContainsString('agentsafe-execution: not_forwarded', strtolower($headers));
    $this->assertSame(['AUTHORITY_UNAVAILABLE'], json_decode($body, TRUE)['reason_codes'] ?? NULL);
    $this->assertSame($seen, self::seen($log), 'the refund never reached Fincra');
  }

  /**
   * Starts the Fincra double; returns its URL and request log.
   *
   * @return array{string, string}
   *   The base URL and the log file.
   */
  private function fincra(): array {
    $port = self::freePort();
    $log = $this->home . '/fincra.log';
    $router = $this->home . '/fincra.php';
    file_put_contents($router, '<?php file_put_contents(' . var_export($log, TRUE) . ', $_SERVER["REQUEST_METHOD"] . " " . parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH) . " " . ($_SERVER["HTTP_API_KEY"] ?? "-") . "\n", FILE_APPEND); header("Content-Type: application/json"); echo json_encode(["status" => true]);');
    $this->processes[] = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    self::wait(fn (): bool => @fsockopen('127.0.0.1', $port) !== FALSE);
    return ['http://127.0.0.1:' . $port, $log];
  }

  /**
   * Starts AgentSafe with generated configuration and the Fincra double.
   */
  private function agentsafe(string $etc, string $fincra): void {
    $environment = getenv() + [
      'AGENTSAFE_UPSTREAM' => $fincra,
      'AGENTSAFE_UPSTREAM_INSECURE' => 'true',
      'AGENTSAFE_UPSTREAM_PUBLIC_ONLY' => 'false',
      'DECIONIS_ALLOW_INSECURE_LOOPBACK' => 'true',
      'DECIONIS_API_KEY_FILE' => $etc . '/decionis-api-key',
      'DECIONIS_TENANT_ID' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1',
    ];
    $this->processes[] = proc_open([(string) getenv('AGENTSAFE_BIN'), 'run', '--config', $etc . '/agentsafe.yaml'], [1 => ['file', $this->home . '/agentsafe.log', 'w'], 2 => ['file', $this->home . '/agentsafe.log', 'a']], $pipes, NULL, $environment);
  }

  /**
   * POSTs JSON; returns the status, the raw headers and the body.
   *
   * @param string $url
   *   The loopback endpoint receiving the request.
   * @param array<string, mixed> $body
   *   The synthetic request fields to encode as JSON.
   *
   * @return array{int, string, string}
   *   The response.
   */
  private static function post(string $url, array $body): array {
    $context = stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => json_encode($body), 'ignore_errors' => TRUE, 'timeout' => 20]]);
    $response = (string) file_get_contents($url, FALSE, $context);
    // PHP sets $http_response_header for the request above.
    $headers = implode("\n", $http_response_header);
    preg_match('#^HTTP/\S+ (\d{3})#', $headers, $match);
    return [(int) ($match[1] ?? 0), $headers, $response];
  }

  /**
   * The requests the Fincra double received, without the healthz probes.
   *
   * @return list<string>
   *   "METHOD /path key" lines.
   */
  private static function seen(string $log): array {
    $lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];
    return array_values(array_filter($lines ?: [], static fn (string $line): bool => $line !== ''));
  }

  /**
   * A free loopback port.
   */
  private static function freePort(): int {
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($socket, FALSE);
    fclose($socket);
    return (int) substr($name, strrpos($name, ':') + 1);
  }

  /**
   * Waits up to 10 s for $ready.
   */
  private static function wait(callable $ready): void {
    for ($i = 0; $i < 100 && !$ready(); $i++) {
      usleep(100000);
    }
  }

}
