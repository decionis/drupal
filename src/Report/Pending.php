<?php

declare(strict_types=1);

namespace Drupal\decionis\Report;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Reads overdue Shadow work without dispatching or evaluating any check.
 */
final class Pending {

  public function __construct(private readonly Connection $database, private readonly TimeInterface $time) {}

  /**
   * Counts work queued more than one hour ago, using its local creation time.
   *
   * @return array{count: int, oldest_age: int}|null
   *   The count and oldest age in seconds, or NULL before the schema is ready.
   */
  public function read(): ?array {
    $schema = $this->database->schema();
    if (!$schema->tableExists('decionis_decision')) {
      return NULL;
    }
    foreach (['mode', 'effect', 'created'] as $field) {
      if (!$schema->fieldExists('decionis_decision', $field)) {
        return NULL;
      }
    }
    $now = $this->time->getCurrentTime();
    $query = $this->database->select('decionis_decision', 'd')
      ->condition('d.mode', 'shadow')
      ->condition('d.effect', ['pending', 'evaluating'], 'IN')
      ->condition('d.created', $now - 3600, '<');
    $query->addExpression('COUNT(*)', 'count');
    $query->addExpression('MIN(d.created)', 'oldest');
    $row = $query->execute()->fetchAssoc();
    $count = (int) ($row['count'] ?? 0);
    return ['count' => $count, 'oldest_age' => $count === 0 ? 0 : $now - (int) $row['oldest']];
  }

}
