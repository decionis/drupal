<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Unit;

use Decionis\Client\Dossier\Record;
use Drupal\decionis\Report\Evidence;
use Drupal\decionis\Report\Reasons;
use Drupal\Tests\UnitTestCase;

/**
 * Evidence identity and reason rendering never trust unrelated response fields.
 *
 * @group decionis
 */
final class EvidenceTest extends UnitTestCase {

  /**
   * Every envelope identity and every embedded projection must agree.
   */
  public function testEveryIdentityIsBound(): void {
    $record = ['dossier_id' => 'd1', 'org_id' => 'o1', 'decision_evaluation_id' => 'e1', 'dossier_payload' => ['dossier_id' => 'd1', 'evaluation_id' => 'e1', 'portable_artifact' => ['dossier_id' => 'd1', 'machine_readable' => ['dossier_id' => 'd1', 'evaluation_id' => 'e1', 'policy_reference' => ['version' => 'signed-v1']]]], 'evidence_hashes' => [], 'created_at' => '2026-01-01T00:00:00Z'];
    $parse = static fn (array $value): Record => Record::fromJson(json_encode(['dossier' => $value], JSON_THROW_ON_ERROR));
    Evidence::assertBinding($parse($record), 'd1', 'o1', 'e1');
    $this->assertSame('signed-v1', Evidence::policyVersion($parse($record)));
    foreach (['dossier_id', 'org_id', 'decision_evaluation_id'] as $field) {
      $wrong = $record;
      $wrong[$field] = 'different';
      try {
        Evidence::assertBinding($parse($wrong), 'd1', 'o1', 'e1');
        $this->fail('Accepted a different ' . $field);
      }
      catch (\RuntimeException $error) {
        $this->assertSame('DOSSIER_IDENTITY_MISMATCH', $error->getMessage());
      }
    }
    $wrong = $record;
    $wrong['dossier_payload']['portable_artifact']['machine_readable']['evaluation_id'] = 'different';
    $this->expectExceptionMessage('DOSSIER_IDENTITY_MISMATCH');
    Evidence::assertBinding($parse($wrong), 'd1', 'o1', 'e1');
  }

  /**
   * All starter codes have explanations; unknown codes remain plain text.
   */
  public function testKnownAndUnknownReasons(): void {
    foreach (array_keys(Reasons::TEXT) as $code) {
      $this->assertNotSame($code, Reasons::text($code));
    }
    $this->assertSame('<unknown-rule>', Reasons::text('<unknown-rule>'));
    $this->assertStringContainsString('payment’s cumulative refunded amount', Reasons::join(['refund_over_unattended_limit'], TRUE));
    $this->assertStringContainsString('This refund', Reasons::join(['refund_over_unattended_limit']));
  }

}
