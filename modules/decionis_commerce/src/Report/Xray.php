<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Report;

use Drupal\decionis\Report\Summary;
use Drupal\decionis_commerce\Sandbox\Local;

/**
 * Totals and the five largest safe references per action and currency.
 *
 * No local estimate is represented as a signed decision or retained Dossier.
 */
final class Xray {

  public const STATE = 'decionis_commerce.xray';
  public const LABEL = 'estimate on your site, using suggested limits';

  /**
   * An empty report also preserves the existing Drush replay result shape.
   */
  public static function empty(): array {
    return ['label' => Summary::DEMO, 'estimate' => self::LABEL, 'reviewed' => 0, 'ungoverned' => 0, 'outside' => 0, 'not_covered' => 0, 'by_action' => [], 'currencies' => [], 'top' => []];
  }

  /**
   * An empty per-currency headline.
   */
  public static function currency(): array {
    return ['orders_below' => 0, 'value_below' => 0.0, 'refunds_over' => 0, 'refund_value' => 0.0, 'stacked_promotions' => 0, 'outside_refunds' => 0, 'outside_value' => 0.0, 'order_lines' => 0, 'costed_lines' => 0];
  }

  /**
   * Reduces one batch immediately; its individual records are not retained.
   */
  public static function collect(array $records, array $limits): array {
    $result = self::empty();
    foreach ($records as $record) {
      $action = $record['action'];
      $currency = $record['currency'];
      $chosen = $limits[$currency] ?? Limits::defaults($currency);
      $headline = $result['currencies'][$currency] ?? self::currency();
      $line = $result['by_action'][$action][$currency] ?? ['count' => 0, 'held' => 0.0, 'covered' => 0.0, 'flagged' => 0, 'below' => 0.0];
      $result['reviewed']++;
      $line['count']++;
      if ($record['covered']) {
        $line['covered'] += $record['amount'];
      }
      [$verdict, $reason] = $record['covered'] ? Local::rule(strtoupper($action), $record['signals'], $chosen) : ['APPROVE', NULL];
      $flagged = in_array($verdict, ['REJECT', 'REVIEW'], TRUE);
      $below = 0.0;
      if (!$record['covered']) {
        $result['not_covered']++;
      }
      if ($action === 'order_acceptance') {
        $headline['order_lines'] += $record['lines'];
        $headline['costed_lines'] += $record['costed_lines'];
        if ($reason === 'below_margin_floor') {
          $below = max(0.0, $record['amount'] * ((float) $chosen['margin_floor'] - (float) $record['signals']['net_margin_percent']) / 100);
          $headline['orders_below']++;
          $headline['value_below'] += $below;
          $line['below'] += $below;
        }
      }
      elseif ($action === 'refund_request' && $record['amount'] > (float) $chosen['refund_limit']) {
        $headline['refunds_over']++;
        $headline['refund_value'] += $record['amount'];
      }
      elseif ($action === 'promotion_change' && $reason === 'unauthorized_coupon_stacking') {
        $headline['stacked_promotions']++;
      }
      elseif ($action === 'outside_refund') {
        $result['outside']++;
        $headline['outside_refunds']++;
        $headline['outside_value'] += $record['amount'];
      }
      if ($flagged || $action === 'outside_refund') {
        if ($flagged) {
          $line['flagged']++;
          $line['held'] += $record['amount'];
        }
        $top = $result['top'][$action][$currency] ?? [];
        $top[] = ['id' => $record['id'], 'amount' => $record['amount'], 'below' => round($below, 6), 'reason' => $reason ?? 'outside_refund'];
        $result['top'][$action][$currency] = self::largest($top);
      }
      $result['by_action'][$action][$currency] = $line;
      $result['currencies'][$currency] = $headline;
    }
    return $result;
  }

  /**
   * Adds a batch without retaining its rows or mixing currencies.
   */
  public static function merge(array $total, array $batch): array {
    foreach (['reviewed', 'ungoverned', 'outside', 'not_covered'] as $key) {
      $total[$key] += $batch[$key];
    }
    foreach ($batch['currencies'] as $currency => $values) {
      $line = $total['currencies'][$currency] ?? self::currency();
      foreach ($values as $key => $value) {
        $line[$key] += $value;
      }
      $total['currencies'][$currency] = $line;
    }
    foreach ($batch['by_action'] as $action => $currencies) {
      foreach ($currencies as $currency => $values) {
        $line = $total['by_action'][$action][$currency] ?? array_fill_keys(array_keys($values), 0);
        foreach ($values as $key => $value) {
          $line[$key] += $value;
        }
        $total['by_action'][$action][$currency] = $line;
      }
    }
    foreach ($batch['top'] as $action => $currencies) {
      foreach ($currencies as $currency => $items) {
        $total['top'][$action][$currency] = self::largest([...($total['top'][$action][$currency] ?? []), ...$items]);
      }
    }
    ksort($total['currencies']);
    return $total;
  }

  /**
   * Deterministic largest items; opaque local entity ids break ties.
   */
  private static function largest(array $items): array {
    usort($items, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount'] ?: strcmp($a['id'], $b['id']));
    return array_slice($items, 0, 5);
  }

}
