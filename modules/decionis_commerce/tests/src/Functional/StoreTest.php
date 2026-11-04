<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Functional;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis\Gate\Deferred;
use Drupal\decionis\Entity\Decision;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;

/**
 * The store's real forms over HTTP, with the hook points as a store has them.
 *
 * A guard called directly proves the guard; only the form proves the guard is
 * attached. The demo authority answers: refunds above 500 escalate, and an
 * order under a 12% margin is blocked.
 *
 * @group decionis
 */
final class StoreTest extends CommerceBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_payment',
    'commerce_promotion',
    'commerce_checkout',
    'commerce_order',
    'commerce_product',
    'field',
    'key',
    'decionis',
    'decionis_commerce',
    'decionis_authority_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return array_merge(parent::getAdministratorPermissions(), [
      'administer commerce_order',
      'administer commerce_payment',
      'administer decionis',
    ]);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    FieldStorageConfig::create(['field_name' => 'field_unit_cost', 'entity_type' => 'commerce_product_variation', 'type' => 'decimal'])->save();
    FieldConfig::create(['field_name' => 'field_unit_cost', 'entity_type' => 'commerce_product_variation', 'bundle' => 'default', 'label' => 'Unit cost'])->save();
    $this->config('decionis_commerce.settings')->set('cost_field', 'field_unit_cost')->save();
    $this->config('decionis.settings')->set('mode', 'enforce')->save();
    \Drupal::state()->set(Settings::ENTITLEMENT, ['enforcement' => TRUE, 'state' => 'paid']);
    PaymentGateway::create(['id' => 'cod', 'label' => 'Pay on delivery', 'plugin' => 'manual'])->save();
    drupal_flush_all_caches();
  }

  /**
   * An order for one unit at $price with cost $cost.
   */
  private function order(string $price, string $cost, string $state = 'completed'): OrderInterface {
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'S-' . $this->randomMachineName(), 'title' => 'S', 'price' => new Price($price, 'USD'), 'field_unit_cost' => $cost, 'status' => 1]);
    $variation->save();
    Product::create(['type' => 'default', 'title' => 'P', 'stores' => [$this->store], 'variations' => [$variation], 'status' => 1])->save();
    $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => '1', 'unit_price' => new Price($price, 'USD'), 'title' => 'S']);
    $item->save();
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'mail' => 'buyer@example.com', 'uid' => $this->adminUser->id(), 'state' => $state, 'order_items' => [$item], 'placed' => time()]);
    $order->recalculateTotalPrice()->save();
    return $order;
  }

  /**
   * A completed payment for the whole of $order.
   */
  private function payment(OrderInterface $order): PaymentInterface {
    $payment = Payment::create(['type' => 'payment_manual', 'payment_gateway' => 'cod', 'order_id' => $order->id(), 'amount' => $order->getTotalPrice(), 'state' => 'completed']);
    $payment->save();
    return $payment;
  }

  /**
   * Submits the refund form for $amount.
   */
  private function refund(PaymentInterface $payment, string $amount): void {
    $this->drupalGet('/admin/commerce/orders/' . $payment->getOrderId() . '/payments/' . $payment->id() . '/operation/refund');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm(['payment[amount][number]' => $amount], 'Refund');
  }

  /**
   * The amount refunded so far, read fresh.
   */
  private function refunded(PaymentInterface $payment): string {
    $storage = \Drupal::entityTypeManager()->getStorage('commerce_payment');
    $storage->resetCache([$payment->id()]);
    $fresh = $storage->load($payment->id());
    $this->assertInstanceOf(PaymentInterface::class, $fresh);
    return (string) ($fresh->getRefundedAmount()?->getNumber() ?? '0');
  }

  /**
   * The state of $order, read fresh.
   */
  private function state(OrderInterface $order): string {
    $storage = \Drupal::entityTypeManager()->getStorage('commerce_order');
    $storage->resetCache([$order->id()]);
    $fresh = $storage->load($order->id());
    $this->assertInstanceOf(OrderInterface::class, $fresh);
    return $fresh->getState()->getId();
  }

  /**
   * The decisions logged, as hook and effect.
   *
   * @return list<string>
   *   One "hook effect" per decision, oldest first.
   */
  private function decisions(): array {
    $rows = \Drupal::database()->select('decionis_decision', 'd')->fields('d', ['hook', 'effect'])->orderBy('id')->execute()->fetchAll();
    return array_map(static fn (object $row): string => $row->hook . ' ' . $row->effect, $rows);
  }

  /**
   * Tests that the refund form holds a refund the policy escalates.
   */
  public function testTheRefundFormHoldsAnEscalatedRefund(): void {
    $payment = $this->payment($this->order('1500', '100'));
    $this->refund($payment, '1200');
    $this->assertSession()->pageTextContains('This action needs approval before it can run.');
    $this->assertSession()->pageTextNotContains('Payment refunded.');
    $this->assertEquals(0, (float) $this->refunded($payment), 'no money moved');
    $this->assertSame(['refund_form held'], $this->decisions());
    $this->assertSame('1', (string) \Drupal::database()->select('decionis_hold', 'h')->countQuery()->execute()->fetchField(), 'it waits for an approver');
  }

  /**
   * Tests that a refund the policy allows runs, as a gated refund.
   */
  public function testAllowedRefundRunsAndIsNotLoggedAsOutside(): void {
    $payment = $this->payment($this->order('1500', '100'));
    $this->refund($payment, '40');
    $this->assertSession()->pageTextContains('Payment refunded.');
    $this->assertEquals(40, (float) $this->refunded($payment));
    $this->assertSame(['refund_form proceeded'], $this->decisions());
  }

  /**
   * Tests that Shadow runs the refund and says what it would have done.
   */
  public function testShadowRunsTheRefundAndWarns(): void {
    $this->config('decionis.settings')->set('mode', 'shadow')
      ->set('org_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1')->set('api_key', 'synthetic-local-authority-reference')->save();
    $payment = $this->payment($this->order('1500', '100'));
    $this->refund($payment, '1200');
    $this->assertEquals(1200, (float) $this->refunded($payment));
    $this->assertSame(['refund_form pending'], $this->decisions());
    $id = (int) \Drupal::database()->select('decionis_decision', 'd')->fields('d', ['id'])->execute()->fetchField();
    \Drupal::service(Deferred::class)->process($id);
    $decision = \Drupal::entityTypeManager()->getStorage('decionis_decision')->load($id);
    $this->assertInstanceOf(Decision::class, $decision);
    $this->assertSame('proceeded', $decision->value('effect'), implode(', ', $decision->reasons()));
    $this->assertSame('ESCALATE', $decision->value('verdict'));
    $this->assertSame('1', $decision->value('save_confirmed'));
    $this->assertSame((string) $this->adminUser->id(), $decision->value('recipient'));
    $this->assertStringContainsString('Commerce Gate would have required approval for this refund', $decision->value('nudge_text'));
    $this->drupalGet('/admin/commerce/config/decionis');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Commerce Gate would have required approval for this refund');
    $this->assertSame(['refund_form proceeded'], $this->decisions());
  }

  /**
   * Tests that a refused order shows why, and its decision stays on record.
   */
  public function testThePlaceFormShowsRefusal(): void {
    $order = $this->order('100', '96', 'draft');
    if (!$order->hasLinkTemplate('state-transition-form')) {
      $this->markTestSkipped('This Commerce has no state transition form.');
    }
    $this->drupalGet('/admin/commerce/orders/' . $order->id() . '/state/place');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Confirm');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Blocked by your Decionis policy');
    $this->assertSame('draft', $this->state($order));
    $this->assertSame(['order_place blocked'], $this->decisions());
  }

  /**
   * Tests that an order the policy allows is placed and asked about once.
   */
  public function testThePlaceFormPlacesAnAllowedOrderOnce(): void {
    $order = $this->order('100', '50', 'draft');
    if (!$order->hasLinkTemplate('state-transition-form')) {
      $this->markTestSkipped('This Commerce has no state transition form.');
    }
    $this->drupalGet('/admin/commerce/orders/' . $order->id() . '/state/place');
    $this->submitForm([], 'Confirm');
    $this->assertNotSame('draft', $this->state($order));
    $this->assertSame(['order_place proceeded'], $this->decisions());
  }

}
