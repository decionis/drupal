<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Report;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_promotion\Entity\PromotionInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\decionis_commerce\Compatibility;
use Drupal\decionis_commerce\Map\Actions;
use Drupal\decionis_commerce\Map\Cost;

/**
 * Bounded reads of local history, reduced immediately to non-personal facts.
 */
final class History {

  public const KINDS = ['order', 'refund', 'promotion', 'outside'];
  public const BATCH = 50;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly Actions $actions,
    private readonly Cost $cost,
    private readonly Compatibility $compatibility,
  ) {}

  /**
   * One kind in the fixed time window; never saves a commerce entity.
   */
  public function batch(string $kind, int $days, int $after, int $until): array {
    $since = $until - $days * 86400;
    if ($kind === 'outside') {
      return $this->outside($since, $until, $after);
    }
    $type = match ($kind) {
      'order' => 'commerce_order',
      'refund' => 'commerce_payment',
      'promotion' => 'commerce_promotion',
      default => throw new \InvalidArgumentException('REPLAY_KIND_INVALID'),
    };
    $storage = $this->entityTypeManager->getStorage($type);
    $id = $this->entityTypeManager->getDefinition($type)->getKey('id');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition($id, $after, '>')->sort($id)->range(0, self::BATCH);
    if ($kind === 'order') {
      $query->condition('placed', [$since, $until], 'BETWEEN')->condition('state', 'draft', '<>');
    }
    elseif ($kind === 'refund') {
      $query->condition('state', ['refunded', 'partially_refunded'], 'IN')->condition($this->compatibility->refundedAt(), [$since, $until], 'BETWEEN');
    }
    else {
      $query->condition('changed', [$since, $until], 'BETWEEN');
    }
    $ids = $query->execute();
    $records = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      if ($entity instanceof OrderInterface) {
        $records[] = $this->order($entity);
      }
      elseif ($entity instanceof PaymentInterface) {
        $record = $this->refund($entity);
        if ($record !== NULL) {
          $records[] = $record;
        }
      }
      elseif ($entity instanceof PromotionInterface) {
        array_push($records, ...$this->promotion($entity));
      }
    }
    $storage->resetCache($ids);
    if ($kind === 'order') {
      $this->entityTypeManager->getStorage('commerce_order_item')->resetCache();
      $this->entityTypeManager->getStorage('commerce_product_variation')->resetCache();
    }
    return ['records' => $records, 'after' => $ids === [] ? NULL : (int) max($ids)];
  }

  /**
   * A store currency remains visible even before its first order.
   */
  public function currencies(): array {
    $currencies = [];
    foreach ($this->entityTypeManager->getStorage('commerce_store')->loadMultiple() as $store) {
      if ($store instanceof StoreInterface) {
        $currencies[] = $store->getDefaultCurrencyCode();
      }
    }
    return array_values(array_unique($currencies));
  }

  /**
   * Keeps only numeric distributions for drafting; no customer hash survives.
   */
  public static function sample(array &$samples, array $records): void {
    foreach ($records as $record) {
      $currency = $record['currency'];
      if ($currency === '') {
        continue;
      }
      $signals = $record['signals'];
      if ($record['action'] === 'refund_request') {
        $samples[$currency]['refunds'][] = $record['amount'];
        $samples[$currency]['repeats'][] = (int) ($signals['refunds_per_customer_90d'] ?? 0);
      }
      elseif ($record['action'] === 'order_acceptance' && isset($signals['net_margin_percent'])) {
        $samples[$currency]['margins'][] = (float) $signals['net_margin_percent'];
      }
      elseif ($record['action'] === 'promotion_change' && isset($signals['stacked_discount_percent'])) {
        $samples[$currency]['stacking'][] = (float) $signals['stacked_discount_percent'];
      }
    }
  }

  /**
   * An order's margin and cost coverage, using today's recorded unit costs.
   */
  private function order(OrderInterface $order): array {
    $currency = $order->getTotalPrice()?->getCurrencyCode() ?? '';
    $covered = 0;
    $items = $order->getItems();
    foreach ($items as $item) {
      $variation = $item->getPurchasedEntity();
      if ($variation instanceof ProductVariationInterface && $this->cost->of($variation, $currency) !== NULL) {
        $covered++;
      }
    }
    $mapped = $this->actions->order($order, 'replay', FALSE, 'drupal-xray');
    $signals = $mapped === NULL ? [] : $mapped[0]->signals();
    return [
      'action' => 'order_acceptance',
      'id' => (string) $order->id(),
      'currency' => $currency,
      'amount' => $mapped === NULL ? (float) ($order->getTotalPrice()?->getNumber() ?? 0) : (float) $mapped[1]->atStake,
      'signals' => $signals,
      'lines' => count($items),
      'costed_lines' => $covered,
      'covered' => $mapped !== NULL && isset($signals['net_margin_percent']),
    ];
  }

  /**
   * Only money actually recorded as refunded, never failed Fincra charges.
   */
  private function refund(PaymentInterface $payment): ?array {
    $amount = $payment->getRefundedAmount();
    if ($payment->getRemoteState() === 'failed' || $amount === NULL || $amount->isZero()) {
      return NULL;
    }
    [$action, $context] = $this->actions->refund($payment, $amount, 'replay', 'drupal-xray');
    // Today's remaining balance is after this historical refund; comparing
    // the refund against it would falsely classify fully refunded payments.
    $signals = $action->signals();
    unset($signals['refund_exceeds_refundable']);
    $signals['refunds_per_customer_90d'] = (int) ($context->facts['refunds_per_customer_90d'] ?? 0);
    return ['action' => 'refund_request', 'id' => (string) $payment->id(), 'currency' => $amount->getCurrencyCode(), 'amount' => (float) $amount->getNumber(), 'signals' => $signals, 'covered' => TRUE];
  }

  /**
   * Current promotion configuration, never coupon codes or names.
   */
  private function promotion(PromotionInterface $promotion): array {
    [$action, $context] = $this->actions->promotion($promotion, 'replay', 'drupal-xray');
    $signals = $action->signals() + $context->facts;
    $currencies = [];
    foreach ($promotion->getStores() as $store) {
      $currencies[] = $store->getDefaultCurrencyCode();
    }
    $records = [];
    foreach (array_unique($currencies ?: $this->currencies()) as $currency) {
      $records[] = ['action' => 'promotion_change', 'id' => (string) $promotion->id(), 'currency' => $currency, 'amount' => 0.0, 'signals' => $signals + ['currency' => $currency], 'covered' => isset($signals['stacked_discount_percent'])];
    }
    return $records;
  }

  /**
   * Existing local webhook observations, not guessed outside refunds.
   */
  private function outside(int $since, int $until, int $after): array {
    $rows = $this->database->select('decionis_decision', 'd')->fields('d', ['id', 'target_id', 'at_stake', 'currency'])
      ->condition('d.action', 'refund_request')->condition('d.effect', 'outside')->condition('d.source', 'webhook')
      ->condition('d.created', [$since, $until], 'BETWEEN')->condition('d.id', $after, '>')
      ->orderBy('d.id')->range(0, self::BATCH)->execute()->fetchAll();
    $records = [];
    foreach ($rows as $row) {
      $records[] = ['action' => 'outside_refund', 'id' => (string) $row->target_id, 'currency' => (string) $row->currency, 'amount' => (float) $row->at_stake, 'signals' => [], 'covered' => TRUE];
    }
    return ['records' => $records, 'after' => $rows === [] ? NULL : (int) end($rows)->id];
  }

}
