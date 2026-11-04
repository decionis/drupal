<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Functional;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis_commerce\Replay\Runs;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
use Drupal\Tests\decionis\Support\Loopback;

/**
 * Connect runs the first history batches before its Decisions redirect.
 *
 * The server provisions only a synthetic loopback fixture, never an account.
 *
 * @group decionis
 */
final class ConnectReplayTest extends CommerceBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['automated_cron', 'commerce_payment', 'commerce_promotion', 'commerce_checkout', 'commerce_order', 'commerce_product', 'decionis', 'decionis_commerce'];

  /**
   * The loopback API used for connection and history replay.
   */
  private ?Loopback $server = NULL;

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return [...parent::getAdministratorPermissions(), 'administer decionis', 'view decionis decisions'];
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->server?->stop();
    parent::tearDown();
  }

  /**
   * The landing page already has replay Dossiers, with the remainder queued.
   */
  public function testConnectLandsOnItsOwnHistoryBeforeCron(): void {
    $this->prepare('ok', 10);
    $this->connect();
    $this->assertSession()->addressEquals('/admin/commerce/decionis/decisions');
    $this->assertSession()->pageTextContains('The first replay results are ready');
    $this->assertSession()->pageTextNotContains('No decisions yet');
    $entries = \Drupal::entityTypeManager()->getStorage('decionis_decision')->loadByProperties(['source' => 'replay']);
    $this->assertNotEmpty($entries);
    $entry = reset($entries);
    $this->assertInstanceOf(Decision::class, $entry);
    $this->assertSame('fixture-policy-1', $entry->value('policy_version'));
    $this->assertSession()->pageTextContains($entry->value('dossier_id'));
    $this->assertSession()->linkByHrefExists('/admin/commerce/decionis/decisions/' . $entry->id() . '/verify');
    \Drupal::state()->resetCache();
    $run = \Drupal::state()->get(Runs::STATE);
    $this->assertSame('running', $run['status']);
    $this->assertSame(8, $run['reviewed']);
    $this->assertGreaterThan(0, \Drupal::queue('decionis_replay')->numberOfItems());
  }

  /**
   * Even an outage produces an explained history row, never a fake verdict.
   */
  public function testUnavailableApiLeavesHonestResultsOnTheLandingPage(): void {
    $this->prepare('outage', 1);
    $this->connect();
    $this->assertSession()->addressEquals('/admin/commerce/decionis/decisions');
    $this->assertSession()->pageTextContains('Commerce Gate could not decide 1 check in this run');
    $this->assertSession()->pageTextContains('An unavailable check is not a policy verdict');
    $this->assertSession()->pageTextNotContains('No decisions yet');
    $entries = \Drupal::entityTypeManager()->getStorage('decionis_decision')->loadByProperties(['source' => 'replay']);
    $this->assertCount(1, $entries);
    $entry = reset($entries);
    $this->assertInstanceOf(Decision::class, $entry);
    $this->assertSame('ungoverned', $entry->value('effect'));
    $this->assertSame('', $entry->value('verdict'));
    $this->assertSame('', $entry->value('dossier_id'));
  }

  /**
   * Due automated web cron retains replay; the explicit cron URL drains it.
   */
  public function testDueWebCronKeepsReplayQueuedForExplicitCron(): void {
    $this->prepare('ok', 10);
    $this->connect();
    $policyCalls = static fn (array $calls): array => array_values(array_filter($calls, static fn (array $call): bool => $call['path'] === '/v1/protocol/evaluate-decision'));
    $before = $policyCalls($this->server->stats()['calls']);
    $this->assertCount(8, $before);
    $this->config('automated_cron.settings')->set('interval', 1)->save();
    \Drupal::state()->set('system.cron_last', 0);
    $this->drupalGet('/admin/commerce/decionis/decisions');
    $this->assertSame($before, $policyCalls($this->server->stats()['calls']), 'automatic cron must not put replay HTTP back on the admin response');
    \Drupal::state()->resetCache();
    $this->assertSame('running', \Drupal::state()->get(Runs::STATE)['status']);
    $this->assertGreaterThan(0, \Drupal::queue('decionis_replay')->numberOfItems());

    $this->drupalGet('/cron/' . \Drupal::state()->get('system.cron_key'));
    $this->assertSession()->statusCodeEquals(204);
    \Drupal::state()->resetCache();
    $run = \Drupal::state()->get(Runs::STATE);
    $this->assertSame('complete', $run['status']);
    $this->assertSame(10, $run['reviewed']);
    $this->assertCount(10, $policyCalls($this->server->stats()['calls']));
  }

  /**
   * Costed synthetic history, persisted for the separate browser requests.
   */
  private function prepare(string $mode, int $count): void {
    if (FieldStorageConfig::loadByName('commerce_product_variation', 'decionis_cost') === NULL) {
      FieldStorageConfig::create(['field_name' => 'decionis_cost', 'entity_type' => 'commerce_product_variation', 'type' => 'commerce_price'])->save();
    }
    if (FieldConfig::loadByName('commerce_product_variation', 'default', 'decionis_cost') === NULL) {
      FieldConfig::create(['field_name' => 'decionis_cost', 'entity_type' => 'commerce_product_variation', 'bundle' => 'default', 'label' => 'Cost'])->save();
    }
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'CONNECT-REPLAY', 'price' => new Price('100', 'USD'), 'decionis_cost' => ['number' => '95', 'currency_code' => 'USD'], 'status' => 1]);
    $variation->save();
    for ($index = 0; $index < $count; $index++) {
      $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => '1', 'unit_price' => $variation->getPrice()]);
      $item->save();
      $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'state' => 'completed', 'placed' => time() - 86400, 'order_items' => [$item]]);
      $order->recalculateTotalPrice()->save();
    }
    $this->server = new Loopback($mode);
    $this->config('decionis.settings')->set('connect_url', $this->server->url)->set('api_base_url', $this->server->url)->set('jwks_url', $this->server->url . '/jwks')->set('policy_version', 'fixture-policy-1')->save();
    $this->config('decionis_commerce.settings')->set('replay_concurrency', 4)->save();
  }

  /**
   * Submits the real connect form and follows the Batch API progress page.
   */
  private function connect(): void {
    $this->drupalGet('/admin/commerce/config/decionis/connect');
    $this->submitForm(['business_name' => 'Synthetic replay store', 'billing_email' => 'billing@example.com', 'terms' => TRUE], 'Connect to Commerce Gate');
  }

}
