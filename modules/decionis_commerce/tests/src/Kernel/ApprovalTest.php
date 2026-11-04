<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\SupportsRefundsInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Session\UserSession;
use Drupal\decionis\Approval\Approvals;
use Drupal\decionis\Approval\Grants;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Entity\Hold;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis_commerce\Guard\Refunds;
use Drupal\Tests\decionis\Support\FakeGrants;
use Drupal\Tests\decionis\Support\Scripted;

/**
 * Checks that approval grants execute the exact held action once.
 *
 * Replays, changed actions, and expired intents are refused. The approver runs
 * the requester's action, and a held price change saves once approved.
 *
 * @group decionis
 */
final class ApprovalTest extends DecionisCommerceTestBase {

  /**
   * The authority double used to issue and consume approval grants.
   */
  private FakeGrants $grants;

  /**
   * The completed payment used for held refund requests.
   */
  private PaymentInterface $payment;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->grants = new FakeGrants();
    $this->container->set(Grants::class, $this->grants);
    $this->container->set('Drupal\decionis\Approval\AgentSafe', $this->grants);
    $this->config('decionis.settings')->set('org_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1')->set('api_key', 'synthetic_approval_key_reference')->save();
    PaymentGateway::create(['id' => 'manual', 'label' => 'Manual', 'plugin' => 'manual'])->save();
    $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'mail' => 'buyer@example.com', 'uid' => 0, 'state' => 'completed']);
    $order->save();
    $payment = Payment::create(['type' => 'payment_manual', 'payment_gateway' => 'manual', 'order_id' => $order->id(), 'amount' => new Price('1500', 'USD'), 'state' => 'completed']);
    $payment->save();
    $this->payment = $payment;
  }

  /**
   * Holds a refund of $amount in Enforce, as the refund form would.
   */
  private function held(string $amount): Hold {
    $gate = $this->container->get(Gate::class);
    $outcome = $gate->within('enforce', new Scripted('REVIEW'), 'live', fn () => $this->container->get(Refunds::class)->check($this->payment, new Price($amount, 'USD')));
    $this->assertSame('held', $outcome?->effect);
    $hold = $this->container->get('entity_type.manager')->getStorage('decionis_hold')->load($outcome->holdId);
    $this->assertInstanceOf(Hold::class, $hold);
    return $hold;
  }

  /**
   * Reads the payment refunded amount from storage.
   */
  private function refunded(): string {
    $payment = $this->container->get('entity_type.manager')->getStorage('commerce_payment')->loadUnchanged($this->payment->id());
    assert($payment instanceof PaymentInterface);
    return (string) $payment->getRefundedAmount()?->getNumber();
  }

  /**
   * Tests that aheld refund runs exactly once and areplay is refused.
   */
  public function testHeldRefundRunsExactlyOnceAndReplayIsRefused(): void {
    $approvals = $this->container->get(Approvals::class);
    $gate = $this->container->get(Gate::class);
    $hold = $this->held('1200');
    $this->assertSame('pending', $hold->value('state'));
    $this->assertSame('https://presence.example/approve/1', $hold->value('approval_url'));
    $this->assertSame('NOT_APPROVED', $approvals->execute($hold, $gate), 'nothing runs before approval');
    $this->assertSame('pending', $approvals->refresh($hold)->value('state'));

    $this->grants->approved = TRUE;
    $this->assertSame('approved', $approvals->refresh($hold)->value('state'));
    $stale = clone $hold;

    $this->assertSame('executed', $approvals->execute($hold, $gate));
    $this->assertEquals('1200', $this->refunded());
    $this->assertSame(['COMMITTED'], $this->grants->outcomes);

    $this->assertSame('ALREADY_RUN', $approvals->execute($hold, $gate), 'the same hold runs once');
    $this->assertSame('ALREADY_RUN', $approvals->execute($stale, $gate), 'a copy loaded before the run cannot run it again');
    $this->assertEquals('1200', $this->refunded());
  }

  /**
   * Tests that areplayed grant is refused.
   */
  public function testReplayedGrantIsRefused(): void {
    $approvals = $this->container->get(Approvals::class);
    $hold = $this->held('1200');
    $this->grants->approved = TRUE;
    $hold = $approvals->refresh($hold);
    $token = $hold->value('grant_token');
    $this->assertNotSame('', $token);
    $this->assertSame('executed', $approvals->execute($hold, $this->container->get(Gate::class)));
    // Someone puts the spent grant back on the hold and tries again.
    $replayed = $this->container->get('entity_type.manager')->getStorage('decionis_hold')->loadUnchanged($hold->id());
    $this->assertInstanceOf(Hold::class, $replayed);
    $replayed->set('state', 'approved')->set('grant_token', $token)->save();
    $this->assertNotSame('executed', $approvals->execute($replayed, $this->container->get(Gate::class)));
    $this->assertEquals('1200', $this->refunded(), 'the refund ran once');
    $this->assertCount(1, $this->grants->claimed);
  }

  /**
   * Tests that achanged action is refused.
   */
  public function testChangedActionIsRefused(): void {
    $approvals = $this->container->get(Approvals::class);
    $gate = $this->container->get(Gate::class);
    $hold = $this->held('1200');
    $this->grants->approved = TRUE;
    $approvals->refresh($hold);
    // The payment changes after approval: another refund reduces its balance.
    $plugin = $this->payment->getPaymentGateway()?->getPlugin();
    $this->assertInstanceOf(SupportsRefundsInterface::class, $plugin);
    $gate->without(fn () => $plugin->refundPayment($this->payment, new Price('100', 'USD')));
    $this->assertSame('CHANGED', $approvals->execute($hold, $gate));
    $this->assertEquals('100', $this->refunded());
    $this->assertSame([], $this->grants->claimed, 'nothing was claimed');
  }

  /**
   * Tests that another user runs the refund the requester asked for.
   */
  public function testApproverRunsTheRequestersRefund(): void {
    $user = $this->container->get('current_user');
    $user->setAccount(new UserSession(['uid' => 7]));
    $hold = $this->held('1200');
    $this->assertSame('HUMAN', $hold->actor()['type'] ?? NULL);
    $this->grants->approved = TRUE;
    $approvals = $this->container->get(Approvals::class);
    $approvals->refresh($hold);
    // The store manager who acts on the approval is not the requester.
    $user->setAccount(new UserSession(['uid' => 9]));
    $this->assertSame('executed', $approvals->execute($hold, $this->container->get(Gate::class)));
    $this->assertEquals('1200', $this->refunded());
  }

  /**
   * Tests that the same refund asked again joins the hold waiting for it.
   */
  public function testRepeatedRefundJoinsTheWaitingHold(): void {
    $first = $this->held('1200');
    $second = $this->held('1200');
    $this->assertSame($first->id(), $second->id());
    $this->assertCount(1, $this->container->get('entity_type.manager')->getStorage('decionis_hold')->loadMultiple());
    $this->assertNotSame($first->id(), $this->held('1100')->id(), 'another amount is another action');
  }

  /**
   * Tests that a grant expiring before the run is refused and never claimed.
   */
  public function testExpiredGrantIsRefusedBeforeTheRun(): void {
    $approvals = $this->container->get(Approvals::class);
    $hold = $this->held('1200');
    $this->grants->approved = TRUE;
    $hold = $approvals->refresh($hold);
    $hold->set('grant_expires', gmdate('Y-m-d\TH:i:s.000\Z', time() - 1))->save();
    $this->assertSame('GRANT_EXPIRED', $approvals->execute($hold, $this->container->get(Gate::class)));
    $this->assertSame(0.0, (float) $this->refunded());
    $this->assertSame([], $this->grants->claimed);
  }

  /**
   * Tests that a refund that ran stays executed when the receipt fails.
   */
  public function testRunRefundStaysExecutedWhenTheReceiptFails(): void {
    $approvals = $this->container->get(Approvals::class);
    $hold = $this->held('1200');
    $this->grants->approved = TRUE;
    $hold = $approvals->refresh($hold);
    $this->grants->receiptsFail = TRUE;
    $this->assertSame('executed', $approvals->execute($hold, $this->container->get(Gate::class)));
    $stored = $this->container->get('entity_type.manager')->getStorage('decionis_hold')->loadUnchanged($hold->id());
    $this->assertInstanceOf(Hold::class, $stored);
    $this->assertSame('executed', $stored->value('state'), 'the run is recorded before the receipt');
    $this->assertEquals('1200', $this->refunded());
  }

  /**
   * Tests that a held price change is saved once approved, and only once.
   */
  public function testHeldPriceChangeIsSavedOnApproval(): void {
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'HELD', 'price' => new Price('100', 'USD'), 'status' => 1]);
    $variation->save();
    $variation->setPrice(new Price('40', 'USD'));
    $gate = $this->container->get(Gate::class);
    $violations = $gate->within('enforce', new Scripted('REVIEW'), 'live', fn () => $variation->validate());
    $this->assertGreaterThan(0, count($violations), 'the held price is not saved');
    $holds = $this->container->get('entity_type.manager')->getStorage('decionis_hold')->loadByProperties(['action' => 'price_change']);
    $hold = reset($holds);
    $this->assertInstanceOf(Hold::class, $hold);
    $this->assertSame(100.0, $this->price($variation));

    $approvals = $this->container->get(Approvals::class);
    $this->grants->approved = TRUE;
    $approvals->refresh($hold);
    $this->assertSame('executed', $approvals->execute($hold, $gate));
    $this->assertSame(40.0, $this->price($variation));
    $this->assertSame('ALREADY_RUN', $approvals->execute($hold, $gate));
  }

  /**
   * Reads a variation's price from storage.
   */
  private function price(ProductVariationInterface $variation): float {
    $stored = $this->container->get('entity_type.manager')->getStorage('commerce_product_variation')->loadUnchanged($variation->id());
    assert($stored instanceof ProductVariationInterface);
    return (float) $stored->getPrice()?->getNumber();
  }

  /**
   * Tests that an expired intent is refused.
   */
  public function testExpiredIntentIsRefused(): void {
    $approvals = $this->container->get(Approvals::class);
    $hold = $this->held('1200');
    $this->grants->approved = TRUE;
    $hold = $approvals->refresh($hold);
    $this->assertSame('approved', $hold->value('state'));
    $binding = $hold->binding();
    $binding['expires_at'] = gmdate('Y-m-d\TH:i:s.000\Z', time() - 1);
    $hold->set('intent', json_encode($binding))->save();
    $this->assertSame('EXPIRED', $approvals->execute($hold, $this->container->get(Gate::class)));
    $this->assertSame(0.0, (float) $this->refunded(), 'the stale intent did not run');
    $this->assertSame([], $this->grants->claimed);
  }

  /**
   * Old holds are refused locally before approval lookup or grant claim.
   */
  public function testReconnectInvalidatesPendingAndApprovedHolds(): void {
    $approvals = $this->container->get(Approvals::class);
    $pending = $this->held('1100');
    $approved = $this->held('1200');
    $this->grants->approved = TRUE;
    $approvals->refresh($approved);
    $issued = $this->grants->issued;
    $this->container->get(Binding::class)->bind('https://shop.example');

    $this->assertSame('refused', $approvals->refresh($pending)->value('state'));
    $this->assertSame('CONNECTION_CHANGED', $approvals->execute($approved, $this->container->get(Gate::class)));
    $this->assertSame($issued, $this->grants->issued, 'No approval lookup reached the old or new workspace.');
    $this->assertSame([], $this->grants->claimed);
    $this->assertEquals(0, $this->refunded());
  }

  /**
   * A copied site cannot notify production approvers or use their old holds.
   */
  public function testCopiedSiteCannotEscalateOrReadProductionApproval(): void {
    $binding = $this->container->get(Binding::class);
    $binding->bind('https://shop.example');
    $old = $this->held('1200');
    $record = $binding->record();
    $record['binding']['host'] = hash('sha256', 'another installation');
    $this->container->get('state')->set(Binding::STATE, $record);
    $this->grants->approved = TRUE;
    $approvals = $this->container->get(Approvals::class);
    $this->assertSame('refused', $approvals->refresh($old)->value('state'));
    $this->assertSame([], $this->grants->issued);
    $new = $this->held('1100');
    $this->assertSame('', $new->value('approval_url'));
    $this->assertSame('', $new->value('escalation_id'));
    $this->assertSame([], $this->grants->claimed);
  }

  /**
   * A slow claim cannot authorize a refund after the site reconnects.
   */
  public function testReconnectDuringClaimCannotRunRefund(): void {
    $hold = $this->held('1200');
    $identity = $hold->value('connection_id');
    $this->grants->approved = TRUE;
    $approvals = $this->container->get(Approvals::class);
    $approvals->refresh($hold);
    $this->grants->duringClaim = fn () => $this->container->get(Binding::class)->bind('https://shop.example');
    $this->assertSame('CONNECTION_CHANGED', $approvals->execute($hold, $this->container->get(Gate::class)));
    $this->assertEquals(0, $this->refunded());
    $this->assertCount(1, $this->grants->claimed, 'The remote claim completed before reconnect was observed.');
    $this->assertSame([], $this->grants->outcomes, 'No receipt was sent through the new connection.');
    $fresh = $this->container->get('entity_type.manager')->getStorage('decionis_hold')->loadUnchanged($hold->id());
    $this->assertInstanceOf(Hold::class, $fresh);
    $this->assertSame($identity, $fresh->value('connection_id'));
    $this->assertSame('refused', $fresh->value('state'));
  }

  /**
   * The same race cannot save a formerly approved price change either.
   */
  public function testReconnectDuringClaimCannotSavePrice(): void {
    $variation = ProductVariation::create(['type' => 'default', 'sku' => 'CLAIM-RACE', 'price' => new Price('100', 'USD'), 'status' => 1]);
    $variation->save();
    $variation->setPrice(new Price('40', 'USD'));
    $gate = $this->container->get(Gate::class);
    $gate->within('enforce', new Scripted('REVIEW'), 'live', fn () => $variation->validate());
    $holds = $this->container->get('entity_type.manager')->getStorage('decionis_hold')->loadByProperties(['action' => 'price_change']);
    $hold = reset($holds);
    $this->assertInstanceOf(Hold::class, $hold);
    $this->grants->approved = TRUE;
    $approvals = $this->container->get(Approvals::class);
    $approvals->refresh($hold);
    $this->grants->duringClaim = fn () => $this->container->get(Binding::class)->bind('https://shop.example');
    $this->assertSame('CONNECTION_CHANGED', $approvals->execute($hold, $gate));
    $this->assertSame(100.0, $this->price($variation));
    $this->assertSame([], $this->grants->outcomes);
  }

}
