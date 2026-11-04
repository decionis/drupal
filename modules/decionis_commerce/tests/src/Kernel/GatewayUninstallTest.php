<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\decionis_commerce\Gateway\Setup;
use Drupal\decionis_commerce\Gateway\Uninstall;

/**
 * Module removal cannot silently remove an active runtime key dependency.
 *
 * @group decionis
 */
final class GatewayUninstallTest extends DecionisCommerceTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['decionis_fincra_test'];

  /**
   * Both custody and rollback must be retired before module removal.
   */
  public function testRuntimeKeyDependencyPreventsUninstall(): void {
    $gateway = PaymentGateway::create(['id' => 'fincra', 'label' => 'Fincra', 'plugin' => 'fincra_standard']);
    $gateway->save();
    $state = $this->container->get('state');
    $validator = $this->container->get(Uninstall::class);
    $record = ['gateway' => 'fincra', 'custody' => TRUE, 'key_file' => '/private/host-owned-fincra-key'];
    $state->set(Setup::STATE, $record);
    $this->assertCount(1, $validator->validate('decionis_commerce'));
    $this->assertCount(1, $validator->validate('decionis'));
    $this->assertSame([], $validator->validate('node'));
    $record['custody'] = FALSE;
    $record['rollback_key'] = 'protected_fincra';
    $state->set(Setup::STATE, $record);
    $this->assertCount(1, $validator->validate('decionis_commerce'));
    $gateway->delete();
    $this->assertSame([], $validator->validate('decionis_commerce'));
    $this->assertSame($record, $state->get(Setup::STATE), 'Recovery metadata is retained.');
  }

}
