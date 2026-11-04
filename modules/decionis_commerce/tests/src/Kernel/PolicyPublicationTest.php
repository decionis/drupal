<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Decionis\Client\Error\TransportException;
use Drupal\Core\Form\FormState;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Connect\Connector;
use Drupal\decionis\Connect\SiteKey;
use Drupal\decionis\Controller\ChallengeController;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis_commerce\Form\LimitsForm;
use Drupal\decionis_commerce\Policy;
use Drupal\decionis_commerce\Replay\Replay;
use Drupal\decionis_commerce\Report\History;
use Drupal\decionis_commerce\Report\Limits;
use Drupal\Tests\decionis\Support\ConnectApi;
use Drupal\Tests\decionis_commerce\Support\PolicyApi;
use GuzzleHttp\Psr7\Response;

/**
 * Publication is bound to the exact local limits, connection and server receipt.
 *
 * @group decionis
 */
final class PolicyPublicationTest extends DecionisCommerceTestBase {

  /**
   * The bounded publication service being exercised.
   */
  private Policy $policy;

  /**
   * The synthetic service endpoint; no external request is made.
   */
  private PolicyApi $api;

  /**
   * Local choices with presentation metadata that must stay on this site.
   *
   * @var array<string, array<string, mixed>>
   */
  private array $limits;

