<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Sandbox;

use Drupal\Core\Entity\EntityInterface;
use Decionis\Client\Decision\Verdict;
use Drupal\decionis\Entity\Decision;
use Decionis\Client\Dossier\Verifier;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\decionis\Approval\Approvals;
use Drupal\decionis\Approval\Intents;
use Drupal\decionis\Entity\Hold;
use Drupal\decionis\Gate\Authority;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis\Gate\Outcome;
use Drupal\decionis_commerce\Approval\RefundExecutor;
use Drupal\decionis_commerce\EventSubscriber\OrderSubscriber;
use Drupal\decionis_commerce\Guard\Orders;
use Drupal\decionis_commerce\Guard\Refunds;
use Drupal\decionis_commerce\Guard\Refused;
use Drupal\decionis_commerce\Map\Actions;
use Drupal\decionis_commerce\Map\Cost;
use Drupal\decionis_commerce\Report\Limits;
use Drupal\decionis_commerce\Plugin\Validation\Constraint\Price as PriceConstraint;
use Drupal\decionis_commerce\Plugin\Validation\Constraint\Promotion as PromotionConstraint;
use Drupal\state_machine\Event\WorkflowTransitionEvent;

/**
 * Drush decionis:sandbox: the whole decision path on a synthetic store.
 *
 * Every scenario runs three ways, directly with no gate, through the gate in
 * Shadow and through the gate enforcing, using the same guards, constraints
 * and subscribers the live module uses. It all happens inside a database
 * transaction that is rolled back, so nothing persists.
 */
final class Sandbox {

  public const LABEL = 'sandbox, not production evidence';

  public function __construct(
    private readonly Gate $gate,
    private readonly Refunds $refunds,
    private readonly Orders $orders,
    private readonly Cost $cost,
    private readonly Wiring $wiring,
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Actions $actions,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly OrderSubscriber $subscriber,
    private readonly Intents $intents,
    private readonly RefundExecutor $refundExecutor,
    private readonly Isolation $isolation,
  ) {}

  /**
   * Runs every scenario and returns the report.
   *
   * @param \Drupal\decionis\Gate\Authority $authority
   *   The authority used for each governed synthetic action.
   * @param callable(string): ?\Decionis\Client\Dossier\Verification|null $verify
   *   Verifies a Dossier by id for authorities whose Dossiers live remotely.
   * @param string|null $policy
   *   The policy version to pin, or NULL for the authority's own.
   *
   * @return array
   *   The scenario results, evidence checks and overall boundary verdict.
   *
   * @phpstan-return array<string, mixed>
   */
  public function run(Authority $authority, ?callable $verify = NULL, ?string $policy = NULL): array {
    // Every key names this site and this run: the rolled-back entity ids come
    // back in later runs and in live actions.
    $scope = sprintf('drupal-sandbox:%s:%s', $this->configFactory->get('system.site')->get('uuid') ?: 'site', bin2hex(random_bytes(6)));
    return $this->actions->scoped($scope, fn (): array => $this->scenariosRun($authority, $verify, $policy));
  }

