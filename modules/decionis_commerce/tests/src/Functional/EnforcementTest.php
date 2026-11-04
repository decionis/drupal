<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Functional;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Gate\Settings;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
use Drupal\Tests\decionis\Support\ConnectApi;
use Drupal\Tests\decionis\Support\Loopback;

/**
 * An action-specific POST requires current entitlement and real hook coverage.
 *
 * @group decionis
 */
final class EnforcementTest extends CommerceBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_payment', 'commerce_promotion', 'commerce_checkout', 'commerce_order', 'commerce_product', 'decionis', 'decionis_commerce', 'decionis_fincra_test'];

  /**
   * The synthetic workspace authority used by real browser submissions.
   */
  private ?Loopback $server = NULL;

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->server?->stop();
    parent::tearDown();
  }

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return [...parent::getAdministratorPermissions(), 'administer decionis', 'view decionis decisions'];
  }

  /**
   * Missing entitlement disables the control, and the empty state links X-ray.
   */
  public function testNoEntitlementAndEmptyState(): void {
    $this->drupalGet('/admin/commerce/decionis/decisions');
    $session = $this->assertSession();
    $session->pageTextContains('No decisions yet');
    $session->pageTextNotContains('drush');
    $session->linkByHrefExists('/admin/commerce/decionis/x-ray');
    $session->pageTextContains('has not reported an enforcement entitlement');
    $this->assertCount(6, $this->getSession()->getPage()->findAll('css', 'button[aria-disabled="true"][disabled]'));
    $this->drupalGet('/admin/commerce/decionis/enforce/price_change');
    $session->buttonExists('Enforce this one');
    $session->elementExists('css', '#edit-submit[disabled]');
    $this->assertNotSame('enforce', $this->config('decionis.settings')->get('actions.price_change'));
  }

  /**
   * Trial enforcement changes one action only, through CSRF-protected POST.
   */
  public function testTrialForecastAndSingleActionPost(): void {
    \Drupal::state()->set(Binding::STATE, ['org_id' => 'synthetic-workspace', 'api_key' => 'synthetic-key-reference']);
    \Drupal::state()->set(Settings::ENTITLEMENT, ['enforcement' => TRUE, 'state' => 'trial', 'ends_at' => gmdate('c', time() + 86400)]);
    Decision::create(['action' => 'price_change', 'source' => 'replay', 'mode' => 'shadow', 'verdict' => 'BLOCK', 'effect' => 'would_block', 'reasons' => '["price_below_cost"]', 'at_stake' => '1250', 'currency' => 'EUR', 'authority' => 'account', 'created' => time(), 'occurred' => time() - 86400])->save();
    Decision::create(['action' => 'price_change', 'source' => 'replay', 'mode' => 'shadow', 'verdict' => 'ESCALATE', 'effect' => 'would_hold', 'at_stake' => '9000', 'currency' => 'NGN', 'authority' => 'account', 'created' => time(), 'occurred' => time() - 86400])->save();
    Decision::create(['action' => 'price_change', 'source' => 'replay', 'mode' => 'shadow', 'verdict' => 'BLOCK', 'effect' => 'would_block', 'at_stake' => '999999', 'currency' => 'EUR', 'authority' => 'account', 'created' => time(), 'occurred' => time() - 150 * 86400])->save();
    foreach ([['live', 1, '500'], ['replay', 0, '10000'], ['live', 0, '650']] as [$source, $checkout, $amount]) {
      Decision::create(['action' => 'order_acceptance', 'source' => $source, 'checkout' => $checkout, 'mode' => 'shadow', 'verdict' => 'ESCALATE', 'effect' => 'proceeded', 'at_stake' => $amount, 'currency' => 'EUR', 'authority' => 'account', 'created' => time(), 'occurred' => time() - 86400])->save();
    }
    $this->drupalGet('/admin/commerce/decionis/enforce/order_acceptance');
    $this->assertSession()->pageTextContains('1 of 3 flagged; EUR 650.00 at stake; 1 would need approval');
    $before = $this->config('decionis.settings')->get('actions');
    $this->drupalGet('/admin/commerce/decionis/enforce/price_change');
    $this->assertSession()->pageTextContains('1 of 1 flagged; EUR 1,250.00 at stake; 0 would need approval');
    $this->assertSession()->pageTextContains('NGN 9,000.00 at stake; 1 would need approval');
    $this->assertSession()->pageTextNotContains('999,999');
    $this->assertSession()->elementExists('css', 'input[name="form_token"]');
    $this->assertSame($before, $this->config('decionis.settings')->get('actions'), 'a GET never changes an action');
    $this->assertSession()->elementExists('css', 'input[name="form_token"]')->setValue('invalid-token');
    $this->submitForm([], 'Enforce this one');
    $this->assertSame($before, $this->config('decionis.settings')->get('actions'), 'an invalid CSRF token cannot change a mode');
    $this->drupalGet('/admin/commerce/decionis/enforce/price_change');
    $this->submitForm([], 'Enforce this one');
    $this->assertSession()->pageTextContains('Price changes now uses Enforce.');
    $after = $this->config('decionis.settings')->get('actions');
    $before['price_change'] = 'enforce';
    $this->assertSame($before, $after);
    $this->assertSame('shadow', $this->config('decionis.settings')->get('mode'));
  }

  /**
   * Fincra outside refunds do not receive an in-Drupal enforcement promise.
   */
  public function testFincraOnlyShowsCoverageAndSaferFirstActions(): void {
    PaymentGateway::create(['id' => 'fincra', 'label' => 'Fincra', 'plugin' => 'fincra_standard', 'status' => TRUE, 'configuration' => ['mode' => 'test']])->save();
    \Drupal::state()->set(Binding::STATE, ['org_id' => 'synthetic-workspace', 'api_key' => 'synthetic-key-reference']);
    \Drupal::state()->set(Settings::ENTITLEMENT, ['enforcement' => TRUE, 'state' => 'paid']);
    $this->drupalGet('/admin/commerce/decionis/decisions');
    $session = $this->assertSession();
    $session->pageTextContains('Fincra has no ordinary refund operation in Drupal');
    $session->pageTextContains('separate customer payout outside Drupal');
    $session->pageTextContains('Start with orders, prices or promotions.');
    foreach (['order_acceptance', 'price_change', 'promotion_change'] as $action) {
      $session->linkByHrefExists('/admin/commerce/decionis/enforce/' . $action);
    }
    $this->drupalGet('/admin/commerce/decionis/enforce/refund_request');
    $session->elementExists('css', '#edit-submit[disabled]');
    $session->pageTextContains('not automatically reflected as Drupal refunds');
    $session->pageTextContains('cannot be stopped here');
  }

  /**
   * Only a permitted, valid POST starts a trial and enables one action.
   */
  public function testAvailableTrialRequiresAuthorizedPost(): void {
    $this->availableTrial();
    $this->config('decionis.settings')->set('mode', 'enforce')->set('actions.refund_request', 'enforce')->set('actions.agent_tool', 'enforce')->save();
    $this->drupalGet('/admin/commerce/decionis/decisions');
    $this->assertSession()->linkByHrefExists('/admin/commerce/decionis/enforce/price_change');
    $this->assertSession()->pageTextContains('Actions currently run in Shadow');
    $this->drupalGet('/admin/commerce/decionis/enforce/price_change');
    $this->assertSession()->pageTextContains('free 14-day enforcement trial');
    $this->assertSame([], $this->server->stats()['calls'], 'GET does not start the trial');
    $this->assertSession()->elementExists('css', 'input[name="form_token"]')->setValue('invalid-token');
    $this->submitForm([], 'Enforce this one');
    $this->assertSame([], $this->server->stats()['calls'], 'an invalid form token makes no request');

    $this->drupalLogin($this->drupalCreateUser(['view decionis decisions']));
    $this->drupalGet('/admin/commerce/decionis/enforce/price_change');
    $this->assertSession()->statusCodeEquals(403);
    $this->assertSame([], $this->server->stats()['calls']);
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('/admin/commerce/decionis/enforce/price_change');
    $this->submitForm([], 'Enforce this one');
    $this->assertSession()->pageTextContains('Price changes now uses Enforce.');
    $settings = \Drupal::service(Settings::class);
    $this->assertSame('enforce', $settings->mode('price_change'));
    foreach (['refund_request', 'promotion_change', 'order_acceptance', 'inventory_mutation', 'agent_tool'] as $action) {
      $this->assertSame('shadow', $settings->mode($action), $action);
    }
    $calls = $this->server->stats()['calls'];
    $this->assertCount(1, $calls);
    $this->assertSame('/v1/drupal/entitlement/trial', $calls[0]['path']);
    $end = $settings->entitlement()['ends_at'];
    $this->drupalGet('/admin/commerce/decionis/enforce/price_change');
    $this->submitForm([], 'Enforce this one');
    $this->assertCount(1, $this->server->stats()['calls']);
    $this->assertSame($end, $settings->entitlement()['ends_at']);
  }

  /**
   * The trial confirmation cannot be replayed after a connection change.
   */
  public function testAvailableTrialConfirmationStaysWithItsConnection(): void {
    $this->availableTrial();
    $this->drupalGet('/admin/commerce/decionis/enforce/price_change');
    $record = \Drupal::state()->get(Binding::STATE);
    $record['generation'] = 'synthetic-new-connection';
    \Drupal::state()->set(Binding::STATE, $record);
    $entitlement = \Drupal::state()->get(Settings::ENTITLEMENT);
    $entitlement['connection_id'] = \Drupal::service(Settings::class)->connectionIdentity();
    \Drupal::state()->set(Settings::ENTITLEMENT, $entitlement);
    $this->submitForm([], 'Enforce this one');
    $this->assertSame([], $this->server->stats()['calls']);
    $this->assertSame('shadow', \Drupal::service(Settings::class)->mode('price_change'));
  }

  /**
   * Starting a trial never bypasses the Fincra refund coverage restriction.
   */
  public function testAvailableTrialDoesNotEnableFincraRefunds(): void {
    $this->availableTrial();
    PaymentGateway::create(['id' => 'fincra', 'label' => 'Fincra', 'plugin' => 'fincra_standard', 'status' => TRUE, 'configuration' => ['mode' => 'test']])->save();
    $this->drupalGet('/admin/commerce/decionis/enforce/refund_request');
    $this->assertSession()->elementExists('css', '#edit-submit[disabled]');
    $this->assertSession()->pageTextContains('separate customer payout outside Drupal');
    $this->assertSession()->pageTextContains('These payouts are not automatically reflected as Drupal refunds and cannot be stopped here.');
    $this->assertSame([], $this->server->stats()['calls']);
  }

  /**
   * Sets up a real synthetic key and an available trial on loopback only.
   */
  private function availableTrial(): void {
    $this->server = new Loopback('trial');
    $this->config('decionis.settings')->set('connect_url', $this->server->url)->set('api_base_url', $this->server->url)->save();
    \Drupal::service(Credentials::class)->store('api_key', Credentials::API_KEY, 'Synthetic trial key', ConnectApi::API_KEY);
    $record = \Drupal::state()->get(Binding::STATE);
    $record['org_id'] = ConnectApi::ORG;
    \Drupal::state()->set(Binding::STATE, $record);
    \Drupal::state()->set(Settings::ENTITLEMENT, ['state' => 'trial_available', 'enforcement' => FALSE, 'connection_id' => \Drupal::service(Settings::class)->connectionIdentity()]);
  }

}
