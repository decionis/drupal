<?php

declare(strict_types=1);

namespace Drupal\decionis\Report;

use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;

/**
 * Read-only, currency-separated impact estimates from completed decisions.
 */
final class Activity {

  public const REPLAY_CAVEAT = 'Historical refund replay counts cumulative refunded payments. Each amount is the total refunded on one payment, with an approximate payment timestamp: last changed in Commerce 3, completed in Commerce 2. It cannot establish whether an individual refund exceeded a limit. Outside webhook entries count the refund deltas observed on this site.';

  public const LABELS = [
    'order_acceptance' => 'Orders',
    'price_change' => 'Price changes',
    'promotion_change' => 'Promotions',
    'inventory_mutation' => 'Stock',
    'refund_request' => 'Refunds',
    'agent_tool' => 'Agent tool calls',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly StateInterface $state,
  ) {}

  /**
   * Aggregates a window, retaining at most five catches per currency.
   *
   * Pending evaluations, demo authority and outside observations cannot be
   * predictions of what an enforced Drupal action would stop.
   */
  public function window(int $since, ?string $mode = NULL): array {
    $query = $this->database->select('decionis_decision', 'd')
      ->fields('d', ['id', 'action', 'source', 'mode', 'verdict', 'effect', 'reasons', 'at_stake', 'currency', 'policy_version', 'dossier_id', 'target_type', 'target_id', 'created', 'checkout'])
      ->where('COALESCE(d.occurred, d.created) >= :activity_since', [':activity_since' => $since])
      ->condition('authority', Summary::DEMO, '<>')
      ->orderBy('id');
    if ($mode !== NULL) {
      $query->condition('mode', $mode);
    }
    $run = $this->state->get('decionis_commerce.replay');
    if (is_array($run) && is_array($run['ids'] ?? NULL)) {
      // Older policy versions remain evidence in the raw log. The current
      // forecast must not count the same historical action for each version.
      $scope = $query->orConditionGroup()->condition('source', 'replay', '<>');
      $ids = array_values(array_filter($run['ids'], static fn ($id): bool => is_int($id) && $id > 0));
      if ($ids !== []) {
        $scope->condition('id', $ids, 'IN');
      }
      $query->condition($scope);
    }
    $result = ['reviewed' => 0, 'pending' => 0, 'outside' => 0, 'not_covered' => 0, 'ungoverned' => 0, 'by_action' => [], 'top' => [], 'dossiers' => [], 'has_replay' => FALSE, 'replay' => is_array($run) ? self::replayLabel($run) : ''];
    foreach ($query->execute() as $entry) {
      $row = (array) $entry;
      $row['action'] = (string) $row['action'];
      $row['source'] = (string) $row['source'];
      $result['has_replay'] = $result['has_replay'] || $row['source'] === 'replay';
      if (in_array($row['effect'], ['pending', 'evaluating'], TRUE)) {
        $result['pending']++;
        continue;
      }
      if (is_string($row['dossier_id']) && $row['dossier_id'] !== '') {
        $result['dossiers'][(int) $row['id']] = $row['dossier_id'];
      }
      if (in_array($row['effect'], ['outside', 'not_covered', 'ungoverned', 'failed_closed'], TRUE)) {
        $counter = $row['effect'] === 'failed_closed' ? 'ungoverned' : $row['effect'];
        $result[$counter]++;
        continue;
      }
      if (!in_array($row['verdict'], ['ALLOW', 'HOLD', 'BLOCK', 'ESCALATE'], TRUE)) {
        continue;
      }
      $result['reviewed']++;
      $currency = (string) $row['currency'];
      $action = (string) $row['action'];
      $line = $result['by_action'][$action][$currency] ?? ['count' => 0, 'covered' => 0.0, 'flagged' => 0, 'value' => 0.0, 'approvals' => 0];
      $line['count']++;
      $line['covered'] += (float) $row['at_stake'];
      $needsApproval = in_array($row['verdict'], ['HOLD', 'ESCALATE'], TRUE);
      // Checkout does not hold a shopper for approval. A historical replay
      // cannot establish whether the original order came through checkout.
      $passesCheckout = $needsApproval && (!empty($row['checkout']) || ($action === 'order_acceptance' && ($row['checkout'] === NULL || $row['source'] === 'replay')));
      if (in_array($row['verdict'], ['HOLD', 'BLOCK', 'ESCALATE'], TRUE) && !$passesCheckout) {
        $line['flagged']++;
        $line['value'] += (float) $row['at_stake'];
        $line['approvals'] += $needsApproval ? 1 : 0;
        $codes = json_decode((string) $row['reasons'], TRUE);
        $row['reasons'] = is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [];
        $result['top'][$currency][] = $row;
        usort($result['top'][$currency], static fn (array $a, array $b): int => ((float) $b['at_stake'] <=> (float) $a['at_stake']) ?: ((int) $b['id'] <=> (int) $a['id']));
        $result['top'][$currency] = array_slice($result['top'][$currency], 0, 5);
      }
      $result['by_action'][$action][$currency] = $line;
    }
    ksort($result['top']);
    return $result;
  }

  /**
   * Formats value without ever converting or comparing different currencies.
   */
  public static function money(float $amount, string $currency): string {
    return trim($currency . ' ' . number_format($amount, 2));
  }

  /**
   * Distinguishes historical payment totals from observed refund events.
   */
  public static function label(string $action, string $source = ''): string {
    return $source === 'replay' && $action === 'refund_request' ? 'Refunded payments (cumulative)' : (self::LABELS[$action] ?? $action);
  }

  /**
   * Describes the separate historical replay scope without exposing its inputs.
   */
  private static function replayLabel(array $run): string {
    return sprintf('Connection replay: %d-day source window ending %s UTC; policy %s; status %s; %d checks unavailable. Totals use its current recorded references within the report window. Earlier policy versions remain in the raw decision log.', (int) ($run['days'] ?? 90), gmdate('Y-m-d', (int) ($run['until'] ?? time())), is_string($run['policy'] ?? NULL) ? $run['policy'] : 'workspace active policy', (string) ($run['status'] ?? 'unknown'), (int) ($run['unavailable'] ?? 0));
  }

}
