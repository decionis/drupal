<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis\Gate\Deferred;
use Drupal\decionis_commerce\Report\History;
use Drupal\decionis_commerce\Report\Xray;
use Drupal\Tests\decionis\Support\Scripted;

/**
 * Refunds that reach a payment without the refund gate are logged.
 *
 * A provider's webhook reporting a refund made in its own dashboard is logged
 * as made outside Decionis, with the Shadow verdict it would have had. A
 * failed charge that a gateway records as refunded is not a refund.
 *
 * @group decionis
 */
final class OutsideRefundTest extends DecionisCommerceTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    PaymentGateway::create(['id' => 'manual', 'label' => 'Manual', 'plugin' => 'manual'])->save();
  }

  /**
   * A completed payment of $amount.
   */
  private function payment(string $amount): Payment {
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'mail' => 'buyer@example.com', 'uid' => 0, 'state' => 'completed']);
    $order->save();
    $payment = Payment::create(['type' => 'payment_manual', 'payment_gateway' => 'manual', 'order_id' => $order->id(), 'amount' => new Price($amount, 'USD'), 'state' => 'completed']);
    $payment->save();
    return $payment;
  }

  /**
   * The log entries for a payment.
   *
   * @return array<int, \Drupal\Core\Entity\EntityInterface>
   *   The entries.
   */
  private function entries(Payment $payment): array {
    return $this->container->get('entity_type.manager')->getStorage('decionis_decision')->loadByProperties(['target_id' => (string) $payment->id(), 'action' => 'refund_request']);
  }

  /**
   * Tests that a webhook refund is logged as made outside Decionis.
   */
  public function testWebhookRefundIsLoggedAsOutside(): void {
    $payment = $this->payment('900');
    $gate = $this->container->get(Gate::class);
    $gate->within('shadow', new Scripted('REVIEW'), 'webhook', function () use ($payment): void {
      // What a gateway webhook does after a refund in the provider dashboard.
      $payment->setState('refunded');
      $payment->setRefundedAmount(new Price('900', 'USD'));
      $payment->save();
    });
    $entries = $this->entries($payment);
    $this->assertCount(1, $entries);
    $entry = reset($entries);
    $this->assertInstanceOf(Decision::class, $entry);
    $this->assertSame('outside', $entry->get('effect')->value);
    $this->assertSame('ESCALATE', $entry->get('verdict')->value, 'with the verdict it would have had');
    $this->assertSame('shadow', $entry->get('mode')->value);
    $this->assertEquals(900, $entry->get('at_stake')->value);
  }

  /**
   * Tests that a failed charge recorded as refunded is not logged.
   */
  public function testFailedChargeRecordedAsRefundedIsNotRefund(): void {
    $payment = $this->payment('500');
    $payment->setRemoteState('failed');
    $payment->setState('refunded');
    $payment->save();
    $this->assertSame([], $this->entries($payment));
  }

  /**
   * A disconnected deferred review cannot erase a real outside refund.
   */
  public function testDeferredUnavailableReviewPreservesOutsideCoverage(): void {
    $payment = $this->payment('900');
    $payment->setState('refunded');
    $payment->setRefundedAmount(new Price('900', 'USD'));
    $payment->save();
    $entries = $this->entries($payment);
    $this->assertCount(1, $entries);
    $entry = reset($entries);
    $this->assertInstanceOf(Decision::class, $entry);
    $this->assertSame('pending', $entry->value('effect'));
    $this->container->get(Deferred::class)->process((int) $entry->id());
    $fresh = Decision::load($entry->id());
    $this->assertSame('outside', $fresh->value('effect'));
    $this->assertSame('', $fresh->value('verdict'));
    $this->assertSame(['NOT_CONNECTED'], $fresh->reasons());
    $batch = $this->container->get(History::class)->batch('outside', 90, 0, time() + 1);
    $report = Xray::collect($batch['records'], []);
    $this->assertSame(1, $report['outside']);
    $this->assertSame(1, $report['currencies']['USD']['outside_refunds']);
    $this->assertSame(900.0, $report['currencies']['USD']['outside_value']);
  }

}
