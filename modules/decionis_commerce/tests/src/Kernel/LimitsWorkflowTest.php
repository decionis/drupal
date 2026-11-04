<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Decionis\Client\Decision\Request;
use Decionis\Client\Decision\Verdict;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_promotion\Entity\Promotion;
use Drupal\Core\Form\FormState;
use Drupal\decionis_commerce\Form\LimitsForm;
use Drupal\decionis_commerce\Map\Actions;
use Drupal\decionis_commerce\Report\Limits;
use Drupal\decionis_commerce\Report\Xray;
use Drupal\decionis_commerce\Sandbox\Local;
use Drupal\decionis_commerce\Sandbox\Sandbox;

/**
 * Local historical drafts, saved edits and sandbox use the same currency limits.
 *
 * @group decionis
 */
final class LimitsWorkflowTest extends DecionisCommerceTestBase {

  /**
   * The settings helper samples actual NGN and EUR payments independently.
   */
  public function testOwnCurrencyDraftsAndEditedFindings(): void {
    $this->container->get('commerce_price.currency_importer')->import('NGN');
    $this->container->get('commerce_price.currency_importer')->import('EUR');
    PaymentGateway::create(['id' => 'manual', 'label' => 'Manual', 'plugin' => 'manual'])->save();
    $payment = NULL;
    foreach (['NGN' => 10000, 'EUR' => 10] as $currency => $step) {
      $store = $this->createStore($currency . ' store', 'store@example.invalid', 'online', FALSE, 'US', $currency);
      for ($i = 1; $i <= 20; $i++) {
        $created = $this->history($currency, $step, $i, (int) $store->id());
        if ($currency === 'NGN' && $i === 1) {
          $payment = $created;
        }
      }
    }
    $form = [];
    $state = new FormState();
    $helper = $this->container->get(LimitsForm::class);
    $helper->build($form, $state);
    $values = $state->get('decionis_limit_values');
    $this->assertSame(190000.0, $values['NGN']['refund_limit']);
    $this->assertSame(190.0, $values['EUR']['refund_limit']);
    $this->assertLessThanOrEqual(10.0, $values['NGN']['margin_floor']);
    $this->assertStringContainsString('19 of 20 cumulative refunded payments', $form['decionis_limits']['NGN']['refund_limit']['#description']);
    $state->setValue('decionis_limits', $values);
    $helper->validate($form, $state);
    $this->assertSame([], $state->getErrors());
    $helper->submit($form, $state);
    $this->assertSame('history_with_defaults', $this->config('decionis_commerce.settings')->get('limits.NGN.source'));

    $this->assertNotNull($payment);
    [$action, $context] = $this->container->get(Actions::class)->refund($payment, new Price('60000', 'NGN'), 'refund_form');
    $request = new Request($action, 'drupal-decionis', 'api', NULL, $context->facts);
    $this->assertSame(Verdict::Allow, (new Local())->evaluate($request, FALSE)->verdict);
    $this->container->get('state')->set(Xray::STATE, ['currencies' => []]);
    $values['NGN']['refund_limit'] = 50000;
    $state->setValue('decionis_limits', $values);
    $helper->build($form, $state);
    $this->assertStringContainsString('5 of 20 cumulative refunded payments', $form['decionis_limits']['NGN']['refund_limit']['#description']);
    $this->assertStringContainsString('15 above it total NGN 1,950,000.00', $form['decionis_limits']['NGN']['refund_limit']['#description']);
    $this->assertSame($form['decionis_limits'], LimitsForm::refresh($form, $state));
    $helper->validate($form, $state);
    $helper->submit($form, $state);
    $this->assertSame('tuned', $this->config('decionis_commerce.settings')->get('limits.NGN.source'));
    $this->assertSame('history_with_defaults', $this->config('decionis_commerce.settings')->get('limits.EUR.source'));
    $this->assertTrue($this->container->get('state')->get(Xray::STATE)['stale']);
    $this->assertSame(Verdict::Escalate, (new Local())->evaluate($request, FALSE)->verdict);
    $this->assertSame('', $this->config('decionis.settings')->get('policy_version'), 'a local edit is never pinned as published');
  }