  /**
   * Runs every scenario three ways inside the rolled-back transaction.
   *
   * @return array
   *   The scenario report and evidence gathered before rollback.
   *
   * @phpstan-return array<string, mixed>
   */
  private function scenariosRun(Authority $authority, ?callable $verify, ?string $policy): array {
    $this->isolation->prepare();
    $store = new Store($this->entityTypeManager, $this->cost);
    $store->purge();
    $outer = $this->database->startTransaction('decionis_sandbox');
    Quiet::set(TRUE);
    try {
      $store->build();
      $limits = $authority instanceof Local ? (new Limits($this->configFactory))->forCurrency(Store::CURRENCY) : Limits::defaults(Store::CURRENCY);
      $setup = $this->gate->without(fn (): array => $this->setup($store, $limits));
      $rows = [];
      $dossiers = [];
      foreach ($this->scenarios($store, $setup) as $name => [$risky, $run]) {
        $row = ['scenario' => $name, 'risky' => $risky];
        foreach (['direct', 'shadow', 'enforce'] as $way) {
          $savepoint = $this->database->startTransaction('decionis_sandbox_run');
          try {
            [$executed, $text, $outcome] = $way === 'direct'
              ? $this->gate->without(fn (): array => $run(NULL))
              : $this->gate->within($way, $name === 'Order, authority unreachable' ? new Unreachable($authority->label()) : $authority, 'sandbox', fn (): array => $run($way), $policy);
          }
          finally {
            if ($this->isolation->intact()) {
              $savepoint->rollBack();
            }
            unset($savepoint);
            \Drupal::service('entity.memory_cache')->deleteAll();
          }
          if (!$this->isolation->intact()) {
            break 2;
          }
          $row[$way] = ['executed' => $executed, 'text' => $text, 'verdict' => $outcome?->verdict?->value, 'effect' => $outcome?->effect];
          if ($outcome?->dossierId !== NULL) {
            $dossiers[] = $outcome->dossierId;
          }
        }
        $rows[] = $row;
      }
      $wiring = $this->wiring->check();
    }
    finally {
      $intact = $this->isolation->intact();
      if ($intact) {
        $outer->rollBack();
      }
      unset($outer);
      Quiet::set(FALSE);
      $this->cost->clear();
      \Drupal::service('entity.memory_cache')->deleteAll();
      // The synthetic gateway and promotions were config, cached as they
      // were written. A cache outside the database (Redis, Memcache) is not
      // rolled back, so it is emptied, and so is the factory's copy.
      $this->configFactory->reset();
      \Drupal::cache('config')->deleteAll();
      if (!$intact) {
        // The database committed what was written so far, so the synthetic
        // records are removed one by one.
        $this->gate->without(static fn () => $store->purge());
        throw new Interrupted('Another module changed the database schema during the sandbox run, which ended its transaction. The synthetic records were removed. Nothing was reported; run it again.');
      }
    }
    return $this->report($authority, $rows, $wiring, $this->evidence($authority, array_values(array_unique($dossiers)), $verify)) + ['policy' => $policy, 'coverage_notes' => $setup['coverage_notes']];
  }

  /**
   * The store state every scenario starts from.
   *
   * @return array
   *   The synthetic entities and values shared by the scenario closures.
   *
   * @phpstan-return array<string, mixed>
   */
  private function setup(Store $store, array $limits): array {
    // Keep valid, bounded synthetic amounts even if an administrator chooses
    // a ceiling beyond this fixture's range. Such a row tests ALLOW, and does
    // not claim that the approval boundary was exercised.
    $limit = (float) $limits['refund_limit'];
    $within = max(0.01, min(40.0, $limit));
    $above = max(0.01, min(1000000.0, $limit * 2.4));
    $replay = max(0.01, min(500000.0, $limit * 1.2));
    $refundOrder = $store->order('SKU-1', max(15, (int) ceil(max($above, 2 * $replay) / 100)));
    $payment = $store->payment($refundOrder);
    $fraction = max(0.0, min(0.8, ((float) $limits['stacking_ceiling'] + 15) / 100 - 0.2));
    $store->promotion('Sandbox percentage off', $fraction)->save();
    $combined = round(($fraction + 0.2) * 100, 6);
    $margin = max(-100.0, (float) $limits['margin_floor'] - 4.0);
    $this->cost->override($store->variations['SKU-3'], 50 * (1 - $margin / 100));
    $notes = [];
    if ($above <= $limit || $replay <= $limit) {
      $notes[] = 'The refund ceiling is beyond one or more bounded synthetic amounts; those rows test permitted refunds, not approval or replay refusal.';
    }
    if ($combined <= (float) $limits['stacking_ceiling']) {
      $notes[] = 'The stacking ceiling permits the valid combined discount in this fixture; that row tests ALLOW.';
    }
    if ($margin >= (float) $limits['margin_floor']) {
      $notes[] = 'The margin floor is below the bounded synthetic cost range; that order tests ALLOW.';
    }
    return ['payment' => (int) $payment->id(), 'within' => $within, 'above' => $above, 'replay' => $replay, 'limit' => $limit, 'combined' => $combined, 'margin' => $margin, 'limits' => $limits, 'coverage_notes' => $notes];
  }

