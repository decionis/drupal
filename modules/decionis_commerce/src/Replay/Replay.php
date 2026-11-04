<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Replay;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\decionis\Gate\Context;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_promotion\Entity\PromotionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\decionis\Gate\Authority;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis\Gate\Outcome;
use Drupal\decionis\Gate\Remote;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis\Report\Summary;
use Drupal\decionis_commerce\Compatibility;
use Drupal\decionis_commerce\Map\Actions;
use Drupal\decionis_commerce\Report\History;
use Drupal\decionis_commerce\Report\Limits;
use Drupal\decionis_commerce\Report\Xray;
use Drupal\decionis_commerce\Sandbox\Isolation;
use Drupal\decionis_commerce\Sandbox\Interrupted;
use Drupal\decionis_commerce\Sandbox\Local;

/**
 * The read-only Shadow replay.
 *
 * Past orders, refunds and promotions evaluated against the store's policy to
 * show what it would have held. Nothing is changed; every decision is recorded
 * with source 'replay'. The demo replay records nothing at all.
 */
final class Replay {

  public const BATCH = 50;

  public function __construct(
    private readonly Gate $gate,
    private readonly Actions $actions,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Summary $summary,
    private readonly Connection $database,
    private readonly Isolation $isolation,
    private readonly History $history,
    private readonly Limits $limits,
    private readonly Settings $settings,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Compatibility $compatibility,
    private readonly StateInterface $state,
  ) {}

  /**
   * Replays one batch of orders, refunds or promotions after a given id.
   *
   * Returns the last id seen, or NULL when the kind is done.
   */
  public function batch(string $kind, int $days, int $after, ?Authority $authority = NULL): ?int {
    return $this->chunk($kind, $days, $after, $authority)['after'];
  }

  /**
   * A bounded live batch, with the canonical ids it touched and policy used.
   *
   * @return array
   *   The next cursor, touched ids, policy version and coverage counts.
   *
   * @phpstan-return array{after: ?int, ids: list<int>, policy: ?string, unavailable: int, not_covered: int}
   */
  public function chunk(string $kind, int $days, int $after, ?Authority $authority = NULL, ?string $policy = NULL, ?string $resolution = NULL, ?int $until = NULL, int $size = self::BATCH, ?string $identity = NULL): array {
    if ($days < 1 || $days > 365 || $after < 0 || $size < 1 || $size > self::BATCH) {
      throw new \InvalidArgumentException('REPLAY_RANGE_INVALID');
    }
    $authority ??= $this->gate->authority();
    $policy ??= $this->gate->policy();
    $resolution ??= bin2hex(random_bytes(16));
    $identity ??= $this->settings->connectionIdentity() ?? $authority->label();
    $until ??= time();
    // Without a selected policy, resolve one real action before dispatching
    // the rest. An outage still costs at most one deadline per operation.
    $size = $authority instanceof Remote ? min($size, $policy === NULL ? 1 : $this->concurrency() * 2) : $size;
    $since = $until - $days * 86400;
    [$type, $query] = match ($kind) {
      'order' => ['commerce_order', $this->query('commerce_order')->condition('placed', [$since, $until], 'BETWEEN')->condition('state', 'draft', '<>')],
      'refund' => ['commerce_payment', $this->query('commerce_payment')->condition('state', ['refunded', 'partially_refunded'], 'IN')->condition($this->compatibility->refundedAt(), [$since, $until], 'BETWEEN')],
      'promotion' => ['commerce_promotion', $this->query('commerce_promotion')->condition('changed', [$since, $until], 'BETWEEN')],
      default => throw new \InvalidArgumentException('REPLAY_KIND_INVALID'),
    };
    $ids = $query->condition($this->entityTypeManager->getDefinition($type)->getKey('id'), $after, '>')->range(0, $size)->execute();
    $result = ['after' => NULL, 'ids' => [], 'policy' => $policy, 'unavailable' => 0, 'not_covered' => 0];
    if ($ids === []) {
      return $result;
    }
    $entities = array_values($this->entityTypeManager->getStorage($type)->loadMultiple($ids));
    try {
      while ($policy === NULL && $entities !== []) {
        $first = array_shift($entities);
        $outcomes = $this->evaluate([$first], $authority, NULL, $identity, $resolution);
        foreach ($outcomes as $outcome) {
          $this->collect($result, $outcome);
          if ($outcome?->verdict !== NULL && $outcome->logId !== NULL) {
            $returned = $this->database->select('decionis_decision', 'd')->fields('d', ['policy_version'])->condition('id', $outcome->logId)->execute()->fetchField();
            $policy = is_string($returned) && $returned !== '' ? $returned : NULL;
          }
        }
      }
      foreach ($this->evaluate($entities, $authority, $policy, $identity, $resolution) as $outcome) {
        $this->collect($result, $outcome);
      }
    }
    finally {
      $this->entityTypeManager->getStorage($type)->resetCache($ids);
    }
    $result['after'] = count($ids) < $size ? NULL : (int) max($ids);
    $result['policy'] = $policy;
    return $result;
  }

