<?php

declare(strict_types=1);

namespace Drupal\decionis\Entity;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Makes replay identity unique even when independent workers race.
 */
final class DecisionSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);
    $table = $entity_type->getBaseTable();
    if ($table !== NULL) {
      $schema[$table]['unique keys']['decionis_replay'] = ['replay_key'];
      $schema[$table]['indexes']['decionis_pending'] = ['effect', 'lease_expires'];
      $schema[$table]['indexes']['decionis_nudge'] = ['recipient', 'nudge_state', 'save_confirmed'];
    }
    return $schema;
  }

}
