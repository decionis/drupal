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
use Drupal\decionis\Gate\Authority;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis\Gate\Ledger;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis_commerce\Form\XrayForm;
use Drupal\decionis_commerce\Map\CostFields;
use Drupal\decionis_commerce\Replay\Replay;
use Drupal\decionis_commerce\Report\Limits;
use Drupal\decionis_commerce\Report\Xray;
use Drupal\decionis_commerce\Report\XrayBatch;
use Drupal\decionis_commerce\Sandbox\Isolation;
use GuzzleHttp\ClientInterface;

/**
 * The own-store X-ray is offline, currency-aware and leaves history intact.
 *
 * @group decionis
 */
final class XrayTest extends DecionisCommerceTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('commerce_price.currency_importer')->import('NGN');
    $this->container->get('commerce_price.currency_importer')->import('EUR');
    $this->container->get(CostFields::class)->ensure();
    PaymentGateway::create(['id' => 'manual', 'label' => 'Manual', 'plugin' => 'manual'])->save();
  }

  /**
   * NGN and EUR keep distinct limits, totals, shortfalls and cost coverage.
   */
  public function testCurrenciesStaySeparateAndNoNetworkOrDecisionWriteOccurs(): void {
    $this->config('decionis_commerce.settings')->set('limits', [
      'NGN' => ['refund_limit' => 150000.0, 'margin_floor' => 12.0, 'stacking_ceiling' => 30.0, 'repeat_limit' => 3, 'source' => 'tuned'],
      'EUR' => ['refund_limit' => 100.0, 'margin_floor' => 10.0, 'stacking_ceiling' => 30.0, 'repeat_limit' => 3, 'source' => 'tuned'],
    ])->save();
    $naira = $this->order('NGN', '200000', '190000');
    $euro = $this->order('EUR', '200', '190');
    $this->order('NGN', '2000', NULL);
    $this->refund($naira, '160000');
    $this->refund($euro, '120');
    $this->refund($euro, '200', 'failed');
    foreach (['0.25', '0.20'] as $percentage) {
      Promotion::create(['name' => 'Do not expose this promotion name', 'order_types' => ['default'], 'stores' => [$this->store->id()], 'status' => 1, 'compatibility' => 'any', 'offer' => ['target_plugin_id' => 'order_percentage_off', 'target_plugin_configuration' => ['percentage' => $percentage]]])->save();
    }
    $this->container->get('database')->insert('decionis_decision')->fields([
      'uuid' => \Drupal::service('uuid')->generate(),
      'action' => 'refund_request',
      'source' => 'webhook',
      'hook' => 'payment_update',
      'mode' => 'shadow',
      'effect' => 'outside',
      'target_id' => (string) $naira->id(),
      'currency' => 'NGN',
      'at_stake' => 7000,
      'created' => time(),
    ])->execute();

    // A connected authority or HTTP client must never even be asked.
    $authority = $this->createMock(Authority::class);
    $authority->expects($this->never())->method('available');
    $authority->expects($this->never())->method('evaluate');
    $this->container->set(Gate::class, new Gate($authority, $this->container->get(Settings::class), $this->container->get(Ledger::class)));
    $http = $this->createMock(ClientInterface::class);
    $http->expects($this->never())->method('request');
    $http->expects($this->never())->method('requestAsync');
    $http->expects($this->never())->method('send');
    $http->expects($this->never())->method('sendAsync');
    $this->container->set('http_client', $http);
    $this->config('decionis.settings')->set('api_base_url', 'http://127.0.0.1:1')->save();
    $this->container->get(Isolation::class)->prepare();
    $before = $this->nonCacheSnapshot();
    $report = $this->container->get(Replay::class)->demo(90);

    $this->assertSame($before, $this->nonCacheSnapshot(), 'every non-cache table remains byte-identical');
    $this->assertSame(Xray::LABEL, $report['estimate']);
    $this->assertSame(1, $report['currencies']['NGN']['orders_below']);
    $this->assertEqualsWithDelta(14000, $report['currencies']['NGN']['value_below'], 0.001);
    $this->assertEqualsWithDelta(10, $report['currencies']['EUR']['value_below'], 0.001);
    $this->assertSame(1, $report['currencies']['NGN']['refunds_over']);
    $this->assertSame(1, $report['currencies']['EUR']['refunds_over']);
    $this->assertEquals(120, $report['currencies']['EUR']['refund_value'], 'failed Fincra payments are not refunds');
    $this->assertSame(1, $report['currencies']['NGN']['outside_refunds']);
    $this->assertSame(2, $report['currencies']['NGN']['order_lines']);
    $this->assertSame(1, $report['currencies']['NGN']['costed_lines']);
    $this->assertEquals(200000, $report['by_action']['order_acceptance']['NGN']['covered'], 'orders without cost are not counted as covered value');
    $this->assertEquals(0, $report['by_action']['outside_refund']['NGN']['held'], 'observed outside refunds do not imply a policy refusal');
    $this->assertSame(0, $report['by_action']['outside_refund']['NGN']['flagged']);
    $this->assertSame(2, $report['currencies']['USD']['stacked_promotions']);
    $this->assertStringNotContainsString('customer@example.com', (string) json_encode($report));
    $this->assertStringNotContainsString('Do not expose', (string) json_encode($report));
  }

  /**
   * Batch completion retains only aggregates, and no more than five items.
   */
  public function testBatchKeepsOnlyTotalsAndTopFiveAndUsesStoredLimits(): void {
    $this->config('decionis_commerce.settings')->set('limits.NGN', Limits::defaults('NGN') + ['defaults' => [], 'findings' => []])->save();
    for ($i = 1; $i <= 8; $i++) {
      $this->order('NGN', (string) (1000 * $i), (string) (990 * $i));
    }
    $context = ['sandbox' => [], 'results' => [], 'finished' => 0];
    $until = time();
    for ($i = 0; $i < 100 && $context['finished'] < 1; $i++) {
      XrayBatch::run(90, $until, $context);
    }
    $this->assertSame(1, $context['finished']);
    XrayBatch::finished(TRUE, $context['results'], []);
    $saved = $this->container->get('state')->get(Xray::STATE);
    $this->assertSame(8, $saved['currencies']['NGN']['orders_below']);
    $this->assertCount(5, $saved['top']['order_acceptance']['NGN']);
    $this->assertArrayNotHasKey('samples', $saved);
    $this->assertArrayNotHasKey('limits', $saved);
    $this->assertArrayNotHasKey('dossier_id', $saved);
    $this->assertSame(0, (int) $this->container->get('database')->select('decionis_decision', 'd')->countQuery()->execute()->fetchField());
    $this->assertArrayNotHasKey('stale', XrayForm::report($saved));
    $this->config('decionis_commerce.settings')->set('limits.NGN.margin_floor', 4.0)->save();
    $this->assertArrayHasKey('stale', XrayForm::report($saved), 'config sync changes are detected without storing policy values in the report');
  }

  /**
   * A cost in another currency cannot silently become an order's cost base.
   */
  public function testCostInAnotherCurrencyIsNotCovered(): void {
    $order = $this->order('NGN', '1000', '900');
    $variation = $order->getItems()[0]->getPurchasedEntity();
    $variation->set('decionis_cost', ['number' => '900', 'currency_code' => 'EUR'])->save();
    $report = $this->container->get(Replay::class)->demo(90);
    $this->assertSame(0, $report['currencies']['NGN']['costed_lines']);
    $this->assertSame(1, $report['not_covered']);
  }

  /**
   * The existing Drush replay can still review one day; UI starts at thirty.
   */
  public function testDrushOneDayWindowStillWorks(): void {
    $report = $this->container->get(Replay::class)->demo(1);
    $this->assertSame(0, $report['reviewed']);
    $this->expectException(\InvalidArgumentException::class);
    $this->container->get(Replay::class)->demo(0);
  }

  /**
   * A synthetic store history with no customer information.
   */
  private function order(string $currency, string $price, ?string $cost): Order {
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'XRAY-' . $this->randomMachineName(), 'price' => new Price($price, $currency), 'status' => 1]);
    if ($cost !== NULL) {
      $variation->set('decionis_cost', ['number' => $cost, 'currency_code' => $currency]);
    }
    $variation->save();
    $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => '1', 'unit_price' => $variation->getPrice()]);
    $item->save();
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'mail' => 'customer@example.com', 'uid' => 0, 'state' => 'completed', 'placed' => time() - 5 * 86400, 'order_items' => [$item]]);
    $order->recalculateTotalPrice()->save();
    return $order;
  }

  /**
   * Records a historical refunded payment, without calling a gateway.
   */
  private function refund(Order $order, string $number, ?string $remote = NULL): void {
    $currency = $order->getTotalPrice()->getCurrencyCode();
    Payment::create(['type' => 'payment_manual', 'payment_gateway' => 'manual', 'order_id' => $order->id(), 'amount' => $order->getTotalPrice(), 'refunded_amount' => new Price($number, $currency), 'state' => 'partially_refunded', 'remote_state' => $remote, 'completed' => time() - 4 * 86400])->save();
  }

  /**
   * Only cache tables may differ after the isolated read.
   */
  private function nonCacheSnapshot(): array {
    return array_filter($this->snapshot(), static fn (string $table): bool => !str_starts_with($table, 'cache'), ARRAY_FILTER_USE_KEY);
  }

}
