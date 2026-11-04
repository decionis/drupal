<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Guard;

use Drupal\decionis\Gate\Context;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis\Gate\Outcome;
use Drupal\decionis_commerce\Map\Actions;

/**
 * Checks an order's margin.
 *
 * Enforce checkout uses the latency budget and fails open. Shadow records
 * pending work. The snapshot avoids checking again at the place transition;
 * the decision log holds the eventual result of deferred work.
 */
final class Orders {

  public const DATA = 'decionis';

  public function __construct(private readonly Gate $gate, private readonly Actions $actions) {}

  /**
   * Checks the action through the gate; NULL while the gate is bypassed.
   */
  public function check(OrderInterface $order, string $hook, bool $checkout): ?Outcome {
    if ($this->gate->bypassed()) {
      return NULL;
    }
    // The checkout budget starts now, before the order is mapped.
    $started = hrtime(TRUE) / 1e6;
    $mapped = $this->actions->order($order, $hook, $checkout, 'drupal', $started);
    $outcome = $mapped === NULL
      ? $this->gate->uncovered(new Context('order_acceptance', $hook, $checkout, 'commerce_order', (string) ($order->id() ?? ''), NULL, $order->getTotalPrice()?->getCurrencyCode()), 'COST_BASIS_UNKNOWN')
      : $this->gate->check(...$mapped);
    $order->setData(self::DATA, [
      'hook' => $hook,
      'effect' => $outcome->effect,
      'governed' => $outcome->effect === 'pending' ? NULL : !in_array($outcome->effect, ['ungoverned', 'not_covered'], TRUE),
      'pending' => $outcome->effect === 'pending',
      'verdict' => $outcome->verdict?->value,
      'dossier_id' => $outcome->dossierId,
      'log_id' => $outcome->logId,
    ]);
    return $outcome;
  }

  /**
   * TRUE when the order already carries a checkout decision.
   */
  public static function decided(OrderInterface $order): bool {
    $data = $order->getData(self::DATA);
    return is_array($data) && isset($data['log_id']);
  }

  /**
   * Checks whether money has already moved for an order.
   *
   * Includes onsite and offsite payments: refusing now would leave a paid
   * order refused.
   */
  public static function paid(OrderInterface $order): bool {
    $paid = $order->getTotalPaid();
    return $paid !== NULL && !$paid->isZero();
  }

}
