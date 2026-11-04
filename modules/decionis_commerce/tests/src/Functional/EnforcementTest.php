<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Functional;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis\Gate\Settings;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;

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
    \Drupal::state()->set(Settings::ENTITLEMENT, ['enforcement' => TRUE, 'state' => 'trial']);
    $this->drupalGet('/admin/commerce/decionis/decisions');
    $session = $this->assertSession();
    $session->pageTextContains('Fincra refunds happen outside Drupal');
    $session->pageTextContains('Start with orders, prices or promotions.');
    foreach (['order_acceptance', 'price_change', 'promotion_change'] as $action) {
      $session->linkByHrefExists('/admin/commerce/decionis/enforce/' . $action);
    }
    $this->drupalGet('/admin/commerce/decionis/enforce/refund_request');
    $session->elementExists('css', '#edit-submit[disabled]');
    $session->pageTextContains('cannot stop provider-dashboard refunds');
  }

}
