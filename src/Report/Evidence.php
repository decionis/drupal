<?php

declare(strict_types=1);

namespace Drupal\decionis\Report;

use Decionis\Client\Dossier\Record;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis\Gate\Remote;
use Drupal\decionis\Gate\Settings;

/**
 * Retrieves and verifies the exact Dossier that belongs to a recorded action.
 */
final class Evidence {

  public function __construct(
    private readonly Remote $remote,
    private readonly Settings $settings,
  ) {}

  /**
   * Fetches the full artifact without changing any signed content.
   */
  public function record(Decision $entry): Record {
    $org = $entry->hasField('org_id') ? $entry->value('org_id') : '';
    $current = $this->settings->get('org_id');
    if ($org !== '' && $org !== $current) {
      throw new \RuntimeException('DOSSIER_WORKSPACE_MISMATCH');
    }
    $record = $this->remote->client()->dossier($entry->value('dossier_id'));
    self::assertBinding($record, $entry->value('dossier_id'), $org ?: $current, $entry->value('evaluation_id'));
    return $record;
  }

  /**
   * Verifies signatures and completeness; policy version comes from signed data.
   */
  public function verify(Decision $entry): array {
    $record = $this->record($entry);
    $result = $this->remote->client()->verify($record);
    return [
      'signature_valid' => $result->verified,
      'complete' => $result->complete,
      'key_id' => $result->keyId ?? 'unavailable',
      'policy_version' => $result->verified ? self::policyVersion($record) : 'unverified',
      'summary' => $result->summary(),
    ];
  }

  /**
   * Prevents a valid signature on another action from being called this proof.
   */
  public static function assertBinding(Record $record, string $dossier, string $org, string $evaluation): void {
    if ($dossier === '' || $org === '' || $evaluation === '' || $record->dossierId !== $dossier || $record->orgId !== $org || $record->evaluationId !== $evaluation) {
      throw new \RuntimeException('DOSSIER_IDENTITY_MISMATCH');
    }
    $payload = $record->payload;
    foreach ([$payload, $payload->portable_artifact ?? NULL, $payload->portable_artifact->machine_readable ?? NULL] as $document) {
      if (!$document instanceof \stdClass) {
        continue;
      }
      foreach (['dossier_id' => $dossier, 'org_id' => $org, 'evaluation_id' => $evaluation, 'decision_evaluation_id' => $evaluation] as $field => $expected) {
        if (property_exists($document, $field) && $document->{$field} !== $expected) {
          throw new \RuntimeException('DOSSIER_IDENTITY_MISMATCH');
        }
      }
    }
    // The signed portable artifact must itself identify this Dossier.
    $portable = $payload->portable_artifact ?? NULL;
    if (!$portable instanceof \stdClass || ($portable->dossier_id ?? $portable->machine_readable->dossier_id ?? NULL) !== $dossier) {
      throw new \RuntimeException('DOSSIER_IDENTITY_MISSING');
    }
    $machine = $portable->machine_readable ?? NULL;
    if ($machine instanceof \stdClass && ($machine->protocol_version ?? NULL) === '1.1' && (($machine->evaluation_id ?? NULL) !== $evaluation || ($machine->dossier_id ?? NULL) !== $dossier)) {
      throw new \RuntimeException('DOSSIER_IDENTITY_MISSING');
    }
  }

  /**
   * Reads the current and legacy signed portable-artifact policy references.
   */
  public static function policyVersion(Record $record): string {
    $portable = $record->payload->portable_artifact ?? NULL;
    $version = $portable->machine_readable->policy_reference->version ?? $portable->routing_decision->policy_version ?? NULL;
    return is_string($version) && $version !== '' ? $version : 'not included in signed artifact';
  }

}
