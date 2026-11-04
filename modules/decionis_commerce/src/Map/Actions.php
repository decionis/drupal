<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Map;

use Decionis\Client\Decision\Action;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_promotion\Entity\PromotionInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\decionis\Gate\Context;
use Drupal\decionis\Gate\Signals;
use Drupal\decionis_commerce\Compatibility;

/**
 * Maps Drupal Commerce writes onto Commerce Gate actions, unchanged in type.
 *
 * Only bounded commerce facts leave the site: amounts, SKUs, cost base,
 * counts, and keyed hashes of the customer and the acting user.
 */
final class Actions {

  public const PLATFORM = 'drupal';

  /**
   * The key prefix every action mapped inside scoped() takes instead.
   */
  private ?string $scope = NULL;

  public function __construct(
    private readonly Cost $cost,
    private readonly Compatibility $compatibility,
    private readonly AccountProxyInterface $currentUser,
    private readonly Connection $database,
    private readonly Attempts $attempts,
  ) {}

  /**
   * Maps every action inside $work under one key prefix (the sandbox's run).
   *
   * Keys from a rolled-back run would otherwise name entity ids a later live
   * action reuses, and the authority would answer it from the run's decision.
   */
  public function scoped(string $prefix, callable $work): mixed {
    $previous = $this->scope;
    $this->scope = $prefix;
    try {
      return $work();
    }
    finally {
      $this->scope = $previous;
    }
  }

  /**
   * A refund of $amount on a payment, before the gateway is called.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment whose refundable balance is checked.
   * @param \Drupal\commerce_price\Price $amount
   *   The requested refund amount and currency.
   * @param string $hook
   *   The integration point requesting the decision.
   * @param string $prefix
   *   The idempotency key prefix outside a scoped run.
   * @param array{type: string, id: string}|null $actor
   *   Who asks for it; NULL is the current user. An approved refund keeps
   *   its requester.
   *
   * @return array
   *   The refund action and its local decision context.
   *
   * @phpstan-return array{Action, Context}
   */
  public function refund(PaymentInterface $payment, Price $amount, string $hook, string $prefix = 'drupal', ?array $actor = NULL): array {
    $actor ??= $this->actor();
    $order = $payment->getOrder();
    $customer = $order === NULL ? NULL : $this->customer($order);
    $number = self::number($amount);
    $balance = $payment->getBalance();
    $key = sprintf('%s:refund:%s:%s:%s:%s', $this->scope ?? $prefix, $payment->id() ?? 'new', self::number($payment->getRefundedAmount() ?? new Price('0', $amount->getCurrencyCode())), $number, self::tag($actor));
    $action = Action::refund($actor, self::PLATFORM, $key, (string) ($order?->id() ?? $payment->getOrderId()), $number, $amount->getCurrencyCode(), NULL, $balance === NULL ? NULL : max(0.0, self::number($balance)), $this->priorRefunds($payment));
    $facts = $order === NULL || $customer === NULL ? [] : ['customer_hash' => $customer, 'refunds_per_customer_90d' => $this->refundsFor($order, $payment)];
    return [$action, new Context('refund_request', $hook, FALSE, 'commerce_payment', (string) $payment->id(), $number, $amount->getCurrencyCode(), $facts)];
  }

  /**
   * A variation price change, or NULL when the price did not change.
   *
   * @param \Drupal\commerce_product\Entity\ProductVariationInterface $variation
   *   The variation with its proposed price.
   * @param \Drupal\commerce_price\Price|null $from
   *   The previous price, or NULL for a new variation.
   * @param string $hook
   *   The integration point requesting the decision.
   * @param string $prefix
   *   The idempotency key prefix outside a scoped run.
   * @param array{type: string, id: string}|null $actor
   *   Who asks for it; NULL is the current user.
   *
   * @return array|null
   *   The price action and local context, or NULL when no price changed.
   *
   * @phpstan-return array{Action, Context}|null
   */
  public function price(ProductVariationInterface $variation, ?Price $from, string $hook, string $prefix = 'drupal', ?array $actor = NULL): ?array {
    $to = $variation->getPrice();
    if ($to === NULL || ($from !== NULL && $from->equals($to))) {
      return NULL;
    }
    $actor ??= $this->actor();
    $cost = $this->cost->of($variation, $to->getCurrencyCode());
    // The cost base is in the key: the same price against a new cost is a
    // new decision.
    $key = sprintf('%s:price:%s:%s:%s:%s:%s', $this->scope ?? $prefix, $variation->id() ?? $variation->uuid(), $from === NULL ? 'new' : self::number($from), self::number($to), $cost === NULL ? 'nocost' : round($cost, 6), self::tag($actor));
    $action = Action::price($actor, self::PLATFORM, $key, (string) ($variation->getSku() ?: $variation->uuid()), $from === NULL ? NULL : self::number($from), self::number($to), $cost, $to->getCurrencyCode());
    $facts = $cost === NULL ? ['margin_coverage' => 'not_covered'] : [];
    return [$action, new Context('price_change', $hook, FALSE, 'commerce_product_variation', (string) ($variation->id() ?? ''), self::number($to), $to->getCurrencyCode(), $facts)];
  }

