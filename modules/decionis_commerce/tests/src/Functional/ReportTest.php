<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Functional;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis_commerce\Map\CostFields;
use Drupal\decionis_commerce\Report\Limits;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;

/**
 * Owner reports keep local estimates and individual signed evidence distinct.
 *
 * @group decionis
 */
final class ReportTest extends CommerceBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_payment', 'commerce_promotion', 'commerce_checkout', 'commerce_order', 'commerce_product', 'decionis', 'decionis_commerce'];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return [...parent::getAdministratorPermissions(), 'administer decionis', 'view decionis decisions'];
  }

  /**
   * A saved X-ray renders with cost/score and exports no personal fields.
   */
  public function testXrayPrintAndCsvAreUnsignedEstimates(): void {
    \Drupal::service('commerce_price.currency_importer')->import('NGN');
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'REPORT-NGN', 'price' => new Price('100000', 'NGN'), CostFields::FIELD => new Price('110000', 'NGN')]);
    $variation->save();
    $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => '1', 'unit_price' => $variation->getPrice()]);
    $item->save();
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'mail' => 'never-report@example.com', 'state' => 'completed', 'placed' => time() - 86400, 'order_items' => [$item]]);
    $order->recalculateTotalPrice()->save();
    $this->config('decionis_commerce.settings')->set('limits.NGN', ['margin_floor' => 12.0, 'source' => 'tuned', 'defaults' => []] + Limits::defaults('NGN'))->save();
    $this->drupalGet('/admin/commerce/decionis/x-ray');
    $this->submitForm(['days' => '90'], 'Run Store X-ray');
    $this->drupalGet('/admin/commerce/decionis/report/xray');
    $session = $this->assertSession();
    $session->statusCodeEquals(200);
    $session->pageTextContains('unsigned estimate');
    $session->pageTextContains('nothing left this site');
    $session->pageTextContains('cumulative refunded payments');
    $session->pageTextContains('1 orders sold under your margin floor');
    $session->pageTextContains('NGN');
    $session->pageTextContains('Current cost coverage');
    $session->pageTextContains('Protection score');
    $session->pageTextContains('This order is below the margin floor.');
    $session->responseNotContains('never-report@example.com');
    $this->drupalGet('/admin/commerce/decionis/report/xray/export.csv');
    $session->responseHeaderContains('Content-Type', 'text/csv');
    $session->responseContains('unsigned estimate');
    $session->responseContains('score_out_of_100');
    $session->responseContains('refund_replay_basis');
    $session->responseNotContains('never-report@example.com');
  }

  /**
   * The assembled Shadow report is unsigned and lists each Dossier separately.
   */
  public function testShadowPrintAndCsvListDossierReferences(): void {
    $dossier = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaa01';
    $entry = Decision::create(['action' => 'price_change', 'source' => 'replay', 'mode' => 'shadow', 'verdict' => 'BLOCK', 'effect' => 'would_block', 'reasons' => '["price_below_cost"]', 'at_stake' => '200', 'currency' => 'EUR', 'authority' => 'account', 'dossier_id' => $dossier, 'target_type' => 'commerce_product_variation', 'target_id' => '1', 'customer' => 'private-customer-reference', 'created' => time(), 'occurred' => time() - 86400]);
    $entry->save();
    Decision::create(['action' => 'price_change', 'source' => 'replay', 'mode' => 'shadow', 'verdict' => 'BLOCK', 'effect' => 'would_block', 'at_stake' => '9999', 'currency' => 'EUR', 'authority' => 'account', 'policy_version' => 'older-policy', 'target_type' => 'commerce_product_variation', 'target_id' => '1', 'created' => time()])->save();
    Decision::create(['action' => 'order_acceptance', 'source' => 'live', 'mode' => 'shadow', 'effect' => 'ungoverned', 'authority' => 'account', 'dossier_id' => NULL, 'created' => time()])->save();
    \Drupal::state()->set('decionis_commerce.replay', ['ids' => [(int) $entry->id()], 'days' => 365, 'until' => time(), 'policy' => 'current-policy', 'status' => 'complete']);
    $this->drupalGet('/admin/commerce/decionis/report/shadow');
    $session = $this->assertSession();
    $session->statusCodeEquals(200);
    $session->pageTextContains('This assembled report is unsigned');
    $session->pageTextContains($dossier);
    $session->pageTextContains('npx -y @decionis/verify --file dossier.json');
    $session->pageTextContains('EUR 200.00');
    $session->pageTextContains('This price is below the recorded cost.');
    $session->pageTextContains('365-day source window');
    $session->pageTextNotContains('9,999');
    $this->assertCount(1, $this->getSession()->getPage()->findAll('css', 'a[href$="/verify"]'), 'NULL Dossier ids never become verification links');
    $session->responseNotContains('private-customer-reference');
    $this->drupalGet('/admin/commerce/decionis/report/shadow/export.csv');
    $session->responseContains($dossier);
    $session->responseContains('unsigned summary');
    $session->responseContains('coverage');
    $session->responseNotContains('private-customer-reference');
    $content = $this->getSession()->getPage()->getContent();
    $this->assertSame(1, substr_count($content, "\nevidence,"), 'the CSV lists only actual Dossier references');
  }

}
