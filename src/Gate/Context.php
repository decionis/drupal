<?php

declare(strict_types=1);

namespace Drupal\decionis\Gate;

/**
 * Where an action was caught and what it puts at stake.
 */
final class Context {

  /**
   * When the action was captured, in monotonic milliseconds.
   */
  public readonly float $started;

  /**
   * Captures an action's origin, target, and policy facts.
   *
   * @param string $action
   *   The action key, the decision type in lower case ('refund_request').
   * @param string $hook
   *   The hook point that caught it ('refund_form', 'order_place', ...).
   * @param bool $checkout
   *   TRUE on the customer's checkout path: never held for a person, fails
   *   open inside the latency budget.
   * @param string|null $targetType
   *   Drupal entity type of the action's target, when available.
   * @param string|null $targetId
   *   Local entity ID of the action's target, when available.
   * @param float|null $atStake
   *   The money a hold or block would protect, in the store currency.
   * @param string|null $currency
   *   Currency code for the value at stake, when available.
   * @param array<string, mixed> $facts
   *   Extra bounded facts for the policy (abuse signals, coverage notes).
   * @param string $source
   *   Origin of the action, such as live, replay, sandbox, or webhook.
   * @param string|null $mode
   *   Forces 'shadow' or 'enforce' (the sandbox); NULL uses the settings.
   * @param float|null $started
   *   Capture time in monotonic milliseconds; NULL starts the budget now.
   * @param int|null $occurredAt
   *   Unix timestamp of the source event, when known.
   */
  public function __construct(
    public readonly string $action,
    public readonly string $hook,
    public readonly bool $checkout = FALSE,
    public readonly ?string $targetType = NULL,
    public readonly ?string $targetId = NULL,
    public readonly ?float $atStake = NULL,
    public readonly ?string $currency = NULL,
    public readonly array $facts = [],
    public readonly string $source = 'live',
    public readonly ?string $mode = NULL,
    ?float $started = NULL,
    public readonly ?int $occurredAt = NULL,
  ) {
    $this->started = $started ?? hrtime(TRUE) / 1e6;
  }

  /**
   * A copy with a forced mode and source, as the sandbox and replay use.
   */
  public function as(string $mode, string $source): self {
    return new self($this->action, $this->hook, $this->checkout, $this->targetType, $this->targetId, $this->atStake, $this->currency, $this->facts, $source, $mode, $this->started, $this->occurredAt);
  }

}
