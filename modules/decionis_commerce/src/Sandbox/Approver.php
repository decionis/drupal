<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Sandbox;

use Decionis\Client\Error\ContractException;
use Decionis\Client\Grant\Claim;
use Decionis\Client\Grant\Grant;
use Drupal\decionis\Approval\Grants;
use Decionis\Client\Intent\Intent;

/**
 * The sandbox's demo approver.
 *
 * It approves a held action at once and issues one grant per intent. It
 * refuses a second claim of any grant, as the authority does, so the sandbox
 * shows an approved refund run once and its replay refused. It never reaches
 * Presence or a real authority.
 */
final class Approver implements Grants {

  /**
   * Grant token => the intent hash it was issued for.
   *
   * @var array<string, string>
   */
  private array $issued = [];

  /**
   * Grant tokens already claimed.
   *
   * @var array<string, true>
   */
  private array $claimed = [];

  /**
   * {@inheritdoc}
   */
  public function available(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function escalate(Intent $intent): Grant {
    return Grant::fromResponse([
      'decision_id' => 'sandbox-escalation',
      'status' => 'ESCALATE',
      'should_execute' => FALSE,
      'action_hash' => $intent->hash(),
      'mode' => 'ENFORCEMENT',
      'execution_token' => NULL,
      'execution_token_expires_at' => NULL,
      'reason_codes' => [],
      'managed_escalation' => ['escalation_id' => 'sandbox-' . $intent->intentId],
    ], $intent, new \DateTimeImmutable());
  }

  /**
   * {@inheritdoc}
   */
  public function approved(string $escalationId, Intent $intent): Grant {
    $token = 'sandbox.' . substr(hash('sha256', $intent->hash()), 0, 32) . '.grant';
    $this->issued[$token] = $intent->hash();
    return Grant::fromResponse([
      'decision_id' => 'sandbox-grant',
      'status' => 'ALLOW',
      'should_execute' => TRUE,
      'action_hash' => $intent->hash(),
      'mode' => 'ENFORCEMENT',
      'execution_token' => $token,
      'execution_token_expires_at' => gmdate('Y-m-d\TH:i:s.000\Z', time() + 300),
      // A grant names the decision it was issued on; this one is the demo's.
      'dossier_id' => 'sandbox-approval-' . $intent->intentId,
      'reason_codes' => [],
    ], $intent, new \DateTimeImmutable());
  }

  /**
   * {@inheritdoc}
   */
  public function claim(Grant $grant, Intent $intent): Claim {
    $token = (string) $grant->token;
    if (($this->issued[$token] ?? NULL) !== $intent->hash() || isset($this->claimed[$token])) {
      throw new ContractException('GRANT_CLAIM_REFUSED');
    }
    $this->claimed[$token] = TRUE;
    return Claim::fromResponse([
      'valid' => TRUE,
      'claim_token' => str_repeat('s', 43),
      'claims' => [
        'iss' => 'decionis-sandbox',
        'org_id' => $intent->tenantId,
        'dossier_id' => $grant->dossierId,
        'decision_id' => $grant->decisionId,
        'action' => $intent->actionType,
        'decision' => 'allow',
        'scope' => 'execute',
        'binding' => ['intent_hash' => $intent->hash()],
      ],
    ], $intent, $grant);
  }

  /**
   * {@inheritdoc}
   */
  public function finalize(Grant $grant, Claim $claim, Intent $intent, string $outcome): void {
  }

}
