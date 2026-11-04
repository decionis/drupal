<?php

declare(strict_types=1);

namespace Drupal\decionis\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\views\EntityViewsData;

/**
 * One line of the decision log.
 *
 * Drupal 10 reads this annotation; Drupal 11 reads the attribute below.
 *
 * @ContentEntityType(
 *   id = "decionis_decision",
 *   label = @Translation("Decionis decision"),
 *   label_collection = @Translation("Decionis decisions"),
 *   handlers = {
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "storage_schema" = "Drupal\decionis\Entity\DecisionSchema",
 *   },
 *   base_table = "decionis_decision",
 *   admin_permission = "administer decionis",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 * )
 *
 * What was caught, what Decionis said, what the gate did. Holds bounded
 * commerce facts and hashes only, never customer PII.
 */
#[ContentEntityType(
  id: 'decionis_decision',
  label: new TranslatableMarkup('Decionis decision'),
  label_collection: new TranslatableMarkup('Decionis decisions'),
  handlers: [
    'views_data' => EntityViewsData::class,
    'storage_schema' => DecisionSchema::class,
  ],
  base_table: 'decionis_decision',
  admin_permission: 'administer decionis',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
)]
final class Decision extends ContentEntityBase {

  /**
   * Field name => [label, type, max length or decimal precision].
   */
  public const FIELDS = [
    'action' => ['Action', 'string', 64],
    'hook' => ['Hook point', 'string', 64],
    'source' => ['Source', 'string', 16],
    'mode' => ['Mode', 'string', 16],
    'verdict' => ['Verdict', 'string', 16],
    'effect' => ['Effect', 'string', 32],
    'reasons' => ['Reason codes', 'string', 2000],
    'authority' => ['Authority', 'string', 32],
    'policy_version' => ['Policy version', 'string', 120],
    'dossier_id' => ['Dossier', 'string', 64],
    'dossier_sha256' => ['Dossier SHA-256', 'string', 64],
    'evaluation_id' => ['Evaluation', 'string', 64],
    'idempotency_key' => ['Idempotency key', 'string', 180],
    'intent_hash' => ['Intent hash', 'string', 71],
    'target_type' => ['Target type', 'string', 64],
    'target_id' => ['Target id', 'string', 64],
    'customer' => ['Customer (hashed)', 'string', 32],
    'currency' => ['Currency', 'string', 3],
    'org_id' => ['Workspace', 'string', 64],
    'replay_key' => ['Replay identity', 'string', 64],
    'connection_id' => ['Captured connection', 'string', 64],
    'lease_token' => ['Evaluation lease', 'string', 32],
    'nudge_state' => ['Notification state', 'string', 16],
    'nudge_text' => ['Notification', 'string', 1000],
  ];

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    foreach (self::FIELDS as $name => [$label, $type, $length]) {
      $fields[$name] = BaseFieldDefinition::create($type)
        ->setLabel($label)
        ->setSetting('max_length', $length)
        ->setDisplayConfigurable('view', TRUE);
    }
    $fields['at_stake'] = BaseFieldDefinition::create('decimal')
      ->setLabel('Value at stake')
      ->setDescription('The money a hold or block protects, in the currency field.')
      ->setSetting('precision', 19)
      ->setSetting('scale', 6);
    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel('Decided at');
    $fields['pending_capture'] = BaseFieldDefinition::create('string_long')
      ->setLabel('Pending evaluation facts');
    $fields['lease_expires'] = BaseFieldDefinition::create('integer')
      ->setLabel('Evaluation lease expires')
      ->setDefaultValue(0);
    $fields['recipient'] = BaseFieldDefinition::create('integer')
      ->setLabel('Local notification recipient')
      ->setDefaultValue(0);
    $fields['save_confirmed'] = BaseFieldDefinition::create('boolean')
      ->setLabel('Corresponding save committed')
      ->setDefaultValue(FALSE);
    $fields['occurred'] = BaseFieldDefinition::create('integer')
      ->setLabel('Source event date');
    $fields['checkout'] = BaseFieldDefinition::create('boolean')
      ->setLabel('Captured during checkout');
    return $fields;
  }

  /**
   * The reason codes as a list.
   *
   * @return list<string>
   *   Valid string reason codes stored with the decision.
   */
  public function reasons(): array {
    $codes = json_decode((string) $this->get('reasons')->value, TRUE);
    return is_array($codes) ? array_values(array_filter($codes, 'is_string')) : [];
  }

  /**
   * A field value as a string ('' when empty).
   */
  public function value(string $field): string {
    return (string) ($this->get($field)->value ?? '');
  }

}
