<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Functional;

use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Entity\Hold;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Gate\Settings;
use Drupal\Tests\BrowserTestBase;

/**
 * A copied connection is visible and can be disconnected by an explicit POST.
 *
 * @group decionis
 */
final class CopyNoticeTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['decionis'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The copy banner offers fixes, and local disconnect needs no reachable API.
   */
  public function testCopyBannerAndLocalDisconnect(): void {
    $this->config('decionis.settings')->set('api_base_url', 'http://127.0.0.1:1')->set('mode', 'enforce')->save();
    \Drupal::service(Credentials::class)->store('api_key', Credentials::API_KEY, 'Synthetic copied key', 'synthetic-copy-test-only');
    $record = \Drupal::state()->get(Binding::STATE);
    $record['org_id'] = 'synthetic-original-workspace';
    $record['binding'] = ['url' => 'https://original.example', 'host' => 'synthetic-other-host'];
    \Drupal::state()->set(Binding::STATE, $record);
    \Drupal::state()->set(Settings::ENTITLEMENT, ['enforcement' => TRUE, 'state' => 'trial']);
    $this->drupalLogin($this->drupalCreateUser(['administer decionis', 'view decionis decisions']));
    $this->drupalGet('/admin/commerce/decionis/decisions');
    $this->assertSession()->pageTextContains('This looks like a copy of a connected site');
    $this->assertSession()->pageTextContains('Actions run in Shadow');
    $this->assertSession()->linkByHrefExists('/admin/commerce/config/decionis/connect');
    $this->assertSession()->linkByHrefExists('/admin/commerce/config/decionis/disconnect');
    $this->drupalGet('/admin/commerce/config/decionis/disconnect');
    \Drupal::state()->resetCache();
    $this->assertSame('synthetic-original-workspace', \Drupal::state()->get(Binding::STATE)['org_id'], 'opening confirmation does not disconnect');
    $this->submitForm([], 'Disconnect this site');
    $this->assertSession()->pageTextContains('This site is disconnected.');
    \Drupal::state()->resetCache();
    $this->assertFalse(\Drupal::service(Settings::class)->connected());
    $this->assertFalse(\Drupal::service(Settings::class)->copied());
    $this->assertNull(\Drupal::state()->get('decionis.secret.' . Credentials::API_KEY));
  }

  /**
   * No stale Presence URL or action control survives a copied/rebound site.
   */
  public function testStaleApprovalLinksStayWithTheirConnection(): void {
    \Drupal::state()->set(Binding::STATE, ['org_id' => 'original-workspace', 'api_key' => 'original-key-reference']);
    $identity = \Drupal::service(Settings::class)->connectionIdentity();
    foreach ([$identity, '', 'different-generation'] as $index => $captured) {
      Hold::create(['state' => 'approved', 'action' => 'refund_request', 'target_type' => 'commerce_payment', 'target_id' => (string) ($index + 1), 'connection_id' => $captured, 'approval_url' => 'https://presence.example/approval/' . $index, 'grant_token' => 'synthetic-grant-not-for-display'])->save();
    }
    $this->drupalLogin($this->drupalCreateUser(['approve decionis holds']));
    $this->drupalGet('/admin/commerce/decionis/approvals');
    $session = $this->assertSession();
    $session->linkByHrefExists('https://presence.example/approval/0');
    $session->linkByHrefNotExists('https://presence.example/approval/1');
    $session->linkByHrefNotExists('https://presence.example/approval/2');
    $session->responseNotContains('synthetic-grant-not-for-display');
    \Drupal::state()->set(Binding::STATE, ['org_id' => 'original-workspace', 'api_key' => 'original-key-reference', 'binding' => ['url' => 'https://original.example', 'host' => 'different-host']]);
    $this->drupalGet('/admin/commerce/decionis/approvals');
    $session->pageTextContains('Approvals from a copied, disconnected or previous connection are hidden');
    $session->linkNotExists('Open in Presence');
    $session->buttonNotExists('Run once');
    \Drupal::state()->set(Binding::STATE, ['org_id' => 'reconnected-workspace', 'api_key' => 'new-key-reference']);
    $this->drupalGet('/admin/commerce/decionis/approvals');
    $session->linkNotExists('Open in Presence');
    $session->buttonNotExists('Run once');
  }

}
