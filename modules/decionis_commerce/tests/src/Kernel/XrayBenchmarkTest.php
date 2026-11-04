<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\decionis_commerce\Map\CostFields;
use Drupal\decionis_commerce\Replay\Replay;
use Drupal\decionis_commerce\Sandbox\Isolation;

/**
 * Reproducible X-ray measurement on 5,000 synthetic NGN orders in every CI row.
 *
 * @group decionis
 */
final class XrayBenchmarkTest extends DecionisCommerceTestBase {

  /**
   * Measures the full two-pass offline scan, including local limit drafting.
   */
  public function testFiveThousandOrders(): void {
    $this->container->get('commerce_price.currency_importer')->import('NGN');
    $this->container->get(CostFields::class)->ensure();
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'BENCHMARK-NGN', 'price' => new Price('100000', 'NGN'), 'decionis_cost' => new Price('90000', 'NGN'), 'status' => 1]);
    $variation->save();
    $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => '1', 'unit_price' => $variation->getPrice()]);
    $item->save();
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'state' => 'completed', 'placed' => time() - 86400, 'order_items' => [$item]]);
    $order->recalculateTotalPrice()->save();
    $this->history($order, $item, 5000);
    $this->container->get(Isolation::class)->prepare();
    $started = hrtime(TRUE);
    $report = $this->container->get(Replay::class)->demo(90);
    $seconds = (hrtime(TRUE) - $started) / 1e9;
    $this->assertSame(5000, $report['currencies']['NGN']['order_lines']);
    $this->assertSame(5000, $report['currencies']['NGN']['costed_lines']);
    $this->assertSame(5000, $report['by_action']['order_acceptance']['NGN']['count']);
    $this->assertSame(0, (int) $this->container->get('database')->select('decionis_decision', 'd')->countQuery()->execute()->fetchField());
    $measurement = ['orders' => 5000, 'currency' => 'NGN', 'seconds' => round($seconds, 3), 'database' => $this->container->get('database')->databaseType(), 'drupal' => \Drupal::VERSION, 'php' => PHP_VERSION];
    // Drupal's isolated PHPUnit runner captures successful-test stdout. CI
    // supplies an artifact path so the measurement survives that boundary.
    $reportPath = getenv('DECIONIS_XRAY_BENCHMARK_FILE');
    if (is_string($reportPath) && $reportPath !== '') {
      file_put_contents($reportPath, json_encode($measurement, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
    }
  }

  /**
   * Clones synthetic rows efficiently; each order has its own order item.
   *
   * Fixture creation is outside the measured run and never contacts a store.
   */
  private function history(Order $order, OrderItem $item, int $count): void {
    $database = $this->container->get('database');
    $tables = [
      'commerce_order' => ['order_id' => $order->id()],
      'commerce_order_item' => ['order_item_id' => $item->id()],
      'commerce_order__order_items' => ['entity_id' => $order->id()],
    ];
    foreach ($tables as $table => $condition) {
      $query = $database->select($table, 't')->fields('t');
      foreach ($condition as $column => $value) {
        $query->condition($column, $value);
      }
      $template = (array) $query->execute()->fetchObject();
      for ($offset = 1; $offset < $count; $offset += 20) {
        $insert = $database->insert($table)->fields(array_keys($template));
        for ($i = $offset; $i < min($count, $offset + 20); $i++) {
          $row = $template;
          $orderId = (int) $order->id() + $i;
          $itemId = (int) $item->id() + $i;
          foreach (['order_id', 'entity_id', 'revision_id'] as $column) {
            if (array_key_exists($column, $row)) {
              $row[$column] = $orderId;
            }
          }
          foreach (['order_item_id', 'order_items_target_id'] as $column) {
            if (array_key_exists($column, $row)) {
              $row[$column] = $itemId;
            }
          }
          if (isset($row['uuid'])) {
            $row['uuid'] = $this->container->get('uuid')->generate();
          }
          $insert->values($row);
        }
        $insert->execute();
      }
    }
    $this->container->get('entity_type.manager')->getStorage('commerce_order')->resetCache();
    $this->container->get('entity_type.manager')->getStorage('commerce_order_item')->resetCache();
  }

}
