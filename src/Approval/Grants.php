<?php

declare(strict_types=1);

namespace Drupal\decionis\Approval;

use Decionis\Client\Grant\Claim;
use Decionis\Client\Grant\Grant;
use Decionis\Client\Intent\Intent;

/**
 * The authority that issues and redeems single-use grants (AgentSafe's).
 */
interface Grants {

  /**
   * TRUE when grants can be requested (the site is connected).
   */
  public function available(): bool;

  /**
   * Opens a managed escalation for an intent the policy holds.
   */
  public function escalate(Intent $intent): Grant;

  /**
   * The grant once the approver approved, else NULL.
   */
  public function approved(string $escalationId, Intent $intent): ?Grant;

  /**
   * Claims the grant for the intent, once.
   *
   * @throws \Decionis\Client\Error\DecionisException
   *   When the grant was already claimed or does not bind this intent.
   */
  public function claim(Grant $grant, Intent $intent): Claim;

  /**
   * Records what happened after the claim.
   */
  public function finalize(Grant $grant, Claim $claim, Intent $intent, string $outcome): void;

}
