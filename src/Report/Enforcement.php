<?php

declare(strict_types=1);

namespace Drupal\decionis\Report;

use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Presentation of an action's observed impact and payment-plugin coverage.
 */
final class Enforcement {

  /**
   * Explains counts without adding unlike currencies together.
   */
  public static function impact(array $currencies): string {
    $lines = [];
    foreach ($currencies as $currency => $line) {
      $lines[] = sprintf('%d of %d flagged; %s at stake; %d would need approval', $line['flagged'], $line['count'], Activity::money($line['value'], $currency), $line['approvals']);
    }
    return $lines === [] ? 'No completed decisions in the last 90 days; impact is unknown.' : implode('. ', $lines) . '.';
  }

  /**
   * TRUE only when every enabled gateway is the observation-only Fincra one.
   */
  public static function fincraOnly(): bool {
    if (!\Drupal::entityTypeManager()->hasDefinition('commerce_payment_gateway')) {
      return FALSE;
    }
    $gateways = \Drupal::entityTypeManager()->getStorage('commerce_payment_gateway')->loadByProperties(['status' => TRUE]);
    if ($gateways === []) {
      return FALSE;
    }
    foreach ($gateways as $gateway) {
      if (!$gateway instanceof PaymentGatewayInterface || $gateway->getPluginId() !== 'fincra_standard') {
        return FALSE;
      }
    }
    return TRUE;
  }

}
