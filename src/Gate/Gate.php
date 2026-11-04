<?php

declare(strict_types=1);

namespace Drupal\decionis\Gate;

use Decionis\Client\Decision\Action;
use Decionis\Client\Decision\Request;
use Decionis\Client\Decision\Verdict;
use Decionis\Client\Error\DecionisException;

/**
 * Applies the authority's verdict to an action captured at a hook point.
 *
 * Shadow logs and runs. Enforce acts on the verdict. Checkout is
 * never held for a person and fails open; other actions follow their failure
 * policy when there is no decision.
 */
final class Gate {

  /**
   * Milliseconds of the checkout budget kept for writing the log.
   */
  public const LOG_MS = 15;

  /**
   * A forced mode, authority and source while within() runs.
   *
   * @var array{string, Authority, string, ?string, ?string, string}|null
   */
  private ?array $scope = NULL;

  /**
   * Whether without() has temporarily disabled the hook checks.
   */
  private bool $bypassed = FALSE;

  public function __construct(
    private readonly Authority $authority,
    private readonly Settings $settings,
    private readonly Ledger $ledger,
    private readonly ?Holds $holds = NULL,
    private readonly ?Deferred $deferred = NULL,
  ) {}

  /**
   * Returns the authority the gate asks right now.
   */
  public function authority(): Authority {
    return $this->scope[1] ?? $this->authority;
  }

  /**
   * Runs work with a forced authority, mode, and recorded source.
   *
   * The sandbox and replay exercise the real hook points within this scope.
   *
   * @param string $mode
   *   Forced mode; values other than 'enforce' select Shadow.
   * @param \Drupal\decionis\Gate\Authority $authority
   *   Authority consulted by hook points while the work runs.
   * @param string $source
   *   Source label attached to captured decisions.
   * @param callable $work
   *   Work that exercises the hook points in the temporary scope.
   * @param string|null $policy
   *   Policy version to pin, or NULL to use the configured version.
   * @param string|null $connection
   *   Connection identity to pin, or NULL to capture the current connection.
   *
   * @return mixed
   *   The work's return value.
   *
   * @template T
   * @phpstan-param callable(): T $work
   * @phpstan-return T
   */
  public function within(string $mode, Authority $authority, string $source, callable $work, ?string $policy = NULL, ?string $connection = NULL): mixed {
    $previous = $this->scope;
    $this->scope = [$mode === 'enforce' ? 'enforce' : 'shadow', $authority, $source, $policy, $connection ?? $this->settings->connectionIdentity(), $this->settings->get('org_id')];
    try {
      return $work();
    }
    finally {
      $this->scope = $previous;
    }
  }

  /**
   * Runs $work with the hook points switched off.
   *
   * The sandbox's "direct" column, showing what happens with no gate.
   *
   * @param callable $work
   *   Work whose actions run without consulting the hook checks.
   *
   * @return mixed
   *   The work's return value.
   *
   * @template T
   * @phpstan-param callable(): T $work
   * @phpstan-return T
   */
  public function without(callable $work): mixed {
    $previous = $this->bypassed;
    $this->bypassed = TRUE;
    try {
      return $work();
    }
    finally {
      $this->bypassed = $previous;
    }
  }

  /**
   * TRUE while without() runs: hook points let the action through unchecked.
   */
  public function bypassed(): bool {
    return $this->bypassed;
  }

  /**
   * Records and allows an action with insufficient facts for a decision.
   *
   * A missing cost basis is one reason an action can lack coverage.
   */
  public function uncovered(Context $context, string $reason): Outcome {
    $context = $this->scoped($context);
    if ($context->source === 'replay' && $this->scope !== NULL && $this->scope[4] !== $this->settings->connectionIdentity()) {
      return new Outcome('shadow', 'ungoverned', NULL, ['REPLAY_CONNECTION_CHANGED']);
    }
    $outcome = new Outcome($context->mode ?? $this->settings->mode($context->action), 'not_covered', NULL, [$reason]);
    return $outcome->logged($this->ledger->record(NULL, $context, $outcome, $this->authority()->label(), NULL, NULL, $this->policy(), $this->scope[5] ?? NULL));
  }

  /**
   * Records an outside action with the Shadow verdict it would have had.
   *
   * This includes refunds already made in the payment provider's dashboard.
   */
  public function observe(Action $action, Context $context): Outcome {
    $context = $context->as('shadow', 'webhook');
    if ($this->scope === NULL && $this->deferred !== NULL) {
      return $this->defer($action, $context);
    }
    $authority = $this->authority();
    $answer = NULL;
    $verdict = NULL;
    $reasons = [];
    if ($authority->available()) {
      try {
        $answer = $authority->evaluate(new Request($action, 'drupal-decionis', 'api', $this->policy(), $context->facts), FALSE);
        $verdict = $answer->verdict;
        $reasons = $answer->reasonCodes;
      }
      catch (DecionisException $error) {
        $reasons = [$error->getMessage()];
      }
      catch (\Throwable) {
        $reasons = ['GATE_ERROR'];
      }
    }
    $outcome = new Outcome('shadow', 'outside', $verdict, $reasons, $answer?->dossierId);
    return $outcome->logged($this->ledger->record($action, $context, $outcome, $authority->label(), $answer));
  }

