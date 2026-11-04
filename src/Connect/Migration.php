<?php

declare(strict_types=1);

namespace Drupal\decionis\Connect;

use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\decionis\Gate\Settings;

/**
 * Moves legacy identity out of config and quarantines unbound old holds.
 */
final class Migration {

  /**
   * Idempotent update, called by the module's update hook.
   */
  public static function run(): string {
    $state = \Drupal::state();
    $config = \Drupal::configFactory()->getEditable('decionis.settings');
    $connection = $state->get(Binding::STATE, []);
    $connection = is_array($connection) ? $connection : [];
    foreach (['org_id', 'api_key', 'site_key'] as $name) {
      $connection[$name] ??= (string) ($config->get($name) ?? '');
      $config->clear($name);
    }
    foreach (['api_key', 'site_key'] as $name) {
      $id = $connection[$name];
      if ($id === '') {
        continue;
      }
      $key = \Drupal::config('key.key.' . $id);
      $connection['managed'][$id] = $key->get('key_provider') === 'state' && $key->get('key_provider_settings.state_key') === 'decionis.secret.' . $id;
    }
    $state->set(Binding::STATE, $connection);
    $config->save();
    if ($connection['org_id'] !== '' && $connection['api_key'] !== '') {
      \Drupal::service(Binding::class)->baseline();
      $entitlement = $state->get(Settings::ENTITLEMENT);
      if (is_array($entitlement) && !array_key_exists('connection_id', $entitlement)) {
        $entitlement['connection_id'] = \Drupal::service(Settings::class)->connectionIdentity();
        $state->set(Settings::ENTITLEMENT, $entitlement);
      }
    }
    $manager = \Drupal::entityDefinitionUpdateManager();
    if ($manager->getEntityType('decionis_hold') !== NULL) {
      if ($manager->getFieldStorageDefinition('connection_id', 'decionis_hold') === NULL) {
        $field = BaseFieldDefinition::create('string')->setLabel('Connection identity')->setSetting('max_length', 64);
        $manager->installFieldStorageDefinition('connection_id', 'decionis_hold', 'decionis', $field);
      }
      $database = \Drupal::database();
      $query = $database->update('decionis_hold')->fields(['state' => 'refused', 'reason' => 'CONNECTION_CHANGED', 'grant_token' => NULL])->condition('state', ['pending', 'approved'], 'IN');
      $query->condition($query->orConditionGroup()->isNull('connection_id')->condition('connection_id', ''))->execute();
    }
    return 'Moved Decionis connection identity to State. Existing hosts were baselined; old unbound holds must be submitted again.';
  }

}
