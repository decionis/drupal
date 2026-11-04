<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Replay;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\decionis\Gate\Background;
use Drupal\decionis\Gate\Settings;

/**
 * One resumable replay cursor shared by the connect batch and cron.
 *
 * Queue items contain only an opaque run id. No credentials, customer facts
 * or commerce payloads are persisted here. A changed connection cancels work.
 */
final class Runs {

  public const STATE = 'decionis_commerce.replay';
  private const KINDS = ['order', 'refund', 'promotion'];

  public function __construct(
    private readonly Replay $replay,
    private readonly Settings $settings,
    private readonly StateInterface $state,
    private readonly LockBackendInterface $lock,
    private readonly QueueFactory $queues,
  ) {}

  /**
   * Starts a fresh run after connect, retaining its source history window.
   */
  public function start(int $days): string {
    if ($days < 1 || $days > 365) {
      throw new \InvalidArgumentException('REPLAY_DAYS_INVALID');
    }
    $id = bin2hex(random_bytes(16));
    $identity = $this->settings->connectionIdentity();
    $this->state->set(self::STATE, [
      'id' => $id,
      'identity' => $identity,
      'days' => $days,
      'until' => time(),
      'policy' => $this->settings->get('policy_version') ?: NULL,
      'after' => array_fill_keys(self::KINDS, 0),
      'next' => 0,
      'status' => $identity === NULL ? 'connection_changed' : 'running',
      'ids' => [],
      'reviewed' => 0,
      'unavailable' => 0,
      'not_covered' => 0,
    ]);
    if ($identity !== NULL) {
      $this->queue($id);
    }
    return $id;
  }

  /**
   * Latest run metadata for the dashboard, without any secret references.
   */
  public function current(): array {
    $this->state->resetCache();
    $run = $this->state->get(self::STATE, []);
    return is_array($run) ? $run : [];
  }

  /**
   * Advances one kind, rotating so the first results cover different actions.
   *
   * A busy cursor is retried; stale or copied-site work never uses a new key.
   */
  public function advance(string $id, bool $interactive = FALSE): array {
    if (!$interactive && !Background::allowed()) {
      return $this->current() + ['deferred' => TRUE];
    }
    if (!$this->lock->acquire(self::STATE, 60)) {
      return $this->current() + ['busy' => TRUE];
    }
    try {
      $run = $this->current();
      if (($run['id'] ?? NULL) !== $id || ($run['status'] ?? NULL) !== 'running') {
        return $run;
      }
      if (($run['identity'] ?? NULL) === NULL || $this->settings->connectionIdentity() !== $run['identity']) {
        $run['status'] = 'connection_changed';
        $this->state->set(self::STATE, $run);
        return $run;
      }
      for ($offset = 0; $offset < count(self::KINDS); $offset++) {
        $index = ($run['next'] + $offset) % count(self::KINDS);
        $kind = self::KINDS[$index];
        if ($run['after'][$kind] !== NULL) {
          $batch = $this->replay->chunk($kind, $run['days'], $run['after'][$kind], policy: $run['policy'], resolution: $id, until: $run['until'], identity: $run['identity']);
          $run['after'][$kind] = $batch['after'];
          $run['next'] = ($index + 1) % count(self::KINDS);
          $run['policy'] = $batch['policy'];
          $run['ids'] = array_values(array_unique([...$run['ids'], ...$batch['ids']]));
          sort($run['ids'], SORT_NUMERIC);
          $run['reviewed'] = count($run['ids']);
          $run['unavailable'] += $batch['unavailable'];
          $run['not_covered'] += $batch['not_covered'];
          break;
        }
      }
      if (array_filter($run['after'], static fn (?int $after): bool => $after !== NULL) === []) {
        $run['status'] = 'complete';
      }
      // A reconnect may have replaced the run while HTTP was in flight.
      $current = $this->current();
      if (($current['id'] ?? NULL) !== $id) {
        return $current;
      }
      if ($this->settings->connectionIdentity() !== $run['identity']) {
        $run['status'] = 'connection_changed';
      }
      $this->state->set(self::STATE, $run);
      return $run;
    }
    finally {
      $this->lock->release(self::STATE);
    }
  }

  /**
   * Retains a cursor until cron can process the next bounded operation.
   */
  public function queue(string $id): void {
    $this->queues->get('decionis_replay')->createItem(['run' => $id]);
  }

}
