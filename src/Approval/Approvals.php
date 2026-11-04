<?php

declare(strict_types=1);

namespace Drupal\decionis\Approval;

use Decionis\Client\Intent\Intent;
use Decionis\Client\Decision\Action;
use Decionis\Client\Decision\Decision as Answer;
use Decionis\Client\Grant\Claim;
use Decionis\Client\Grant\Grant;
use Decionis\Client\Intent\Hasher;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\decionis\Entity\Hold;
use Drupal\decionis\Gate\Context;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis\Gate\Holds;
use Drupal\decionis\Gate\Settings;
use Psr\Log\LoggerInterface;

/**
 * The approval queue.
 *
 * An ESCALATE in Enforce opens a hold with the exact action as an intent.
 * The approver approves that exact action in Presence; Decionis then issues a
 * single-use grant. The action runs once: the stored intent hash must match
 * the action as it stands, the hold moves from approved to executing in one
 * atomic update, and the grant is claimed before anything runs. A replay, a
 * changed action or an expired intent is refused, never run.
 */
final class Approvals implements Holds {

  /**
   * The executors, by the action key they handle.
   *
   * @var list<Executor>
   */
  private array $executors = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Connection $database,
    private readonly Intents $intents,
    private readonly Grants $grants,
    private readonly LoggerInterface $logger,
    private readonly ?Settings $settings = NULL,
  ) {}

  /**
   * Registers an executor (service collector for decionis.executor).
   */
  public function addExecutor(Executor $executor): void {
    $this->executors[] = $executor;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(string $action): bool {
    return $this->executor($action) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function open(Action $action, Context $context, int $logId, ?Answer $answer): int {
    // The same action submitted again joins the hold already waiting for it.
    $existing = $context->targetType === NULL || $context->targetId === NULL ? NULL : $this->waiting($context, $action->idempotencyKey);
    if ($existing !== NULL) {
      return (int) $existing->id();
    }
    $intent = $this->intents->capture($action, $context->action, 'drupal:' . ($context->targetType ?? 'action') . ':' . ($context->targetId ?? $action->idempotencyKey));
    $hold = $this->entityTypeManager->getStorage('decionis_hold')->create([
      'action' => $context->action,
      'hook' => $context->hook,
      'target_type' => $context->targetType,
      'target_id' => $context->targetId,
      'state' => 'pending',
      'intent' => json_encode($intent->toArray(), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
      'intent_hash' => $intent->hash(),
      'dossier_id' => $answer?->dossierId,
      'log_id' => $logId,
      'at_stake' => $context->atStake,
      'currency' => $context->currency,
      'connection_id' => $this->settings?->connectionIdentity(),
    ]);
    assert($hold instanceof Hold);
    // Only live actions escalate: a sandbox or replay hold never reaches an
    // approver or a real authority.
    $authority = $this->authority($hold);
    if ($context->source === 'live' && $this->belongs($hold) && $authority->available()) {
      try {
        $escalation = $authority->escalate($intent);
        if ($this->belongs($hold)) {
          $hold->set('escalation_id', $escalation->escalationId)->set('approval_url', $escalation->approvalUrl)->set('decision_id', $escalation->decisionId);
        }
        else {
          $hold->set('state', 'refused')->set('reason', 'CONNECTION_CHANGED');
        }
      }
      catch (\Throwable) {
        $hold->set('reason', $this->belongs($hold) ? 'APPROVAL_UNAVAILABLE' : 'CONNECTION_CHANGED');
      }
    }
    $hold->save();
    $this->logger->info('Decionis held @action @hold for approval.', ['@action' => $context->action, '@hold' => $hold->id()]);
    return (int) $hold->id();
  }

  /**
   * Asks whether the approver approved, and stores the grant when so.
   */
  public function refresh(Hold $hold): Hold {
    $hold = $this->reload($hold);
    if (in_array($hold->value('state'), ['executed', 'executing'], TRUE)) {
      return $hold;
    }
    if (!$this->belongs($hold)) {
      $this->refuse($hold, 'CONNECTION_CHANGED');
      return $hold;
    }
    if ($hold->value('state') !== 'pending' || $hold->value('escalation_id') === '' || $this->expired($hold)) {
      return $this->expire($hold);
    }
    $intent = $this->intent($hold);
    try {
      $grant = $intent === NULL ? NULL : $this->authority($hold)->approved($hold->value('escalation_id'), $intent);
    }
    catch (\Throwable $error) {
      if ($this->belongs($hold)) {
        throw $error;
      }
      $grant = NULL;
    }
    if (!$this->belongs($hold)) {
      $this->refuse($hold, 'CONNECTION_CHANGED');
      return $hold;
    }
    if ($grant !== NULL && $grant->executable()) {
      $hold->set('state', 'approved')->set('grant_token', $grant->token)->set('grant_expires', $grant->expiresAt)->set('decision_id', $grant->decisionId)->set('dossier_id', $grant->dossierId)->save();
    }
    elseif ($grant !== NULL) {
      $hold->set('state', 'refused')->set('reason', $grant->reasonCodes[0] ?? 'REJECTED')->save();
    }
    return $hold;
  }

  /**
   * Runs an approved action once.
   *
   * @return string
   *   'executed', or why it was refused: ALREADY_RUN, NOT_APPROVED, EXPIRED,
   *   CHANGED, NO_EXECUTOR, GRANT_EXPIRED, CLAIM_REFUSED or FAILED.
   */
  public function execute(Hold $hold, Gate $gate): string {
    // Decide on the stored hold, never on a copy the caller loaded earlier.
    $hold = $this->reload($hold);
    $state = $hold->value('state');
    if (in_array($state, ['executing', 'executed'], TRUE)) {
      return 'ALREADY_RUN';
    }
    if (!$this->belongs($hold)) {
      return $this->refuse($hold, 'CONNECTION_CHANGED');
    }
    if ($state !== 'approved' || $hold->value('grant_token') === '') {
      return 'NOT_APPROVED';
    }
    if ($this->expired($hold)) {
      $this->expire($hold);
      return 'EXPIRED';
    }
    $executor = $this->executor($hold->value('action'));
    if ($executor === NULL) {
      return $this->refuse($hold, 'NO_EXECUTOR');
    }
    $current = $executor->capture($hold);
    $intent = $current === NULL ? NULL : $this->intents->recapture($current, $hold->binding());
    if ($intent === NULL || !Hasher::matches($hold->value('intent_hash'), $intent->hash())) {
      return $this->refuse($hold, 'CHANGED');
    }
    try {
      $grant = $this->grant($hold, $intent);
    }
    catch (\Throwable) {
      $grant = NULL;
    }
    if ($grant === NULL || !$grant->executable()) {
      // The grant expired between approval and the run: ask again, never run.
      return $this->refuse($hold, 'GRANT_EXPIRED');
    }
    if (!$this->belongs($hold)) {
      return $this->refuse($hold, 'CONNECTION_CHANGED');
    }
    $authority = $this->authority($hold);
    // One atomic transition: of any number of parallel runs, one proceeds.
    $won = $this->database->update('decionis_hold')->fields(['state' => 'executing'])->condition('id', $hold->id())->condition('state', 'approved')->execute();
    if ($won !== 1) {
      return 'ALREADY_RUN';
    }
    try {
      $claim = $authority->claim($grant, $intent);
    }
    catch (\Throwable) {
      return $this->refuse($this->reload($hold), $this->belongs($hold) ? 'CLAIM_REFUSED' : 'CONNECTION_CHANGED');
    }
    if (!$this->belongs($hold)) {
      return $this->refuse($this->reload($hold), 'CONNECTION_CHANGED');
    }
    try {
      $gate->without(function () use ($executor, $hold): void {
        if (!$this->belongs($hold)) {
          throw new \LogicException('DECIONIS_CONNECTION_CHANGED');
        }
        $executor->run($hold);
      });
    }
    catch (\Throwable $error) {
      if ($error instanceof \LogicException && $error->getMessage() === 'DECIONIS_CONNECTION_CHANGED') {
        return $this->refuse($this->reload($hold), 'CONNECTION_CHANGED');
      }
      $this->finalize($authority, $grant, $claim, $intent, 'FAILED');
      $this->logger->error('Decionis approved action @hold failed: @class', ['@hold' => $hold->id(), '@class' => get_class($error)]);
      return $this->refuse($this->reload($hold), 'FAILED');
    }
    // The action ran: record it as run whatever the receipt call does.
    $this->reload($hold)->set('state', 'executed')->set('grant_token', NULL)->save();
    $this->finalize($authority, $grant, $claim, $intent, 'COMMITTED');
    return 'executed';
  }

  /**
   * Records the attempt with the authority; a failure to record is logged.
   */
  private function finalize(Grants $authority, Grant $grant, Claim $claim, Intent $intent, string $outcome): void {
    try {
      $authority->finalize($grant, $claim, $intent, $outcome);
    }
    catch (\Throwable $error) {
      $this->logger->warning('Decionis could not record the @outcome receipt (@class).', ['@outcome' => $outcome, '@class' => get_class($error)]);
    }
  }

  /**
   * A hold still waiting for the same action, if any.
   */
  private function waiting(Context $context, string $idempotencyKey): ?Hold {
    $holds = $this->entityTypeManager->getStorage('decionis_hold')->loadByProperties([
      'action' => $context->action,
      'target_type' => $context->targetType,
      'target_id' => $context->targetId,
    ]);
    foreach ($holds as $hold) {
      if ($hold instanceof Hold && $this->belongs($hold) && in_array($hold->value('state'), ['pending', 'approved'], TRUE) && ($hold->binding()['context']['idempotency_key'] ?? NULL) === $idempotencyKey) {
        return $hold;
      }
    }
    return NULL;
  }

  /**
   * The executor for an action key, or NULL.
   */
  private function executor(string $action): ?Executor {
    foreach ($this->executors as $executor) {
      if ($executor->handles($action)) {
        return $executor;
      }
    }
    return NULL;
  }

  /**
   * Real holds belong to the exact connection that captured them.
   *
   * The sandbox constructs its own Approvals with a local demo grant authority
   * and no Settings service inside its rolled-back transaction.
   *
   * @phpstan-impure
   */
  private function belongs(Hold $hold): bool {
    if ($this->settings === NULL) {
      return TRUE;
    }
    $current = $this->settings->connectionIdentity();
    return $current !== NULL && $hold->value('connection_id') !== '' && hash_equals($current, $hold->value('connection_id'));
  }

  /**
   * Pins real grant HTTP operations to a hold, preserving local test providers.
   */
  private function authority(Hold $hold): Grants {
    return $this->grants instanceof AgentSafe ? $this->grants->bound($hold->value('connection_id')) : $this->grants;
  }

  /**
   * The stored grant as the client reads it, bound to the intent.
   */
  private function grant(Hold $hold, Intent $intent): Grant {
    return Grant::fromResponse([
      'decision_id' => $hold->value('decision_id'),
      'status' => 'ALLOW',
      'should_execute' => TRUE,
      'mode' => 'ENFORCEMENT',
      'action_hash' => $intent->hash(),
      'execution_token' => $hold->value('grant_token'),
      'execution_token_expires_at' => $hold->value('grant_expires'),
      'dossier_id' => $hold->value('dossier_id') ?: NULL,
    ], $intent, new \DateTimeImmutable());
  }

  /**
   * The stored intent, rebuilt for the client.
   */
  private function intent(Hold $hold): ?Intent {
    $binding = $hold->binding();
    try {
      return new Intent(
        (string) ($binding['tenant_id'] ?? ''), (string) ($binding['intent_id'] ?? ''), (string) ($binding['captured_at'] ?? ''), (string) ($binding['expires_at'] ?? ''),
        (array) ($binding['actor'] ?? []), (string) ($binding['action']['type'] ?? ''), (string) ($binding['action']['resource'] ?? ''),
        (array) ($binding['action']['parameters'] ?? []), (array) ($binding['context'] ?? []), (array) ($binding['downstream_target'] ?? []),
        is_array($binding['policy_projection'] ?? NULL) ? $binding['policy_projection'] : NULL,
      );
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * TRUE once the intent's own expiry has passed.
   */
  private function expired(Hold $hold): bool {
    $expires = strtotime((string) ($hold->binding()['expires_at'] ?? ''));
    return $expires === FALSE || $expires <= time();
  }

  /**
   * Marks a hold expired when its intent is stale.
   */
  private function expire(Hold $hold): Hold {
    if ($this->expired($hold) && in_array($hold->value('state'), ['pending', 'approved'], TRUE)) {
      $hold->set('state', 'expired')->set('grant_token', NULL)->save();
    }
    return $hold;
  }

  /**
   * Refuses a hold for good and says why.
   */
  private function refuse(Hold $hold, string $reason): string {
    $hold->set('state', 'refused')->set('reason', $reason)->set('grant_token', NULL)->save();
    $this->logger->warning('Decionis refused to run hold @hold: @reason.', ['@hold' => $hold->id(), '@reason' => $reason]);
    return $reason;
  }

  /**
   * The hold as stored now.
   */
  private function reload(Hold $hold): Hold {
    $fresh = $this->entityTypeManager->getStorage('decionis_hold')->loadUnchanged($hold->id());
    assert($fresh instanceof Hold);
    return $fresh;
  }

}
