<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Unit;

use Decionis\Client\Decision\Action;
use Drupal\decionis\Gate\Context;
use Drupal\decionis_commerce\Replay\Proposal;
use Drupal\Tests\UnitTestCase;

/**
 * Replay request identity follows the policy and complete historical inputs.
 *
 * @group decionis
 */
final class ProposalTest extends UnitTestCase {

  /**
   * Changing actor does not change a replay; changing its policy or facts does.
   */
  public function testKeysBindPolicyConnectionAndInputsInsteadOfCurrentUser(): void {
    $actor = ['type' => 'HUMAN', 'id' => 'synthetic-admin'];
    $action = Action::order($actor, 'drupal', 'old-action-key', '42', 100, 0, 80, 'USD');
    $context = new Context('order_acceptance', 'replay', targetType: 'commerce_order', targetId: '42', facts: ['orders_last_hour' => 2]);
    $first = Proposal::action($action, $context, 'connection-a', 'policy-1', 'run-a');
    $cron = Action::order(['type' => 'WORKFLOW', 'id' => 'synthetic-cron'], 'drupal', 'different-key', '42', 100, 0, 80, 'USD');
    $this->assertSame($first->toArray(), Proposal::action($cron, $context, 'connection-a', 'policy-1', 'run-b')->toArray());
    $this->assertNotSame($first->idempotencyKey, Proposal::action($action, $context, 'connection-a', 'policy-2', 'run-a')->idempotencyKey);
    $this->assertNotSame($first->idempotencyKey, Proposal::action($action, $context, 'connection-b', 'policy-1', 'run-a')->idempotencyKey);
    $cost = Action::order($actor, 'drupal', 'old-action-key', '42', 100, 0, 95, 'USD');
    $this->assertNotSame($first->idempotencyKey, Proposal::action($cost, $context, 'connection-a', 'policy-1', 'run-a')->idempotencyKey);
    $facts = new Context('order_acceptance', 'replay', targetType: 'commerce_order', targetId: '42', facts: ['orders_last_hour' => 3]);
    $this->assertNotSame($first->idempotencyKey, Proposal::action($action, $facts, 'connection-a', 'policy-1', 'run-a')->idempotencyKey);
    $this->assertNotSame(Proposal::action($action, $context, 'connection-a', NULL, 'run-a')->idempotencyKey, Proposal::action($action, $context, 'connection-a', NULL, 'run-b')->idempotencyKey, 'a new unpinned run resolves the current policy instead of hitting an old resolution cache entry');
  }

  /**
   * A historical refund cannot be compared to today's post-refund balance.
   */
  public function testPastRefundOmitsUnknownPriorBalance(): void {
    $action = Action::refund(['type' => 'WORKFLOW', 'id' => 'synthetic'], 'drupal', 'old-key', '42', 400, 'USD', NULL, 100);
    $context = new Context('refund_request', 'replay', targetType: 'commerce_payment', targetId: '17');
    $replay = Proposal::action($action, $context, 'connection-a', 'policy-1', 'run-a');
    $this->assertArrayNotHasKey('remaining_refundable', $replay->payload);
    $this->assertArrayNotHasKey('refund_exceeds_refundable', $replay->signals());
    $this->assertSame(400.0, $replay->amount);
  }

}
