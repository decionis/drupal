<?php

declare(strict_types=1);

namespace Drupal\decionis\Database;

use Drupal\Core\Database\Connection;
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
   * A schema change can make MySQL commit and the driver void the transaction.
   * Only commit a handle still present in its connection's transaction stack;
   * destruction purges a voided handle and runs its remaining callbacks.
   *
   * @param \Drupal\Core\Database\Transaction|null $transaction
   *   The open transaction, or NULL once it has been ended.
   * @param \Drupal\Core\Database\Connection $connection
   *   The connection that started the transaction.
   *
   * @param-out null $transaction
   */
  public static function transaction(?Transaction &$transaction, Connection $connection): void {
    if ($transaction !== NULL && method_exists($transaction, 'commitOrRelease') && $connection->transactionManager()?->has($transaction->name())) {
      $transaction->commitOrRelease();
    }
    // The last reference ends the transaction on cores without the method.
    $transaction = NULL;
  }

}
