<?php

declare(strict_types=1);

namespace Drupal\decionis\Approval;

use Decionis\Client\Decision\Action;
use Decionis\Client\Intent\Intent;
use Drupal\decionis\Gate\Settings;

/**
 * Captures the exact commerce action as an agent-safe.intent/1 binding.
 *
 * A grant is bound to this action, with every field included in the hash.
 */
final class Intents {

  /**
   * How long a held action stays approvable. */
  public const TTL = 86400;

  private const NIL = '00000000-0000-0000-0000-000000000000';

  public function __construct(private readonly Settings $settings) {}

  /**
   * A fresh intent for an action caught at a hook point.
   */
  public function capture(Action $action, string $actionKey, string $resource, ?\DateTimeImmutable $at = NULL): Intent {
    $at ??= new \DateTimeImmutable();
    return $this->bind($action, $actionKey, $resource, Intent::uuid(), Intent::instant($at), Intent::instant($at->modify('+' . self::TTL . ' seconds')));
  }

  /**
   * Recaptures the stored intent from the action as it stands now.
   *
   * The intent remains identical when nothing about the action changed.
   *
   * @param \Decionis\Client\Decision\Action $action
   *   Current action to compare with the stored binding.
   * @param array<string, mixed> $stored
   *   Original intent fields, including its actor, resource, and lifetime.
   */
  public function recapture(Action $action, array $stored): ?Intent {
    $resource = $stored['action']['resource'] ?? NULL;
    $key = $stored['policy_projection']['transaction_type'] ?? NULL;
    if (!is_string($resource) || !is_string($key) || !is_string($stored['intent_id'] ?? NULL) || !is_string($stored['captured_at'] ?? NULL) || !is_string($stored['expires_at'] ?? NULL)) {
      return NULL;
    }
    // The requester stays the actor: whoever runs the approved action, it is
    // the action that was asked for.
    $actor = is_array($stored['actor'] ?? NULL) ? array_map('strval', $stored['actor']) : NULL;
    return $this->bind($action, $key, $resource, $stored['intent_id'], $stored['captured_at'], $stored['expires_at'], $actor);
  }

  /**
   * Builds the binding.
   */
  private function bind(Action $action, string $actionKey, string $resource, string $intentId, string $capturedAt, string $expiresAt, ?array $actor = NULL): Intent {
    $org = $this->settings->get('org_id');
    $type = $action->type;
    return new Intent(
      $org !== '' ? $org : self::NIL,
      $intentId,
      $capturedAt,
      $expiresAt,
      $actor ?? ['id' => $action->actorId, 'type' => $action->actorType, 'runtime' => 'drupal'],
      'commerce.' . str_replace('_', '.', $actionKey),
      $resource,
      $action->payload,
      ['idempotency_key' => $action->idempotencyKey, 'source' => 'drupal-decionis'],
      ['system' => 'drupal_commerce', 'operation' => $actionKey],
      ['decision_type' => $type->value, 'transaction_type' => $type->transactionType(), 'workflow_key' => $type->workflowKey(), 'channel' => 'api'],
    );
  }

}
