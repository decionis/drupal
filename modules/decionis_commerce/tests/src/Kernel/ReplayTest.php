<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_promotion\Entity\Promotion;
use Drupal\decionis_commerce\Map\CostFields;
use Drupal\decionis\Report\Summary;
use Drupal\decionis_commerce\Replay\Replay;
use Drupal\decionis_commerce\Sandbox\Local;

/**
 * Checks the 90-day Shadow replay against synthetic store history.
 *
 * Shows the refund and margin value the policy would have held, changes
 * nothing, and leaves out Fincra's failed payments recorded as refunded.
 *
 * @group decionis
 */
final class ReplayTest extends DecionisCommerceTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get(CostFields::class)->ensure();
    PaymentGateway::create(['id' => 'manual', 'label' => 'Manual', 'plugin' => 'manual'])->save();
  }

  /**
   * Tests that replay shows held refund and margin value.
   */
  public function testReplayShowsHeldRefundAndMarginValue(): void {
    $healthy = $this->variation('HEALTHY', '100', '60');
    $thin = $this->variation('THIN', '50', '46');
    $uncosted = $this->variation('UNCOSTED', '30', NULL);

    $this->order($healthy, 1);
    $thinOrder = $this->order($thin, 2);
    $this->order($uncosted, 1);
    $big = $this->order($healthy, 15);
    $this->refunded($big, '1200');
    $this->refunded($this->order($healthy, 1), '40');
    $failed = $this->refunded($this->order($healthy, 1), '100', 'failed');
    Promotion::create(['name' => 'Spring', 'order_types' => ['default'], 'stores' => [$this->store->id()], 'status' => 1, 'compatibility' => 'any', 'offer' => ['target_plugin_id' => 'order_percentage_off', 'target_plugin_configuration' => ['percentage' => '0.25']]])->save();
    Promotion::create(['name' => 'Stacked', 'order_types' => ['default'], 'stores' => [$this->store->id()], 'status' => 1, 'compatibility' => 'any', 'offer' => ['target_plugin_id' => 'order_percentage_off', 'target_plugin_configuration' => ['percentage' => '0.20']]])->save();

    $orders = $this->snapshotOf(['commerce_order', 'commerce_payment', 'commerce_promotion']);
    $totals = $this->container->get(Replay::class)->run(90, new Local());

    $this->assertSame($orders, $this->snapshotOf(['commerce_order', 'commerce_payment', 'commerce_promotion']), 'the replay is read-only');
    $refunds = $totals['by_action']['refund_request']['USD'];
    $this->assertSame(2, $refunds['count'], 'the failed payment recorded as refunded is left out');
    $this->assertEqualsWithDelta(1240.0, $refunds['covered'], 0.001);
    $this->assertEqualsWithDelta(1200.0, $refunds['held'], 0.001, 'the refund above the unattended limit would have been held');
    $orders = $totals['by_action']['order_acceptance']['USD'];
    $this->assertEqualsWithDelta(100.0, $orders['held'], 0.001, 'the order under the 12% margin floor would have been blocked');
    $this->assertSame(1, $totals['not_covered'], 'the order without a cost base is reported, not guessed');
    $this->assertSame(2, $totals['by_action']['promotion_change']['']['count'], 'promotions were reviewed');
    $storage = $this->container->get('entity_type.manager')->getStorage('decionis_decision');
    $this->assertCount(0, $storage->loadByProperties(['target_id' => (string) $failed->id(), 'action' => 'refund_request']));
    $this->assertNotEmpty($storage->loadByProperties(['target_id' => (string) $thinOrder->id(), 'verdict' => 'BLOCK', 'source' => 'replay']));
    $ids = array_keys($storage->loadByProperties(['source' => 'replay']));
    $again = $this->container->get(Replay::class)->run(90, new Local());
    $this->assertSame($totals, $again, 'a second run reports all touched rows, including existing ids');
    $this->assertSame($ids, array_keys($storage->loadByProperties(['source' => 'replay'])), 'replaying the same policy does not double the ledger');
  }

  /**
   * Tests that the demo replay leaves nothing in the decision log.
   */
  public function testDemoReplayRecordsNothing(): void {
    $this->refunded($this->order($this->variation('DEMO', '100', '60'), 15), '1200');
    $totals = $this->container->get(Replay::class)->demo(90);
    $this->assertSame('demo authority', $totals['label']);
    $this->assertEqualsWithDelta(1200.0, $totals['by_action']['refund_request']['USD']['held'], 0.001);
    $this->assertCount(0, $this->container->get('entity_type.manager')->getStorage('decionis_decision')->loadMultiple(), 'the demo verdicts were rolled back');
    $this->assertSame(0, $this->container->get(Summary::class)->totals(0)['reviewed']);
  }

  /**
   * Creates a variation with an optional unit cost.
   */
  private function variation(string $sku, string $price, ?string $cost): ProductVariation {
    $variation = ProductVariation::create(['type' => 'default', 'sku' => $sku, 'price' => new Price($price, 'USD'), 'status' => 1]);
    if ($cost !== NULL) {
      $variation->set('decionis_cost', ['number' => $cost, 'currency_code' => 'USD']);
    }
    $variation->save();
    return $variation;
  }

  /**
   * Creates a completed order for one variation, placed five days ago.
   */
  private function order(ProductVariation $variation, int $quantity): Order {
    $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => (string) $quantity, 'unit_price' => $variation->getPrice()]);
    $item->save();
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'mail' => 'buyer@example.com', 'uid' => 0, 'state' => 'completed', 'placed' => time() - 5 * 86400, 'order_items' => [$item]]);
    $order->recalculateTotalPrice();
    $order->save();
    return $order;
  }

  /**
   * Records a refunded payment for an order.
   */
  private function refunded(Order $order, string $amount, ?string $remoteState = NULL): Payment {
    $refunded = new Price($amount, 'USD');
    $payment = Payment::create(['type' => 'payment_manual', 'payment_gateway' => 'manual', 'order_id' => $order->id(), 'amount' => $order->getTotalPrice(), 'refunded_amount' => $remoteState === 'failed' ? $order->getTotalPrice() : $refunded, 'state' => $refunded->equals($order->getTotalPrice()) || $remoteState === 'failed' ? 'refunded' : 'partially_refunded', 'remote_state' => $remoteState, 'completed' => time() - 4 * 86400]);
    $payment->save();
    return $payment;
  }

  /**
   * Restricts the database snapshot to the tables under comparison.
   *
   * @param list<string> $tables
   *   The database table names to include.
   *
   * @return array<string, string>
   *   Row counts and content hashes for the selected tables.
   */
  private function snapshotOf(array $tables): array {
    return array_intersect_key($this->snapshot(), array_flip($tables));
  }

}
