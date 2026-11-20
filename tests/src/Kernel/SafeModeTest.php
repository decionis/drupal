<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Kernel;

use Decionis\Client\Decision\Action;
use Drupal\decionis\Connect\Connector;
use Drupal\decionis\Connect\SiteKey;
use Drupal\decionis\Controller\ChallengeController;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis\Gate\Context;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis\Gate\Ledger;
use Drupal\decionis\Gate\Outcome;
use Drupal\decionis\Gate\SafeMode;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis\Hook\CoreHooks;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\decionis\Support\ConnectApi;
use Drupal\Tests\decionis\Support\Scripted;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Checks that the module reads and honors Decionis Commerce safe mode.
 *
 * Halt refuses live actions without a verdict; Shadow never blocks, while money out
 * still fails closed without a decision. Sandbox and replay are unaffected.
 *
 * @group decionis
 */
#[RunTestsInSeparateProcesses]
final class SafeModeTest extends KernelTestBase {

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
   * A synthetic safe-mode rule as the status route states it.
   *
   * @return array<string, string|null>
   *   The rule in its wire shape.
   */
  private static function rule(string $posture): array {
    return ['posture' => $posture, 'scope' => 'org', 'key' => NULL, 'reason' => 'Synthetic runaway agent', 'set_at' => '2026-11-15T08:00:00.000Z', 'event_id' => 'synthetic-event-1'];
  }

  /**
   * Connects the site through configuration and sets safe mode for it.
   */
  private function connected(?string $posture, bool $enforce = FALSE): SafeMode {
    $this->config('decionis.settings')->set('org_id', ConnectApi::ORG)->set('api_key', 'synthetic-reference')->save();
    if ($enforce) {
      $this->config('decionis.settings')->set('mode', 'enforce')->save();
      $this->container->get('state')->set(Settings::ENTITLEMENT, ['enforcement' => TRUE, 'state' => 'paid']);
    }
    $safeMode = $this->container->get(SafeMode::class);
    $this->assertTrue($safeMode->adopt($posture === NULL ? NULL : self::rule($posture), (string) $this->container->get(Settings::class)->connectionIdentity()));
    return $safeMode;
  }

  /**
   * A gate that asks the given authority, outside any sandbox or replay scope.
   */
  private function gate(Scripted $authority): Gate {
    return new Gate($authority, $this->container->get(Settings::class), $this->container->get(Ledger::class), NULL, NULL, $this->container->get(SafeMode::class));
  }

  /**
   * Checks a live refund.
   */
  private function refund(Gate $gate): Outcome {
    $refund = Action::refund(['type' => 'HUMAN', 'id' => 'drupal:user:x'], 'drupal', 'drupal:refund:1:0:10', '1', 10.0, 'EUR');
    return $gate->check($refund, new Context('refund_request', 'refund_form', FALSE, 'commerce_payment', '1', 10.0, 'EUR'));
  }

  /**
   * Checks a live checkout order.
   */
  private function checkout(Gate $gate): Outcome {
    $order = Action::order(['type' => 'WORKFLOW', 'id' => 'drupal-checkout'], 'drupal', 'drupal:order:1:checkout_review:80', '1', 100.0, 20.0, 75.0, 'EUR');
    return $gate->check($order, new Context('order_acceptance', 'checkout_review', TRUE, 'commerce_order', '1', 80.0, 'EUR'));
  }

