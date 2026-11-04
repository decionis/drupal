<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Form\FormState;
use Drupal\decionis_commerce\Hook\CommerceHooks;
use Drupal\decionis_commerce\Map\Actions;
use Drupal\decionis_commerce\Map\Attempts;
use Drupal\decionis_commerce\Map\Cost;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Checks abuse signals against recorded activity on Commerce 2 and 3.
 *
 * Counts completed refunds and coupon codes tried in the shopper's session.
 *
 * @group decionis
 */
final class SignalsTest extends DecionisCommerceTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    PaymentGateway::create(['id' => 'manual', 'label' => 'Manual', 'plugin' => 'manual'])->save();
  }

  /**
   * Tests that the count reads this customer's other refunded payments.
   */
  public function testTheRefundCountReadsOtherRefundedPayments(): void {
    $current = $this->payment('buyer@example.com', 'refunded');
    $this->payment('buyer@example.com', 'partially_refunded');
    $this->payment('buyer@example.com', 'completed');
    $this->payment('buyer@example.com', 'refunded')->set('remote_state', 'failed')->save();
    $this->payment('someone@example.com', 'refunded');
    [, $context] = $this->container->get(Actions::class)->refund($current, new Price('10', 'USD'), 'refund_form');
    $this->assertSame(1, $context->facts['refunds_per_customer_90d'], 'not this refund, a failed provider refund, a payment never refunded, or another customer');
  }

  /**
   * Tests that coupon codes tried in the session reach the checkout facts.
   */
  public function testCouponTriesInTheSessionReachTheCheckoutFacts(): void {
    $request = Request::create('/checkout/1/order_information');
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $form = [];
    $complete = [];
    $this->container->get(CommerceHooks::class)->couponForm($form, new FormState(), $complete);
    $this->assertContains([CommerceHooks::class, 'couponTried'], $form['#element_validate']);

    $element = ['#parents' => ['coupon_redemption']];
    foreach ([['apply_coupon', NULL, 'SPRING10'], [NULL, 'primary', 'WINTER25'], ['remove_coupon_0', NULL, 'SPRING10'], ['apply_coupon', NULL, '  ']] as [$name, $type, $code]) {
      $state = new FormState();
      $state->setTriggeringElement(['#name' => $name, '#button_type' => $type]);
      $state->setValue(['coupon_redemption', 'code'], $code);
      CommerceHooks::couponTried($element, $state);
    }
    // A try from more than an hour ago no longer counts.
    $this->container->get(Attempts::class)->record(time() - Attempts::WINDOW - 60);

    $order = $this->order();
    [, $checkout] = $this->container->get(Actions::class)->order($order, 'checkout_review', TRUE);
    $this->assertSame(2, $checkout->facts['coupon_attempts_session'], 'an apply and a step submit with a code; not a removal, not an empty code, not a stale try');
    [, $admin] = $this->container->get(Actions::class)->order($order, 'order_place', FALSE);
    $this->assertArrayNotHasKey('coupon_attempts_session', $admin->facts, 'an order placed outside checkout has no shopper session');
    $this->assertStringNotContainsString('SPRING10', (string) json_encode($checkout->facts), 'no code leaves the site');
  }

  /**
   * A payment in $state on its own order for $mail.
   */
  private function payment(string $mail, string $state): PaymentInterface {
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'mail' => $mail, 'uid' => 0, 'state' => 'completed']);
    $order->save();
    $payment = Payment::create(['type' => 'payment_manual', 'payment_gateway' => 'manual', 'order_id' => $order->id(), 'amount' => new Price('100', 'USD'), 'state' => $state, 'completed' => time()]);
    $payment->save();
    return $payment;
  }

  /**
   * A draft order for one unit of a variation with a known cost.
   */
  private function order(): OrderInterface {
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'SIGNAL', 'price' => new Price('100', 'USD'), 'status' => 1]);
    $variation->save();
    $this->container->get(Cost::class)->override($variation, 60.0);
    $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => '1', 'unit_price' => $variation->getPrice()]);
    $item->save();
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'mail' => 'buyer@example.com', 'uid' => 0, 'state' => 'draft', 'order_items' => [$item]]);
    $order->recalculateTotalPrice()->save();
    return $order;
  }

}