  /**
   * Replays every kind to the end (the drush command).
   */
  public function run(int $days, ?Authority $authority = NULL): array {
    $authority ??= $this->gate->authority();
    $ids = [];
    $policy = $this->gate->policy();
    $resolution = bin2hex(random_bytes(16));
    $identity = $this->settings->connectionIdentity();
    $until = time();
    $unavailable = $uncovered = 0;
    $this->state->resetCache();
    $previousRun = $this->state->get(Runs::STATE, []);
    foreach (['order', 'refund', 'promotion'] as $kind) {
      $after = 0;
      do {
        $batch = $this->chunk($kind, $days, $after, $authority, $policy, $resolution, $until, identity: $identity);
        $ids = [...$ids, ...$batch['ids']];
        $unavailable += $batch['unavailable'];
        $uncovered += $batch['not_covered'];
        $policy = $batch['policy'];
        $after = $batch['after'];
        if ($authority instanceof Remote && $this->settings->connectionIdentity() !== $identity) {
          throw new \LogicException('REPLAY_CONNECTION_CHANGED');
        }
      } while ($after !== NULL);
    }
    $ids = array_values(array_unique($ids));
    sort($ids, SORT_NUMERIC);
    $this->state->resetCache();
    $currentRun = $this->state->get(Runs::STATE, []);
    if ($authority instanceof Remote && $identity !== NULL && ($currentRun['id'] ?? NULL) === ($previousRun['id'] ?? NULL) && $this->settings->connectionIdentity() === $identity) {
      // A complete Drush replay becomes the current UI report too. A newer
      // connect/run or changed workspace wins instead; partial CLI failures
      // leave the previous report intact.
      $this->state->set(Runs::STATE, [
        'id' => $resolution,
        'identity' => $identity,
        'days' => $days,
        'until' => $until,
        'policy' => $policy,
        'after' => ['order' => NULL, 'refund' => NULL, 'promotion' => NULL],
        'next' => 0,
        'status' => 'complete',
        'ids' => $ids,
        'reviewed' => count($ids),
        'unavailable' => $unavailable,
        'not_covered' => $uncovered,
      ]);
    }
    return $this->summary->totals(0, 'replay', 0, $authority instanceof Local, $ids);
  }

  /**
   * The replay against the bundled demo authority, rolled back.
   *
   * Its verdicts are local estimates. No Gate or Ledger is called, even
   * temporarily. A kind and cursor select one bounded Batch API operation;
   * without them the existing Drush command gets the complete report.
   *
   * @return array<string, mixed>
   *   The totals, labelled.
   */
  public function demo(int $days, ?string $kind = NULL, int $after = 0, ?array $limits = NULL, ?int $until = NULL): array {
    if ($days < 1 || $days > 365) {
      throw new \InvalidArgumentException('XRAY_DAYS_INVALID');
    }
    $until ??= time();
    $this->isolation->prepare();
    $transaction = $this->database->startTransaction('decionis_demo_replay');
    try {
      if ($limits === NULL) {
        $samples = [];
        foreach (History::KINDS as $sampleKind) {
          $cursor = 0;
          do {
            $batch = $this->history->batch($sampleKind, $days, $cursor, $until);
            History::sample($samples, $batch['records']);
            $cursor = $batch['after'];
          } while ($cursor !== NULL);
        }
        $limits = $this->limits->effective(Limits::draft($samples, $this->history->currencies()));
      }
      $result = Xray::empty();
      foreach ($limits as $currency => $values) {
        $result['currencies'][$currency] = Xray::currency();
      }
      foreach ($kind === NULL ? History::KINDS : [$kind] as $current) {
        $cursor = $kind === NULL ? 0 : $after;
        do {
          $batch = $this->history->batch($current, $days, $cursor, $until);
          $result = Xray::merge($result, Xray::collect($batch['records'], $limits));
          $cursor = $batch['after'];
        } while ($kind === NULL && $cursor !== NULL);
      }
      if (!$this->isolation->intact()) {
        throw new Interrupted('XRAY_ISOLATION_INTERRUPTED');
      }
      return $result + ['after' => $cursor ?? NULL];
    }
    finally {
      if ($this->isolation->intact()) {
        $transaction->rollBack();
      }
      unset($transaction);
      $this->entityTypeManager->getStorage('decionis_decision')->resetCache();
    }
  }

