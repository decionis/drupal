<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Functional;

use Decionis\Client\Decision\Action;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis\Gate\Context;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Gate\Deferred;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis\Notification\Digest;
use Drupal\Tests\BrowserTestBase;

/**
 * Due automatic cron on a non-FPM page cannot start remote Shadow work.
 *
 * @group decionis
 */
final class AutomatedCronTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'decionis', 'automated_cron'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The slow authority process used to detect unwanted cron requests.
   *
   * @var resource|null
   */
  private $server = NULL;

  /**
   * The temporary PHP router for the slow loopback server.
   */
  private ?string $router = NULL;

  /**
   * The temporary log of requests received by the authority double.
   */
  private ?string $requests = NULL;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_resource($this->server)) {
      proc_terminate($this->server);
      proc_close($this->server);
    }
    foreach ([$this->router, $this->requests] as $path) {
      if ($path !== NULL && is_file($path)) {
        unlink($path);
      }
    }
    parent::tearDown();
  }

  /**
   * The real automatic-cron subscriber leaves existing work for explicit cron.
   */
  public function testDueAutomaticCronDoesNotDelayAnAdminPage(): void {
    $admin = $this->drupalCreateUser(['administer decionis', 'administer site configuration']);
    $this->drupalLogin($admin);
    $port = $this->slowServer();
    $this->config('decionis.settings')->set('api_base_url', 'http://127.0.0.1:' . $port)
      ->set('connect_url', 'http://127.0.0.1:' . $port)
      ->set('org_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1')
      ->set('digest.enabled', TRUE)->set('digest.recipients', [(int) $admin->id()])->set('digest.day', 0)->save();
    $this->container->get(Credentials::class)->store('api_key', Credentials::API_KEY, 'Synthetic key', 'dcy_aaaaaaaa_synthetic-autocron-test-key');
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('automated_cron.settings')->set('interval', 1)->save();
    $this->container->get('state')->set('system.cron_last', 0);

    $action = Action::price(['type' => 'HUMAN', 'id' => 'synthetic-admin'], 'drupal', 'synthetic-autocron-price', 'SKU-2', 120.0, 96.0, 100.0, 'USD');
    $outcome = $this->container->get(Gate::class)->check($action, new Context('price_change', 'synthetic_save', atStake: 96.0, currency: 'USD'));
    $this->assertSame('pending', $outcome->effect);
    $this->assertSame(1, $this->container->get(Deferred::class)->dispatch());
    Decision::create([
      'action' => 'price_change',
      'source' => 'live',
      'mode' => 'shadow',
      'effect' => 'proceeded',
      'verdict' => 'BLOCK',
      'authority' => 'account',
      'at_stake' => 96,
      'currency' => 'USD',
      'save_confirmed' => 1,
      'connection_id' => $this->container->get(Settings::class)->connectionIdentity(),
    ])->save();

    $start = hrtime(TRUE);
    $this->drupalGet('/user/' . $admin->id());
    $this->assertSession()->statusCodeEquals(200);
    // A second request also proves the single-threaded loopback web server
    // was not left doing two-second HTTP work after flushing the first page.
    $this->drupalGet('/user/' . $admin->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertLessThan(1500, (hrtime(TRUE) - $start) / 1e6);
    $this->assertNotNull($this->requests);
    $this->assertFalse(is_file($this->requests), 'Neither queue evaluation nor entitlement refresh ran on web cron.');
    $this->container->get('state')->resetCache();
    $this->assertGreaterThan(0, $this->container->get('state')->get('system.cron_last'), 'Automatic cron really ran.');
    $this->assertEmpty($this->container->get('state')->get('system.test_mail_collector', []), 'Automatic web cron sent no digest.');

    $queue = $this->container->get('queue')->get('decionis_shadow');
    $this->assertSame(1, $queue->numberOfItems(), 'Suspending unsafe web work preserves the queue item.');
    $queued = $queue->claimItem();
    $this->assertIsObject($queued);
    $this->container->get('plugin.manager.queue_worker')->createInstance('decionis_shadow')->processItem($queued->data);
    $queue->deleteItem($queued);
    $this->assertCount(1, file($this->requests, FILE_IGNORE_NEW_LINES));
    $this->assertSame('BLOCK', Decision::load($outcome->logId)->value('verdict'));
    $this->assertSame(1, $this->container->get(Digest::class)->sendDue(), 'An explicit CLI worker can deliver the opted-in digest.');

    $next = Action::price(['type' => 'HUMAN', 'id' => 'synthetic-admin'], 'drupal', 'synthetic-manual-cron-price', 'SKU-3', 120.0, 96.0, 100.0, 'USD');
    $pending = $this->container->get(Gate::class)->check($next, new Context('price_change', 'synthetic_save', atStake: 96.0, currency: 'USD'));
    $this->container->get(Deferred::class)->dispatch();
    $this->container->get('state')->set('decionis.entitlement_checked', time());
    $this->container->get('state')->set('system.cron_last', 0);
    $this->drupalGet('/admin/config/system/cron');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame(1, $queue->numberOfItems(), 'Merely opening cron settings is not permission to run remote work.');
    $this->container->get('state')->set('system.cron_last', 0);
    $this->submitForm(['interval' => '3600'], 'Save configuration');
    $this->assertSame(1, $queue->numberOfItems(), 'Saving cron settings leaves unsafe automatic work queued.');
    $this->assertCount(1, file($this->requests, FILE_IGNORE_NEW_LINES));
    $this->submitForm([], 'Run cron');
    $this->assertSession()->pageTextContains('Cron ran successfully.');
    $this->assertSame(0, $queue->numberOfItems());
    $this->assertCount(2, file($this->requests, FILE_IGNORE_NEW_LINES));
    $this->container->get('entity_type.manager')->getStorage('decionis_decision')->resetCache();
    $this->assertSame('BLOCK', Decision::load($pending->logId)->value('verdict'));
  }

  /**
   * The synthetic authority delays any accidental policy or account request.
   */
  private function slowServer(): int {
    $this->router = sys_get_temp_dir() . '/decionis-autocron-' . getmypid() . '.php';
    $this->requests = $this->router . '.requests';
    $answer = [
      'outcome' => 'REJECT',
      'confidence' => 0.9,
      'policy_version' => 'synthetic-v1',
      'objective_profile' => 'synthetic',
      'dossier_id' => '18962a2d-6842-4f6a-9ff4-901c50acaed8',
      'evaluation_id' => '5a7cb7e8-507a-4bba-ad6f-98554e418d72',
      'mode' => 'SHADOW',
      'fallback_to_legacy' => FALSE,
      'idempotent_replay' => FALSE,
      'reason_codes' => ['price_below_cost'],
      'governance_metrics' => ['override_drift_rate' => 0, 'governance_tension_index' => 0, 'boundary_volatility_score' => 0, 'decision_volume_under_governance' => 0],
    ];
    file_put_contents($this->router, '<?php file_put_contents(' . var_export($this->requests, TRUE) . ', "request\n", FILE_APPEND); sleep(2); header("Content-Type: application/json"); echo ' . var_export(json_encode($answer), TRUE) . ';');
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = (string) stream_socket_get_name($socket, FALSE);
    fclose($socket);
    $port = (int) substr($name, strrpos($name, ':') + 1);
    $this->server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, $this->router], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 100 && @fsockopen('127.0.0.1', $port) === FALSE; $i++) {
      usleep(20000);
    }
    return $port;
  }

}