  /**
   * Scenario name => [risky, run]. A run returns [executed, text, outcome].
   *
   * @param \Drupal\decionis_commerce\Sandbox\Store $store
   *   The synthetic store used to construct orders and promotions.
   * @param array<string, mixed> $setup
   *   The initial synthetic entities and values returned by setup().
   *
   * @return array
   *   Scenario names mapped to a risk flag and a closure for each gate mode.
   *
   * @phpstan-return array<string, array{bool, callable(?string): array{bool, string, ?Outcome}}>
   */
  private function scenarios(Store $store, array $setup): array {
    $refund = fn (string $amount): callable => $this->refundRun($setup, $amount);
    $price = function (?string $way) use ($store): array {
      $variation = $this->load('commerce_product_variation', (int) $store->variations['SKU-2']->id());
      assert($variation instanceof ProductVariationInterface);
      $this->cost->override($variation, 30.0);
      $variation->setPrice(new Price('25', Store::CURRENCY));
      $violations = $way === NULL ? [] : self::ours($variation->validate());
      $outcome = $this->lastOutcome();
      if ($violations !== []) {
        return [FALSE, 'BLOCK: price not saved', $outcome];
      }
      $variation->save();
      return [TRUE, self::ran('saved', $way, $way === NULL ? NULL : $outcome), $way === NULL ? NULL : $outcome];
    };
    $coupon = function (?string $way) use ($store): array {
      $promotion = $store->promotion('Sandbox 20% coupon', 0.20, TRUE);
      $violations = $way === NULL ? [] : self::ours($promotion->validate());
      $outcome = $way === NULL ? NULL : $this->lastOutcome();
      if ($violations !== []) {
        return [FALSE, 'BLOCK: coupon refused', $outcome];
      }
      $promotion->save();
      return [TRUE, self::ran('applied', $way, $outcome), $outcome];
    };
    $order = fn (string $sku, bool $checkout): callable => $this->orderRun($store, $sku, $checkout);
    $withinRisk = $setup['within'] > $setup['limit'];
    $aboveRisk = $setup['above'] > $setup['limit'];
    $replayRisk = $setup['replay'] > $setup['limit'];
    $number = static fn (float $value): string => rtrim(rtrim(number_format($value, 2), '0'), '.');
    return [
      sprintf('Refund %s limit (EUR %s)', $withinRisk ? 'above' : 'within', $number($setup['within'])) => [$withinRisk, $refund((string) $setup['within'])],
      sprintf('Refund %s ceiling (EUR %s)', $aboveRisk ? 'above' : 'within', $number($setup['above'])) => [$aboveRisk, $refund((string) $setup['above'])],
      'Price below cost (SKU-2)' => [TRUE, $price],
      sprintf('Two coupons, %s%% combined', $number($setup['combined'])) => [$setup['combined'] > $setup['limits']['stacking_ceiling'], $coupon],
      sprintf('Order %s %s%% margin floor', $setup['margin'] < $setup['limits']['margin_floor'] ? 'under' : 'within', $number((float) $setup['limits']['margin_floor'])) => [$setup['margin'] < $setup['limits']['margin_floor'], $order('SKU-3', FALSE)],
      ($replayRisk ? 'Approved refund, replayed' : 'Permitted refund, repeated') => [$replayRisk, $this->replayRun($setup, (string) $setup['replay'])],
      'Order, authority unreachable' => [FALSE, $order('SKU-1', TRUE)],
    ];
  }

  /**
   * Replays a refund above the limit after approving its first attempt.
   *
   * Without a gate both go through; enforcing, the hold runs once on its
   * single-use grant and the replay is refused. "Executed" is whether the
   * replay went through.
   *
   * @param array<string, mixed> $setup
   *   The scenario setup.
   * @param string $amount
   *   The refund amount in the synthetic store's currency.
   *
   * @return callable(?string): array{bool, string, ?Outcome}
   *   The scenario run.
   */
  private function replayRun(array $setup, string $amount): callable {
    return function (?string $way) use ($setup, $amount): array {
      $payment = $this->load('commerce_payment', $setup['payment']);
      assert($payment instanceof PaymentInterface);
      $price = new Price($amount, Store::CURRENCY);
      $outcome = $way === NULL ? NULL : $this->refunds->check($payment, $price, 'refund_form');
      if ($outcome === NULL || $outcome->proceeds()) {
        $gateway = $payment->getPaymentGateway()?->getPlugin();
        if (!$gateway instanceof SupportsRefundsInterface) {
          throw new \LogicException('DECIONIS_SANDBOX_GATEWAY_CANNOT_REFUND');
        }
        $gateway->refundPayment($payment, $price);
        $again = $this->load('commerce_payment', $setup['payment']);
        assert($again instanceof PaymentInterface);
        $gateway->refundPayment($again, $price);
        return [TRUE, 'refunded twice', $outcome];
      }
      $hold = $outcome->holdId === NULL ? NULL : $this->load('decionis_hold', $outcome->holdId);
      if (!$hold instanceof Hold) {
        return [FALSE, 'BLOCK: not refunded', $outcome];
      }
      // The demo approver approves at once; the grant is single-use.
      $approvals = new Approvals($this->entityTypeManager, $this->database, $this->intents, new Approver(), \Drupal::logger('decionis'));
      $approvals->addExecutor($this->refundExecutor);
      $hold->set('escalation_id', 'sandbox-' . $hold->id())->save();
      $approvals->refresh($hold);
      $first = $approvals->execute($hold, $this->gate);
      $replay = $approvals->execute($hold, $this->gate);
      return $first === 'executed' && $replay !== 'executed'
        // Approved, the grant says ALLOW: it runs once.
        ? [FALSE, 'ALLOW once, replay REFUSED', $outcome]
        : [$replay === 'executed', 'not run once: ' . $first . ', replay ' . $replay, $outcome];
    };
  }