  /**
   * One bounded, rolled-back pass for numeric drafting samples only.
   */
  public function sample(int $days, string $kind, int $after, int $until): array {
    if ($days < 1 || $days > 365) {
      throw new \InvalidArgumentException('XRAY_DAYS_INVALID');
    }
    $this->isolation->prepare();
    $transaction = $this->database->startTransaction('decionis_xray_draft');
    try {
      $batch = $this->history->batch($kind, $days, $after, $until);
      $samples = [];
      History::sample($samples, $batch['records']);
      if (!$this->isolation->intact()) {
        throw new Interrupted('XRAY_ISOLATION_INTERRUPTED');
      }
      return ['samples' => $samples, 'after' => $batch['after']];
    }
    finally {
      if ($this->isolation->intact()) {
        $transaction->rollBack();
      }
      unset($transaction);
    }
  }

  /**
   * Replays one order, refund or promotion.
   */
  private function one(object $entity, string $identity, string $resolution): ?Outcome {
    $mapped = NULL;
    if ($entity instanceof OrderInterface) {
      $mapped = $this->actions->order($entity, 'replay', FALSE, 'drupal-replay');
      $occurred = (int) $entity->getPlacedTime();
      if ($mapped === NULL) {
        return $this->gate->uncovered(new Context('order_acceptance', 'replay', FALSE, 'commerce_order', (string) $entity->id(), NULL, $entity->getTotalPrice()?->getCurrencyCode(), occurredAt: $occurred), 'COST_BASIS_UNKNOWN');
      }
    }
    elseif ($entity instanceof PaymentInterface) {
      // commerce_fincra maps a failed payment to "refunded": no money moved
      // back, so it is not a refund and is left out.
      $refunded = $entity->getRefundedAmount();
      if ($entity->getRemoteState() === 'failed' || $refunded === NULL || $refunded->isZero()) {
        return NULL;
      }
      $mapped = $this->actions->refund($entity, $refunded, 'replay', 'drupal-replay');
      $occurred = (int) $entity->get($this->compatibility->refundedAt())->value;
    }
    elseif ($entity instanceof PromotionInterface) {
      $mapped = $this->actions->promotion($entity, 'replay', 'drupal-replay');
      $occurred = (int) $entity->getChangedTime();
    }
    if ($mapped === NULL) {
      return NULL;
    }
    [$action, $context] = $mapped;
    $context = new Context($context->action, $context->hook, $context->checkout, $context->targetType, $context->targetId, $context->atStake, $context->currency, $context->facts, $context->source, $context->mode, $context->started, $occurred);
    return $this->gate->check(Proposal::action($action, $context, $identity, $this->gate->policy(), $resolution), $context);
  }

  /**
   * One outer scope covers all fibers; local sandbox work remains serial.
   *
   * @param list<object> $entities
   *   The order, payment or promotion entities in the current batch.
   * @param \Drupal\decionis\Gate\Authority $authority
   *   The authority used for each replayed action.
   * @param string|null $policy
   *   The pinned policy version, or NULL for the authority's current version.
   * @param string $identity
   *   The immutable connection identity bound to this replay run.
   * @param string $resolution
   *   The replay resolution identifier used to scope repeated evaluations.
   *
   * @return list<Outcome|null>
   *   One outcome per entity, or NULL where the action has no covered margin.
   */
  private function evaluate(array $entities, Authority $authority, ?string $policy, string $identity, string $resolution): array {
    if ($entities === []) {
      return [];
    }
    return $this->gate->within('shadow', $authority, 'replay', function () use ($entities, $authority, $identity, $resolution): array {
      $jobs = array_map(fn (object $entity): callable => fn (): ?Outcome => $this->one($entity, $identity, $resolution), $entities);
      $outcomes = $authority instanceof Remote ? $authority->concurrent($jobs, $this->concurrency(), $identity) : array_map(static fn (callable $job) => $job(), $jobs);
      foreach ($outcomes as $outcome) {
        if ($outcome instanceof \Throwable) {
          throw $outcome;
        }
      }
      return $outcomes;
    }, $policy, $authority instanceof Remote ? $identity : NULL);
  }

  /**
   * Counts this run's touched rows, even when an upsert kept their old ids.
   */
  private function collect(array &$result, ?Outcome $outcome): void {
    if ($outcome?->logId !== NULL) {
      $result['ids'][] = $outcome->logId;
      $result['unavailable'] += $outcome->effect === 'ungoverned' ? 1 : 0;
      $result['not_covered'] += $outcome->effect === 'not_covered' ? 1 : 0;
    }
  }

  /**
   * Two waves per operation stay inside ordinary PHP/cron request budgets.
   */
  private function concurrency(): int {
    return max(1, min(8, (int) ($this->configFactory->get('decionis_commerce.settings')->get('replay_concurrency') ?? 4)));
  }

  /**
   * Starts an entity query sorted by id, without access checks.
   */
  private function query(string $type): QueryInterface {
    $id = $this->entityTypeManager->getDefinition($type)->getKey('id');
    return $this->entityTypeManager->getStorage($type)->getQuery()->accessCheck(FALSE)->sort($id);
  }

}
