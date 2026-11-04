<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationType;
use Drupal\Core\Recipe\Recipe;
use Drupal\Core\Recipe\RecipeRunner;
use Drupal\decionis_commerce\Map\CostFields;
use Drupal\decionis_commerce\Report\Coverage;
use Drupal\field\Entity\FieldConfig;

/**
 * Installation, update and recipe use one idempotent, multi-bundle cost path.
 *
 * @group decionis
 */
final class CostFieldsTest extends DecionisCommerceTestBase {

  /**
   * The real recipe and update hook can be applied twice on Drupal 10 and 11.
   */
  public function testRecipeAndUpdatePreserveAllThreeBundlesAndChoices(): void {
    foreach (['clothing', 'service'] as $bundle) {
      ProductVariationType::create(['id' => $bundle, 'label' => ucfirst($bundle), 'orderItemType' => 'default'])->save();
    }
    $this->container->get(CostFields::class)->ensure();
    foreach (['default', 'clothing', 'service'] as $bundle) {
      $this->assertBundle($bundle);
    }
    // Model an upgrade from a site where only the default bundle had cost.
    FieldConfig::loadByName('commerce_product_variation', 'clothing', CostFields::FIELD)?->delete();
    FieldConfig::loadByName('commerce_product_variation', 'service', CostFields::FIELD)?->delete();
    $this->config('decionis_commerce.settings')->set('limits.NGN.refund_limit', 90000.0)->set('cost_field', 'existing_unit_cost')->save();
    $this->config('decionis.settings')->set('mode', 'enforce')->save();
    $before = $this->config('decionis_commerce.settings')->get();
    $this->container->get('module_handler')->loadInclude('decionis_commerce', 'install');
    \decionis_commerce_update_10001();
    \decionis_commerce_update_10001();
    $path = DRUPAL_ROOT . '/' . $this->container->get('extension.list.module')->getPath('decionis') . '/recipes/decionis_commerce';
    RecipeRunner::processRecipe(Recipe::createFromDirectory($path));
    RecipeRunner::processRecipe(Recipe::createFromDirectory($path));
    foreach (['default', 'clothing', 'service'] as $bundle) {
      $this->assertBundle($bundle);
    }
    $this->assertSame($before, $this->config('decionis_commerce.settings')->get());
    $this->assertSame('enforce', $this->config('decionis.settings')->get('mode'));
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('field_storage_config')->loadByProperties(['entity_type' => 'commerce_product_variation', 'field_name' => CostFields::FIELD]));

    // Types introduced after installation get the same editable field.
    ProductVariationType::create(['id' => 'later', 'label' => 'Later', 'orderItemType' => 'default'])->save();
    $this->assertBundle('later');
  }

  /**
   * Missing and currency-mismatched costs remain outside both coverage shares.
   */
  public function testCoverageCountsVariationTypesAndHistoricalLines(): void {
    $this->container->get('commerce_price.currency_importer')->import('NGN');
    $this->container->get('commerce_price.currency_importer')->import('EUR');
    foreach (['clothing', 'service'] as $bundle) {
      ProductVariationType::create(['id' => $bundle, 'label' => ucfirst($bundle), 'orderItemType' => 'default'])->save();
    }
    $items = [];
    foreach (['default' => ['60', 'NGN'], 'clothing' => NULL, 'service' => ['60', 'EUR']] as $bundle => $cost) {
      $variation = ProductVariation::create(['type' => $bundle, 'sku' => 'COST-' . $bundle, 'price' => new Price('100', 'NGN'), 'status' => 1]);
      if ($cost !== NULL) {
        $variation->set(CostFields::FIELD, new Price(...$cost));
      }
      $variation->save();
      $item = OrderItem::create(['type' => 'default', 'purchased_entity' => $variation, 'quantity' => '1', 'unit_price' => $variation->getPrice()]);
      $item->save();
      $items[] = $item;
    }
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'state' => 'completed', 'placed' => time() - 86400, 'order_items' => $items]);
    $order->recalculateTotalPrice()->save();
    $coverage = $this->container->get(Coverage::class)->totals();
    $share = ['total' => 3, 'costed' => 1, 'percent' => 33.3];
    $this->assertSame($share, $coverage['variations']);
    $this->assertSame($share, $coverage['order_lines']);
    $this->assertSame($share, $coverage['by_currency']['NGN']);
  }

  /**
   * The cost field is a price and appears on the normal variation edit form.
   */
  private function assertBundle(string $bundle): void {
    $field = FieldConfig::loadByName('commerce_product_variation', $bundle, CostFields::FIELD);
    $this->assertNotNull($field, $bundle);
    $this->assertSame('commerce_price', $field->getType());
    $display = $this->container->get('entity_display.repository')->getFormDisplay('commerce_product_variation', $bundle, 'default');
    $this->assertSame('commerce_price_default', $display->getComponent(CostFields::FIELD)['type']);
  }

}
