<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Support;

use Decionis\Client\Error\ContractException;
use Decionis\Client\Grant\Claim;
use Decionis\Client\Grant\Grant;
use Decionis\Client\Intent\Intent;
use Drupal\decionis\Approval\Grants;

/**
 * Doubles AgentSafe approval and single-use grant behavior.
 *
 * Escalates, issues one grant per intent once approved, and refuses a second
 * claim of any grant, as the authority does.
 */
final class FakeGrants implements Grants {

  /**
   * Whether approval polling may issue an execution grant.
   */
  public bool $approved = FALSE;

  /**
   * Issued tokens mapped to the intent hash each token authorizes.
   *
   * @var array<string, string>
   */
  public array $issued = [];

  /**
   * Tokens already consumed by a successful claim.
   *
   * @var list<string>
   */
  public array $claimed = [];

  /**
   * Execution outcomes submitted when grants are finalized.
   *
   * @var list<string>
   */
  public array $outcomes = [];

  /**
   * TRUE makes every receipt call fail, as an unreachable authority would.
   */
  public bool $receiptsFail = FALSE;

  /**
   * Simulates a connection change while a remote claim is in flight.
   */
  public ?\Closure $duringClaim = NULL;

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
      'decision_id' => 'decision-1',
      'status' => 'ESCALATE',
      'should_execute' => FALSE,
      'action_hash' => $intent->hash(),
      'mode' => 'ENFORCEMENT',
      'execution_token' => NULL,
      'execution_token_expires_at' => NULL,
      'dossier_id' => 'dossier-1',
      'reason_codes' => ['refund_over_unattended_limit'],
      'managed_escalation' => ['escalation_id' => 'escalation-' . $intent->intentId],
      'verification' => ['verification_page_url' => 'https://presence.example/approve/1'],
    ], $intent, new \DateTimeImmutable());
  }

  /**
   * {@inheritdoc}
   */
  public function approved(string $escalationId, Intent $intent): ?Grant {
    if (!$this->approved) {
      return NULL;
    }
    $token = 'grant.' . substr(hash('sha256', $intent->hash()), 0, 32) . '.sig';
    $this->issued[$token] = $intent->hash();
    return Grant::fromResponse([
      'decision_id' => 'decision-2',
      'status' => 'ALLOW',
      'should_execute' => TRUE,
      'action_hash' => $intent->hash(),
      'mode' => 'ENFORCEMENT',
      'execution_token' => $token,
      'execution_token_expires_at' => gmdate('Y-m-d\TH:i:s.000\Z', time() + 300),
      'dossier_id' => 'dossier-2',
      'reason_codes' => [],
    ], $intent, new \DateTimeImmutable());
  }

  /**
   * {@inheritdoc}
   */
  public function claim(Grant $grant, Intent $intent): Claim {
    $token = (string) $grant->token;
    if (($this->issued[$token] ?? NULL) !== $intent->hash() || in_array($token, $this->claimed, TRUE)) {
      throw new ContractException('GRANT_CLAIM_REFUSED');
    }
    $this->claimed[] = $token;
    ($this->duringClaim)?->__invoke();
    return Claim::fromResponse([
      'valid' => TRUE,
      'claim_token' => str_repeat('c', 43),
      'claims' => [
        'iss' => 'decionis',
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
    if ($this->receiptsFail) {
      throw new ContractException('RECEIPT_UNAVAILABLE');
    }
    $this->outcomes[] = $outcome;
  }

}
