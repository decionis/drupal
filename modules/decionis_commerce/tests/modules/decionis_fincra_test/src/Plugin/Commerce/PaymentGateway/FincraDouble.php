<?php

declare(strict_types=1);

namespace Drupal\decionis_fincra_test\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;

/**
 * Stands in for Commerce Fincra, with the patched configuration keys.
 *
 * @CommercePaymentGateway(
 *   id = "fincra_standard",
 *   label = "Fincra (test double)",
 *   display_label = "Fincra",
 *   payment_method_types = {"credit_card"},
 * )
 */
final class FincraDouble extends OffsitePaymentGatewayBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'secret_key_test' => '',
      'secret_key_live' => '',
      'public_key_test' => '',
      'public_key_live' => '',
      'api_base_url' => '',
      'secret_key_held_by_gateway' => FALSE,
    ] + parent::defaultConfiguration();
  }

}
