<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Gate\Deferred;
use Drupal\decionis\Gate\Ledger;
use Drupal\decionis\Notification\Nudges;
use Drupal\decionis\Report\Summary;
use Drupal\decionis_commerce\Guard\Orders;
use Drupal\decionis_commerce\Map\Cost;

/**
 * Real delayed HTTP is entirely outside checkout and price-save requests.
 *
 * @group decionis
 */
final class ShadowTest extends DecionisCommerceTestBase {

  /**
   * The child process serving the delayed authority response.
   *
   * @var resource|null
   */
  private $server = NULL;

  /**
   * The temporary request log written by the slow authority double.
   */
  private string $requests;

  /**
   * The temporary PHP router for the slow authority double.
   */
  private string $router;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $port = $this->slowServer();
    $this->config('decionis.settings')->set('api_base_url', 'http://127.0.0.1:' . $port)
      ->set('org_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1')->set('mode', 'shadow')->save();
    $this->container->get(Credentials::class)->store('api_key', Credentials::API_KEY, 'Decionis API key', 'dcy_aaaaaaaa_synthetic-shadow-test-key');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_resource($this->server)) {
      proc_terminate($this->server);
      proc_close($this->server);
    }
    foreach ([$this->requests, $this->router] as $path) {
      if (is_file($path)) {
        unlink($path);
      }
    }
    parent::tearDown();
  }

  /**
   * Neither native path waits for the two-second authority, then both resolve.
   */
  public function testSlowCheckoutAndPriceSaveReturnBeforeHttp(): void {
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'SHADOW-2', 'price' => new Price('100', 'USD'), 'status' => 1]);
    $variation->save();
    $this->container->get(Cost::class)->override($variation, 60.0);
    $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => '1', 'unit_price' => $variation->getPrice()]);
    $item->save();
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'mail' => 'synthetic-buyer@example.com', 'state' => 'draft', 'order_items' => [$item]]);
    $order->recalculateTotalPrice()->save();

    $start = hrtime(TRUE);
    $checkout = $this->container->get(Orders::class)->check($order, 'checkout_review', TRUE);
    $this->assertLessThan(500, (hrtime(TRUE) - $start) / 1e6);
    $this->assertSame('pending', $checkout?->effect);
    $this->assertTrue($checkout->proceeds());
    $this->assertNull($order->getData(Orders::DATA)['governed']);
    $this->assertTrue($order->getData(Orders::DATA)['pending']);
    $order->getState()->applyTransitionById('place');
    $order->save();

    $variation->setPrice(new Price('57.6', 'USD'));
    $start = hrtime(TRUE);
    $variation->validate();
    $variation->save();
    $this->assertLessThan(500, (hrtime(TRUE) - $start) / 1e6);
    $this->assertFalse(is_file($this->requests), 'No authority request during checkout or admin save.');
    $totals = $this->container->get(Summary::class)->totals(0, 'live');
    $this->assertSame(2, $totals['pending']);
    $this->assertSame(0, $totals['reviewed']);
    $this->assertSame(0, $totals['ungoverned']);

    // Change the entity before the worker runs. The evaluation must retain
    // the earlier proposal, policy pin and idempotency key.
    $variation->setPrice(new Price('90', 'USD'))->save();
    $deferred = $this->container->get(Deferred::class);
    $this->assertSame(2, $deferred->dispatch());
    $queue = $this->container->get('queue')->get('decionis_shadow');
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance('decionis_shadow');
    $processed = [];
    while (is_object($queued = $queue->claimItem())) {
      $worker->processItem($queued->data);
      $processed[] = $queued->data;
      $queue->deleteItem($queued);
    }
    $this->assertCount(2, $processed);
    foreach ($processed as $data) {
      $worker->processItem($data);
    }
    $requests = array_map(static fn (string $line): array => json_decode($line, TRUE, 512, JSON_THROW_ON_ERROR), file($this->requests, FILE_IGNORE_NEW_LINES));
    $this->assertCount(2, $requests, 'Redelivery performs no additional HTTP.');
    $this->assertStringContainsString('57.6', json_encode($requests));
    $this->assertStringNotContainsString('synthetic-buyer@example.com', json_encode($requests));
    foreach (Decision::loadMultiple() as $decision) {
      $this->assertSame('BLOCK', $decision->value('verdict'));
      $this->assertSame('proceeded', $decision->value('effect'));
      $this->assertSame('shadow-test-v1', $decision->value('policy_version'));
      $this->assertSame('', $decision->value('pending_capture'));
    }
    $totals = $this->container->get(Summary::class)->totals(0, 'live');
    $this->assertSame(0, $totals['pending']);
    $this->assertSame(2, $totals['reviewed']);
    $this->assertSame(0, $totals['ungoverned']);
  }

  /**
   * The queued record cannot use a changed workspace's credentials.
   */
  public function testReconnectionCancelsQueuedNetworkWork(): void {
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'COPY-2', 'price' => new Price('100', 'USD'), 'status' => 1]);
    $variation->save();
    $this->container->get(Cost::class)->override($variation, 60.0);
    $variation->setPrice(new Price('57.6', 'USD'));
    $variation->validate();
    $variation->save();
    $entries = Decision::loadMultiple();
    $this->assertCount(1, $entries);
    $entry = reset($entries);
    $this->config('decionis.settings')->set('org_id', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2')->save();
    $this->container->get(Deferred::class)->process((int) $entry->id());
    $this->assertFalse(is_file($this->requests));
    $fresh = Decision::load($entry->id());
    $this->assertSame('ungoverned', $fresh->value('effect'));
    $this->assertSame(['SHADOW_CONNECTION_CHANGED'], $fresh->reasons());
    $this->assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1', $fresh->value('org_id'));
  }

  /**
   * Save qualification rolls back; failed proposals never become admin nudges.
   */
  public function testRolledBackSaveDoesNotNudgeOrReinsertPendingWork(): void {
    $admin = $this->createUser(['administer decionis']);
    $this->container->get('current_user')->setAccount($admin);
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'ROLLBACK-2', 'price' => new Price('100', 'USD'), 'status' => 1]);
    $variation->save();
    $this->container->get(Cost::class)->override($variation, 60.0);
    $variation->setPrice(new Price('57.6', 'USD'));
    $variation->validate();
    $entries = Decision::loadMultiple();
    $entry = reset($entries);
    $this->assertSame((string) $admin->id(), $entry->value('recipient'));
    $database = $this->container->get('database');
    $transaction = $database->startTransaction();
    $variation->save();
    $this->assertSame('1', Decision::load($entry->id())->value('save_confirmed'));
    $transaction->rollBack();
    unset($transaction);
    $this->container->get('entity_type.manager')->getStorage('decionis_decision')->resetCache();
    $this->assertSame('0', Decision::load($entry->id())->value('save_confirmed'));
    $this->container->get(Deferred::class)->process((int) $entry->id());
    $this->assertSame(0, $this->container->get(Nudges::class)->deliver());

    // Another save in this request does not requalify the failed attempt.
    $variation->save();
    $this->assertSame('0', Decision::load($entry->id())->value('save_confirmed'));

    // If capture itself was within the failed native transaction, its row
    // disappears completely and the ledger must not reinsert it afterward.
    $before = count(Decision::loadMultiple());
    $transaction = $database->startTransaction();
    $variation->setPrice(new Price('55', 'USD'));
    $variation->validate();
    $variation->save();
    $this->assertCount($before + 1, Decision::loadMultiple());
    $transaction->rollBack();
    unset($transaction);
    $this->container->get('entity_type.manager')->getStorage('decionis_decision')->resetCache();
    $this->assertCount($before, Decision::loadMultiple());
  }

  /**
   * A worker already holding the lease wins; an interrupted lease is recovered.
   */
  public function testExpiredLeaseRecoversWithoutDuplicateResolution(): void {
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'LEASE-2', 'price' => new Price('100', 'USD'), 'status' => 1]);
    $variation->save();
    $this->container->get(Cost::class)->override($variation, 60.0);
    $variation->setPrice(new Price('57.6', 'USD'));
    $variation->validate();
    $variation->save();
    $entries = Decision::loadMultiple();
    $entry = reset($entries);
    $id = (int) $entry->id();
    $this->assertNotNull($this->container->get(Ledger::class)->claim($id, time()));
    $this->container->get(Deferred::class)->process($id);
    $this->assertFalse(is_file($this->requests));
    $this->container->get('database')->update('decionis_decision')->fields(['lease_expires' => time() - 1])->condition('id', $id)->execute();
    $this->assertSame(1, $this->container->get(Deferred::class)->dispatch());
    $this->container->get(Deferred::class)->process($id);
    $this->container->get(Deferred::class)->process($id);
    $this->assertCount(1, file($this->requests, FILE_IGNORE_NEW_LINES));
    $this->assertSame('BLOCK', Decision::load($id)->value('verdict'));
    $this->assertCount(1, Decision::loadMultiple());
  }

  /**
   * A synthetic non-production authority records bounded requests and delays.
   */
  private function slowServer(): int {
    $this->router = sys_get_temp_dir() . '/decionis-shadow-' . getmypid() . '.php';
    $this->requests = $this->router . '.requests';
    $answer = [
      'outcome' => 'REJECT',
      'confidence' => 0.9,
      'policy_version' => 'shadow-test-v1',
      'objective_profile' => 'synthetic',
      'dossier_id' => '18962a2d-6842-4f6a-9ff4-901c50acaed8',
      'evaluation_id' => '5a7cb7e8-507a-4bba-ad6f-98554e418d72',
      'mode' => 'SHADOW',
      'fallback_to_legacy' => FALSE,
      'idempotent_replay' => FALSE,
      'reason_codes' => ['price_below_cost'],
      'governance_metrics' => ['override_drift_rate' => 0, 'governance_tension_index' => 0, 'boundary_volatility_score' => 0, 'decision_volume_under_governance' => 0],
    ];
    file_put_contents($this->router, '<?php file_put_contents(' . var_export($this->requests, TRUE) . ', file_get_contents("php://input") . "\n", FILE_APPEND); sleep(2); header("Content-Type: application/json"); echo ' . var_export(json_encode($answer), TRUE) . ';');
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