  /**
   * Checks an action and records the decision.
   */
  public function check(Action $action, Context $context): Outcome {
    $context = $this->scoped($context);
    $connection = $context->source === 'replay' ? ($this->scope !== NULL ? $this->scope[4] : $this->settings->connectionIdentity()) : NULL;
    $organization = $context->source === 'replay' ? ($this->scope[5] ?? $this->settings->get('org_id')) : NULL;
    if ($context->source === 'replay' && $connection !== $this->settings->connectionIdentity()) {
      return new Outcome('shadow', 'ungoverned', NULL, ['REPLAY_CONNECTION_CHANGED']);
    }
    $authority = $this->authority();
    $mode = $context->mode ?? $this->settings->mode($context->action);
    if ($this->scope === NULL && $context->source === 'live' && $mode === 'shadow' && $this->deferred !== NULL) {
      return $this->defer($action, $context->as('shadow', $context->source));
    }
    $answer = NULL;
    // No decision, for whatever reason, follows the action's failure policy:
    // checkout always runs, other actions in Enforce default to held.
    $closed = $mode === 'enforce' && !$context->checkout && $this->settings->failure($context->action) === 'closed';
    if (!$authority->available()) {
      $outcome = new Outcome($mode, $closed ? 'failed_closed' : 'ungoverned', NULL, ['NOT_CONNECTED']);
    }
    else {
      try {
        $request = new Request($action, 'drupal-decionis', $context->checkout ? 'workflow' : 'api', $this->policy(), $context->facts);
        $answer = $authority->evaluate($request, $context->checkout, $context->checkout ? $this->remaining($context) : NULL);
        $outcome = $this->act($mode, $answer->verdict, $answer->reasonCodes, $answer->dossierId, $context);
      }
      catch (DecionisException $error) {
        $outcome = new Outcome($mode, $closed ? 'failed_closed' : 'ungoverned', NULL, [$error->getMessage()]);
      }
      catch (\Throwable $error) {
        // A bad setting or an unencodable fact is no decision too, never a
        // crash on the store's own path.
        $outcome = new Outcome($mode, $closed ? 'failed_closed' : 'ungoverned', NULL, ['GATE_ERROR']);
      }
    }
    if ($context->source === 'replay' && $connection !== $this->settings->connectionIdentity()) {
      // The response (including a failure) belongs to the abandoned run.
      // Do not relabel it with the workspace that has since connected.
      return new Outcome('shadow', 'ungoverned', NULL, ['REPLAY_CONNECTION_CHANGED']);
    }
    if ($outcome->effect === 'held' && ($this->holds === NULL || !$this->holds->supports($context->action))) {
      // Nothing can run this action once approved, so holding it would only
      // stall it: in Enforce an ESCALATE without an approval path refuses.
      $outcome = new Outcome($outcome->mode, 'blocked', $outcome->verdict, [...$outcome->reasons, 'APPROVAL_NOT_AVAILABLE'], $outcome->dossierId);
    }
    $holdId = NULL;
    $logId = $this->ledger->record($action, $context, $outcome, $authority->label(), $answer, NULL, $this->policy(), $organization);
    if ($outcome->effect === 'held' && $this->holds !== NULL) {
      try {
        $holdId = $this->holds->open($action, $context, $logId, $answer);
      }
      catch (\Throwable) {
        // The action stays refused; only the queue entry is missing.
        $holdId = NULL;
      }
    }
    return $outcome->logged($logId, $holdId);
  }

  /**
   * Returns checkout's remaining budget after gate work and the log reserve.
   */
  private function remaining(Context $context): int {
    $spent = (int) ceil(hrtime(TRUE) / 1e6 - $context->started);
    return max(0, $this->settings->budgetMs() - $spent - self::LOG_MS);
  }

  /**
   * Returns the scoped or configured policy version, or NULL when unset.
   */
  public function policy(): ?string {
    $policy = $this->scope[3] ?? $this->settings->get('policy_version');
    return is_string($policy) && $policy !== '' ? $policy : NULL;
  }

  /**
   * Captures a live Shadow proposal without consulting the remote authority.
   */
  private function defer(Action $action, Context $context): Outcome {
    try {
      $request = new Request($action, 'drupal-decionis', $context->checkout ? 'workflow' : 'api', $this->policy(), $context->facts);
      return $this->deferred->enqueue(new Capture($request, $context));
    }
    catch (\Throwable) {
      // Failure to capture is an explicit absence of review, never a reason
      // to call the network from the request or to stop a Shadow action.
      $outcome = new Outcome('shadow', $context->source === 'webhook' ? 'outside' : 'ungoverned', NULL, ['SHADOW_CAPTURE_FAILED']);
      return $outcome->logged($this->ledger->record($action, $context, $outcome, $this->authority()->label(), NULL));
    }
  }

  /**
   * Applies the forced mode and source of within(), if any.
   */
  private function scoped(Context $context): Context {
    return $this->scope === NULL ? $context : $context->as($this->scope[0], $this->scope[2]);
  }

  /**
   * Turns a verdict into an effect for the mode.
   *
   * @param string $mode
   *   Effective gate mode for the captured action.
   * @param \Decionis\Client\Decision\Verdict $verdict
   *   The authority's decision about the action.
   * @param list<string> $reasons
   *   Reason codes returned with the decision.
   * @param string $dossierId
   *   Remote dossier ID supporting the decision.
   * @param \Drupal\decionis\Gate\Context $context
   *   Capture context that identifies the checkout path.
   */
  private function act(string $mode, Verdict $verdict, array $reasons, string $dossierId, Context $context): Outcome {
    if ($mode !== 'enforce') {
      return new Outcome('shadow', 'proceeded', $verdict, $reasons, $dossierId);
    }
    $effect = match ($verdict) {
      Verdict::Allow => 'proceeded',
      Verdict::Block => 'blocked',
      Verdict::Escalate => $context->checkout ? 'proceeded' : 'held',
    };
    return new Outcome('enforce', $effect, $verdict, $reasons, $dossierId);
  }

}
