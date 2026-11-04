<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Kernel;

use Drupal\decionis\Connect\Connector;
use Drupal\decionis\Connect\SiteKey;
use Drupal\decionis\Controller\ChallengeController;
use Drupal\decionis\Controller\DashboardController;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Gate\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\decionis\Support\ConnectApi;

/**
 * Checks that effective mode follows the server's entitlement.
 *
 * Covers trial start, expiry back to Shadow, paid access, and the grace period.
 * The module never grants itself an entitlement.
 *
 * @group decionis
 */
final class EntitlementTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'decionis'];

  /**
   * The connect API double that supplies workspace entitlement.
   */
  private ConnectApi $api;

  /**
   * The connector whose entitlement refresh is under test.
   */
  private Connector $connector;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('decionis_decision');
    $this->installEntitySchema('decionis_hold');
    $this->installConfig(['decionis']);
    $this->api = new ConnectApi(ChallengeController::create($this->container));
    $this->connector = new Connector($this->container->get(Settings::class), $this->container->get(Credentials::class), $this->container->get(SiteKey::class), $this->container->get('state'), $this->container->get('config.factory'), $this->container->get('module_handler'), $this->api);
    $this->connector->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $this->config('decionis.settings')->set('mode', 'enforce')->save();
  }

  /**
   * The effective refund mode after the server reports $entitlement.
   *
   * @param array<string, mixed>|null $entitlement
   *   The workspace entitlement reported by the API double.
   */
  private function modeAfter(?array $entitlement): string {
    $this->api->entitlement = $entitlement;
    $this->connector->refresh();
    return $this->container->get(Settings::class)->mode('refund_request');
  }

  /**
   * Tests that the mode follows the servers entitlement.
   */
  public function testTheModeFollowsTheServersEntitlement(): void {
    $this->assertSame('shadow', $this->modeAfter(NULL), 'no entitlement reported: Shadow, whatever the admin chose');
    $this->assertSame('enforce', $this->modeAfter(['enforcement' => TRUE, 'state' => 'trial', 'ends_at' => gmdate('c', time() + 14 * 86400)]), 'trial started');
    $this->assertSame('shadow', $this->modeAfter(['enforcement' => FALSE, 'state' => 'expired']), 'trial over: back to Shadow');
    $this->assertSame('enforce', $this->modeAfter(['enforcement' => TRUE, 'state' => 'paid']), 'Build plan');
    $this->assertSame('enforce', $this->modeAfter(['enforcement' => TRUE, 'state' => 'grace', 'ends_at' => gmdate('c', time() + 30 * 86400)]), 'payment late: grace period');
    $this->assertSame('shadow', $this->modeAfter(['enforcement' => FALSE, 'state' => 'expired']), 'grace over: back to Shadow');
  }

  /**
   * Tests that an ended period drops to shadow before the next refresh.
   */
  public function testAnEndedPeriodDropsToShadowBeforeTheNextRefresh(): void {
    $this->modeAfter(['enforcement' => TRUE, 'state' => 'trial', 'ends_at' => gmdate('c', time() + 60)]);
    $state = $this->container->get('state');
    $entitlement = $state->get(Settings::ENTITLEMENT);
    $entitlement['ends_at'] = gmdate('c', time() - 1);
    $state->set(Settings::ENTITLEMENT, $entitlement);
    $this->assertSame('shadow', $this->container->get(Settings::class)->mode('refund_request'));
  }

  /**
   * Tests that the dashboard shows the days left and the subscribe link.
   */
  public function testTheDashboardShowsTheDaysLeftAndTheSubscribeLink(): void {
    $this->modeAfter(['enforcement' => TRUE, 'state' => 'trial', 'ends_at' => gmdate('c', time() + 5 * 86400 - 60), 'subscribe_url' => 'https://app.decionis.com/billing/org-1']);
    $plan = (string) DashboardController::create($this->container)->view()['plan']['#markup'];
    $this->assertStringContainsString('Free enforcement trial: 5 days left.', $plan);
    $this->assertStringContainsString('href="https://app.decionis.com/billing/org-1"', $plan);
    $this->modeAfter(['enforcement' => TRUE, 'state' => 'grace', 'ends_at' => gmdate('c', time() + 30 * 86400 - 60), 'subscribe_url' => 'javascript:alert(1)']);
    $plan = (string) DashboardController::create($this->container)->view()['plan']['#markup'];
    $this->assertStringContainsString('Payment grace period: 30 days left before Shadow.', $plan);
    $this->assertStringNotContainsString('javascript:', $plan, 'only an https subscribe link is shown');
  }

  /**
   * Tests that shadow history is kept when enforcement ends.
   */
  public function testShadowHistoryIsKeptWhenEnforcementEnds(): void {
    $this->modeAfter(['enforcement' => TRUE, 'state' => 'trial', 'ends_at' => gmdate('c', time() + 3600)]);
    $this->modeAfter(['enforcement' => FALSE, 'state' => 'expired']);
    $this->assertSame('enforce', $this->container->get(Settings::class)->chosen('refund_request'), 'the admin choice is kept for when enforcement returns');
    $this->assertTrue($this->container->get(Settings::class)->connected());
  }

}
