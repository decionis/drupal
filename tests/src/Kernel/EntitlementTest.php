<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AccountInterface;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Connect\Connector;
use Drupal\decionis\Connect\SiteKey;
use Drupal\decionis\Controller\ChallengeController;
use Drupal\decionis\Controller\DashboardController;
use Drupal\decionis\Form\EnforceForm;
use Drupal\decionis\Form\SettingsForm;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Gate\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\decionis\Support\ConnectApi;
use GuzzleHttp\Psr7\Response;

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
    $this->container->set(Connector::class, $this->connector);
    $this->connector->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $this->config('decionis.settings')->set('mode', 'enforce')->save();
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(TRUE);
    $this->container->get('current_user')->setAccount($account);
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
    $this->assertSame('shadow', $this->modeAfter(['enforcement' => TRUE, 'state' => 'trial', 'ends_at' => gmdate('c', time() + 14 * 86400)]), 'a newly connected site still needs an action selection');
    $settings = $this->container->get(Settings::class);
    $this->assertTrue($settings->selectEnforcement('refund_request', TRUE, $settings->connectionIdentity()));
    $this->assertSame('enforce', $settings->mode('refund_request'));
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
    $settings = $this->container->get(Settings::class);
    $this->assertTrue($settings->selectEnforcement('refund_request', TRUE, $settings->connectionIdentity()));
    $this->assertSame('enforce', $settings->mode('refund_request'));
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

  /**
   * A server-issued trial enables only the action in the confirmation.
   */
  public function testFirstTrialIgnoresHistoricalAndImportedSelections(): void {
    $this->modeAfter(['enforcement' => FALSE, 'state' => 'trial_available']);
    $settings = $this->container->get(Settings::class);
    $this->assertTrue($settings->trialAvailable());
    $this->assertFalse($settings->enforcementAllowed());
    $plan = (string) DashboardController::create($this->container)->view()['plan']['#markup'];
    $this->assertStringContainsString('starts when you choose', $plan);
    [$form, $state, $build] = $this->confirmation('price_change');
    $this->assertFalse($build['actions']['submit']['#disabled']);
    $this->assertCount(3, $this->api->calls, 'building a confirmation makes no trial request');

    // Simulate a config import containing Enforce preferences made elsewhere.
    foreach (array_keys(SettingsForm::ACTIONS) as $action) {
      $this->config('decionis.settings')->set('actions.' . $action, 'enforce')->save();
    }
    $form->submitForm($build, $state);
    $this->assertTrue($settings->enforcementAllowed());
    $this->assertSame($this->api->entitlement['ends_at'], $settings->entitlement()['ends_at']);
    foreach (array_keys(SettingsForm::ACTIONS) as $action) {
      $this->assertSame($action === 'price_change' ? 'enforce' : 'shadow', $settings->mode($action), $action);
    }
    $this->assertSame(['/v1/drupal/entitlement/trial', ['org_id' => ConnectApi::ORG]], array_slice($this->api->calls, -1)[0]);
    $this->assertSame('Bearer ' . ConnectApi::API_KEY, $this->api->trialHeaders[0]['authorization']);
    $this->assertNotEmpty($this->api->trialHeaders[0]['idempotency_key']);

    $end = $settings->entitlement()['ends_at'];
    $form->submitForm($build, $state);
    $this->assertCount(1, $this->api->trialHeaders, 'a repeated local confirmation does not restart the trial');
    $this->assertSame($end, $settings->entitlement()['ends_at']);
    foreach (['paid', 'grace'] as $period) {
      $this->modeAfter(['enforcement' => TRUE, 'state' => $period, 'ends_at' => gmdate('c', time() + 3600)]);
      $this->assertSame('enforce', $settings->mode('price_change'));
      $this->assertSame('shadow', $settings->mode('refund_request'));
    }
  }

  /**
   * A concurrent status read cannot activate unconfirmed actions mid-submit.
   */
  public function testStatusDuringTrialKeepsOtherActionsInShadow(): void {
    $this->modeAfter(['enforcement' => FALSE, 'state' => 'trial_available']);
    $settings = $this->container->get(Settings::class);
    $this->api->duringTrial = function () use ($settings): void {
      $this->connector->refresh();
      $this->assertTrue($settings->enforcementAllowed());
      foreach (array_keys(SettingsForm::ACTIONS) as $action) {
        $this->assertSame('shadow', $settings->mode($action), 'no action has been confirmed yet');
      }
    };
    [$form, $state, $build] = $this->confirmation('price_change');
    $form->submitForm($build, $state);
    $this->assertSame('enforce', $settings->mode('price_change'));
    $this->assertSame('shadow', $settings->mode('order_acceptance'));
  }

  /**
   * A lost reply retries the same operation without resetting the server date.
   */
  public function testRetryRetainsTheWorkspaceTrial(): void {
    $this->modeAfter(['enforcement' => FALSE, 'state' => 'trial_available']);
    $this->api->trialResponse = new Response(503, [], '{"error":"RESPONSE_LOST"}');
    $end = gmdate('c', time() + 13 * 86400);
    $this->api->duringTrial = function () use ($end): void {
      $this->api->entitlement = ['state' => 'trial', 'enforcement' => TRUE, 'ends_at' => $end];
    };
    [$form, $state, $build] = $this->confirmation('price_change');
    $form->submitForm($build, $state);
    $settings = $this->container->get(Settings::class);
    $this->assertFalse($settings->enforcementAllowed());
    $this->api->trialResponse = NULL;
    $form->submitForm($build, $state);
    $this->assertSame('enforce', $settings->mode('price_change'));
    $this->assertSame($end, $settings->entitlement()['ends_at']);
    $this->assertCount(2, $this->api->trialHeaders);
    $this->assertSame($this->api->trialHeaders[0]['idempotency_key'], $this->api->trialHeaders[1]['idempotency_key']);
  }

  /**
   * A billing transition during confirmation uses the current paid entitlement.
   */
  public function testTrialStartCanReturnCurrentPaidAccess(): void {
    $this->modeAfter(['enforcement' => FALSE, 'state' => 'trial_available']);
    $this->api->entitlement = ['state' => 'paid', 'enforcement' => TRUE];
    [$form, $state, $build] = $this->confirmation('price_change');
    $form->submitForm($build, $state);
    $settings = $this->container->get(Settings::class);
    $this->assertSame('paid', $settings->entitlement()['state']);
    $this->assertSame('enforce', $settings->mode('price_change'));
    $this->assertSame('shadow', $settings->mode('refund_request'));
  }

  /**
   * Missing, inconsistent, and expired responses cannot enable an action.
   */
  public function testUnavailableAndMalformedTrialsStayInShadow(): void {
    $responses = [
      new Response(404, [], '{"error":"NOT_FOUND"}'),
      new Response(503, [], '{"error":"UNAVAILABLE"}'),
      new Response(200, [], 'not json'),
    ];
    foreach ([NULL, [], ['state' => 'trial', 'enforcement' => TRUE], ['state' => 'unknown', 'enforcement' => TRUE], ['state' => 'trial_available', 'enforcement' => TRUE], ['state' => 'trial', 'enforcement' => 'true', 'ends_at' => gmdate('c', time() + 3600)], ['state' => 'trial', 'enforcement' => TRUE, 'ends_at' => 'tomorrow'], ['state' => 'trial', 'enforcement' => TRUE, 'ends_at' => '2099-02-30T12:00:00Z'], ['state' => 'trial', 'enforcement' => TRUE, 'ends_at' => gmdate('c', time() - 1)], ['state' => 'trial_available', 'enforcement' => FALSE], ['state' => 'expired', 'enforcement' => FALSE]] as $entitlement) {
      $responses[] = new Response(200, [], json_encode(['org_id' => ConnectApi::ORG, 'entitlement' => $entitlement], JSON_THROW_ON_ERROR));
    }
    $responses[] = new Response(200, [], json_encode(['org_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'entitlement' => ['state' => 'paid', 'enforcement' => TRUE]], JSON_THROW_ON_ERROR));
    foreach ($responses as $response) {
      $this->modeAfter(['enforcement' => FALSE, 'state' => 'trial_available']);
      $this->api->trialResponse = $response;
      [$form, $state, $build] = $this->confirmation('price_change');
      $before = $this->config('decionis.settings')->get('actions');
      $form->submitForm($build, $state);
      $this->assertSame($before, $this->config('decionis.settings')->get('actions'));
      $this->assertSame('shadow', $this->container->get(Settings::class)->mode('price_change'));
    }
  }

  /**
   * An inactive settings page cannot preselect enforcement after trial start.
   */
  public function testInactiveSettingsSubmissionCannotActivateOtherActions(): void {
    $this->modeAfter(['enforcement' => FALSE, 'state' => 'trial_available']);
    $settingsForm = SettingsForm::create($this->container);
    $settingsState = new FormState();
    $settingsBuild = $settingsForm->buildForm([], $settingsState);
    $this->assertTrue($settingsBuild['mode']['#disabled']);
    $this->assertTrue($settingsBuild['actions_mode']['refund_request']['mode']['#disabled']);
    $settingsState->setValue('mode_token', $settingsBuild['mode_token']['#default_value']);
    $settingsState->setValue('mode', 'enforce');
    foreach (array_keys(SettingsForm::ACTIONS) as $action) {
      $settingsState->setValue(['actions_mode', $action, 'mode'], 'enforce');
    }
    [$form, $state, $build] = $this->confirmation('price_change');
    $form->submitForm($build, $state);
    $settingsForm->submitForm($settingsBuild, $settingsState);
    $settings = $this->container->get(Settings::class);
    $this->assertSame('enforce', $settings->mode('price_change'));
    $this->assertSame('shadow', $settings->mode('refund_request'));

    // A freshly built form during paid access can deliberately select more.
    $this->modeAfter(['enforcement' => TRUE, 'state' => 'paid']);
    $settingsState = new FormState();
    $settingsBuild = $settingsForm->buildForm([], $settingsState);
    $this->assertSame('shadow', $settingsBuild['mode']['#default_value']);
    $this->assertSame('shadow', $settingsBuild['actions_mode']['refund_request']['mode']['#default_value']);
    $this->assertSame('enforce', $settingsBuild['actions_mode']['price_change']['mode']['#default_value']);
    $settingsState->setValue('mode_token', $settingsBuild['mode_token']['#default_value']);
    $settingsState->setValue('mode', 'shadow');
    $settingsState->setValue(['actions_mode', 'refund_request', 'mode'], 'enforce');
    $settingsForm->submitForm($settingsBuild, $settingsState);
    $this->assertSame('enforce', $settings->mode('refund_request'));
    $this->assertSame('shadow', $settings->mode('price_change'));
  }

  /**
   * Token, permission, and connection failures make no remote request.
   */
  public function testInvalidConfirmationCannotStartTrial(): void {
    $this->modeAfter(['enforcement' => FALSE, 'state' => 'trial_available']);
    [$form, $state, $build] = $this->confirmation('price_change');
    $state->setValue('connection', 'invalid-token');
    $form->submitForm($build, $state);
    $this->assertSame([], $this->api->trialHeaders);
    [$form, $state, $build] = $this->confirmation('price_change');
    $account = $this->createMock(AccountInterface::class);
    $account->method('hasPermission')->willReturn(FALSE);
    $this->container->get('current_user')->setAccount($account);
    $form->submitForm($build, $state);
    $this->assertSame([], $this->api->trialHeaders);
  }

  /**
   * Rebuilding an old POST cannot bind its confirmation to a new connection.
   */
  public function testReconnectionInvalidatesTheConfirmationToken(): void {
    $this->modeAfter(['enforcement' => FALSE, 'state' => 'trial_available']);
    [$form, $state, $build] = $this->confirmation('price_change');
    $token = $state->getValue('connection');
    $record = $this->container->get('state')->get(Binding::STATE);
    $record['generation'] = 'synthetic-reconnected-generation';
    $this->container->get('keyvalue')->get('state')->set(Binding::STATE, $record);
    $this->connector->refresh();
    $form->submitForm($build, $state);
    $this->assertSame([], $this->api->trialHeaders, 'the original form still names the old connection');
    [$form, $state, $build] = $this->confirmation('price_change');
    $state->setValue('connection', $token);
    $form->submitForm($build, $state);
    $this->assertSame([], $this->api->trialHeaders, 'a rebuilt form still rejects the old signed connection');
  }

  /**
   * A long-lived worker cannot retain an entitlement revoked in another request.
   */
  public function testEntitlementReadsBypassTheStateCache(): void {
    $this->modeAfter(['enforcement' => TRUE, 'state' => 'paid']);
    $settings = $this->container->get(Settings::class);
    $this->assertTrue($settings->enforcementAllowed());
    $state = $this->container->get('state');
    $before = $state->get(Settings::ENTITLEMENT);
    $this->container->get('keyvalue')->get('state')->set(Settings::ENTITLEMENT, ['state' => 'expired', 'enforcement' => FALSE, 'connection_id' => $settings->connectionIdentity()]);
    $this->assertSame($before, $state->get(Settings::ENTITLEMENT));
    $this->assertFalse($settings->enforcementAllowed());
  }

  /**
   * Builds a confirmation and preserves its signed connection field.
   *
   * @return array{EnforceForm, FormState, array}
   *   The form object, its state, and the renderable form.
   */
  private function confirmation(string $action): array {
    $form = new EnforceForm();
    $state = new FormState();
    $build = $form->buildForm([], $state, $action);
    $state->setValue('connection', $build['connection']['#default_value']);
    return [$form, $state, $build];
  }

}