  /**
   * A promotion as it is about to be saved, with the discounts it stacks on.
   *
   * @return array
   *   The promotion action and its local decision context.
   *
   * @phpstan-return array{Action, Context}
   */
  public function promotion(PromotionInterface $promotion, string $hook, string $prefix = 'drupal'): array {
    $fraction = $this->compatibility->percentage($promotion);
    $combines = $this->compatibility->combinesWith($promotion);
    $stacked = $fraction === NULL ? NULL : $fraction + ($combines['order_discounts'] ? $this->stackable($promotion) : 0.0);
    $end = $promotion->getEndDate();
    $couponCount = $promotion->get('coupons')->count();
    $key = sprintf('%s:promotion:%s:%s', $this->scope ?? $prefix, $promotion->id() ?? $promotion->uuid(), substr(hash('sha256', serialize([$fraction, $end?->format('c'), $promotion->isEnabled(), $combines, $couponCount])), 0, 16));
    $action = Action::promotion($this->actor(), self::PLATFORM, $key, (string) ($promotion->id() ?? $promotion->uuid()), $fraction, $end?->format('c'), $promotion->isEnabled() ? 'active' : 'disabled', $combines);
    $facts = [
      'coupon_count' => $couponCount,
      'store_currencies' => array_values(array_unique(array_map(static fn ($store): string => $store->getDefaultCurrencyCode(), $promotion->getStores()))),
      'stacked_discount_percent' => $stacked === NULL ? NULL : round(min(1.0, $stacked) * 100, 6),
      'has_end_date' => $end !== NULL,
    ];
    return [$action, new Context('promotion_change', $hook, FALSE, 'commerce_promotion', (string) ($promotion->id() ?? ''), NULL, NULL, $facts)];
  }

  /**
   * Maps an order about to be placed when all items have a cost base.
   *
   * @return array|null
   *   The order action and local context, or NULL when its margin is uncovered.
   *
   * @phpstan-return array{Action, Context}|null
   */
  public function order(OrderInterface $order, string $hook, bool $checkout, string $prefix = 'drupal', ?float $started = NULL): ?array {
    $total = $order->getTotalPrice();
    if ($total === NULL) {
      return NULL;
    }
    $currency = $total->getCurrencyCode();
    $gross = 0.0;
    $cost = 0.0;
    foreach ($order->getItems() as $item) {
      $entity = $item->getPurchasedEntity();
      $unit = $entity === NULL ? NULL : $this->cost->of($entity, $currency);
      if ($unit === NULL) {
        return NULL;
      }
      $cost += $unit * (float) $item->getQuantity();
      $gross += self::number($item->getTotalPrice() ?? new Price('0', $currency));
    }
    $discount = 0.0;
    foreach ($order->collectAdjustments() as $adjustment) {
      $value = self::number($adjustment->getAmount());
      $type = $adjustment->getType();
      if (in_array($type, ['promotion', 'custom', 'shipping_promotion'], TRUE) && $value < 0) {
        $discount -= $value;
        // An included discount has already lowered the price: gross it back
        // up, so it is taken off once.
        if ($adjustment->isIncluded()) {
          $gross -= $value;
        }
      }
      elseif ($type === 'shipping' && $value > 0) {
        $gross += $value;
      }
      elseif ($type === 'tax' && $adjustment->isIncluded()) {
        // Tax inside the price is not revenue. Tax added on top was never in
        // gross.
        $gross -= $value;
      }
    }
    $discount = min($discount, $gross);
    $key = sprintf('%s:order:%s:%s:%s', $this->scope ?? $prefix, $order->id() ?? $order->uuid(), $hook, self::number($total));
    $action = Action::order(['type' => $checkout ? 'WORKFLOW' : $this->actor()['type'], 'id' => $checkout ? 'drupal-checkout' : $this->actor()['id']], self::PLATFORM, $key, (string) ($order->id() ?? $order->uuid()), round($gross, 6), round($discount, 6), round($cost, 6), $currency);
    $customer = $this->customer($order);
    $facts = ['customer_hash' => $customer, 'orders_last_hour' => $this->ordersFor($order), 'coupon_count' => count($order->get('coupons')->getValue())];
    if ($checkout) {
      // The shopper's own session: coupon codes tried in the last hour.
      $facts['coupon_attempts_session'] = $this->attempts->count();
    }
    return [$action, new Context('order_acceptance', $hook, $checkout, 'commerce_order', (string) ($order->id() ?? ''), round($gross - $discount, 6), $currency, $facts, started: $started)];
  }