  /**
   * Tuned local limits keep valid scenarios and describe unexercised boundaries.
   */
  public function testSandboxUsesTunedLimitsAndDescribesValidCoverage(): void {
    $this->config('decionis_commerce.settings')->set('limits.EUR', array_replace(Limits::defaults('EUR'), ['refund_limit' => 25.0, 'margin_floor' => 4.0, 'stacking_ceiling' => 50.0, 'source' => 'tuned']))->save();
    $report = $this->container->get(Sandbox::class)->run(new Local());
    $this->assertSame('BOUNDARY HOLDS', $report['verdict']);
    $rows = array_column($report['scenarios'], NULL, 'scenario');
    $this->assertArrayHasKey('Refund above ceiling (EUR 60)', $rows);
    $this->assertArrayHasKey('Order under 4% margin floor', $rows);
    $this->assertArrayHasKey('Two coupons, 65% combined', $rows);
    $this->assertSame([], $report['coverage_notes']);

    $this->config('decionis_commerce.settings')->set('limits.EUR.refund_limit', 2000000.0)->set('limits.EUR.stacking_ceiling', 100.0)->set('limits.EUR.margin_floor', -200.0)->save();
    $report = $this->container->get(Sandbox::class)->run(new Local());
    $this->assertSame('BOUNDARY HOLDS', $report['verdict']);
    $this->assertCount(3, $report['coverage_notes']);
    $this->assertSame(1, $report['exposure']['risky'], 'only the valid below-cost price scenario crosses these tuned limits');
    $rows = array_column($report['scenarios'], NULL, 'scenario');
    $this->assertTrue($rows['Two coupons, 100% combined']['enforce']['executed']);
    $this->assertFalse($rows['Two coupons, 100% combined']['risky']);
  }

  /**
   * A store's promotions use its currency and never stack with another store.
   */
  public function testPromotionUsesItsOwnStoreCurrency(): void {
    $stores = [];
    foreach (['NGN' => '0.20', 'EUR' => '0.70'] as $currency => $fraction) {
      $stores[$currency] = $this->createStore($currency . ' store', 'store@example.invalid', 'online', FALSE, 'US', $currency);
      Promotion::create(['name' => 'Synthetic ' . $currency, 'stores' => [$stores[$currency]->id()], 'order_types' => ['default'], 'status' => 1, 'compatibility' => 'any', 'offer' => ['target_plugin_id' => 'order_percentage_off', 'target_plugin_configuration' => ['percentage' => $fraction]]])->save();
    }
    $this->config('decionis_commerce.settings')->set('limits.NGN', array_replace(Limits::defaults('NGN'), ['stacking_ceiling' => 80.0]))->set('limits.EUR', array_replace(Limits::defaults('EUR'), ['stacking_ceiling' => 10.0]))->save();
    $promotion = Promotion::create(['name' => 'Synthetic coupon', 'stores' => [$stores['NGN']->id()], 'order_types' => ['default'], 'status' => 1, 'compatibility' => 'any', 'offer' => ['target_plugin_id' => 'order_percentage_off', 'target_plugin_configuration' => ['percentage' => '0.30']]]);
    [$action, $context] = $this->container->get(Actions::class)->promotion($promotion, 'promotion_form');
    $this->assertSame(['NGN'], $context->facts['store_currencies']);
    $this->assertSame(50.0, $context->facts['stacked_discount_percent']);
    $request = new Request($action, 'drupal-decionis', 'api', NULL, $context->facts);
    $this->assertSame(Verdict::Allow, (new Local())->evaluate($request, FALSE)->verdict);
    $this->config('decionis_commerce.settings')->set('limits.NGN.stacking_ceiling', 40.0)->save();
    $this->assertSame(Verdict::Block, (new Local())->evaluate($request, FALSE)->verdict);
  }

  /**
   * Creates local historical payments; no gateway or external action is called.
   */
  private function history(string $currency, int $step, int $index, int $storeId): Payment {
    $price = new Price((string) ($step * 30), $currency);
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'LIMIT-' . $currency . '-' . $index, 'price' => $price, 'decionis_cost' => new Price((string) ($step * 27), $currency), 'status' => 1]);
    $variation->save();
    $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => '1', 'unit_price' => $price]);
    $item->save();
    $order = Order::create(['type' => 'default', 'store_id' => $storeId, 'mail' => $currency . $index . '@example.invalid', 'state' => 'completed', 'placed' => time() - 86400, 'order_items' => [$item]]);
    $order->recalculateTotalPrice()->save();
    $payment = Payment::create(['type' => 'payment_manual', 'payment_gateway' => 'manual', 'order_id' => $order->id(), 'amount' => $price, 'refunded_amount' => new Price((string) ($step * $index), $currency), 'state' => 'partially_refunded', 'completed' => time() - 86400]);
    $payment->save();
    return $payment;
  }

}
