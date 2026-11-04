<?php

declare(strict_types=1);

namespace Drupal\decionis\Gate;

use Drupal\Core\Database\IntegrityConstraintViolationException;
use Decionis\Client\Decision\Action;
use Decionis\Client\Decision\Decision as Answer;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\decionis\Entity\Decision;
use Psr\Log\LoggerInterface;

/**
 * Writes the decision log.
 *
 * Every evaluation is recorded, in every mode, with hashes and stable ids only.
 */
final class Ledger {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LoggerInterface $logger,
    private readonly Connection $database,
    private readonly ?Settings $settings = NULL,
  ) {}

  /**
   * Records one decision and returns the log entry id.
   */
  public function record(?Action $action, Context $context, Outcome $outcome, string $authority, ?Answer $answer, ?string $intentHash = NULL, ?string $requestedPolicy = NULL, ?string $organization = NULL): int {
    $fields = $this->fields($action, $context, $outcome, $authority, $answer, $intentHash, $organization);
    if ($context->source === 'replay') {
      $fields['policy_version'] = $answer?->policyVersion ?? $requestedPolicy;
      $fields['replay_key'] = hash('sha256', json_encode([
        $fields['org_id'], $authority, $context->source, $context->action, $context->targetType,
        $context->targetId ?? $action?->idempotencyKey,
        $fields['policy_version'] ?? 'unversioned',
      ], JSON_THROW_ON_ERROR));
      $entry = $this->replay($fields);
    }
    else {
      $entry = $this->entityTypeManager->getStorage('decionis_decision')->create($fields);
      $entry->save();
    }
    // A refusal thrown from inside a save rolls back the save's transaction,
    // and this entry with it. Pending work must never survive such a rollback.
    if ($context->source === 'live' && !in_array($outcome->effect, ['pending', 'evaluating'], TRUE) && $this->database->inTransaction()) {
      $this->database->transactionManager()->addPostTransactionCallback(fn () => $this->keep($entry));
    }
    $this->log($context, $outcome, $answer);
    return (int) $entry->id();
  }

  /**
   * Captures deferred work in the decision row; the queue contains only its id.
   */
  public function pending(Capture $capture, ?string $connection, int $recipient): int {
    $fields = $this->fields($capture->request->action, $capture->context, new Outcome('shadow', 'pending', NULL), 'account', NULL);
    $fields += [
      'pending_capture' => $capture->encode(),
      'connection_id' => $connection,
      'recipient' => $recipient,
      'nudge_state' => $recipient > 0 ? 'pending' : NULL,
    ];
    $fields['policy_version'] = $capture->request->policyVersion;
    $entry = $this->entityTypeManager->getStorage('decionis_decision')->create($fields);
    $entry->save();
    return (int) $entry->id();
  }

  /**
   * Claims once, or reclaims an interrupted worker after its lease expires.
   */
  public function claim(int $id, int $now): ?Decision {
    $token = bin2hex(random_bytes(16));
    $update = $this->database->update('decionis_decision');
    $eligible = $update->orConditionGroup()->condition('effect', 'pending')
      ->condition($update->andConditionGroup()->condition('effect', 'evaluating')->condition('lease_expires', $now, '<='));
    $changed = $update->fields([
      'effect' => 'evaluating',
      'lease_token' => $token,
      'lease_expires' => $now + 120,
    ])->condition('id', $id)->condition($eligible)->execute();
    if (!$changed) {
      return NULL;
    }
    $storage = $this->entityTypeManager->getStorage('decionis_decision');
    $storage->resetCache([$id]);
    $entry = $storage->load($id);
    return $entry instanceof Decision ? $entry : NULL;
  }

  /**
   * Resolves only the lease owner, keeping the original row and captured facts.
   */
  public function resolve(Decision $entry, Capture $capture, Outcome $outcome, string $authority, ?Answer $answer, string $nudge = ''): bool {
    $fields = $this->fields($capture->request->action, $capture->context, $outcome, $authority, $answer);
    // These values belong to the original site and successful entity save,
    // even if its settings or entity changed while the request was queued.
    unset($fields['org_id'], $fields['target_id']);
    $fields['pending_capture'] = NULL;
    $fields['lease_token'] = NULL;
    $fields['lease_expires'] = 0;
    $fields['nudge_text'] = $nudge !== '' ? $nudge : NULL;
    if ($nudge === '') {
      $fields['nudge_state'] = NULL;
    }
    $changed = $this->database->update('decionis_decision')->fields($fields)
      ->condition('id', $entry->id())->condition('effect', 'evaluating')
      ->condition('lease_token', $entry->value('lease_token'))->execute();
    $this->entityTypeManager->getStorage('decionis_decision')->resetCache([$entry->id()]);
    if ($changed) {
      $this->log($capture->context, $outcome, $answer);
    }
    return (bool) $changed;
  }

  /**
   * A successful entity save qualifies the captured recipient for a nudge.
   *
   * This update joins the entity save transaction: rollback undoes it too.
   */
  public function saved(int $id, EntityInterface $entity): void {
    $this->database->update('decionis_decision')->fields([
      'save_confirmed' => 1,
      'target_id' => (string) $entity->id(),
    ])->condition('id', $id)->execute();
    $this->entityTypeManager->getStorage('decionis_decision')->resetCache([$id]);
  }

  /**
   * Discards an unreadable capture without inventing a policy decision.
   */
  public function abandon(Decision $entry): void {
    $this->database->update('decionis_decision')->fields([
      'effect' => $entry->value('source') === 'webhook' ? 'outside' : 'ungoverned',
      'reasons' => '["SHADOW_CAPTURE_INVALID"]',
      'pending_capture' => NULL,
      'lease_token' => NULL,
      'lease_expires' => 0,
      'nudge_state' => NULL,
      'nudge_text' => NULL,
    ])->condition('id', $entry->id())->condition('effect', 'evaluating')
      ->condition('lease_token', $entry->value('lease_token'))->execute();
    $this->entityTypeManager->getStorage('decionis_decision')->resetCache([$entry->id()]);
  }

  /**
   * Bounded scalar fields shared by synchronous and deferred decisions.
   */
  private function fields(?Action $action, Context $context, Outcome $outcome, string $authority, ?Answer $answer, ?string $intentHash = NULL, ?string $organization = NULL): array {
    return [
      'action' => $context->action,
      'hook' => $context->hook,
      'source' => $context->source,
      'mode' => $outcome->mode,
      'verdict' => $outcome->verdict?->value,
      'effect' => $outcome->effect,
      'reasons' => json_encode(array_slice($outcome->reasons, 0, 20)),
      'authority' => $authority,
      'policy_version' => $answer?->policyVersion,
      'dossier_id' => $answer?->dossierId,
      'dossier_sha256' => $answer?->dossierSha256,
      'evaluation_id' => $answer?->evaluationId,
      'idempotency_key' => $action?->idempotencyKey,
      'intent_hash' => $intentHash,
      'target_type' => $context->targetType,
      'target_id' => $context->targetId,
      'customer' => is_string($context->facts['customer_hash'] ?? NULL) ? $context->facts['customer_hash'] : NULL,
      'at_stake' => $context->atStake,
      'currency' => $context->currency,
      'org_id' => ($organization ?? $this->settings?->get('org_id')) ?: NULL,
      'occurred' => $context->occurredAt,
      'checkout' => $context->checkout ? 1 : 0,
    ];
  }

  /**
   * A database uniqueness constraint arbitrates simultaneous replay inserts.
   */
  private function replay(array $fields): Decision {
    $storage = $this->entityTypeManager->getStorage('decionis_decision');
    $matches = $storage->loadByProperties(['replay_key' => $fields['replay_key']]);
    $entry = reset($matches);
    if (!$entry instanceof Decision && $fields['org_id'] !== NULL && $fields['verdict'] !== NULL && $fields['policy_version'] !== NULL) {
      $entry = $this->adoptUnversioned($fields);
    }
    if (!$entry instanceof Decision && $fields['org_id'] !== NULL) {
      // Adopt only a row with the same known workspace and authority.
      $query = $storage->getQuery()->accessCheck(FALSE)->notExists('replay_key');
      foreach ([
        'source' => 'replay',
        'action' => $fields['action'],
        'org_id' => $fields['org_id'],
        'authority' => $fields['authority'],
        'target_type' => $fields['target_type'],
        'target_id' => $fields['target_id'],
        'policy_version' => $fields['policy_version'],
      ] as $field => $value) {
        $value === NULL ? $query->notExists($field) : $query->condition($field, $value);
      }
      $matches = $storage->loadMultiple($query->sort('id')->range(0, 1)->execute());
      $entry = reset($matches);
    }
    if (!$entry instanceof Decision) {
      $entry = $storage->create($fields);
      if (!$entry instanceof Decision) {
        throw new \LogicException('DECISION_ENTITY_INVALID');
      }
      try {
        $entry->save();
        return $entry;
      }
      catch (\Exception $error) {
        // A competing insert may have won. Other storage errors still fail.
        $storage->resetCache();
        $matches = $storage->loadByProperties(['replay_key' => $fields['replay_key']]);
        $entry = reset($matches);
        if (!$entry instanceof Decision) {
          throw $error;
        }
      }
    }
    // A temporary failure must not erase a previous verifiable decision.
    if ($fields['verdict'] !== NULL || $entry->value('verdict') === '') {
      foreach ($fields as $name => $value) {
        $entry->set($name, $value);
      }
    }
    else {
      $entry->set('replay_key', $fields['replay_key']);
    }
    $entry->save();
    return $entry;
  }

  /**
   * Promotes a failed unpinned replay after its actual policy is known.
   */
  private function adoptUnversioned(array $fields): ?Decision {
    $storage = $this->entityTypeManager->getStorage('decionis_decision');
    $query = $storage->getQuery()->accessCheck(FALSE)->notExists('policy_version')->notExists('verdict')
      ->condition('source', 'replay')->condition('effect', 'ungoverned')
      ->condition('org_id', $fields['org_id'])->condition('authority', $fields['authority'])
      ->condition('action', $fields['action']);
    foreach (['target_type', 'target_id'] as $name) {
      $fields[$name] === NULL ? $query->notExists($name) : $query->condition($name, $fields[$name]);
    }
    $ids = $query->sort('id')->range(0, 1)->execute();
    $id = reset($ids);
    if ($id === FALSE) {
      return NULL;
    }
    $transaction = $this->database->startTransaction();
    try {
      // Set the policy in the claim too: another policy may not adopt the
      // same placeholder while its first successful decision is being saved.
      $claimed = $this->database->update('decionis_decision')->fields([
        'replay_key' => $fields['replay_key'],
        'policy_version' => $fields['policy_version'],
      ])->condition('id', $id)->isNull('policy_version')->isNull('verdict')->execute();
      $storage->resetCache([$id]);
      if ($claimed) {
        $entry = $storage->load($id);
        return $entry instanceof Decision ? $entry : NULL;
      }
    }
    catch (IntegrityConstraintViolationException) {
      // A competing insert of this exact policy already became canonical.
      $transaction->rollBack();
    }
    finally {
      unset($transaction);
    }
    $matches = $storage->loadByProperties(['replay_key' => $fields['replay_key']]);
    $entry = reset($matches);
    return $entry instanceof Decision ? $entry : NULL;
  }

  /**
   * Logs the resolved outcome once, without the captured action payload.
   */
  private function log(Context $context, Outcome $outcome, ?Answer $answer): void {
    $this->logger->info('Decionis @effect @action at @hook (@mode, @verdict, dossier @dossier).', [
      '@effect' => $outcome->effect,
      '@action' => $context->action,
      '@hook' => $context->hook,
      '@mode' => $outcome->mode,
      '@verdict' => $outcome->verdict === NULL ? 'no decision' : $outcome->verdict->value,
      '@dossier' => $answer === NULL ? 'none' : $answer->dossierId,
    ]);
  }

  /**
   * Writes an entry again if its transaction took it away.
   */
  private function keep(EntityInterface $entry): void {
    $storage = $this->entityTypeManager->getStorage('decionis_decision');
    $storage->resetCache([$entry->id()]);
    if ($storage->load($entry->id()) === NULL) {
      $values = $entry->toArray();
      unset($values['id'], $values['uuid']);
      $storage->create($values)->save();
    }
  }

}
