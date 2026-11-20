<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Kernel;

use Drupal\Core\Database\Transaction;
use Drupal\Core\Database\TransactionOutOfOrderException;
use Drupal\decionis\Database\Commit;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Commits live transactions without operating on handles core already voided.
 *
 * @group decionis
 */
#[RunTestsInSeparateProcesses]
final class CommitTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'decionis'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('database')->schema()->createTable('decionis_commit_test', [
      'fields' => ['id' => ['type' => 'int', 'not null' => TRUE]],
      'primary key' => ['id'],
    ]);
  }

  /**
   * Commits a live root and releases a savepoint on all supported cores.
   */
  public function testCommitsLiveRootAndReleasesSavepoint(): void {
    $database = $this->container->get('database');
    $root = $database->startTransaction();
    $database->insert('decionis_commit_test')->fields(['id' => 1])->execute();
    $savepoint = $database->startTransaction('decionis_nested');
    $database->insert('decionis_commit_test')->fields(['id' => 2])->execute();

    Commit::transaction($savepoint, $database);
    $this->assertTrue($database->inTransaction());
    Commit::transaction($root, $database);
    $this->assertFalse($database->inTransaction());
    $this->assertSame(2, (int) $database->select('decionis_commit_test')->countQuery()->execute()->fetchField());
  }

  /**
   * Disposing of a voided savepoint must not commit a newer transaction.
   */
  public function testVoidedHandleDoesNotCommitAnotherTransaction(): void {
    if (!method_exists(Transaction::class, 'commitOrRelease')) {
      $this->markTestSkipped('Explicit commit can void outliving handles on Drupal 11.3 and later.');
    }
    $database = $this->container->get('database');
    $root = $database->startTransaction();
    $savepoint = $database->startTransaction('decionis_nested');
    $database->insert('decionis_commit_test')->fields(['id' => 1])->execute();
    Commit::transaction($root, $database);

    $next = $database->startTransaction();
    $database->insert('decionis_commit_test')->fields(['id' => 2])->execute();
    Commit::transaction($savepoint, $database);
    $this->assertTrue($database->inTransaction());
    $next->rollBack();
    unset($next);
    $this->assertSame(['1'], array_map('strval', $database->select('decionis_commit_test', 't')->fields('t', ['id'])->execute()->fetchCol()));
  }

  /**
   * A reused name still reaches core's transaction identity check.
   */
  public function testReusedNameDoesNotHideAnOutOfOrderCommit(): void {
    if (!method_exists(Transaction::class, 'commitOrRelease')) {
      $this->markTestSkipped('Explicit commit can void outliving handles on Drupal 11.3 and later.');
    }
    $database = $this->container->get('database');
    $root = $database->startTransaction();
    $parent = $database->startTransaction('decionis_parent');
    $orphan = $database->startTransaction('decionis_reused');
    Commit::transaction($parent, $database);
    $replacement = $database->startTransaction('decionis_reused');

    $this->expectException(TransactionOutOfOrderException::class);
    try {
      Commit::transaction($orphan, $database);
    }
    finally {
      $replacement->rollBack();
      unset($replacement);
      $root->rollBack();
      unset($root);
    }
  }

}
