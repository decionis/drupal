<?php

declare(strict_types=1);

namespace Drupal\decionis\Gate;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\State\StateInterface;

/**
 * Decionis Commerce safe mode, as the workspace status last stated it.
 *
 * An owner or administrator sets safe mode in Decionis Commerce when Commerce
 * Gate itself is the problem. 'shadow' evaluates and never blocks or holds;
 * 'halt' refuses every governed action without asking for a verdict. The
 * module reads it from /v1/drupal/status on cron, at most once a minute, and
 * keeps the last answer: the store's request path never waits on it. A
 * stored posture stands until a later status read replaces it, so a halt
 * never lapses because a read failed.
 */
final class SafeMode {

  public const STATE = 'decionis.safe_mode';

  /**
   * Seconds a status read is fresh before cron reads it again.
   */
  public const TTL = 60;

  /**
   * Reason recorded when a halt refused an action without a verdict.
   */
  public const HALT_REASON = 'SAFE_MODE_HALT';

  /**
   * Reason recorded when Shadow kept an Enforce verdict from acting.
   */
  public const SHADOW_REASON = 'SAFE_MODE_SHADOW';

  /**
   * Actions that move money out: D2 keeps them failing closed in Shadow.
   */
  public const MONEY_OUT = ['refund_request'];

  public function __construct(
    private readonly StateInterface $state,
    private readonly Settings $settings,
    private readonly ?KeyValueFactoryInterface $keyValueFactory = NULL,
  ) {}

  /**
   * Adopts the safe mode a status response stated for a connection.
   *
   * @param mixed $value
   *   The response's 'safe_mode' field: NULL (or absent) when none is set.
   * @param string $identity
   *   Local connection captured before the status request was sent.
   *
   * @return bool
   *   TRUE when adopted; FALSE for a malformed value or another connection.
   */
  public function adopt(mixed $value, string $identity): bool {
    if ($identity !== $this->settings->connectionIdentity()) {
      return FALSE;
    }
    $rule = NULL;
    if ($value !== NULL) {
      $rule = is_array($value) ? self::validRule($value) : NULL;
      if ($rule === NULL) {
        // A malformed answer never lifts a halt: the last rule stands.
        return FALSE;
      }
    }
    $this->state->set(self::STATE, ['rule' => $rule, 'connection_id' => $identity, 'fetched_at' => time()]);
    return TRUE;
  }

  /**
   * Forgets the stored safe mode, as a disconnect does.
   */
  public function forget(): void {
    $this->state->delete(self::STATE);
  }

  /**
   * The posture in force: 'shadow', 'halt', or NULL when none is set.
   */
  public function posture(): ?string {
    $rule = $this->rule();
    return $rule === NULL ? NULL : $rule['posture'];
  }

  /**
   * The rule in force for this connection, or NULL.
   *
   * @return array{posture: string, scope: string, key: string|null, reason: string, set_at: string, event_id: string}|null
   *   The validated rule as the status route stated it.
   */
  public function rule(): ?array {
    $entry = $this->entry();
    $rule = $entry['rule'] ?? NULL;
    return is_array($rule) ? self::validRule($rule) : NULL;
  }

  /**
   * When this connection's safe mode was last read, or NULL.
   */
  public function checkedAt(): ?int {
    $entry = $this->entry();
    return isset($entry['fetched_at']) && is_int($entry['fetched_at']) ? $entry['fetched_at'] : NULL;
  }

  /**
   * TRUE when the stored answer is older than the TTL, or there is none.
   */
  public function due(): bool {
    $checked = $this->checkedAt();
    return $checked === NULL || $checked <= time() - self::TTL;
  }

  /**
   * One line for the admin pages: the posture, why, and when it was read.
   */
  public function describe(): string {
    $rule = $this->rule();
    $text = match ($rule['posture'] ?? NULL) {
      'halt' => sprintf('Safe mode: Halt. Every governed action is refused until the owner clears it in Decionis Commerce. Reason: %s. Set at %s.', $rule['reason'], $rule['set_at']),
      'shadow' => sprintf('Safe mode: Shadow. Verdicts are recorded and nothing is blocked; refunds still fail closed when no decision can be had. Reason: %s. Set at %s.', $rule['reason'], $rule['set_at']),
      default => 'Safe mode: off.',
    };
    $checked = $this->checkedAt();
    return $text . ($checked === NULL ? ' Not checked yet.' : ' Last checked ' . gmdate('Y-m-d H:i', $checked) . ' UTC.');
  }

  /**
   * The stored entry when it belongs to the current connection.
   *
   * @return array<string, mixed>
   *   The stored entry, or an empty array.
   */
  private function entry(): array {
    $entry = $this->keyValueFactory !== NULL ? $this->keyValueFactory->get('state')->get(self::STATE) : $this->state->get(self::STATE);
    $identity = $this->settings->connectionIdentity();
    if (!is_array($entry) || $identity === NULL || ($entry['connection_id'] ?? NULL) !== $identity) {
      return [];
    }
    return $entry;
  }

  /**
   * Validates the status route's safe-mode shape.
   *
   * @param array<mixed> $value
   *   The rule as received or stored.
   *
   * @return array{posture: string, scope: string, key: string|null, reason: string, set_at: string, event_id: string}|null
   *   The bounded rule, or NULL when any field is out of contract.
   */
  private static function validRule(array $value): ?array {
    $posture = $value['posture'] ?? NULL;
    $scope = $value['scope'] ?? NULL;
    $key = $value['key'] ?? NULL;
    $reason = $value['reason'] ?? NULL;
    $setAt = $value['set_at'] ?? NULL;
    $eventId = $value['event_id'] ?? NULL;
    if (!in_array($posture, ['shadow', 'halt'], TRUE) || !in_array($scope, ['org', 'channel', 'agent'], TRUE)) {
      return NULL;
    }
    if (($key !== NULL && (!is_string($key) || strlen($key) > 200)) || !is_string($reason) || mb_strlen($reason) > 500) {
      return NULL;
    }
    if (!is_string($setAt) || $setAt === '' || strlen($setAt) > 64 || !is_string($eventId) || $eventId === '' || strlen($eventId) > 200) {
      return NULL;
    }
    return ['posture' => $posture, 'scope' => $scope, 'key' => $key, 'reason' => $reason, 'set_at' => $setAt, 'event_id' => $eventId];
  }

}
