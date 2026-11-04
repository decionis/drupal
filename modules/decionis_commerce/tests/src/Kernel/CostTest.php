<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\Core\Form\FormState;
use Drupal\decionis\Form\SettingsForm;
use Drupal\decionis_commerce\Map\Cost;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Checks that margin rules read the cost field mapped by the admin.
 *
 * The mapping uses the store's existing cost field on the Decionis settings
 * page.
 *
 * @group decionis
 */
final class CostTest extends DecionisCommerceTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    FieldStorageConfig::create(['field_name' => 'field_unit_cost', 'entity_type' => 'commerce_product_variation', 'type' => 'decimal'])->save();
    FieldConfig::create(['field_name' => 'field_unit_cost', 'entity_type' => 'commerce_product_variation', 'bundle' => 'default', 'label' => 'Unit cost'])->save();
  }

  /**
   * Tests that the settings page maps an existing field as the cost base.
   */
  public function testTheSettingsPageMapsAnExistingCostField(): void {
    $form = $this->container->get('form_builder')->getForm(SettingsForm::class);
    $options = $form['cost']['cost_field']['#options'];
    $this->assertArrayHasKey('field_unit_cost', $options);
    $this->assertArrayNotHasKey('price', $options, 'the sale price is never offered as its own cost');

    $state = (new FormState())->setValues(['cost_field' => 'field_unit_cost']);
    $this->container->get('form_builder')->submitForm(SettingsForm::class, $state);
    $this->assertSame([], $state->getErrors());
    $this->assertSame('field_unit_cost', $this->config('decionis_commerce.settings')->get('cost_field'));

    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'MAPPED', 'price' => new Price('100', 'USD'), 'field_unit_cost' => '61.5', 'status' => 1]);
    $variation->save();
    $this->assertSame(61.5, $this->container->get(Cost::class)->of($variation));
  }

}
