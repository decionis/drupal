<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Sandbox;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * What a rolled-back run depends on.
 *
 * MySQL and MariaDB commit on any schema change, and Drupal creates several of
 * its tables the first time they are used. One created inside a run would
 * commit everything written so far, so they are created before it starts.
 */
final class Isolation {

  private const PROBE = 'decionis_sandbox_probe';

  public function __construct(
    private readonly Connection $database,
    private readonly CacheTagsInvalidatorInterface $tags,
    private readonly LockBackendInterface $lock,
    private readonly QueueFactory $queues,
    private readonly FloodInterface $flood,
  ) {}

  /**
   * Creates the tables Drupal would otherwise create on first use.
   */
  public function prepare(): void {
    $schema = $this->database->schema();
    foreach (Cache::getBins() as $bin => $backend) {
      if (!$schema->tableExists('cache_' . $bin)) {
        $backend->set(self::PROBE, TRUE);
        $backend->delete(self::PROBE);
      }
    }
    if (!$schema->tableExists('cachetags')) {
      $this->tags->invalidateTags([self::PROBE]);
    }
    if (!$schema->tableExists('semaphore') && $this->lock->acquire(self::PROBE)) {
      $this->lock->release(self::PROBE);
    }
    if (!$schema->tableExists('queue')) {
      $queue = $this->queues->get(self::PROBE);
      $queue->createItem(TRUE);
      $queue->deleteQueue();
    }
    if (!$schema->tableExists('flood')) {
      $this->flood->register(self::PROBE);
      $this->flood->clear(self::PROBE);
    }
  }

  /**
   * FALSE once the database has ended the open transaction by itself.
   *
   * Only MySQL and MariaDB do. PostgreSQL and SQLite keep a schema change in
   * the transaction, and Drupal's SQLite connection does not report one here.
   */
  public function intact(): bool {
    if ($this->database->databaseType() !== 'mysql') {
      return TRUE;
    }
    $client = $this->database->getClientConnection();
    return !method_exists($client, 'inTransaction') || $client->inTransaction();
  }

}
