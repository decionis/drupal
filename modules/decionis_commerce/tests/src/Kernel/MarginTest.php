<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\decionis_commerce\Map\Actions;
use Drupal\decionis_commerce\Map\Cost;

/**
 * What the margin check sends for an order with discounts, tax and shipping.
 *
 * @group decionis
 */
final class MarginTest extends DecionisCommerceTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['decionis_shipping_test'];

  /**
   * The facts sent for one unit at $unit, cost 60, with these adjustments.
   *
   * @param string $unit
   *   The unit price in USD before adjustments.
   * @param list<array{string, string, bool}> $itemAdjustments
   *   Type, amount, included.
   * @param list<array{string, string, bool}> $orderAdjustments
   *   Type, amount, included.
   *
   * @return array{float, float, float}
   *   Gross, discount and revenue after discount.
   */
  private function facts(string $unit, array $itemAdjustments = [], array $orderAdjustments = []): array {
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'M-' . random_int(1, PHP_INT_MAX), 'price' => new Price($unit, 'USD'), 'status' => 1]);
    $variation->save();
    $this->container->get(Cost::class)->override($variation, 60.0);
    $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => '1', 'unit_price' => new Price($unit, 'USD'), 'title' => 'M']);
    foreach ($itemAdjustments as $adjustment) {
      $item->addAdjustment(self::adjustment(...$adjustment));
    }
    $item->save();
    // Not a draft: refreshing a draft would replace these adjustments.
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'mail' => 'margin@example.com', 'state' => 'completed', 'order_items' => [$item]]);
    foreach ($orderAdjustments as $adjustment) {
      $order->addAdjustment(self::adjustment(...$adjustment));
    }
    $order->recalculateTotalPrice()->save();
    [$action] = $this->container->get(Actions::class)->order($order, 'order_place', FALSE);
    $payload = $action->toArray()['payload'];
    return [(float) $payload['gross_amount'], (float) $payload['discount_amount'], (float) $payload['gross_amount'] - (float) $payload['discount_amount']];
  }

  /**
   * An adjustment of $type for $amount.
   */
  private static function adjustment(string $type, string $amount, bool $included): Adjustment {
    return new Adjustment(['type' => $type, 'label' => $type, 'amount' => new Price($amount, 'USD'), 'included' => $included]);
  }

  /**
   * Tests that a discount shown off the price counts as much as one below it.
   *
   * Commerce lowers the unit price of a display-inclusive offer and keeps the
   * adjustment as included: a $100 item at 25% off sells for $75 either way.
   */
  public function testIncludedDiscountIsTakenOffOnce(): void {
    $this->assertSame([100.0, 25.0, 75.0], $this->facts('75', [['promotion', '-25', TRUE]]));
    $this->assertSame([100.0, 25.0, 75.0], $this->facts('100', [['promotion', '-25', FALSE]]));
    $this->assertSame([100.0, 25.0, 75.0], $this->facts('100', [], [['promotion', '-25', FALSE]]));
  }

  /**
   * Tests that tax inside the price is not counted as revenue.
   */
  public function testTaxInsideThePriceIsNotRevenue(): void {
    $this->assertSame([100.0, 0.0, 100.0], $this->facts('120', [['tax', '20', TRUE]]));
    $this->assertSame([100.0, 0.0, 100.0], $this->facts('100', [], [['tax', '20', FALSE]]), 'tax added on top was never in gross');
  }

  /**
   * Tests that free shipping counts against the margin.
   */
  public function testFreeShippingOfferCountsAsDiscount(): void {
    $this->assertSame([110.0, 10.0, 100.0], $this->facts('100', [], [['shipping', '10', FALSE], ['shipping_promotion', '-10', FALSE]]));
  }

}
