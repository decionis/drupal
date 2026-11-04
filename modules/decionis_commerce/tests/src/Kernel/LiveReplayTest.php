<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Session\UserSession;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Report\Summary;
use Drupal\decionis_commerce\Map\Cost;
use Drupal\decionis_commerce\Plugin\QueueWorker\ReplayWorker;
use Drupal\decionis_commerce\Replay\Replay;
use Drupal\decionis_commerce\Replay\Runs;
use Drupal\Tests\decionis\Support\ConnectApi;
use Drupal\Tests\decionis\Support\Loopback;

/**
 * Remote replay uses only the declared client contract against a local fixture.
 *
 * @group decionis
 */
final class LiveReplayTest extends DecionisCommerceTestBase {

  /**
   * The loopback API used to evaluate synthetic historical actions.
   */
  private Loopback $server;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->server = new Loopback();
    $this->container->get('state')->set(Binding::STATE, ['org_id' => ConnectApi::ORG, 'generation' => 'synthetic-generation-1']);
    $this->container->get(Credentials::class)->store('api_key', Credentials::API_KEY, 'Synthetic replay fixture', ConnectApi::API_KEY);
    $this->config('decionis.settings')->set('api_base_url', $this->server->url)->set('jwks_url', $this->server->url . '/jwks')->set('policy_version', 'fixture-policy-1')->save();
    $this->config('decionis_commerce.settings')->set('replay_concurrency', 4)->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->server->stop();
    parent::tearDown();
  }

  /**
   * Stable policy-bound keys and upserts give the same totals on repeat runs.
   */
  public function testRemoteReplaysKeepTotalsAndBindKeysToPolicy(): void {
    for ($index = 0; $index < 12; $index++) {
      $this->order($index);
    }
    $replay = $this->container->get(Replay::class);
    $first = $replay->run(90);
    $storage = $this->container->get('entity_type.manager')->getStorage('decionis_decision');
    $ids = array_keys($storage->loadByProperties(['source' => 'replay']));
    $this->container->get('current_user')->setAccount(new UserSession(['uid' => 37]));
    $this->assertSame($first, $replay->run(90), 'cron and an admin get the same replay results');
    $this->assertSame($ids, array_keys($storage->loadByProperties(['source' => 'replay'])));
    $this->assertSame(12, $first['reviewed']);
    $this->assertSame(0, $first['ungoverned']);
    $run = $this->container->get(Runs::class)->current();
    $this->assertSame('complete', $run['status']);
    $this->assertSame('fixture-policy-1', $run['policy']);
    $this->assertSame($ids, $run['ids'], 'a direct Drush replay also refreshes the UI report');
    $stats = $this->server->stats();
    $this->assertSame(4, $stats['peak']);
    $calls = array_values(array_filter($stats['calls'], static fn (array $call): bool => $call['path'] === '/v1/protocol/evaluate-decision'));
    $this->assertCount(24, $calls);
    $keys = array_unique(array_column($calls, 'key'));
    $this->assertCount(12, $keys);
    foreach ($calls as $call) {
      $this->assertSame('fixture-policy-1', $call['policy']);
      $this->assertTrue($call['exact']);
      $this->assertSame(['type' => 'WORKFLOW', 'id' => 'drupal:replay'], $call['actor']);
    }
    $this->config('decionis.settings')->set('policy_version', 'fixture-policy-2')->save();
    $this->assertSame(12, $replay->run(90)['reviewed']);
    $this->assertSame('fixture-policy-2', $this->container->get(Runs::class)->current()['policy']);
    $this->assertCount(24, $storage->loadByProperties(['source' => 'replay']));
    $new = array_slice($this->server->stats()['calls'], 24);
    $this->assertSame([], array_values(array_intersect($keys, array_column($new, 'key'))), 'a new policy can never hit an old idempotency cache entry');
  }

  /**
   * The first actual decision resolves the policy before concurrent dispatch.
   */
  public function testUnpinnedRunResolvesOnePolicyBeforeDispatchingTheRest(): void {
    $this->config('decionis.settings')->set('policy_version', '')->save();
    for ($index = 0; $index < 9; $index++) {
      $this->order($index);
    }
    $this->assertSame(9, $this->container->get(Replay::class)->run(90)['reviewed']);
    $calls = $this->server->stats()['calls'];
    $this->assertCount(9, $calls);
    $this->assertNull($calls[0]['policy']);
    foreach (array_slice($calls, 1) as $call) {
      $this->assertSame('fixture-policy-1', $call['policy']);
      $this->assertTrue($call['exact']);
      $this->assertGreaterThanOrEqual($calls[0]['finished'], $call['started']);
    }
  }

  /**
   * A queued cursor is never transferred to another connection or old item.
   */
  public function testQueuedReplayCancelsOnReconnectAndIgnoresUnboundItems(): void {
    $this->order(1);
    $runs = $this->container->get(Runs::class);
    $id = $runs->start(90);
    $connection = $this->container->get('state')->get(Binding::STATE);
    $connection['generation'] = 'synthetic-generation-2';
    $this->container->get('state')->set(Binding::STATE, $connection);
    $worker = ReplayWorker::create($this->container, [], 'decionis_replay', []);
    $worker->processItem(['run' => $id]);
    $worker->processItem(['kind' => 'order', 'days' => 90, 'after' => 0]);
    $this->assertSame('connection_changed', $runs->current()['status']);
    $this->assertSame([], $this->server->stats()['calls']);
    $this->assertSame([], $runs->current()['ids']);
  }

  /**
   * Browser and cron share one cursor, frozen policy and set of result ids.
   */
  public function testFirstBatchAndCronResumeTheSameFrozenRun(): void {
    for ($index = 0; $index < 10; $index++) {
      $this->order($index);
    }
    $runs = $this->container->get(Runs::class);
    $id = $runs->start(90);
    $until = $runs->current()['until'];
    $this->config('decionis.settings')->set('policy_version', 'fixture-policy-2')->save();
    $initial = $runs->advance($id);
    $this->assertSame(8, $initial['reviewed']);
    $this->assertSame('running', $initial['status']);
    $worker = ReplayWorker::create($this->container, [], 'decionis_replay', []);
    for ($step = 0; $step < 6 && $runs->current()['status'] === 'running'; $step++) {
      $worker->processItem(['run' => $id]);
    }
    $run = $runs->current();
    $this->assertSame('complete', $run['status']);
    $this->assertSame(10, $run['reviewed']);
    $this->assertCount(10, $run['ids']);
    $this->assertSame($until, $run['until']);
    $this->assertSame('fixture-policy-1', $run['policy']);
    $this->assertStringNotContainsString(ConnectApi::API_KEY, json_encode($run, JSON_THROW_ON_ERROR));
    $worker->processItem(['run' => $id]);
    $calls = $this->server->stats()['calls'];
    $this->assertCount(10, $calls, 'finished or duplicate queue items do not replay completed work');
    foreach ($calls as $call) {
      $this->assertSame('fixture-policy-1', $call['policy']);
    }
  }

  /**
   * A 365-day replay does not turn an old action into a recent source event.
   */
  public function testReplayKeepsSourceDatesForNinetyDayReports(): void {
    $this->order(1, 120);
    $this->assertSame(1, $this->container->get(Replay::class)->run(365)['reviewed']);
    $this->assertSame(0, $this->container->get(Summary::class)->totals(time() - 90 * 86400, 'replay')['reviewed']);
  }

  /**
   * A server returning a different policy never supplies a trusted verdict.
   */
  public function testPolicyMismatchIsUngoverned(): void {
    $this->server->stop();
    $this->server = new Loopback('mismatch');
    $this->config('decionis.settings')->set('api_base_url', $this->server->url)->save();
    $this->order(1);
    $totals = $this->container->get(Replay::class)->run(90);
    $this->assertSame(1, $totals['ungoverned']);
    $this->assertSame(0.0, $totals['by_action']['order_acceptance']['USD']['held']);
  }

  /**
   * Creates a costed synthetic order with no shopper data.
   */
  private function order(int $index, int $days = 5): void {
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'LIVE-REPLAY-' . $index, 'price' => new Price('100', 'USD'), 'status' => 1]);
    $variation->save();
    $this->container->get(Cost::class)->override($variation, 95.0);
    $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => '1', 'unit_price' => $variation->getPrice()]);
    $item->save();
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'state' => 'completed', 'placed' => time() - $days * 86400, 'order_items' => [$item]]);
    $order->recalculateTotalPrice()->save();
  }

}