  /**
   * The refund scenario: refund $amount on the setup payment.
   *
   * @param array<string, mixed> $setup
   *   The scenario setup.
   * @param string $amount
   *   The refund amount in the synthetic store's currency.
   *
   * @return callable(?string): array{bool, string, ?Outcome}
   *   The scenario run.
   */
  private function refundRun(array $setup, string $amount): callable {
    return function (?string $way) use ($setup, $amount): array {
      $payment = $this->load('commerce_payment', $setup['payment']);
      assert($payment instanceof PaymentInterface);
      $price = new Price($amount, Store::CURRENCY);
      $outcome = $way === NULL ? NULL : $this->refunds->check($payment, $price, 'refund_form');
      if ($outcome !== NULL && !$outcome->proceeds()) {
        return [FALSE, $outcome->effect === 'held' ? 'ESCALATE: held for approval' : 'BLOCK: not refunded', $outcome];
      }
      $gateway = $payment->getPaymentGateway()?->getPlugin();
      if (!$gateway instanceof SupportsRefundsInterface) {
        throw new \LogicException('DECIONIS_SANDBOX_GATEWAY_CANNOT_REFUND');
      }
      $gateway->refundPayment($payment, $price);
      return [TRUE, self::ran('refunded', $way, $outcome), $outcome];
    };
  }

  /**
   * The order scenario: place one unit of $sku, via checkout or not.
   *
   * @return callable(?string): array{bool, string, ?Outcome}
   *   The scenario run.
   */
  private function orderRun(Store $store, string $sku, bool $checkout): callable {
    return function (?string $way) use ($store, $sku, $checkout): array {
      $order = $store->order($sku, 1);
      $outcome = NULL;
      if ($checkout && $way !== NULL) {
        $outcome = $this->orders->check($order, 'checkout_review', TRUE);
        if (!$outcome?->proceeds()) {
          return [FALSE, 'BLOCK: order not placed', $outcome];
        }
      }
      try {
        // The live subscriber, called as the place transition calls it. The
        // transition itself would also run every other module's listeners
        // (receipts, stock, analytics), and some act outside the database
        // this run rolls back.
        if ($way !== NULL) {
          $this->subscriber->onPlace(self::placing($order));
        }
      }
      catch (\Throwable $error) {
        if (!self::refused($error)) {
          throw $error;
        }
        return [FALSE, 'BLOCK: order not placed', $this->lastOutcome()];
      }
      $outcome ??= $way === NULL ? NULL : $this->lastOutcome();
      $marked = is_array($order->getData(Orders::DATA)) && ($order->getData(Orders::DATA)['governed'] ?? TRUE) === FALSE;
      $text = self::ran('placed', $way, $outcome);
      if ($way === 'enforce' && $marked) {
        $text = 'placed (fail-open), marked ungoverned';
      }
      elseif ($way === 'shadow' && $marked) {
        $text = 'placed, ungoverned';
      }
      return [TRUE, $text, $outcome];
    };
  }

  /**
   * The event the place transition would dispatch for $order.
   */
  private static function placing(OrderInterface $order): WorkflowTransitionEvent {
    $workflow = $order->getState()->getWorkflow();
    $transition = $workflow->getTransition('place');
    if ($transition === NULL) {
      throw new \LogicException('DECIONIS_SANDBOX_NO_PLACE_TRANSITION');
    }
    return new WorkflowTransitionEvent($transition, $workflow, $order, 'state');
  }

  /**
   * The outcome of the last decision the gate recorded in this run.
   */
  private function lastOutcome(): ?Outcome {
    $storage = $this->entityTypeManager->getStorage('decionis_decision');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('id', 'DESC')->range(0, 1)->execute();
    $entry = $ids === [] ? NULL : $storage->load(reset($ids));
    if (!$entry instanceof Decision) {
      return NULL;
    }
    $verdict = Verdict::tryFrom($entry->value('verdict'));
    return new Outcome($entry->value('mode'), $entry->value('effect'), $verdict, $entry->reasons(), $entry->value('dossier_id') ?: NULL, (int) $entry->id());
  }