  /**
   * A stock adjustment from $from to $from + $delta units.
   *
   * @return array
   *   The inventory action and its local decision context.
   *
   * @phpstan-return array{Action, Context}
   */
  public function stock(string $sku, string $entityId, float $from, float $delta, ?string $location, string $hook, string $prefix = 'drupal'): array {
    $level = (int) floor($from);
    $after = (int) floor($from + $delta);
    $key = sprintf('%s:stock:%s:%s:%s:%s', $this->scope ?? $prefix, $entityId, $location ?? 'any', $level, $after);
    $action = Action::inventory($this->actor(), self::PLATFORM, $key, $sku, max(0, $level), max(0, $after), $location);
    $facts = ['adjustment_units' => $delta, 'shortfall_units' => $after < 0 ? -$after : 0];
    return [$action, new Context('inventory_mutation', $hook, FALSE, 'commerce_product_variation', $entityId, NULL, NULL, $facts)];
  }

  /**
   * The acting user as a hashed id; no name or email leaves the site.
   *
   * @return array
   *   The actor type and its site-scoped, hashed identifier.
   *
   * @phpstan-return array{type: string, id: string}
   */
  public function actor(): array {
    $uid = (int) $this->currentUser->id();
    return $uid === 0
      ? ['type' => 'WORKFLOW', 'id' => 'drupal:system']
      : ['type' => 'HUMAN', 'id' => 'drupal:user:' . Signals::hash('user', $uid)];
  }

  /**
   * The order's customer as a keyed hash: the account, else the email.
   */
  public function customer(OrderInterface $order): string {
    $uid = (int) $order->getCustomerId();
    return $uid > 0 ? Signals::hash('customer', $uid) : Signals::hash('email', mb_strtolower((string) $order->getEmail()));
  }

  /**
   * Counts refunded payments already on the payment's order.
   */
  private function priorRefunds(PaymentInterface $payment): int {
    $query = $this->database->select('commerce_payment', 'p')
      ->condition('p.order_id', $payment->getOrderId())
      ->condition('p.state', ['partially_refunded', 'refunded'], 'IN');
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Other payments refunded to this customer in the last 90 days.
   *
   * It counts refunds made, never the decision log: a refund asked twice,
   * or evaluated on the form and again on submit, is still one refund.
   */
  private function refundsFor(OrderInterface $order, PaymentInterface $payment): int {
    $query = $this->database->select('commerce_payment', 'p');
    $query->join('commerce_order', 'o', 'o.order_id = p.order_id');
    $query->condition('p.state', ['partially_refunded', 'refunded'], 'IN')
      ->condition('p.' . $this->compatibility->refundedAt(), time() - 90 * 86400, '>=');
    $query->condition($query->orConditionGroup()->isNull('p.remote_state')->condition('p.remote_state', 'failed', '<>'));
    if ($payment->id() !== NULL) {
      $query->condition('p.payment_id', $payment->id(), '<>');
    }
    $uid = (int) $order->getCustomerId();
    $uid > 0 ? $query->condition('o.uid', $uid) : $query->condition('o.mail', (string) $order->getEmail());
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * A short, stable tag for the actor inside an idempotency key.
   *
   * @param array{type: string, id: string} $actor
   *   The actor type and identifier to bind into the key.
   */
  private static function tag(array $actor): string {
    return substr(hash('sha256', $actor['type'] . ':' . $actor['id']), 0, 12);
  }

  /**
   * Orders this customer placed in the last hour (order bursts).
   */
  private function ordersFor(OrderInterface $order): int {
    $query = $this->database->select('commerce_order', 'o')->condition('o.placed', time() - 3600, '>=');
    $uid = (int) $order->getCustomerId();
    $uid > 0 ? $query->condition('o.uid', $uid) : $query->condition('o.mail', (string) $order->getEmail());
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * The percentage other active, combinable promotions already take off.
   */
  private function stackable(PromotionInterface $promotion): float {
    $stores = $promotion->getStoreIds();
    if ($stores === []) {
      return 0.0;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('commerce_promotion');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('status', 1)->condition('stores', $stores, 'IN')->condition('compatibility', PromotionInterface::COMPATIBLE_ANY)->sort('promotion_id')->range(0, 50)->execute();
    $total = 0.0;
    foreach ($storage->loadMultiple($ids) as $other) {
      if ($other instanceof PromotionInterface && $other->id() !== $promotion->id() && array_intersect($other->getStoreIds(), $promotion->getStoreIds()) !== []) {
        $total += $this->compatibility->percentage($other) ?? 0.0;
      }
    }
    return $total;
  }

  /**
   * Reads a price as a float rounded to six decimals.
   */
  private static function number(Price $price): float {
    return round((float) $price->getNumber(), 6);
  }

}
