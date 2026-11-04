<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Support;

use Decionis\Client\Decision\Decision;
use Decionis\Client\Decision\Request;
use Decionis\Client\Error\TransportException;
use Drupal\decionis\Gate\Authority;

/**
 * An authority double that answers with a fixed outcome, or never answers.
 */
final class Scripted implements Authority {

  /**
   * The number of evaluation requests received by this double.
   */
  public int $calls = 0;

  public function __construct(private readonly ?string $outcome, private readonly bool $available = TRUE) {}

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return 'test double';
  }

  /**
   * {@inheritdoc}
   */
  public function available(): bool {
    return $this->available;
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(Request $request, bool $checkout, ?int $budgetMs = NULL): Decision {
    $this->calls++;
    if ($this->outcome === NULL) {
      throw new TransportException('UPSTREAM_UNREACHABLE');
    }
    return Decision::fromResponse([
      'outcome' => $this->outcome,
      'confidence' => 0.9,
      'policy_version' => 'test-policy-v1',
      'objective_profile' => 'test',
      'dossier_id' => '18962a2d-6842-4f6a-9ff4-901c50acaed8',
      'evaluation_id' => '5a7cb7e8-507a-4bba-ad6f-98554e418d72',
      'mode' => 'SHADOW',
      'fallback_to_legacy' => FALSE,
      'idempotent_replay' => FALSE,
      'reason_codes' => $this->outcome === 'APPROVE' ? [] : ['test_reason'],
      'governance_metrics' => ['override_drift_rate' => 0, 'governance_tension_index' => 0, 'boundary_volatility_score' => 0, 'decision_volume_under_governance' => 0],
    ]);
  }

}
