<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis_commerce\Map\Cost;
use Drupal\decionis_commerce\Sandbox\Local;

/**
 * Checks refusal at the order-place transition outside a form.
 *
 * The refusal undoes the order's save, but the decision stays on record.
 *
 * @group decionis
 */
final class PlaceTest extends DecionisCommerceTestBase {

  /**
   * Tests that a refused order stays a draft and its decision survives.
   */
  public function testRefusedOrderKeepsItsDecision(): void {
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'THIN', 'price' => new Price('100', 'USD'), 'status' => 1]);
    $variation->save();
    $this->container->get(Cost::class)->override($variation, 96.0);
    $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => '1', 'unit_price' => new Price('100', 'USD'), 'title' => 'THIN']);
    $item->save();
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'mail' => 'place@example.com', 'state' => 'draft', 'order_items' => [$item]]);
    $order->recalculateTotalPrice()->save();

    $this->container->get(Gate::class)->within('enforce', new Local(), 'live', function () use ($order): void {
      try {
        $order->getState()->applyTransitionById('place');
        $order->save();
        $this->fail('The order was placed');
      }
      catch (EntityStorageException $error) {
        $this->assertStringContainsString('Blocked by your Decionis policy', $error->getMessage());
      }
    });

    $storage = $this->container->get('entity_type.manager')->getStorage('commerce_order');
    $storage->resetCache([$order->id()]);
    $reloaded = $storage->load($order->id());
    $this->assertInstanceOf(OrderInterface::class, $reloaded);
    $this->assertSame('draft', $reloaded->getState()->getId());
    $decisions = $this->container->get('database')->select('decionis_decision', 'd')->fields('d', ['hook', 'effect', 'source'])->execute()->fetchAll();
    $this->assertSame([['hook' => 'order_place', 'effect' => 'blocked', 'source' => 'live']], array_map(static fn (object $row): array => (array) $row, $decisions), 'written once, after the rollback');
  }

  /**
   * Tests that a decision in a transaction that commits is not written twice.
   */
  public function testCommittedDecisionIsNotWrittenTwice(): void {
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'GOOD', 'price' => new Price('100', 'USD'), 'status' => 1]);
    $variation->save();
    $this->container->get(Cost::class)->override($variation, 50.0);
    $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => '1', 'unit_price' => new Price('100', 'USD'), 'title' => 'GOOD']);
    $item->save();
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'mail' => 'place@example.com', 'state' => 'draft', 'order_items' => [$item]]);
    $order->recalculateTotalPrice()->save();

    $this->container->get(Gate::class)->within('enforce', new Local(), 'live', function () use ($order): void {
      $order->getState()->applyTransitionById('place');
      $order->save();
    });

    $this->assertSame('1', (string) $this->container->get('database')->select('decionis_decision', 'd')->countQuery()->execute()->fetchField());
  }

}
