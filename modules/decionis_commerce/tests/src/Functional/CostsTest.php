<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Functional;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\decionis_commerce\Report\Coverage;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;

/**
 * A merchant can enter cost on the ordinary product variation form.
 *
 * @group decionis
 */
final class CostsTest extends CommerceBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_payment', 'commerce_promotion', 'commerce_checkout', 'commerce_order', 'commerce_product', 'decionis', 'decionis_commerce'];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return [...parent::getAdministratorPermissions(), 'administer commerce_product', 'administer decionis', 'view decionis decisions'];
  }

  /**
   * The installed price widget stores currency-aware cost and coverage updates.
   */
  public function testCostIsEditableAndCoverageChanges(): void {
    \Drupal::service('commerce_price.currency_importer')->import('NGN');
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'EDITABLE-COST', 'price' => new Price('1000', 'NGN'), 'status' => 1]);
    $variation->save();
    $product = Product::create(['type' => 'default', 'title' => 'Cost example', 'stores' => [$this->store->id()], 'variations' => [$variation], 'status' => 1]);
    $product->save();
    $this->assertSame(0, \Drupal::service(Coverage::class)->variations()['costed']);
    $this->drupalGet('/product/' . $product->id() . '/variations/' . $variation->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldExists('decionis_cost[0][number]');
    $this->submitForm(['decionis_cost[0][number]' => '600', 'decionis_cost[0][currency_code]' => 'NGN'], 'Save');
    $this->assertSession()->pageTextNotContains('An illegal choice');
    \Drupal::entityTypeManager()->getStorage('commerce_product_variation')->resetCache();
    $variation = ProductVariation::load($variation->id());
    $this->assertNotNull($variation);
    $this->assertEqualsWithDelta(600.0, (float) $variation->get('decionis_cost')->first()->getValue()['number'], 0.001);
    $this->assertSame('NGN', $variation->get('decionis_cost')->first()->getValue()['currency_code']);
    $this->assertSame(['total' => 1, 'costed' => 1, 'percent' => 100.0], \Drupal::service(Coverage::class)->variations());
    $this->drupalGet('/admin/commerce/decionis/x-ray');
    $this->assertSession()->pageTextContains('100%');
    $this->assertSession()->pageTextContains('1 of 1');
  }

}
