<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Kernel;

use Drupal\decionis\Entity\Decision;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis\Notification\Digest;
use Drupal\decionis\Notification\Nudges;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Opt-in, recipient permissions and durable notification deduplication.
 *
 * @group decionis
 */
final class NotificationsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'decionis'];

  /**
   * The reviewer account used as the notification recipient.
   */
  private UserInterface $admin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('decionis_decision');
    $this->installEntitySchema('decionis_hold');
    $this->installConfig(['system', 'user', 'decionis']);
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $this->config('decionis.settings')->set('org_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1')->set('api_key', 'synthetic-key-reference')->save();
    User::create(['name' => 'root-fixture', 'status' => 1])->save();
    Role::create(['id' => 'reviewer', 'label' => 'Reviewer', 'permissions' => ['administer decionis']])->save();
    $this->admin = User::create(['name' => 'reviewer-fixture', 'mail' => 'reviewer@example.com', 'roles' => ['reviewer'], 'status' => 1]);
    $this->admin->save();
    $this->container->get('current_user')->setAccount($this->admin);
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * A completed Shadow record contains only bounded local report fields.
   */
  private function entry(int $confirmed = 1): Decision {
    $entry = Decision::create([
      'action' => 'price_change',
      'hook' => 'price_constraint',
      'source' => 'live',
      'mode' => 'shadow',
      'effect' => 'proceeded',
      'verdict' => 'BLOCK',
      'authority' => 'account',
      'policy_version' => 'test-v1',
      'reasons' => '["price_below_cost"]',
      'at_stake' => 96,
      'currency' => 'EUR',
      'connection_id' => $this->container->get(Settings::class)->connectionIdentity(),
      'recipient' => $this->admin->id(),
      'save_confirmed' => $confirmed,
      'nudge_state' => 'pending',
      'nudge_text' => 'Commerce Gate would have blocked this price: SKU-2 now sells 4% below cost.',
      'customer' => 'synthetic-hash-only',
    ]);
    $entry->save();
    return $entry;
  }

  /**
   * A pending or rolled-back save cannot notify; a completed save notifies once.
   */
  public function testNudgeRequiresSavedActionAndOriginalRecipient(): void {
    $entry = $this->entry(0);
    $nudges = $this->container->get(Nudges::class);
    $this->assertSame(0, $nudges->deliver());
    $entry->set('save_confirmed', 1)->save();
    $viewer = User::create(['name' => 'another-reviewer', 'roles' => ['reviewer'], 'status' => 1]);
    $viewer->save();
    $this->container->get('current_user')->setAccount($viewer);
    $this->assertSame(0, $nudges->deliver());
    $this->container->get('current_user')->setAccount($this->admin);
    $this->assertSame(1, $nudges->deliver());
    $this->assertSame(0, $nudges->deliver());
    $this->assertCount(1, $this->container->get('messenger')->messagesByType('warning'));
  }

  /**
   * Drupal mail gets one PII-free digest per week, only after explicit opt-in.
   */
  public function testWeeklyDigestOptInPermissionsAndDeduplication(): void {
    $this->entry();
    Decision::create([
      'action' => 'order_acceptance',
      'source' => 'live',
      'mode' => 'shadow',
      'effect' => 'proceeded',
      'verdict' => 'ESCALATE',
      'checkout' => TRUE,
      'at_stake' => 9000,
      'currency' => 'EUR',
      'save_confirmed' => 1,
      'connection_id' => $this->container->get(Settings::class)->connectionIdentity(),
    ])->save();
    $digest = $this->container->get(Digest::class);
    $this->assertSame(0, $digest->sendDue());
    $viewer = User::create(['name' => 'viewer-fixture', 'mail' => 'viewer@example.com', 'status' => 1]);
    $viewer->save();
    $this->config('decionis.settings')->set('digest.enabled', TRUE)
      ->set('digest.recipients', [(int) $this->admin->id(), (int) $viewer->id()])->set('digest.day', 0)->save();
    $this->assertSame(1, $digest->sendDue());
    $this->assertSame(0, $digest->sendDue());
    $mail = $this->container->get('state')->get('system.test_mail_collector');
    $this->assertCount(1, $mail);
    $this->assertSame('reviewer@example.com', $mail[0]['to']);
    $this->assertStringContainsString('96.00 EUR', $mail[0]['body']);
    $this->assertStringContainsString('Enforce this one:', $mail[0]['body']);
    $this->assertStringNotContainsString('synthetic-hash-only', $mail[0]['body']);
    $this->assertStringNotContainsString('reviewer@example.com', $mail[0]['body']);
    $this->assertStringNotContainsString('SKU-2', $mail[0]['body']);
    $this->assertStringNotContainsString('9,000', $mail[0]['body']);

    // Clearing the test receipt simulates another due week. Permission loss
    // still suppresses delivery, even though that uid remains opted in.
    $this->container->get('keyvalue')->get('decionis_digest')->deleteAll();
    $role = Role::load('reviewer');
    $role->revokePermission('administer decionis')->save();
    $this->assertSame(0, $digest->sendDue());
    $this->assertCount(1, $this->container->get('state')->get('system.test_mail_collector'));
  }

}
