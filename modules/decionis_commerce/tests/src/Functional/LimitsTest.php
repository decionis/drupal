<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Functional;

use Behat\Mink\Driver\BrowserKitDriver;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\decionis_commerce\Report\Xray;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;

/**
 * The settings form previews local findings with AJAX, then saves chosen limits.
 *
 * @group decionis
 */
final class LimitsTest extends CommerceBrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['commerce_payment', 'commerce_promotion', 'commerce_checkout', 'commerce_order', 'commerce_product', 'decionis', 'decionis_commerce'];

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return [...parent::getAdministratorPermissions(), 'administer decionis', 'view decionis decisions'];
  }

  /**
   * Counts refresh before saving, and the old X-ray then asks to be rerun.
   */
  public function testEditableLimitsPreviewCountsAndInvalidateOldReport(): void {
    \Drupal::service('commerce_price.currency_importer')->import('NGN');
    $this->store->setDefaultCurrencyCode('NGN')->save();
    PaymentGateway::create(['id' => 'manual', 'label' => 'Manual', 'plugin' => 'manual'])->save();
    foreach (['90000', '160000'] as $index => $refunded) {
      $order = Order::create(['type' => 'default', 'store_id' => $this->store->id(), 'mail' => 'history' . $index . '@example.invalid', 'state' => 'completed']);
      $order->save();
      Payment::create(['type' => 'payment_manual', 'payment_gateway' => 'manual', 'order_id' => $order->id(), 'amount' => new Price('200000', 'NGN'), 'refunded_amount' => new Price($refunded, 'NGN'), 'state' => 'partially_refunded', 'completed' => time() - 86400])->save();
    }
    \Drupal::state()->set(Xray::STATE, Xray::empty() + ['days' => 90]);
    $this->drupalGet('/admin/commerce/config/decionis');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->fieldValueEquals('decionis_limits[NGN][refund_limit]', '150000');
    $this->assertSession()->pageTextContains('1 of 2 cumulative refunded payments are at or below NGN 150,000.00');
    $this->assertSession()->pageTextContains('Fewer than 20 observations');
    $this->assertSession()->pageTextContains('Publishing them to your connected workspace is not available yet');

    // Use the same POST and triggering element as Drupal's number-field AJAX.
    // No browser JavaScript or external service is needed to verify its result.
    $driver = $this->getSession()->getDriver();
    $this->assertInstanceOf(BrowserKitDriver::class, $driver);
    $client = $driver->getClient();
    $values = $client->getCrawler()->selectButton('Save configuration')->form()->getPhpValues();
    unset($values['op']);
    $values['decionis_limits']['NGN']['refund_limit'] = '200000';
    $values['_triggering_element_name'] = 'decionis_limits[NGN][refund_limit]';
    $values['ajax_form'] = '1';
    $client->request('POST', $this->getUrl() . '?ajax_form=1&_wrapper_format=drupal_ajax', $values, [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $this->assertSame(200, $client->getResponse()->getStatusCode());
    $commands = json_decode($client->getResponse()->getContent(), TRUE, 512, JSON_THROW_ON_ERROR);
    $html = implode('', array_filter(array_column($commands, 'data'), 'is_string'));
    $this->assertStringContainsString('2 of 2 cumulative refunded payments are at or below NGN 200,000.00', $html);
    $this->container->get('config.factory')->reset('decionis_commerce.settings');
    $this->assertNull($this->config('decionis_commerce.settings')->get('limits.NGN.refund_limit'), 'preview does not save a policy');

    $this->drupalGet('/admin/commerce/config/decionis');
    $this->submitForm(['decionis_limits[NGN][refund_limit]' => '200000'], 'Save configuration');
    $this->assertSession()->pageTextContains('Limits updated');
    $this->assertSession()->pageTextContains('2 of 2 cumulative refunded payments are at or below NGN 200,000.00');
    $this->container->get('config.factory')->reset('decionis_commerce.settings');
    $this->assertSame(200000.0, $this->config('decionis_commerce.settings')->get('limits.NGN.refund_limit'));
    $this->assertSame('tuned', $this->config('decionis_commerce.settings')->get('limits.NGN.source'));
    $this->assertSame('', $this->config('decionis.settings')->get('policy_version'));
    $this->drupalGet('/admin/commerce/decionis/x-ray');
    $this->assertSession()->pageTextContains('Limits have changed since this estimate');
  }

}
