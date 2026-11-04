<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Approval;

use Decionis\Client\Decision\Action;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\decionis\Approval\Executor;
use Drupal\decionis\Entity\Hold;
use Drupal\decionis_commerce\Map\Actions;

/**
 * Runs an approved refund through the payment's own gateway, once.
 */
final class RefundExecutor implements Executor {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Actions $actions,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handles(string $action): bool {
    return $action === 'refund_request';
  }

  /**
   * {@inheritdoc}
   */
  public function capture(Hold $hold): ?Action {
    [$payment, $amount] = $this->target($hold);
    return $payment === NULL || $amount === NULL ? NULL : $this->actions->refund($payment, $amount, $hold->value('hook'), 'drupal', $hold->actor())[0];
  }

  /**
   * {@inheritdoc}
   */
  public function run(Hold $hold): void {
    [$payment, $amount] = $this->target($hold);
    $gateway = $payment?->getPaymentGateway()?->getPlugin();
    if ($payment === NULL || $amount === NULL || !$gateway instanceof SupportsRefundsInterface) {
      throw new \LogicException('DECIONIS_REFUND_NOT_EXECUTABLE');
    }
    $gateway->refundPayment($payment, $amount);
  }

  /**
   * The payment and the held amount, read fresh from storage.
   *
   * @return array
   *   The payment and held amount, with NULL for either unavailable value.
   *
   * @phpstan-return array{?PaymentInterface, ?Price}
   */
  private function target(Hold $hold): array {
    $payment = $this->entityTypeManager->getStorage('commerce_payment')->loadUnchanged($hold->value('target_id'));
    $parameters = $hold->binding()['action']['parameters'] ?? [];
    $amount = is_array($parameters) && is_numeric($parameters['amount'] ?? NULL) && is_string($parameters['currency'] ?? NULL)
      ? new Price((string) $parameters['amount'], $parameters['currency'])
      : NULL;
    return [$payment instanceof PaymentInterface ? $payment : NULL, $amount];
  }

}