  /**
   * The violations the Decionis constraints raised, ignoring Commerce's own.
   *
   * @return list<\Symfony\Component\Validator\ConstraintViolationInterface>
   *   The violations originating from Decionis price or promotion constraints.
   */
  private static function ours(iterable $violations): array {
    $ours = [];
    foreach ($violations as $violation) {
      $constraint = $violation->getConstraint();
      if ($constraint instanceof PriceConstraint || $constraint instanceof PromotionConstraint) {
        $ours[] = $violation;
      }
    }
    return $ours;
  }

  /**
   * Checks whether a gate refusal caused a write to fail.
   *
   * Entity storage may wrap the refusal in its own exception.
   */
  public static function refused(\Throwable $error): bool {
    for ($current = $error; $current !== NULL; $current = $current->getPrevious()) {
      if ($current instanceof Refused) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Loads an entity fresh from storage.
   */
  private function load(string $type, int|string $id): ?EntityInterface {
    return $this->entityTypeManager->getStorage($type)->loadUnchanged($id);
  }

  /**
   * Describes an action that ran, as the report prints it.
   */
  private static function ran(string $done, ?string $way, ?Outcome $outcome): string {
    if ($way === NULL) {
      return $done;
    }
    $verdict = $outcome?->verdict?->value;
    if ($outcome === NULL || $verdict === NULL) {
      return $done . ', ungoverned';
    }
    if ($way === 'shadow') {
      return $done . ', would ' . $verdict;
    }
    return $verdict . ': ' . $done . ($outcome->dossierId !== NULL ? ', dossier' : '');
  }

  /**
   * Verifies every Dossier the run produced.
   *
   * @param \Drupal\decionis\Gate\Authority $authority
   *   The authority that produced the Dossiers.
   * @param list<string> $dossiers
   *   The Dossier identifiers referenced by the scenario decisions.
   * @param callable(string): ?\Decionis\Client\Dossier\Verification|null $verify
   *   The remote verifier, or NULL when verification is handled locally.
   *
   * @return array
   *   The number of referenced Dossiers and the number successfully verified.
   *
   * @phpstan-return array{count: int, verified: int}
   */
  private function evidence(Authority $authority, array $dossiers, ?callable $verify): array {
    $verified = 0;
    if ($authority instanceof Local) {
      $verifier = new Verifier($authority->jwks());
      foreach ($authority->dossiers() as $id => $dossier) {
        $verified += in_array($id, $dossiers, TRUE) && $verifier->verify($dossier)->verified ? 1 : 0;
      }
    }
    elseif ($verify !== NULL) {
      foreach ($dossiers as $id) {
        try {
          $verified += $verify($id)?->verified ? 1 : 0;
        }
        catch (\Throwable) {
        }
      }
    }
    return ['count' => count($dossiers), 'verified' => $verified];
  }

  /**
   * Combines scenario results, hook wiring and evidence into the final report.
   *
   * @param \Drupal\decionis\Gate\Authority $authority
   *   The authority whose label identifies the report's decision source.
   * @param list<array<string, mixed>> $rows
   *   The results of each scenario in direct, Shadow and Enforce modes.
   * @param array{points: array<string, bool>, overrides: list<string>} $wiring
   *   The gate hook status map and detected custom-code overrides.
   * @param array{count: int, verified: int} $evidence
   *   The referenced and successfully verified Dossier counts.
   *
   * @return array
   *   The complete report, including its boundary verdict and process status.
   *
   * @phpstan-return array<string, mixed>
   */
  private function report(Authority $authority, array $rows, array $wiring, array $evidence): array {
    $risky = array_values(array_filter($rows, static fn (array $row): bool => $row['risky']));
    $exposure = [];
    foreach (['direct', 'shadow', 'enforce'] as $way) {
      $exposure[$way] = count(array_filter($risky, static fn (array $row): bool => $row[$way]['executed']));
    }
    $active = count(array_filter($wiring['points']));
    $holds = $active === count($wiring['points'])
      && $exposure['enforce'] === 0
      && $evidence['verified'] === $evidence['count']
      && $evidence['count'] > 0;
    return [
      'schema' => 'decionis.drupal.sandbox/1',
      'label' => self::LABEL,
      'authority' => $authority->label(),
      'scenarios' => $rows,
      'wiring' => $wiring + ['active' => $active, 'total' => count($wiring['points'])],
      'exposure' => $exposure + ['risky' => count($risky)],
      'evidence' => $evidence,
      'verdict' => $holds ? 'BOUNDARY HOLDS' : 'EXPOSURE',
      'exit_code' => $holds ? 0 : 1,
    ];
  }

}
