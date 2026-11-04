<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Functional;

use Decionis\Client\Dossier\Jwks;
use Decionis\Client\Dossier\Verifier;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis\Gate\Credentials;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\decionis\Support\EvidenceApi;

/**
 * Currency-safe first catches, plain reasons, inline verification and downloads.
 *
 * @group decionis
 */
final class DashboardTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['decionis'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Large NGN values never suppress the largest EUR catch; pending is separate.
   */
  public function testCatchesAndReasons(): void {
    $this->entry(['at_stake' => '1200']);
    $this->entry(['currency' => 'NGN', 'at_stake' => '2400000', 'reasons' => '["refund_over_unattended_limit"]', 'action' => 'refund_request', 'verdict' => 'HOLD']);
    $this->entry(['at_stake' => '999999999', 'effect' => 'pending', 'verdict' => '', 'reasons' => '[]']);
    $this->entry(['at_stake' => '10', 'reasons' => '["<script>unknown_rule</script>"]']);
    $this->drupalLogin($this->drupalCreateUser(['view decionis decisions']));
    $this->drupalGet('/admin/commerce/decionis/decisions');
    $session = $this->assertSession();
    $session->statusCodeEquals(200);
    $session->pageTextContains('Largest catch in EUR');
    $session->pageTextContains('Largest catch in NGN');
    $cards = $this->getSession()->getPage()->findAll('css', '.decionis-catch');
    $this->assertCount(2, $cards);
    $this->assertStringContainsString('EUR 1,200.00', $cards[0]->getText());
    $this->assertStringContainsString('This price is below the recorded cost.', $cards[0]->getText());
    $this->assertStringContainsString('NGN 2,400,000.00', $cards[1]->getText());
    $session->pageTextContains('1 pending');
    $session->pageTextContains('Agent tool calls: SHADOW');
    $session->responseNotContains('<script>unknown_rule</script>');
    $session->pageTextContains('<script>unknown_rule</script>');
    $this->drupalGet('/admin/commerce/decionis/decisions/export.csv');
    $session->responseContains('This price is below the recorded cost.');
    $session->responseContains('This refund is above the limit for refunds without approval.');
  }

  /**
   * Full JSON keeps every signed artifact; AJAX binds it to this exact action.
   */
  public function testDossierDownloadAndInlineVerification(): void {
    $api = new EvidenceApi();
    try {
      $this->config('decionis.settings')->set('api_base_url', $api->url)->set('jwks_url', $api->url . '/.well-known/decision-dossier-jwks.json')->save();
      \Drupal::state()->set(Binding::STATE, ['org_id' => EvidenceApi::ORG]);
      \Drupal::service(Credentials::class)->store('api_key', Credentials::API_KEY, 'Synthetic test key', 'dcy_aaaaaaaa_synthetic-evidence-only');
      $entry = $this->entry(['dossier_id' => EvidenceApi::DOSSIER, 'evaluation_id' => EvidenceApi::EVALUATION, 'org_id' => EvidenceApi::ORG, 'policy_version' => 'unsigned-local-policy']);
      $path = '/admin/commerce/decionis/decisions/' . $entry->id();
      $this->drupalGet($path . '/dossier.json');
      $this->assertSession()->statusCodeEquals(403);
      $this->drupalLogin($this->drupalCreateUser(['view decionis decisions']));
      $this->drupalGet($path . '/dossier.json');
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->responseHeaderContains('Content-Disposition', 'attachment');
      $json = $this->getSession()->getPage()->getContent();
      $verifier = new Verifier(Jwks::fromArray($api->fixture['jwks']));
      $this->assertTrue($verifier->verifyJson($json)->complete, 'the complete downloaded Dossier still verifies');
      $this->assertEquals($api->fixture['record']['dossier_payload'], json_decode($json));
      $this->drupalGet($path . '/verify');
      $this->assertSession()->pageTextContains('Signature valid. Evidence coverage complete.');
      $this->assertSession()->pageTextContains('Key id: synthetic-evidence-test');
      $this->assertSession()->pageTextContains('Signed policy version: signed-policy-v7');
      $this->assertSession()->pageTextNotContains('unsigned-local-policy');
      $this->drupalGet($path . '/verify', ['query' => ['_wrapper_format' => 'drupal_ajax', 'slot' => 'recent']]);
      $commands = json_decode($this->getSession()->getPage()->getContent(), TRUE);
      $insert = array_values(array_filter($commands, static fn (array $command): bool => ($command['command'] ?? '') === 'insert'));
      $this->assertSame('#decionis-proof-recent-' . $entry->id(), $insert[0]['selector']);
      $this->assertStringContainsString('Signature valid', $insert[0]['data']);
      $api->fixture['record']['dossier_payload']->inputs_snapshot->amount = 1;
      $api->write();
      $this->drupalGet($path . '/verify');
      $this->assertSession()->pageTextContains('Signature NOT VERIFIED');
      $this->assertSession()->pageTextContains('Signed policy version: unverified');
      $api->fixture['record']['dossier_payload']->inputs_snapshot->amount = 1250;
      array_pop($api->fixture['record']['dossier_payload']->integrity->proof_bundle->artifacts);
      $api->write();
      $this->drupalGet($path . '/verify');
      $this->assertSession()->pageTextContains('Signature valid. Evidence coverage incomplete.');
      // A valid foreign Dossier is not this action's evidence.
      $api->fixture['record']['org_id'] = 'foreign-workspace';
      $api->write();
      $this->drupalGet($path . '/verify');
      $this->assertSession()->pageTextContains('Could not verify this Dossier');
      $this->assertSession()->pageTextNotContains('Signature valid');
      $this->drupalGet($path . '/dossier.json');
      $this->assertSession()->statusCodeEquals(503);
    }
    finally {
      $api->close();
    }
  }

  /**
   * Creates metadata only, without contacting a decision authority.
   */
  private function entry(array $values): Decision {
    $entry = Decision::create($values + ['action' => 'price_change', 'hook' => 'price_constraint', 'source' => 'live', 'mode' => 'shadow', 'verdict' => 'BLOCK', 'effect' => 'would_block', 'reasons' => '["price_below_cost"]', 'currency' => 'EUR', 'at_stake' => '1250', 'authority' => 'account', 'created' => time()]);
    $entry->save();
    return $entry;
  }

}
