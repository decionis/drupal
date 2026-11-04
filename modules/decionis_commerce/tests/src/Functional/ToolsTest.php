<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Functional;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis_commerce\Form\SandboxForm;
use Drupal\decionis_commerce\Map\CostFields;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;

/**
 * First-run tools in the admin UI: local Batch, bounded CSV, redacted preview.
 *
 * @group decionis
 */
final class ToolsTest extends CommerceBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_payment', 'commerce_promotion', 'commerce_checkout', 'commerce_order', 'commerce_product', 'decionis', 'decionis_commerce', 'decionis_fincra_test'];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return [...parent::getAdministratorPermissions(), 'administer decionis', 'view decionis decisions'];
  }

  /**
   * The local sandbox still completes with an unreachable authority endpoint.
   */
  public function testLocalSandboxRunsThroughBatch(): void {
    $this->config('decionis.settings')->set('api_base_url', 'http://127.0.0.1:1')->save();
    $before = \Drupal::entityTypeManager()->getStorage('decionis_decision')->getQuery()->accessCheck(FALSE)->count()->execute();
    $this->drupalGet('/admin/commerce/decionis/sandbox');
    $this->assertSession()->statusCodeEquals(200);
    $this->submitForm([], 'Run local sandbox');
    $this->assertSession()->pageTextContains('Local sandbox complete');
    $this->assertSession()->pageTextContains('BOUNDARY HOLDS');
    $this->assertSession()->pageTextContains('sandbox, not production evidence');
    $this->assertSession()->pageTextContains('demo authority');
    \Drupal::state()->resetCache();
    $report = \Drupal::state()->get(SandboxForm::STATE);
    $this->assertCount(7, $report['scenarios']);
    $this->assertSame('demo authority', $report['authority']);
    $this->assertSame($before, \Drupal::entityTypeManager()->getStorage('decionis_decision')->getQuery()->accessCheck(FALSE)->count()->execute(), 'the UI sandbox leaves no decision rows');
  }

  /**
   * CSV is checked before any write, then updates the exact variation currency.
   */
  public function testCostImportValidatesAndSavesInBatch(): void {
    \Drupal::service('commerce_price.currency_importer')->import('NGN');
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'IMPORT-NGN', 'price' => new Price('1000', 'NGN')]);
    $variation->save();
    $file = sys_get_temp_dir() . '/decionis-cost-test-' . bin2hex(random_bytes(6)) . '.csv';
    try {
      file_put_contents($file, "sku,cost,currency\nIMPORT-NGN,600,EUR\n");
      $this->drupalGet('/admin/commerce/decionis/cost-import');
      $this->submitForm(['files[csv]' => $file], 'Import costs');
      $this->assertSession()->pageTextContains('different currency');
      $this->assertTrue($this->current($variation)->get(CostFields::FIELD)->isEmpty());
      file_put_contents($file, "sku,cost,currency\nIMPORT-NGN,600,NGN\n");
      $this->drupalGet('/admin/commerce/decionis/cost-import');
      $this->submitForm(['files[csv]' => $file], 'Import costs');
      $this->assertSession()->pageTextContains('Cost set on 1 variations.');
      $cost = $this->current($variation)->get(CostFields::FIELD)->first()->getValue();
      $this->assertEqualsWithDelta(600.0, (float) $cost['number'], 0.00001);
      $this->assertSame('NGN', $cost['currency_code']);
      file_put_contents($file, "sku,cost\nIMPORT-NGN,500\nIMPORT-NGN,1\n");
      $this->drupalGet('/admin/commerce/decionis/cost-import');
      $this->submitForm(['files[csv]' => $file], 'Import costs');
      $this->assertSession()->pageTextContains('invalid or repeated');
      $this->assertEqualsWithDelta(600.0, (float) $this->current($variation)->get(CostFields::FIELD)->first()->getValue()['number'], 0.00001);
    }
    finally {
      unlink($file);
    }
  }

  /**
   * Preview includes non-secret file contents and never changes key custody.
   */
  public function testGatewayPreviewIsRedactedAndDoesNotApply(): void {
    $secret = 'synthetic-fincra-preview-test-only';
    $api = 'dcy_aaaaaaaa_synthetic-preview-only';
    $gateway = PaymentGateway::create(['id' => 'fincra', 'label' => 'Test Fincra', 'plugin' => 'fincra_standard', 'configuration' => ['mode' => 'test', 'secret_key_test' => $secret]]);
    $gateway->save();
    \Drupal::state()->set(Binding::STATE, ['org_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1']);
    \Drupal::service(Credentials::class)->store('api_key', Credentials::API_KEY, 'Synthetic preview key', $api);
    $this->drupalGet('/admin/commerce/decionis/gateway-preview');
    $this->submitForm(['tier' => 'separate', 'gateway' => 'fincra'], 'Preview dry-run plan');
    $this->assertSession()->pageTextContains('agentsafe.yaml');
    $this->assertSession()->pageTextContains('systemctl');
    $this->assertSession()->pageTextContains('[secret, not shown]');
    $this->assertSession()->responseNotContains($secret);
    $this->assertSession()->responseNotContains($api);
    \Drupal::state()->resetCache();
    $this->assertNull(\Drupal::state()->get('decionis.protection'));
    $configuration = PaymentGateway::load('fincra')->getPluginConfiguration();
    $this->assertSame($secret, $configuration['secret_key_test']);
    $this->assertFalse($configuration['secret_key_held_by_gateway']);
  }

  /**
   * Reloads the product variation written by the browser request.
   */
  private function current(ProductVariation $variation): ProductVariation {
    $current = \Drupal::entityTypeManager()->getStorage('commerce_product_variation')->loadUnchanged($variation->id());
    if (!$current instanceof ProductVariation) {
      throw new \RuntimeException('The imported variation disappeared.');
    }
    return $current;
  }

}