  /**
   * The stored posture belongs to the connection that read it.
   */
  public function testPostureIsBoundToTheConnection(): void {
    $safeMode = $this->connected('halt');
    $this->assertSame('halt', $safeMode->posture());
    $this->assertFalse($safeMode->due());
    $this->assertStringContainsString('Safe mode: Halt.', $safeMode->describe());
    $this->assertStringContainsString('Synthetic runaway agent', $safeMode->describe());
    $this->config('decionis.settings')->set('org_id', 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbb2')->save();
    $this->assertNull($safeMode->posture(), 'another workspace does not inherit the halt');
    $this->assertFalse($safeMode->adopt(self::rule('shadow'), 'synthetic-old-connection'), 'an answer for an old connection is not adopted');
  }

  /**
   * A malformed answer keeps the halt; an answer without safe mode clears it.
   */
  public function testMalformedKeepsTheHaltAndNoneClearsIt(): void {
    $safeMode = $this->connected('halt');
    $identity = (string) $this->container->get(Settings::class)->connectionIdentity();
    $this->assertFalse($safeMode->adopt(['posture' => 'pause'] + self::rule('halt'), $identity));
    $this->assertFalse($safeMode->adopt('halt', $identity));
    $this->assertSame('halt', $safeMode->posture());
    $this->assertTrue($safeMode->adopt(NULL, $identity));
    $this->assertNull($safeMode->posture());
    $this->assertStringStartsWith('Safe mode: off.', $safeMode->describe());
  }

  /**
   * A halt refuses refunds and checkout without asking the authority.
   */
  public function testHaltRefusesRefundsAndCheckoutUnevaluated(): void {
    $this->connected('halt');
    $authority = new Scripted('APPROVE');
    $gate = $this->gate($authority);
    $refund = $this->refund($gate);
    $this->assertSame('blocked', $refund->effect, 'a refund is refused in Shadow too');
    $this->assertSame([SafeMode::HALT_REASON], $refund->reasons);
    $this->assertSame('Decionis Commerce safe mode is halting this action; nothing was run.', $refund->message(FALSE));
    $this->assertSame('blocked', Decision::load($refund->logId)->value('effect'));
    $checkout = $this->checkout($gate);
    $this->assertFalse($checkout->proceeds());
    $this->assertSame('Checkout is temporarily paused by the store. Please try again later.', $checkout->message(TRUE));
    $uncovered = $gate->uncovered(new Context('order_acceptance', 'checkout_review', TRUE, 'commerce_order', '2', NULL, 'EUR'), 'COST_BASIS_UNKNOWN');
    $this->assertSame('blocked', $uncovered->effect, 'an order without cost facts is refused too');
    $this->assertSame(0, $authority->calls);

    // The sandbox and replay run in their own scope, untouched by the halt.
    $scoped = $gate->within('enforce', $authority, 'sandbox', fn (): Outcome => $this->refund($gate));
    $this->assertTrue($scoped->proceeds());
    $this->assertSame(1, $authority->calls);
  }

  /**
   * Shadow lets an enforced BLOCK through and says why.
   */
  public function testShadowNeverBlocks(): void {
    $this->connected('shadow', TRUE);
    $this->assertSame('enforce', $this->container->get(Settings::class)->mode('refund_request'));
    $gate = $this->gate(new Scripted('REJECT'));
    foreach ([$this->refund($gate), $this->checkout($gate)] as $outcome) {
      $this->assertSame('proceeded', $outcome->effect);
      $this->assertSame('shadow', $outcome->mode);
      $this->assertSame('BLOCK', $outcome->verdict?->value);
      $this->assertContains(SafeMode::SHADOW_REASON, $outcome->reasons);
    }
  }

  /**
   * In Shadow, money out with no decision still fails closed (D2).
   */
  public function testShadowMoneyOutStillFailsClosed(): void {
    $this->connected('shadow', TRUE);
    $unreachable = $this->refund($this->gate(new Scripted(NULL)));
    $this->assertSame('failed_closed', $unreachable->effect);
    $this->assertContains(SafeMode::SHADOW_REASON, $unreachable->reasons);
    $this->assertSame('failed_closed', $this->refund($this->gate(new Scripted('APPROVE', FALSE)))->effect);
    $this->assertSame('ungoverned', $this->checkout($this->gate(new Scripted(NULL)))->effect, 'checkout still fails open');
  }

  /**
   * Cron reads safe mode from the status route at most once per TTL.
   */
  public function testCronReadsSafeModeWhenDue(): void {
    $api = new ConnectApi(ChallengeController::create($this->container));
    $api->reportsSafeMode = TRUE;
    $api->safeMode = self::rule('halt');
    $connector = new Connector($this->container->get(Settings::class), $this->container->get(Credentials::class), $this->container->get(SiteKey::class), $this->container->get('state'), $this->container->get('config.factory'), $this->container->get('module_handler'), $api);
    $this->container->set(Connector::class, $connector);
    $connector->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $hooks = $this->container->get(CoreHooks::class);
    $safeMode = $this->container->get(SafeMode::class);
    $statuses = fn (): int => count(array_filter($api->calls, static fn (array $call): bool => $call[0] === '/v1/drupal/status'));

    $hooks->cron();
    $this->assertSame(1, $statuses(), 'the first cron run reads the status');
    $this->assertSame('halt', $safeMode->posture());
    $hooks->cron();
    $this->assertSame(1, $statuses(), 'read at most once per TTL');

    $state = $this->container->get('state');
    $entry = $state->get(SafeMode::STATE);
    $entry['fetched_at'] = time() - SafeMode::TTL - 1;
    $state->set(SafeMode::STATE, $entry);
    $state->set('decionis.safe_mode_checked', time() - SafeMode::TTL - 1);
    $api->safeMode = NULL;
    $hooks->cron();
    $this->assertSame(2, $statuses());
    $this->assertNull($safeMode->posture(), 'the owner cleared it');

    $connector->disconnect();
    $this->assertNull($state->get(SafeMode::STATE));
  }

}
