<?php

declare(strict_types=1);

namespace Drupal\decionis\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * An action held for approval.
 *
 * Drupal 10 reads this annotation; Drupal 11 reads the attribute below.
 *
 * @ContentEntityType(
 *   id = "decionis_hold",
 *   label = @Translation("Decionis hold"),
 *   label_collection = @Translation("Pending approvals"),
 *   base_table = "decionis_hold",
 *   admin_permission = "approve decionis holds",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *   },
 * )
 *
 * It keeps the exact action as an agent-safe.intent/1 binding and its hash.
 * Once approved, the grant runs the action once, and only while the action
 * still hashes the same. It has no Views data: a view would expose the
 * single-use grant token.
 */
#[ContentEntityType(
  id: 'decionis_hold',
  label: new TranslatableMarkup('Decionis hold'),
  label_collection: new TranslatableMarkup('Pending approvals'),
  base_table: 'decionis_hold',
  admin_permission: 'approve decionis holds',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
)]
final class Hold extends ContentEntityBase {

  /**
   * States: pending, approved, executing, executed, refused, expired.
   */
  public const STATES = ['pending', 'approved', 'executing', 'executed', 'refused', 'expired'];

  /**
   * Field name => [label, type, max length].
   */
  public const FIELDS = [
    'action' => ['Action', 'string', 64],
    'hook' => ['Hook point', 'string', 64],
    'target_type' => ['Target type', 'string', 64],
    'target_id' => ['Target id', 'string', 64],
    'state' => ['State', 'string', 16],
    'intent_hash' => ['Intent hash', 'string', 71],
    'dossier_id' => ['Dossier', 'string', 64],
    'decision_id' => ['Grant decision', 'string', 200],
    'escalation_id' => ['Escalation', 'string', 200],
    'approval_url' => ['Approval page', 'string', 2000],
    'grant_expires' => ['Grant expires', 'string', 40],
    'reason' => ['Reason', 'string', 120],
    'currency' => ['Currency', 'string', 3],
    'connection_id' => ['Connection identity', 'string', 64],
  ];

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);
    foreach (self::FIELDS as $name => [$label, $type, $length]) {
      $fields[$name] = BaseFieldDefinition::create($type)->setLabel($label)->setSetting('max_length', $length);
    }
    $fields['intent'] = BaseFieldDefinition::create('string_long')->setLabel('Intent binding');
    // The single-use execution token: never shown, only presented to claim.
    $fields['grant_token'] = BaseFieldDefinition::create('string_long')->setLabel('Grant');
    $fields['log_id'] = BaseFieldDefinition::create('integer')->setLabel('Decision log entry');
    $fields['at_stake'] = BaseFieldDefinition::create('decimal')->setLabel('Value at stake')->setSetting('precision', 19)->setSetting('scale', 6);
    $fields['created'] = BaseFieldDefinition::create('created')->setLabel('Held at');
    $fields['changed'] = BaseFieldDefinition::create('changed')->setLabel('Updated');
    return $fields;
  }

  /**
   * A field value as a string ('' when empty).
   */
  public function value(string $field): string {
    return (string) ($this->get($field)->value ?? '');
  }

  /**
   * The stored intent binding.
   *
   * @return array<string, mixed>
   *   Decoded intent fields, or an empty array if the stored JSON is invalid.
   */
  public function binding(): array {
    $binding = json_decode($this->value('intent'), TRUE);
    return is_array($binding) ? $binding : [];
  }

  /**
   * Who asked for the held action, as the mapper takes an actor.
   *
   * @return array{type: string, id: string}|null
   *   The requester, or NULL when the binding has none.
   */
  public function actor(): ?array {
    $actor = $this->binding()['actor'] ?? NULL;
    return is_array($actor) && is_string($actor['type'] ?? NULL) && is_string($actor['id'] ?? NULL) ? ['type' => $actor['type'], 'id' => $actor['id']] : NULL;
  }

}
