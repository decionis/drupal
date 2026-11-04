<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Report;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\decionis_commerce\Map\Cost;

/**
 * Cost coverage for all variation types and order lines in the report window.
 */
final class Coverage {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Cost $cost,
    private readonly History $history,
  ) {}

  /**
   * Returns separate variation and order-line shares, never monetary guesses.
   */
  public function totals(int $days = 90): array {
    $lines = ['total' => 0, 'costed' => 0];
    $currencies = [];
    $after = 0;
    $until = time();
    do {
      $batch = $this->history->batch('order', max(1, min(365, $days)), $after, $until);
      foreach ($batch['records'] as $record) {
        $currency = $record['currency'];
        $line = $currencies[$currency] ?? ['total' => 0, 'costed' => 0];
        $line['total'] += $record['lines'];
        $line['costed'] += $record['costed_lines'];
        $currencies[$currency] = $line;
        $lines['total'] += $record['lines'];
        $lines['costed'] += $record['costed_lines'];
      }
      $after = $batch['after'];
    } while ($after !== NULL);
    return ['variations' => $this->variations(), 'order_lines' => self::share($lines), 'by_currency' => array_map([self::class, 'share'], $currencies)];
  }

  /**
   * All variation types, read in bounded pages and checked in their currency.
   */
  public function variations(): array {
    $totals = ['total' => 0, 'costed' => 0];
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $after = 0;
    do {
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('variation_id', $after, '>')->sort('variation_id')->range(0, 100)->execute();
      foreach ($storage->loadMultiple($ids) as $variation) {
        if (!$variation instanceof ProductVariationInterface) {
          continue;
        }
        $totals['total']++;
        if ($this->cost->of($variation, $variation->getPrice()?->getCurrencyCode()) !== NULL) {
          $totals['costed']++;
        }
      }
      $storage->resetCache($ids);
      $after = $ids === [] ? NULL : (int) max($ids);
    } while ($after !== NULL);
    return self::share($totals);
  }

  /**
   * Formats a numerator and denominator without hiding an empty history.
   */
  public static function share(array $counts): array {
    return $counts + ['percent' => $counts['total'] > 0 ? round(100 * $counts['costed'] / $counts['total'], 1) : 0.0];
  }

}
