<?php

declare(strict_types=1);

namespace Drupal\decionis\Gate;

use Decionis\Client\Error\DecionisException;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\decionis\Notification\Nudges;

/**
 * Durable Shadow work. Capturing a live request performs database writes only.
 */
final class Deferred {

  /**
   * Captures from this request only, used to qualify successful entity saves.
   *
   * @var array<int, \Drupal\decionis\Gate\Capture>
   */
  private array $captures = [];

  /**
   * Pending ids remain eligible for termination after save qualification.
   *
   * @var list<int>
   */
  private array $requestIds = [];

  public function __construct(
    private readonly Ledger $ledger,
    private readonly Settings $settings,
    private readonly Authority $authority,
    private readonly Connection $database,
    private readonly QueueFactory $queues,
    private readonly AccountProxyInterface $user,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Records pending work without reading a secret provider or doing HTTP.
   */
  public function enqueue(Capture $capture): Outcome {
    $recipient = !$capture->context->checkout && $capture->context->source === 'live'
      && $this->user->isAuthenticated() && $this->user->hasPermission('administer decionis')
      ? (int) $this->user->id() : 0;
    $id = $this->ledger->pending($capture, $this->settings->connectionIdentity(), $recipient);
    $this->captures[$id] = $capture;
    $this->requestIds[] = $id;
    // Queue backends can perform network I/O or lazily create database tables.
    // Dispatch them from cron, never from inside a native commerce save.
    return new Outcome('shadow', 'pending', NULL, [], NULL, $id);
  }

  /**
   * Called from generic entity insert/update hooks inside the save transaction.
   */
  public function saved(EntityInterface $entity): void {
    foreach ($this->captures as $id => $capture) {
      if ($capture->matches($entity)) {
        $this->ledger->saved($id, $entity);
        // A later rollback must not let another save in the same request
        // qualify this failed attempt. The transaction owns the flag now.
        unset($this->captures[$id]);
      }
    }
  }

  /**
   * Creates bounded queue items from durable rows, recovering expired workers.
   */
  public function dispatch(int $limit = 100): int {
    if (!Background::allowed()) {
      return 0;
    }
    $now = $this->time->getCurrentTime();
    $ids = $this->database->select('decionis_decision', 'd')->fields('d', ['id'])
      ->condition('d.effect', ['pending', 'evaluating'], 'IN')
      ->condition('d.lease_expires', $now, '<=')->orderBy('d.id')
      ->range(0, max(1, min(100, $limit)))->execute()->fetchCol();
    $queue = $this->queues->get('decionis_shadow', TRUE);
    $count = 0;
    foreach ($ids as $id) {
      $claimed = $this->database->update('decionis_decision')->fields([
        'effect' => 'pending',
        'lease_token' => NULL,
        'lease_expires' => $now + 300,
      ])->condition('id', $id)->condition('effect', ['pending', 'evaluating'], 'IN')
        ->condition('lease_expires', $now, '<=')->execute();
      if ($claimed) {
        $queue->createItem(['id' => (int) $id]);
        $count++;
      }
    }
    return $count;
  }

  /**
   * Evaluates one frozen proposal and resolves its row at most once.
   */
  public function process(int $id): void {
    if (!Background::allowed()) {
      return;
    }
    $entry = $this->ledger->claim($id, $this->time->getCurrentTime());
    if ($entry === NULL) {
      return;
    }
    try {
      $capture = Capture::decode($entry->value('pending_capture'));
    }
    catch (\Throwable) {
      $this->ledger->abandon($entry);
      return;
    }
    $answer = NULL;
    $identity = $this->settings->connectionIdentity();
    $captured = $entry->value('connection_id');
    if ($captured === '' || $identity === NULL || !hash_equals($captured, $identity)) {
      $outcome = new Outcome('shadow', 'ungoverned', NULL, [$captured === '' ? 'NOT_CONNECTED' : 'SHADOW_CONNECTION_CHANGED']);
    }
    else {
      try {
        if (!$this->authority->available()) {
          $outcome = new Outcome('shadow', 'ungoverned', NULL, ['NOT_CONNECTED']);
        }
        else {
          // No checkout deadline: the shopper's response has already ended.
          $answer = $this->authority instanceof Remote
            ? $this->authority->evaluate($capture->request, FALSE, NULL, $captured)
            : $this->authority->evaluate($capture->request, FALSE);
          $outcome = new Outcome('shadow', $capture->context->source === 'webhook' ? 'outside' : 'proceeded', $answer->verdict, $answer->reasonCodes, $answer->dossierId);
        }
      }
      catch (DecionisException $error) {
        $outcome = new Outcome('shadow', 'ungoverned', NULL, [$error->getMessage()]);
      }
      catch (\Throwable) {
        $outcome = new Outcome('shadow', 'ungoverned', NULL, ['GATE_ERROR']);
      }
    }
    // Reconnection during a worker must not attach or notify a different site.
    if ($captured !== '' && $this->settings->connectionIdentity() !== $captured) {
      $answer = NULL;
      $outcome = new Outcome('shadow', 'ungoverned', NULL, ['SHADOW_CONNECTION_CHANGED']);
    }
    if ($capture->context->source === 'webhook' && $outcome->effect !== 'outside') {
      // The refund was observed outside the gate regardless of whether a
      // policy decision is available. Keep that fact visible to coverage.
      $outcome = new Outcome('shadow', 'outside', $outcome->verdict, $outcome->reasons, $outcome->dossierId);
    }
    $nudge = (int) $entry->value('recipient') > 0 ? Nudges::text($capture, $outcome) : '';
    $this->ledger->resolve($entry, $capture, $outcome, $this->authority->label(), $answer, $nudge);
  }

  /**
   * Drains this request only after FPM has actually finished its response.
   */
  public function finishResponse(): void {
    if (!Background::finishResponse()) {
      return;
    }
    foreach (array_slice($this->requestIds, 0, 4) as $id) {
      $this->process($id);
    }
  }

}
