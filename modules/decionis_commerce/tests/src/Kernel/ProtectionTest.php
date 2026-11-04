<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis\Report\Protection;
use Drupal\decionis_commerce\Map\CostFields;
use Drupal\decionis_commerce\Report\Limits;

/**
 * The named checks give repeatable scores for fresh, connected and complete sites.
 *
 * @group decionis
 */
final class ProtectionTest extends DecionisCommerceTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['decionis_fincra_test'];

  /**
   * Each point is backed by a local observation; missing cost is never complete.
   */
  public function testFreshConnectedAndFullySetUp(): void {
    $service = $this->container->get(Protection::class);
    $fresh = $service->read();
    $this->assertSame(20, $fresh['score'], 'fresh Commerce has the four hooks, but no other setup evidence');
    $this->assertCount(7, $fresh['checks']);
    foreach ($fresh['checks'] as $check) {
      $this->assertNotEmpty($check['route']);
    }
    $state = $this->container->get('state');
    $state->set(Binding::STATE, ['org_id' => 'synthetic-workspace', 'api_key' => 'synthetic-key-reference']);
    $connected = $service->read();
    $this->assertSame(35, $connected['score']);
    $currency = $this->store->getDefaultCurrencyCode();
    $this->container->get(CostFields::class)->ensure();
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'PROTECTION-COST', 'price' => new Price('100', $currency), CostFields::FIELD => new Price('60', $currency)]);
    $variation->save();
    $limits = Limits::defaults($currency);
    $limits['source'] = 'tuned';
    $limits['defaults'] = [];
    $this->config('decionis_commerce.settings')->set('limits.' . $currency, $limits)->save();
    $state->set(Settings::ENTITLEMENT, ['enforcement' => TRUE, 'state' => 'trial', 'ends_at' => gmdate('c', time() + 86400)]);
    $this->config('decionis.settings')->set('actions.price_change', 'enforce')->save();
    PaymentGateway::create(['id' => 'fincra', 'label' => 'Test Fincra', 'plugin' => 'fincra_standard', 'configuration' => ['mode' => 'test', 'api_base_url' => 'http://127.0.0.1:18080', 'secret_key_held_by_gateway' => TRUE, 'secret_key_test' => '', 'secret_key_live' => '']])->save();
    $state->set('decionis.protection', ['tier' => 'separate', 'gateway' => 'fincra', 'port' => 18080, 'mode' => 'test', 'custody' => TRUE, 'connection_id' => $this->container->get(Settings::class)->connectionIdentity(), 'host' => $this->container->get(Binding::class)->host()]);
    Decision::create(['action' => 'refund_request', 'hook' => 'outside_refund', 'source' => 'live', 'mode' => 'shadow', 'effect' => 'outside', 'authority' => 'account', 'currency' => $currency, 'at_stake' => '1', 'created' => time()])->save();
    $complete = $service->read();
    $this->assertSame(100, $complete['score']);
    foreach ($complete['checks'] as $check) {
      $this->assertSame($check['weight'], $check['points'], $check['label']);
    }
    $this->config('decionis_commerce.settings')->set('limits.NGN', Limits::defaults('NGN'))->save();
    $untuned = $service->read();
    $this->assertSame(85, $untuned['score'], 'an untuned second currency prevents the limits check from claiming completion');
    $this->config('decionis_commerce.settings')->clear('limits.NGN')->save();
    $this->config('decionis.settings')->set('actions.price_change', 'shadow')->set('actions.refund_request', 'enforce')->save();
    $outsideOnly = $service->read();
    $this->assertSame(85, $outsideOnly['score'], 'Fincra outside refunds alone do not count as an enforced Drupal action');
    $this->config('decionis.settings')->set('actions.price_change', 'enforce')->set('actions.refund_request', 'inherit')->save();
    $variation->set(CostFields::FIELD, NULL)->save();
    $missingCost = $service->read();
    $this->assertSame(80, $missingCost['score'], 'a missing cost removes its coverage points');
    $state->set(Settings::ENTITLEMENT, NULL);
    $expired = $service->read();
    $this->assertSame(65, $expired['score'], 'stored Enforce preference does not override missing entitlement');
    $state->set(Binding::STATE, ['org_id' => 'new-workspace', 'api_key' => 'new-key-reference']);
    $reconnected = $service->read();
    $this->assertSame(55, $reconnected['score'], 'a previous connection’s custody proof cannot score on a reconnected site');
  }

}
