<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Gateway;

use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleUninstallValidatorInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Keeps the runtime key provider installed while a gateway relies on it.
 */
final class Uninstall implements ModuleUninstallValidatorInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function validate($module): array {
    if (!in_array($module, ['decionis', 'decionis_commerce'], TRUE) || !$this->entityTypeManager->hasDefinition('commerce_payment_gateway')) {
      return [];
    }
    $record = $this->state->get(Setup::STATE, []);
    if (!is_array($record) || empty($record['gateway'])) {
      return [];
    }
    $gateway = $this->entityTypeManager->getStorage('commerce_payment_gateway')->load((string) $record['gateway']);
    if (!$gateway instanceof PaymentGatewayInterface || !Custody::matches($record, $gateway)) {
      return [];
    }
    if (!empty($record['custody']) || !empty($record['rollback_key']) || !empty($gateway->get('configuration')['secret_key_held_by_gateway'])) {
      return [(string) $this->t('This gateway still depends on Commerce Gate payment-key custody or its rollback provider. Have the host configure and verify an independent protected key provider, then remove the recorded gateway before uninstalling. Keep the private key file and custody recovery record.')];
    }
    return [];
  }

}
