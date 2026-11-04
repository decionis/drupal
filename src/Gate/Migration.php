<?php

declare(strict_types=1);

namespace Drupal\decionis\Gate;

/**
 * Adds durable deferred work and replay identities to existing installations.
 */
final class Migration {

  public const FIELDS = ['org_id', 'replay_key', 'connection_id', 'lease_token', 'nudge_state', 'nudge_text', 'pending_capture', 'lease_expires', 'recipient', 'save_confirmed', 'occurred', 'checkout'];

  /**
   * Called by the module update hook before live requests use new fields.
   */
  public static function update(): void {
    $manager = \Drupal::entityDefinitionUpdateManager();
    $checkoutAdded = $manager->getFieldStorageDefinition('checkout', 'decionis_decision') === NULL;
    $definitions = \Drupal::service('entity_field.manager')->getBaseFieldDefinitions('decionis_decision');
    foreach (self::FIELDS as $name) {
      if ($manager->getFieldStorageDefinition($name, 'decionis_decision') === NULL) {
        $manager->installFieldStorageDefinition($name, 'decionis_decision', 'decionis', $definitions[$name]->getFieldStorageDefinition());
      }
    }
    if ($checkoutAdded) {
      \Drupal::database()->update('decionis_decision')->fields(['checkout' => 1])->condition('hook', 'checkout_review')->execute();
    }
    $schema = \Drupal::database()->schema();
    if (!$schema->indexExists('decionis_decision', 'decionis_replay')) {
      $schema->addUniqueKey('decionis_decision', 'decionis_replay', ['replay_key']);
    }
    $manager->updateEntityType(\Drupal::entityTypeManager()->getDefinition('decionis_decision'));
    // Queue backends may lazily create their tables. Do that here, outside
    // any native commerce save: MySQL DDL can otherwise commit that save.
    $queue = \Drupal::queue('decionis_shadow', TRUE);
    $item = $queue->createItem(['prepare' => TRUE]);
    if ($item !== FALSE) {
      $queue->deleteItem((object) ['item_id' => $item]);
    }
  }

}
