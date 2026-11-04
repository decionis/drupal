<?php

declare(strict_types=1);

namespace Drupal\decionis\Gate;

use Decionis\Client\Decision\Verdict;

/**
 * What the gate did with an action, and why.
 *
 * Effects: 'proceeded', 'blocked', 'held', 'ungoverned' (no decision, ran
 * anyway), 'failed_closed' (no decision, refused), 'not_covered' (no facts to
 * decide on, ran anyway).
 */
final class Outcome {

  /**
   * Records the local effect of a policy decision or an unavailable check.
   *
   * @param string $mode
   *   Effective gate mode for the action.
   * @param string $effect
   *   Local result, such as proceeded, held, or blocked.
   * @param \Decionis\Client\Decision\Verdict|null $verdict
   *   The authority's verdict, or NULL when no decision was available.
   * @param list<string> $reasons
   *   Policy or local reason codes explaining the effect.
   * @param string|null $dossierId
   *   Remote dossier ID, when the authority returned one.
   * @param int|null $logId
   *   Local decision log entry ID, once recorded.
   * @param int|null $holdId
   *   Local approval queue entry ID, when an action is held.
   */
  public function __construct(
    public readonly string $mode,
    public readonly string $effect,
    public readonly ?Verdict $verdict,
    public readonly array $reasons = [],
    public readonly ?string $dossierId = NULL,
    public readonly ?int $logId = NULL,
    public readonly ?int $holdId = NULL,
  ) {}

  /**
   * TRUE when the action may commit now.
   */
  public function proceeds(): bool {
    return in_array($this->effect, ['proceeded', 'ungoverned', 'not_covered', 'pending'], TRUE);
  }

  /**
   * A copy that knows its decision log entry and hold.
   */
  public function logged(int $logId, ?int $holdId = NULL): self {
    return new self($this->mode, $this->effect, $this->verdict, $this->reasons, $this->dossierId, $logId, $holdId);
  }

  /**
   * A message safe to show the person who tried the action.
   */
  public function message(bool $customer): string {
    if ($this->effect === 'held') {
      return 'This action needs approval before it can run. It has been sent to an approver.';
    }
    if ($this->effect === 'failed_closed') {
      return 'Decionis could not check this action, so it was not run. Try again shortly.';
    }
    if ($customer) {
      return 'We could not complete this order as it stands. Please review your cart or contact us.';
    }
    $reason = $this->reasons[0] ?? 'POLICY';
    return sprintf('Blocked by your Decionis policy (%s).', $reason);
  }

}
