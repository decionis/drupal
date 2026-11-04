<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Functional;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis\Gate\Deferred;
use Drupal\decionis\Gate\Settings;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;

/**
 * A real Shadow price form saves immediately and nudges its admin next load.
 *
 * @group decionis
 */
final class ShadowSaveTest extends CommerceBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_product', 'commerce_order', 'commerce_payment', 'commerce_promotion',
    'commerce_checkout', 'key', 'decionis', 'decionis_commerce', 'decionis_authority_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return array_merge(parent::getAdministratorPermissions(), ['administer commerce_product', 'administer decionis']);
  }

  /**
   * The HTTP form runs the normal validation and entity-save hooks.
   */
  public function testPriceSaveShowsItsDeferredNudgeOnlyOnce(): void {
    FieldStorageConfig::create(['field_name' => 'field_unit_cost', 'entity_type' => 'commerce_product_variation', 'type' => 'decimal'])->save();
    FieldConfig::create(['field_name' => 'field_unit_cost', 'entity_type' => 'commerce_product_variation', 'bundle' => 'default', 'label' => 'Unit cost'])->save();
    $this->config('decionis_commerce.settings')->set('cost_field', 'field_unit_cost')->save();
    $this->config('decionis.settings')->set('mode', 'shadow')
      ->set('org_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1')->set('api_key', 'synthetic-local-authority-reference')->save();
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'SKU-2', 'title' => 'Synthetic variation', 'price' => new Price('120', 'USD'), 'field_unit_cost' => '100', 'status' => 1]);
    $variation->save();
    $product = Product::create(['type' => 'default', 'title' => 'Synthetic product', 'stores' => [$this->store], 'variations' => [$variation], 'status' => 1]);
    $product->save();
    drupal_flush_all_caches();

    $this->drupalGet('/product/' . $product->id() . '/variations/' . $variation->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm(['price[0][number]' => '96'], 'Save');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('now sells 4% below cost');
    $storage = \Drupal::entityTypeManager()->getStorage('commerce_product_variation');
    $storage->resetCache();
    $saved = $storage->load($variation->id());
    $this->assertInstanceOf(ProductVariation::class, $saved);
    $this->assertEquals(96, (float) $saved->getPrice()->getNumber());
    $entries = Decision::loadMultiple();
    $this->assertCount(1, $entries);
    $entry = reset($entries);
    $this->assertSame('pending', $entry->value('effect'));
    $this->assertSame((string) $this->adminUser->id(), $entry->value('recipient'));
    $this->assertSame('1', $entry->value('save_confirmed'));
    $this->assertSame($entry->value('connection_id'), \Drupal::service(Settings::class)->connectionIdentity(), 'The HTTP save and its CLI worker share the same local connection identity.');

    \Drupal::service(Deferred::class)->process((int) $entry->id());
    $this->drupalGet('/admin/commerce/config/decionis');
    $this->assertSession()->pageTextContains('Commerce Gate would have blocked this price: SKU-2 now sells 4% below cost.');
    $this->drupalGet('/admin/commerce/config/decionis');
    $this->assertSession()->pageTextNotContains('now sells 4% below cost');
  }

}