  /**
   * The connection captured when the form was opened.
   */
  private string $identity;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('commerce_price.currency_importer')->import('NGN');
    $connectApi = new ConnectApi(ChallengeController::create($this->container));
    $connector = new Connector($this->container->get(Settings::class), $this->container->get(Credentials::class), $this->container->get(SiteKey::class), $this->container->get('state'), $this->container->get('config.factory'), $this->container->get('module_handler'), $connectApi);
    $connector->connect('https://shop.example', 'publication-site', 'Synthetic shop', 'billing@example.invalid');
    $this->api = new PolicyApi();
    $this->policy = new Policy($this->container->get(Settings::class), $this->container->get(Credentials::class), $this->container->get('config.factory'), $this->container->get('keyvalue'), $this->container->get('lock'), $this->api);
    $this->container->set(Policy::class, $this->policy);
    $this->identity = (string) $this->policy->identity();
    $this->assertNotSame('', $this->identity);
    $this->limits = ['NGN' => Limits::defaults('NGN') + ['findings' => ['refund_limit' => 'Local-only finding'], 'defaults' => ['margin_floor']]];
    $this->config('decionis_commerce.settings')->set('limits', $this->limits)->save();
    $this->limits = $this->config('decionis_commerce.settings')->get('limits');
  }

  /**
   * Saving sends only permitted values and pins the confirmed server version.
   */
  public function testSavePublishesOnlyNormalizedLimits(): void {
    $helper = new LimitsForm($this->container->get(Limits::class), $this->container->get(History::class), $this->container->get(Replay::class), $this->policy);
    $form = [];
    $state = new FormState();
    $helper->build($form, $state);
    $state->setValue('decionis_limits', $state->get('decionis_limit_values'));
    $helper->validate($form, $state);
    $this->assertSame([], $state->getErrors());
    $helper->submit($form, $state);
    $this->assertCount(1, $this->api->requests);
    $request = $this->api->requests[0];
    $this->assertSame('/v1/drupal/policy/limits', $request->getUri()->getPath());
    $this->assertSame('Bearer ' . ConnectApi::API_KEY, $request->getHeaderLine('Authorization'));
    $body = json_decode((string) $request->getBody(), TRUE, 32, JSON_THROW_ON_ERROR);
    $this->assertSame(['org_id', 'limits_by_currency', 'expected_policy_version'], array_keys($body));
    $this->assertSame(ConnectApi::ORG, $body['org_id']);
    $this->assertSame(['margin_floor', 'refund_limit', 'repeat_limit', 'stacking_ceiling'], array_keys($body['limits_by_currency']['NGN']));
    $this->assertSame(150000, $body['limits_by_currency']['NGN']['refund_limit']);
    $this->assertNull($body['expected_policy_version']);
    $this->assertSame($this->api->version, $this->config('decionis.settings')->get('policy_version'));
    $this->assertSame($this->api->version, $this->policy->version('NGN', $this->limits['NGN']));
    $this->assertNull($this->policy->version('NGN', array_replace($this->limits['NGN'], ['refund_limit' => 140000])));
    $this->policy->publish($this->config('decionis_commerce.settings')->get('limits'), $this->identity);
    $received = $this->api->received();
    $this->assertCount(2, $received, 'saving unchanged limits revalidates the original publication');
    $this->assertSame($request->getHeaderLine('Idempotency-Key'), $received[1]->getHeaderLine('Idempotency-Key'));
    $this->assertSame((string) $request->getBody(), (string) $received[1]->getBody());
  }

  /**
   * Unsupported and forbidden services preserve saved limits and the old pin.
   */
  public function testUnsupportedPublicationPreservesLocalState(): void {
    $this->config('decionis.settings')->set('policy_version', 'previous-policy')->save();
    foreach ([403, 404] as $status) {
      $this->api->during = static fn () => new Response($status, [], '{"error":"unavailable"}');
      $this->assertSame('unavailable', $this->policy->publish($this->limits, $this->identity)['status']);
      $this->assertSame('previous-policy', $this->config('decionis.settings')->get('policy_version'));
      $this->assertSame($this->limits, $this->config('decionis_commerce.settings')->get('limits'));
      $this->assertNull($this->policy->version('NGN', $this->limits['NGN']));
    }
  }

  /**
   * An uncertain response reuses its operation identity before accepting a pin.
   */
  public function testLostResponseReusesTheSameOperation(): void {
    $this->api->during = static function (): void {
      throw new TransportException('SYNTHETIC_TIMEOUT');
    };
    $this->assertSame('failed', $this->policy->publish($this->limits, $this->identity)['status']);
    $this->assertSame('', $this->config('decionis.settings')->get('policy_version'));
    $this->api->during = NULL;
    $this->assertSame('published', $this->policy->publish($this->limits, $this->identity)['status']);
    $this->assertCount(2, $this->api->requests);
    $this->assertSame($this->api->requests[0]->getHeaderLine('Idempotency-Key'), $this->api->requests[1]->getHeaderLine('Idempotency-Key'));
    $this->assertSame((string) $this->api->requests[0]->getBody(), (string) $this->api->requests[1]->getBody());
    $this->assertNull($this->container->get('keyvalue')->get('state')->get(Policy::PENDING));
  }

  /**
   * An old pending operation is resolved before sending newly edited values.
   */
  public function testChangedValuesFollowTheConfirmedPriorVersion(): void {
    $this->api->during = static function (): void {
      throw new TransportException('SYNTHETIC_TIMEOUT');
    };
    $this->policy->publish($this->limits, $this->identity);
    $this->limits['NGN']['refund_limit'] = 140000;
    $this->config('decionis_commerce.settings')->set('limits', $this->limits)->save();
    $this->api->during = NULL;
    $this->assertSame('published', $this->policy->publish($this->limits, $this->identity)['status']);
    $this->assertCount(3, $this->api->requests);
    $last = json_decode((string) $this->api->requests[2]->getBody(), TRUE, 32, JSON_THROW_ON_ERROR);
    $this->assertSame($this->api->version, $last['expected_policy_version']);
    $this->assertSame(140000, $last['limits_by_currency']['NGN']['refund_limit']);
    $this->assertNotSame($this->api->requests[1]->getHeaderLine('Idempotency-Key'), $this->api->requests[2]->getHeaderLine('Idempotency-Key'));
  }

  /**
   * A malformed digest never becomes a publication receipt or policy pin.
   */
  public function testMalformedConfirmationIsNeverAdopted(): void {
    $this->api->during = static fn () => new Response(200, [], json_encode(['org_id' => ConnectApi::ORG, 'policy_version' => 'unverified', 'bundle_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa2', 'limits_digest' => 'sha256:' . str_repeat('0', 64)], JSON_THROW_ON_ERROR));
    $this->assertSame('failed', $this->policy->publish($this->limits, $this->identity)['status']);
    $this->assertSame('', $this->config('decionis.settings')->get('policy_version'));
    $this->assertNull($this->container->get('keyvalue')->get('state')->get(Policy::RECEIPT));
  }

  /**
   * Reconnection during HTTP cannot pin a result for the preceding workspace.
   */
  public function testConnectionChangesDiscardAnInFlightResponse(): void {
    $this->api->during = function (): void {
      $state = $this->container->get('state');
      $binding = $state->get(Binding::STATE);
      $binding['generation'] = 'synthetic-new-connection';
      $state->set(Binding::STATE, $binding);
    };
    $this->assertSame('failed', $this->policy->publish($this->limits, $this->identity)['status']);
    $this->assertSame('', $this->config('decionis.settings')->get('policy_version'));
    $this->assertNull($this->container->get('keyvalue')->get('state')->get(Policy::RECEIPT));
  }

  /**
   * Concurrent edits and imports cannot receive a pin for preceding values.
   */
  public function testConcurrentSaveDoesNotPinStaleLimits(): void {
    $this->api->during = function (): void {
      $changed = $this->limits;
      $changed['NGN']['refund_limit'] = 130000;
      $this->config('decionis_commerce.settings')->set('limits', $changed)->save();
    };
    $this->assertSame('stale', $this->policy->publish($this->limits, $this->identity)['status']);
    $this->assertSame('', $this->config('decionis.settings')->get('policy_version'));
    $this->assertNull($this->policy->version('NGN', $this->limits['NGN']));
  }

  /**
   * A form from an older connection and out-of-contract values do no HTTP work.
   */
  public function testStaleOrInvalidSubmissionsStayLocal(): void {
    $this->assertSame('stale', $this->policy->publish($this->limits, 'older-connection')['status']);
    $this->limits['NGN']['margin_floor'] = -5;
    $this->assertSame('invalid', $this->policy->publish($this->limits, $this->identity)['status']);
    $this->assertCount(0, $this->api->requests);
    $this->assertSame('', $this->config('decionis.settings')->get('policy_version'));
  }

  /**
   * A reviewed current version can reconcile another site's policy publication.
   */
  public function testSupersededReceiptCanBeReconciled(): void {
    $this->assertSame('published', $this->policy->publish($this->limits, $this->identity)['status']);
    $this->api->during = static fn () => new Response(409, [], '{"error":"DRUPAL_POLICY_VERSION_CONFLICT"}');
    $this->assertSame('conflict', $this->policy->publish($this->limits, $this->identity)['status']);
    $this->assertNull($this->policy->version('NGN', $this->limits['NGN']));
    $this->assertSame($this->api->version, $this->config('decionis.settings')->get('policy_version'));
    $this->assertSame($this->api->requests[0]->getHeaderLine('Idempotency-Key'), $this->api->requests[1]->getHeaderLine('Idempotency-Key'));
    $this->config('decionis.settings')->set('policy_version', 'reviewed-current-version')->save();
    $this->limits['NGN']['refund_limit'] = 130000;
    $this->config('decionis_commerce.settings')->set('limits', $this->limits)->save();
    $this->api->during = NULL;
    $this->api->version = 'confirmed-new-version';
    $this->assertSame('published', $this->policy->publish($this->limits, $this->identity)['status']);
    $this->assertCount(3, $this->api->requests);
    $body = json_decode((string) $this->api->requests[2]->getBody(), TRUE, 32, JSON_THROW_ON_ERROR);
    $this->assertSame('reviewed-current-version', $body['expected_policy_version']);
    $this->assertSame('confirmed-new-version', $this->config('decionis.settings')->get('policy_version'));
    $this->assertSame('confirmed-new-version', $this->policy->version('NGN', $this->limits['NGN']));
  }

  /**
   * A foreign manual pin receives no replacement when the authority refuses it.
   */
  public function testForeignManualPolicyIsNotOverwritten(): void {
    $this->assertSame('published', $this->policy->publish($this->limits, $this->identity)['status']);
    $this->config('decionis.settings')->set('policy_version', 'owner-reviewed-policy')->save();
    $this->api->during = static fn () => new Response(409, [], '{"error":"DRUPAL_POLICY_VERSION_CONFLICT"}');
    $this->assertSame('conflict', $this->policy->publish($this->limits, $this->identity)['status']);
    $body = json_decode((string) $this->api->requests[1]->getBody(), TRUE, 32, JSON_THROW_ON_ERROR);
    $this->assertSame('owner-reviewed-policy', $body['expected_policy_version']);
    $this->assertSame('owner-reviewed-policy', $this->config('decionis.settings')->get('policy_version'));
    $this->assertNull($this->policy->version('NGN', $this->limits['NGN']));
  }

  /**
   * A response version must fit the stored portable decision reference.
   */
  public function testOversizeVersionCannotBePinned(): void {
    $this->api->version = str_repeat('v', 121);
    $this->assertSame('failed', $this->policy->publish($this->limits, $this->identity)['status']);
    $this->assertSame('', $this->config('decionis.settings')->get('policy_version'));
    $this->assertNull($this->container->get('keyvalue')->get('state')->get(Policy::RECEIPT));
  }

}
