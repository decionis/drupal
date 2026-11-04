<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Guard;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis\Gate\Outcome;
use Drupal\decionis_commerce\Map\Actions;

/**
 * Checks a refund before the gateway is asked to move money.
 *
 * The refund form and the sandbox both go through here.
 */
final class Refunds {

  public function __construct(private readonly Gate $gate, private readonly Actions $actions) {}

  /**
   * Checks the action through the gate; NULL while the gate is bypassed.
   */
  public function check(PaymentInterface $payment, Price $amount, string $hook = 'refund_form'): ?Outcome {
    if ($this->gate->bypassed()) {
      return NULL;
    }
    [$action, $context] = $this->actions->refund($payment, $amount, $hook);
    return $this->gate->check($action, $context);
  }

}
