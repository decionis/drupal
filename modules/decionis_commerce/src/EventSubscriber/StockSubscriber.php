<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\EventSubscriber;

use Drupal\commerce\PurchasableEntityInterface;
use Drupal\commerce_stock_local\Event\LocalStockTransactionEvent;
use Drupal\Core\Database\Connection;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis_commerce\Guard\Refused;
use Drupal\decionis_commerce\Map\Actions;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Checks manual stock adjustments with Commerce Stock.
 *
 * Write-offs, stock in and out. Sales and returns follow their order and are
 * not asked about again.
 */
final class StockSubscriber implements EventSubscriberInterface {

  /**
   * Stock in, stock out and new stock (commerce_stock transaction types). */
  private const MANUAL = [1, 2, 6];

  public function __construct(
    private readonly Gate $gate,
    private readonly Actions $actions,
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return ['commerce_stock_local.stock_transaction.create' => 'onCreate'];
  }

  /**
   * Checks a manual stock transaction before it is written.
   */
  public function onCreate(LocalStockTransactionEvent $event): void {
    $transaction = $event->getStockTransaction();
    $entity = $event->getEntity();
    if ($this->gate->bypassed() || !$entity instanceof PurchasableEntityInterface || !in_array((int) ($transaction['transaction_type_id'] ?? 0), self::MANUAL, TRUE)) {
      return;
    }
    $query = $this->database->select('commerce_stock_transaction', 't')
      ->condition('t.entity_id', $entity->id())
      ->condition('t.entity_type', $entity->getEntityTypeId());
    $query->addExpression('COALESCE(SUM(t.qty), 0)', 'level');
    $level = (float) $query->execute()?->fetchField();
    $sku = method_exists($entity, 'getSku') ? (string) $entity->getSku() : (string) $entity->id();
    $outcome = $this->gate->check(...$this->actions->stock($sku, (string) $entity->id(), $level, (float) ($transaction['qty'] ?? 0), isset($transaction['location_id']) ? (string) $transaction['location_id'] : NULL, 'stock_transaction'));
    if (!$outcome->proceeds()) {
      throw new Refused($outcome->message(FALSE));
    }
  }

}
