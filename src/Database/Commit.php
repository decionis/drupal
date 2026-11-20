<?php

declare(strict_types=1);

namespace Drupal\decionis\Database;

use Drupal\Core\Database\Transaction;

/**
 * Ends a transaction that was not rolled back, on every supported core.
 *
 * Drupal 11.3 added Transaction::commitOrRelease(), and Drupal 11.5
 * deprecated committing by letting the object go out of scope; Drupal 10 and
 * earlier 11 releases commit only that way.
 *
 * @see https://www.drupal.org/node/3524461
 */
final class Commit {

  /**
   * Commits the transaction, or releases its savepoint, and drops it.
   *
   * After MySQL has ended the transaction itself (a schema change), the core
   * driver voids it instead of committing, as it does on destruction.
   *
   * @param \Drupal\Core\Database\Transaction|null $transaction
   *   The open transaction, or NULL once it has been ended.
   *
   * @param-out null $transaction
   */
  public static function transaction(?Transaction &$transaction): void {
    if ($transaction !== NULL && method_exists($transaction, 'commitOrRelease')) {
      $transaction->commitOrRelease();
    }
    // The last reference ends the transaction on cores without the method.
    $transaction = NULL;
  }

}
