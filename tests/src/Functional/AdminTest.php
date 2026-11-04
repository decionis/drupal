<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Functional;

use Drupal\decionis\Connect\Connector;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Gate\Settings;
use Drupal\Tests\BrowserTestBase;

/**
 * Checks permissions and safe disclosure on admin and challenge routes.
 *
 * Covers the one-step connect form, challenges outside a connection, and the
 * approval queue, which never shows a grant token.
 *
 * @group decionis
 */
final class AdminTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['decionis'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests that each page needs its own permission.
   */
  public function testEachPageNeedsItsPermission(): void {
    $pages = ['/admin/commerce/config/decionis', '/admin/commerce/config/decionis/connect', '/admin/commerce/decionis/decisions', '/admin/commerce/decionis/decisions/export.csv', '/admin/commerce/decionis/approvals'];
    foreach ($pages as $page) {
      $this->drupalGet($page);
      $this->assertSession()->statusCodeEquals(403);
    }
    $this->drupalLogin($this->drupalCreateUser(['view decionis decisions']));
    foreach ($pages as $page) {
      $this->drupalGet($page);
      $this->assertSession()->statusCodeEquals(str_contains($page, '/decisions') ? 200 : 403);
    }
    $this->drupalGet('/admin/commerce/decionis/decisions/export.csv');
    $this->assertSame('id,created,action,hook,source,mode,verdict,effect,reasons,at_stake,currency,policy_version,dossier_id,dossier_sha256,target_type,target_id,authority', strtok($this->getSession()->getPage()->getContent(), "\n"), 'the export carries no customer column');
  }

  /**
   * Tests that the connect form is prefilled and needs the terms.
   */
  public function testTheConnectFormIsPrefilledAndNeedsTheTerms(): void {
    $this->config('system.site')->set('name', 'Example Shop')->save();
    $admin = $this->drupalCreateUser(['administer decionis']);
    $admin->setEmail('owner@example.com')->save();
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/commerce/config/decionis/connect');
    $session = $this->assertSession();
    $session->fieldValueEquals('business_name', 'Example Shop');
    $session->fieldValueEquals('billing_email', 'owner@example.com');
    $session->fieldDisabled('site_url');
    $this->assertStringStartsWith('http', (string) $this->getSession()->getPage()->findField('site_url')?->getValue());
    // Without the terms the form stops before it contacts Decionis.
    $this->submitForm([], 'Connect to Commerce Gate');
    $session->pageTextContains('field is required');
    $this->assertNull(\Drupal::state()->get(Connector::STATE), 'no connect was started');
  }

  /**
   * Tests that the challenge answers only while a connect is open.
   */
  public function testTheChallengeAnswersOnlyDuringConnect(): void {
    $this->drupalGet('/.well-known/decionis-challenge');
    $this->assertSession()->statusCodeEquals(404);
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');

    $proof = ['site_uuid' => 'site-1', 'nonce' => 'n-1', 'signature' => 'synthetic'];
    \Drupal::state()->set(Connector::STATE, ['proof' => $proof, 'expires' => time() + 300]);
    $this->drupalGet('/.well-known/decionis-challenge');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSame($proof, json_decode($this->getSession()->getPage()->getContent(), TRUE));

    \Drupal::state()->set(Connector::STATE, ['proof' => $proof, 'expires' => time() - 1]);
    $this->drupalGet('/.well-known/decionis-challenge');
    $this->assertSession()->statusCodeEquals(404);
  }

  /**
   * Tests the plain path, for web servers that keep /.well-known/ to themselves.
   */
  public function testThePlainChallengePathServesTheSameProof(): void {
    $this->drupalGet('/decionis/challenge');
    $this->assertSession()->statusCodeEquals(404);
    $proof = ['site_uuid' => 'site-1', 'nonce' => 'n-1', 'signature' => 'synthetic'];
    \Drupal::state()->set(Connector::STATE, ['proof' => $proof, 'expires' => time() + 300]);
    $this->drupalGet('/decionis/challenge');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->responseHeaderContains('Cache-Control', 'no-store');
    $this->assertSame($proof, json_decode($this->getSession()->getPage()->getContent(), TRUE));
  }

  /**
   * Tests that a site in maintenance mode can still prove control.
   */
  public function testTheChallengeAnswersDuringMaintenance(): void {
    $proof = ['site_uuid' => 'site-1', 'nonce' => 'n-1', 'signature' => 'synthetic'];
    \Drupal::state()->set(Connector::STATE, ['proof' => $proof, 'expires' => time() + 300]);
    \Drupal::state()->set('system.maintenance_mode', TRUE);
    foreach (['/.well-known/decionis-challenge', '/decionis/challenge'] as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(200);
    }
  }

  /**
   * Tests that the code step can be left.
   */
  public function testTheCodeStepCanStartOver(): void {
    \Drupal::state()->set(Connector::STATE, ['connect_id' => 'c-1', 'expires' => time() + 300]);
    $this->drupalLogin($this->drupalCreateUser(['administer decionis']));
    $this->drupalGet('/admin/commerce/config/decionis/connect');
    $session = $this->assertSession();
    $session->fieldExists('code');
    $this->submitForm([], 'Start over');
    $session->fieldNotExists('code');
    $session->fieldExists('business_name');
    \Drupal::state()->resetCache();
    $this->assertNull(\Drupal::state()->get(Connector::STATE));
  }

  /**
   * Tests that the approval queue links to Presence and hides the grant.
   */
  public function testTheApprovalQueueLinksToPresenceAndHidesTheGrant(): void {
    \Drupal::state()->set(Binding::STATE, ['org_id' => 'synthetic-workspace', 'api_key' => 'synthetic-key-reference']);
    \Drupal::entityTypeManager()->getStorage('decionis_hold')->create([
      'action' => 'refund_request',
      'hook' => 'refund_form',
      'target_type' => 'commerce_payment',
      'target_id' => '7',
      'state' => 'approved',
      'intent' => '{}',
      'intent_hash' => 'sha256:' . str_repeat('a', 64),
      'approval_url' => 'https://presence.example/approve/7',
      'grant_token' => 'grant.not-for-display.sig',
      'connection_id' => \Drupal::service(Settings::class)->connectionIdentity(),
      'at_stake' => '1200',
      'currency' => 'EUR',
    ])->save();
    $this->drupalLogin($this->drupalCreateUser(['approve decionis holds']));
    $this->drupalGet('/admin/commerce/decionis/approvals');
    $session = $this->assertSession();
    $session->statusCodeEquals(200);
    $session->linkByHrefExists('https://presence.example/approve/7');
    $session->buttonExists('Run once');
    $session->pageTextContains('EUR 1,200.00');
    $session->responseNotContains('grant.not-for-display.sig');
  }

}
