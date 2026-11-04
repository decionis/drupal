<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Kernel;

use Drupal\decionis\Gate\Outcome;
use Decionis\Client\Decision\Action;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis\Gate\Context;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis\Gate\Authority;
use Drupal\decionis\Report\Summary;
use Decionis\Client\Decision\Request;
use Decionis\Client\Decision\Decision as Answer;
use Decionis\Client\Error\TransportException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\decionis\Support\Scripted;

/**
 * Checks modes, verdict handling, and per-action failure policy.
 *
 * Shadow is the default, Enforce handles verdicts, and checkout is never held
 * for a person.
 *
 * @group decionis
 */
final class GateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'decionis'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('decionis_decision');
    $this->installEntitySchema('decionis_hold');
    $this->installConfig(['decionis']);
  }

  /**
   * Checks a refund through the gate in a forced mode.
   */
  private function gate(Scripted $authority, string $mode): Outcome {
    $gate = $this->container->get(Gate::class);
    $refund = Action::refund(['type' => 'HUMAN', 'id' => 'drupal:user:x'], 'drupal', 'drupal:refund:1:0:10', '1', 10.0, 'EUR');
    return $gate->within($mode, $authority, 'live', fn () => $gate->check($refund, new Context('refund_request', 'refund_form', FALSE, 'commerce_payment', '1', 10.0, 'EUR')));
  }

  /**
   * Checks a checkout order through the gate in a forced mode.
   */
  private function checkout(Scripted $authority, string $mode): Outcome {
    $gate = $this->container->get(Gate::class);
    $order = Action::order(['type' => 'WORKFLOW', 'id' => 'drupal-checkout'], 'drupal', 'drupal:order:1:checkout_review:80', '1', 100.0, 20.0, 75.0, 'EUR');
    return $gate->within($mode, $authority, 'live', fn () => $gate->check($order, new Context('order_acceptance', 'checkout_review', TRUE, 'commerce_order', '1', 80.0, 'EUR')));
  }

  /**
   * Tests that fresh install is shadow everywhere.
   */
  public function testFreshInstallIsShadowEverywhere(): void {
    $settings = $this->container->get(Settings::class);
    foreach (['refund_request', 'price_change', 'promotion_change', 'order_acceptance', 'inventory_mutation', 'agent_tool'] as $action) {
      $this->assertSame('shadow', $settings->mode($action));
    }
  }

  /**
   * Tests that enforce needs the servers entitlement.
   */
  public function testEnforceNeedsTheServersEntitlement(): void {
    $this->config('decionis.settings')->set('mode', 'enforce')->save();
    $settings = $this->container->get(Settings::class);
    $this->assertSame('enforce', $settings->chosen('refund_request'));
    $this->assertSame('shadow', $settings->mode('refund_request'), 'no entitlement from the server: Shadow');
    $this->container->get('state')->set(Settings::ENTITLEMENT, ['enforcement' => TRUE, 'state' => 'trial']);
    $this->assertSame('enforce', $settings->mode('refund_request'));
    $this->config('decionis.settings')->set('actions.refund_request', 'shadow')->save();
    $this->assertSame('shadow', $settings->mode('refund_request'), 'a per-action Shadow wins over store-wide Enforce');
  }

  /**
   * Tests that shadow logs and runs.
   */
  public function testShadowLogsAndRuns(): void {
    $outcome = $this->gate(new Scripted('REJECT'), 'shadow');
    $this->assertTrue($outcome->proceeds());
    $this->assertSame('BLOCK', $outcome->verdict?->value);
    $this->assertNotNull($outcome->logId);
  }

  /**
   * Tests that enforce acts on the verdict.
   */
  public function testEnforceActsOnTheVerdict(): void {
    $this->assertTrue($this->gate(new Scripted('APPROVE'), 'enforce')->proceeds());
    $this->assertSame('blocked', $this->gate(new Scripted('REJECT'), 'enforce')->effect);
    // With no executor to run it once approved, a hold would never resolve:
    // the action is blocked instead, and says why.
    $held = $this->gate(new Scripted('REVIEW'), 'enforce');
    $this->assertSame('blocked', $held->effect);
    $this->assertContains('APPROVAL_NOT_AVAILABLE', $held->reasons);
    $this->assertSame('blocked', $this->gate(new Scripted('ESCALATE'), 'enforce')->effect);
  }

  /**
   * Tests that no decision follows the per action failure policy.
   */
  public function testNoDecisionFollowsThePerActionFailurePolicy(): void {
    $this->assertSame('failed_closed', $this->gate(new Scripted(NULL), 'enforce')->effect, 'refunds fail closed by default');
    $this->config('decionis.settings')->set('failure.refund_request', 'open')->save();
    $this->assertSame('ungoverned', $this->gate(new Scripted(NULL), 'enforce')->effect);
    $this->assertSame('ungoverned', $this->gate(new Scripted(NULL), 'shadow')->effect, 'Shadow never fails closed');
  }

  /**
   * Tests that checkout is never held and fails open.
   */
  public function testCheckoutIsNeverHeldAndFailsOpen(): void {
    $this->config('decionis.settings')->set('failure.order_acceptance', 'closed')->save();
    $this->assertSame('ungoverned', $this->checkout(new Scripted(NULL), 'enforce')->effect, 'checkout fails open whatever the setting says');
    $this->assertSame('proceeded', $this->checkout(new Scripted('REVIEW'), 'enforce')->effect, 'a shopper is never held for a person');
    $this->assertSame('blocked', $this->checkout(new Scripted('REJECT'), 'enforce')->effect, 'checkout blocks only on an explicit BLOCK');
  }

  /**
   * An escalation during checkout is reviewed but is not a stopped action.
   */
  public function testCheckoutEscalationDoesNotInflateWouldStopValue(): void {
    $outcome = $this->checkout(new Scripted('REVIEW'), 'shadow');
    $this->assertSame('ESCALATE', $outcome->verdict?->value);
    $summary = $this->container->get(Summary::class)->totals(0);
    $this->assertSame(1, $summary['reviewed']);
    $this->assertSame(80.0, $summary['by_action']['order_acceptance']['EUR']['covered']);
    $this->assertSame(0.0, $summary['by_action']['order_acceptance']['EUR']['held']);
    $this->assertSame('1', Decision::load($outcome->logId)->value('checkout'));
  }

  /**
   * Tests that not connected follows the failure policy and is logged.
   */
  public function testNotConnectedFollowsTheFailurePolicyAndIsLogged(): void {
    $authority = new Scripted('REJECT', FALSE);
    $outcome = $this->gate($authority, 'enforce');
    $this->assertSame('failed_closed', $outcome->effect, 'an enforcing site that lost its key does not wave refunds through');
    $this->assertSame(['NOT_CONNECTED'], $outcome->reasons);
    $this->assertNotNull($outcome->logId);
    $this->assertSame('ungoverned', $this->gate($authority, 'shadow')->effect);
    $this->assertSame(0, $authority->calls);
  }

  /**
   * Tests that the log holds no customer data.
   */
  public function testTheLogHoldsNoCustomerData(): void {
    $this->gate(new Scripted('REJECT'), 'shadow');
    $entries = $this->container->get('entity_type.manager')->getStorage('decionis_decision')->loadMultiple();
    $entry = reset($entries);
    $this->assertInstanceOf(Decision::class, $entry);
    $this->assertSame('drupal:refund:1:0:10', $entry->get('idempotency_key')->value);
    $this->assertSame('18962a2d-6842-4f6a-9ff4-901c50acaed8', $entry->get('dossier_id')->value);
    $this->assertSame('["test_reason"]', $entry->get('reasons')->value);
  }

  /**
   * Old replay answers and failures never create a row in a new workspace.
   */
  public function testReplayReconnectDiscardsOldResponseAndFailure(): void {
    $action = Action::refund(['type' => 'WORKFLOW', 'id' => 'synthetic-replay'], 'drupal', 'synthetic-reconnect', '1', 12.0, 'EUR');
    $context = new Context('refund_request', 'replay', FALSE, 'commerce_payment', '1', 12.0, 'EUR');
    $gate = $this->container->get(Gate::class);
    foreach ([FALSE, TRUE] as $fails) {
      $this->config('decionis.settings')->set('org_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1')->set('api_key', 'synthetic-reference')->save();
      $identity = $this->container->get(Settings::class)->connectionIdentity();
      $change = function (): void {
        $this->config('decionis.settings')->set('org_id', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2')->save();
      };
      $authority = new class($change, $fails) implements Authority {

        public function __construct(private readonly \Closure $change, private readonly bool $fails) {}

        /**
         * {@inheritdoc}
         */
        public function label(): string {
          return 'synthetic-reconnect';
        }

        /**
         * {@inheritdoc}
         */
        public function available(): bool {
          return TRUE;
        }

        /**
         * {@inheritdoc}
         */
        public function evaluate(Request $request, bool $checkout, ?int $budgetMs = NULL): Answer {
          ($this->change)();
          if ($this->fails) {
            throw new TransportException('UPSTREAM_UNREACHABLE');
          }
          return (new Scripted('REJECT'))->evaluate($request, $checkout, $budgetMs);
        }

      };
      $outcome = $gate->within('shadow', $authority, 'replay', fn () => $gate->check($action, $context), NULL, $identity);
      $this->assertNull($outcome->logId);
      $this->assertSame(['REPLAY_CONNECTION_CHANGED'], $outcome->reasons);
      $this->assertSame([], Decision::loadMultiple());
    }
  }

}
