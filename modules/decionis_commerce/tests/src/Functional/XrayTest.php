<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Functional;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\decionis_commerce\Report\Xray;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;

/**
 * First-run X-ray over HTTP, without an account or an answering API.
 *
 * @group decionis
 */
final class XrayTest extends CommerceBrowserTestBase {

  /**
   * Start of the fresh test-site install, excluding Composer provisioning.
   */
  private float $installStarted;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_payment', 'commerce_promotion', 'commerce_checkout', 'commerce_order', 'commerce_product', 'decionis', 'decionis_commerce'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->installStarted = microtime(TRUE);
    parent::setUp();
  }

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return [...parent::getAdministratorPermissions(), 'administer decionis', 'view decionis decisions'];
  }

  /**
   * The route is permission protected and a Batch run produces local totals.
   */
  public function testFirstRunUsesTheSitesHistoryOffline(): void {
    \Drupal::service('commerce_price.currency_importer')->import('NGN');
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'XRAY-NAIRA', 'price' => new Price('100000', 'NGN'), 'status' => 1]);
    $variation->save();
    $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => '1', 'unit_price' => $variation->getPrice()]);
    $item->save();
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'state' => 'completed', 'placed' => time() - 86400, 'order_items' => [$item]]);
    $order->recalculateTotalPrice()->save();
    $this->config('decionis.settings')->set('api_base_url', 'http://127.0.0.1:1')->save();
    $this->drupalLogout();
    $this->drupalGet('/admin/commerce/decionis/x-ray');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/commerce/decionis/decisions');
    $this->assertSession()->linkByHrefExists('/admin/commerce/decionis/x-ray');
    $this->drupalGet('/admin/commerce/decionis/x-ray');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('days', '90');
    $this->submitForm(['days' => '29'], 'Run Store X-ray');
    $this->assertSession()->pageTextContains('must be higher than or equal to 30');
    $started = microtime(TRUE);
    $this->submitForm(['days' => '90'], 'Run Store X-ray');
    $this->assertSession()->pageTextContains('Store X-ray complete');
    $this->assertSession()->pageTextContains('estimate on your site, using suggested limits');
    $this->assertSession()->pageTextContains('nothing left this site');
    $this->assertSession()->pageTextContains('NGN');
    $this->assertSession()->pageTextContains('0% (0/1)');
    $elapsed = microtime(TRUE) - $started;
    $artifact = getenv('DECIONIS_XRAY_BENCHMARK_FILE');
    if (is_string($artifact) && $artifact !== '') {
      $record = ['scenario' => 'first_run_submit_to_headline', 'orders' => 1, 'currency' => 'NGN', 'seconds' => round($elapsed, 3), 'database' => \Drupal::database()->databaseType(), 'drupal' => \Drupal::VERSION, 'php' => PHP_VERSION];
      $this->assertNotFalse(file_put_contents($artifact, json_encode($record, JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX));
      $record['scenario'] = 'fresh_install_to_headline';
      $record['seconds'] = round(microtime(TRUE) - $this->installStarted, 3);
      $this->assertNotFalse(file_put_contents($artifact, json_encode($record, JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX));
    }
    \Drupal::state()->resetCache();
    $this->assertSame(1, \Drupal::state()->get(Xray::STATE)['currencies']['NGN']['order_lines']);
    $this->assertEmpty($this->config('decionis.settings')->get('org_id'));
  }

}
