<?php

declare(strict_types=1);

namespace Drupal\decionis\Report;

use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;

/**
 * Adds up the decision log.
 *
 * What was reviewed, the value covered, and what the policy held or blocked, or
 * would have, per currency.
 */
final class Summary {

  /**
   * The bundled demo authority's label: its verdicts are not the store's.
   */
  public const DEMO = 'demo authority';

  public function __construct(private readonly Connection $database, private readonly ?StateInterface $state = NULL) {}

  /**
   * The newest log entry id, to total only what a run adds after it.
   */
  public function lastId(): int {
    return (int) $this->database->select('decionis_decision', 'd')->fields('d', ['id'])->orderBy('d.id', 'DESC')->range(0, 1)->execute()->fetchField();
  }

  /**
   * Totals decisions for a time window and optional source.
   *
   * Demo-authority decisions are excluded unless explicitly requested.
   *
   * @param int $sinceTimestamp
   *   Earliest included source event or creation time, as a Unix timestamp.
   * @param string|null $source
   *   Optional source filter, such as live or replay.
   * @param int $afterId
   *   Only include log entries with IDs greater than this value.
   * @param bool $demo
   *   Whether to include decisions from the bundled demo authority.
   * @param list<int>|null $ids
   *   Canonical decision ids visited by a replay, including upserted rows.
   *
   * @return array{reviewed: int, pending: int, ungoverned: int, outside: int, not_covered: int, by_action: array<string, array<string, array{count: int, held: float, covered: float}>>}
   *   Decision counts and values at stake grouped by action and currency.
   */
  public function totals(int $sinceTimestamp, ?string $source = NULL, int $afterId = 0, bool $demo = FALSE, ?array $ids = NULL): array {
    $totals = ['reviewed' => 0, 'pending' => 0, 'ungoverned' => 0, 'outside' => 0, 'not_covered' => 0, 'by_action' => []];
    if ($ids === []) {
      return $totals;
    }
    $query = $this->database->select('decionis_decision', 'd')
      ->fields('d', ['action', 'verdict', 'effect', 'currency', 'checkout', 'source'])
      ->where('COALESCE(d.occurred, d.created) >= :since', [':since' => $sinceTimestamp])
      ->condition('d.id', $afterId, '>');
    $query->addExpression('COUNT(*)', 'count');
    $query->addExpression('SUM(COALESCE(d.at_stake, 0))', 'value');
    $query->groupBy('d.action')->groupBy('d.verdict')->groupBy('d.effect')->groupBy('d.currency')->groupBy('d.checkout')->groupBy('d.source');
    if ($source !== NULL) {
      $query->condition('d.source', $source);
    }
    if ($ids !== NULL) {
      $query->condition('d.id', array_values(array_unique($ids)), 'IN');
    }
    else {
      $latest = $this->state?->get('decionis_commerce.replay');
      if (is_array($latest) && is_array($latest['ids'] ?? NULL)) {
        // Keep older policy evidence, but do not count one historical event
        // again every time a merchant runs it under a different policy.
        $current = $query->orConditionGroup()->condition('d.source', 'replay', '<>')->isNull('d.source');
        if ($latest['ids'] !== []) {
          $current->condition('d.id', $latest['ids'], 'IN');
        }
        $query->condition($current);
      }
    }
    if (!$demo) {
      $query->condition($query->orConditionGroup()->isNull('d.authority')->condition('d.authority', self::DEMO, '<>'));
    }
    foreach ($query->execute() as $row) {
      $count = (int) $row->count;
      if (in_array($row->effect, ['pending', 'evaluating'], TRUE)) {
        $totals['pending'] += $count;
        continue;
      }
      $value = (float) $row->value;
      $currency = (string) ($row->currency ?? '');
      $totals['reviewed'] += $count;
      match ((string) $row->effect) {
        'ungoverned', 'failed_closed' => $totals['ungoverned'] += $count,
        'outside' => $totals['outside'] += $count,
        'not_covered' => $totals['not_covered'] += $count,
        default => NULL,
      };
      $line = $totals['by_action'][(string) $row->action][$currency] ?? ['count' => 0, 'held' => 0.0, 'covered' => 0.0];
      $line['count'] += $count;
      $line['covered'] += $value;
      $checkout = (bool) $row->checkout || ($row->action === 'order_acceptance' && ($row->checkout === NULL || $row->source === 'replay'));
      if ($row->verdict === 'BLOCK' || ($row->verdict === 'ESCALATE' && !$checkout)) {
        $line['held'] += $value;
      }
      $totals['by_action'][(string) $row->action][$currency] = $line;
    }
    return $totals;
  }

}
