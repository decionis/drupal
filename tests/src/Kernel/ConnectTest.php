<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Kernel;

use Decionis\Client\Error\HttpException;
use Drupal\Core\Database\Connection;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Connect\Connector;
use Drupal\decionis\Connect\Migration;
use Drupal\decionis\Connect\SiteKey;
use Drupal\decionis\Controller\ChallengeController;
use Drupal\decionis\Form\ConnectForm;
use Drupal\decionis\Entity\Hold;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Gate\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\key\KeyInterface;
use Drupal\Tests\decionis\Support\ConnectApi;
use Drupal\Tests\decionis\Support\BindingProbe;

/**
 * Tests one-step connect.
 *
 * Proof of site control through the served challenge, credentials into the Key
 * module, and nothing secret in config export.
 *
 * @group decionis
 */
final class ConnectTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'key', 'decionis'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('decionis_decision');
    $this->installEntitySchema('decionis_hold');
    $this->installConfig(['decionis']);
  }

  /**
   * Builds a connector that talks to the connect API double.
   */
  private function connector(ConnectApi $api): Connector {
    return new Connector(
      $this->container->get(Settings::class),
      $this->container->get(Credentials::class),
      $this->container->get(SiteKey::class),
      $this->container->get('state'),
      $this->container->get('config.factory'),
      $this->container->get('module_handler'),
      $api,
    );
  }

  /**
   * Tests that connect proves site control and stores credentials outside config.
   */
  public function testConnectProvesSiteControlAndStoresCredentialsOutsideConfig(): void {
    $api = new ConnectApi(ChallengeController::create($this->container));
    $this->assertSame('connected', $this->connector($api)->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com'));

    $this->assertSame(['/v1/drupal/connect/start', '/v1/drupal/connect/verify'], array_column($api->calls, 0));
    $start = $api->calls[0][1];
    $this->assertSame(['site_url', 'site_uuid', 'business_name', 'billing_email', 'public_key', 'terms_accepted'], array_keys($start));
    $this->assertSame('https://shop.example', $api->served['site_url'] ?? NULL, 'Decionis fetched the signed challenge from the site');

    $settings = $this->container->get(Settings::class);
    $this->assertTrue($settings->connected());
    $this->assertSame(ConnectApi::ORG, $settings->get('org_id'));
    $this->assertSame('shadow', $settings->get('mode'));
    $this->assertSame(ConnectApi::API_KEY, $this->container->get(Credentials::class)->apiKey());

    $export = '';
    $storage = $this->container->get('config.storage');
    foreach ($storage->listAll() as $name) {
      $export .= json_encode($storage->read($name));
    }
    $this->assertStringNotContainsString(ConnectApi::API_KEY, $export, 'the API key is not in config export');
    $this->assertStringNotContainsString(base64_encode((string) $this->container->get(Credentials::class)->siteSecret()), $export, 'the site secret key is not in config export');
    $this->assertStringContainsString('decionis.secret.decionis_api_key', $export, 'config holds only the State variable name');
  }

  /**
   * Tests that the challenge is served only while connecting.
   */
  public function testTheChallengeIsServedOnlyWhileConnecting(): void {
    $challenges = ChallengeController::create($this->container);
    $this->assertSame(404, $challenges->serve()->getStatusCode());
    $this->connector(new ConnectApi($challenges))->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $this->assertSame(404, $challenges->serve()->getStatusCode(), 'single-use: not served again after connect');
  }

  /**
   * Tests that unreachable sites confirm with the email code.
   */
  public function testUnreachableSitesConfirmWithTheEmailCode(): void {
    $challenges = ChallengeController::create($this->container);
    $api = new ConnectApi($challenges, FALSE);
    $connector = $this->connector($api);
    $this->assertSame('code_sent', $connector->connect('https://staging.internal.example', 'site-uuid-2', 'Example Shop', 'billing@example.com'));
    $this->assertSame(404, $challenges->serve()->getStatusCode(), 'the challenge stops being served once verify answered');
    $this->assertFalse($this->container->get(Settings::class)->connected());
    $this->assertSame('connected', $connector->confirm('123456'));
    $this->assertTrue($this->container->get(Settings::class)->connected());
  }

  /**
   * Tests that a failed verify starts over instead of waiting for a code.
   */
  public function testFailedVerifyStartsOver(): void {
    $challenges = ChallengeController::create($this->container);
    $api = new ConnectApi($challenges);
    $api->verifyStatus = 403;
    $connector = $this->connector($api);
    try {
      $connector->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
      $this->fail('The connect went through');
    }
    catch (HttpException $error) {
      $this->assertSame(403, $error->status);
    }
    $this->assertNull($this->container->get('state')->get(Connector::STATE), 'no code is on its way');
    $this->assertSame(404, $challenges->serve()->getStatusCode(), 'single-use: not served again');
    $form = $this->container->get('form_builder')->getForm(ConnectForm::class);
    $this->assertArrayHasKey('business_name', $form, 'the form offers to connect again');
    $this->assertArrayNotHasKey('code', $form);

    $api->verifyStatus = NULL;
    $this->assertSame('connected', $connector->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com'));
  }

  /**
   * Tests that codes are six digits.
   */
  public function testCodesAreSixDigits(): void {
    $this->expectExceptionMessage('DECIONIS_CONNECT_CODE_INVALID');
    $this->connector(new ConnectApi(ChallengeController::create($this->container)))->confirm('12345');
  }

  /**
   * Tests that status reads the servers entitlement and disconnect forgets.
   */
  public function testStatusReadsTheServersEntitlementAndDisconnectForgets(): void {
    $api = new ConnectApi(ChallengeController::create($this->container));
    $connector = $this->connector($api);
    $connector->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $connector->refresh();
    $settings = $this->container->get(Settings::class);
    $this->assertTrue($settings->enforcementAllowed());
    $this->assertSame('trial', $settings->entitlement()['state']);

    $connector->disconnect();
    $this->assertFalse($settings->connected());
    $this->assertNull($this->container->get(Credentials::class)->apiKey());
    $this->assertNull($this->container->get('state')->get('decionis.secret.decionis_api_key'));
    $this->assertSame([], $settings->entitlement());
  }

  /**
   * An old workspace's success or failure cannot replace new entitlements.
   */
  public function testReconnectDuringStatusKeepsTheNewWorkspaceFree(): void {
    foreach ([200, 503] as $code) {
      $api = new ConnectApi(ChallengeController::create($this->container));
      $connector = $this->connector($api);
      $connector->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
      $settings = $this->container->get(Settings::class);
      $before = $settings->connectionIdentity();
      $api->statusCode = $code;
      $api->duringStatus = function () use ($api, $connector, $settings): void {
        $api->org = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $api->apiKey = 'dcy_bbbbbbbb_synthetic-new-workspace-key';
        $connector->connect('https://shop.example', 'site-uuid-1', 'Another Workspace', 'billing@example.com');
        $this->container->get('state')->set(Settings::ENTITLEMENT, ['enforcement' => FALSE, 'state' => 'free', 'connection_id' => $settings->connectionIdentity()]);
      };
      $connector->refresh();
      $this->assertNotSame($before, $settings->connectionIdentity());
      $this->assertSame('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', $settings->get('org_id'));
      $this->assertSame('free', $settings->entitlement()['state']);
      $this->assertFalse($settings->enforcementAllowed());
    }
  }

  /**
   * A real import of pre-connect config must leave the site's keys connected.
   */
  public function testPreConnectConfigImportKeepsStateConnection(): void {
    $this->installConfig(['system']);
    $active = $this->container->get('config.storage');
    $this->copyConfig($active, $this->container->get('config.storage.sync'));
    $api = new ConnectApi(ChallengeController::create($this->container));
    $connector = $this->connector($api);
    $connector->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $settings = $this->container->get(Settings::class);
    $identity = $settings->connectionIdentity();
    $credentials = $this->container->get(Credentials::class);
    $secret = $credentials->siteSecret();

    $this->configImporter()->import();
    $this->assertFalse($active->exists('key.key.' . Credentials::API_KEY), 'The actual importer removed the managed Key entity absent in pre-connect config.');
    $this->assertTrue($settings->connected());
    $this->assertSame($identity, $settings->connectionIdentity());
    $this->assertSame(ConnectApi::API_KEY, $credentials->apiKey());
    $this->assertSame($secret, $credentials->siteSecret());
    $this->assertSame(ConnectApi::ORG, $settings->get('org_id'));
    $this->assertArrayNotHasKey('org_id', $active->read('decionis.settings'));
    $this->assertArrayNotHasKey('api_key', $active->read('decionis.settings'));
    $connector->refresh();
    $this->assertTrue($settings->enforcementAllowed(), 'A later credential read still succeeds after import.');
  }

  /**
   * A database copy cannot use credentials or revoke the original site key.
   */
  public function testCopySuppressesCredentialsAndDisconnectIsLocal(): void {
    $api = new ConnectApi(ChallengeController::create($this->container));
    $connector = $this->connector($api);
    $connector->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $connector->refresh();
    $state = $this->container->get('state');
    $record = $state->get(Binding::STATE);
    $record['binding']['host'] = hash('sha256', 'a different machine and installation path');
    $state->set(Binding::STATE, $record);
    $before = count($api->calls);
    $settings = $this->container->get(Settings::class);
    $this->config('decionis.settings')->set('actions.refund_request', 'enforce')->save();
    $this->assertTrue($settings->copied());
    $this->assertFalse($settings->connected());
    $this->assertSame('shadow', $settings->mode('refund_request'));
    $this->assertNull($settings->connectionIdentity());
    $this->assertNull($this->container->get(Credentials::class)->apiKey());
    $this->assertNull($this->container->get(Credentials::class)->siteSecret());
    $connector->refresh();
    $connector->disconnect();
    $this->assertCount($before, $api->calls, 'Neither status nor remote revocation was attempted by the copy.');
    $this->assertFalse($settings->copied());
    $this->assertFalse($settings->connected());
  }

  /**
   * Even a connect awaiting its email code is bound to the original host.
   */
  public function testPendingConnectionCopyCannotConfirm(): void {
    $api = new ConnectApi(ChallengeController::create($this->container), FALSE);
    $connector = $this->connector($api);
    $connector->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $binding = $this->container->get(Binding::class);
    $record = $binding->record();
    $record['binding']['host'] = hash('sha256', 'copied before code confirmation');
    $this->container->get('state')->set(Binding::STATE, $record);
    $before = count($api->calls);
    try {
      $connector->confirm('123456');
      $this->fail('A copied pending site signed its production confirmation.');
    }
    catch (\LogicException $error) {
      $this->assertSame('DECIONIS_SITE_KEY_MISSING', $error->getMessage());
    }
    $this->assertCount($before, $api->calls);
  }

  /**
   * CLI and HTTP driver aliases identify the same physical database.
   */
  public function testDatabaseDriverAliasesPreserveTheConnection(): void {
    $api = new ConnectApi(ChallengeController::create($this->container));
    $this->connector($api)->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $settings = $this->container->get(Settings::class);
    $credentials = $this->container->get(Credentials::class);
    $identity = $settings->connectionIdentity();
    $key = $credentials->apiKey();
    $database = $this->container->get('database');
    $options = $database->getConnectionOptions();
    try {
      foreach ([$database->driver(), get_class($database)] as $driver) {
        $options['driver'] = $driver;
        $alias = $this->createMock(Connection::class);
        $alias->method('getConnectionOptions')->willReturn($options);
        $alias->method('driver')->willReturn($database->driver());
        $this->container->set('database', $alias);
        $this->assertFalse($settings->copied());
        $this->assertSame($identity, $settings->connectionIdentity());
        $this->assertSame($key, $credentials->apiKey());
      }
    }
    finally {
      $this->container->set('database', $database);
    }
  }

  /**
   * A CLI database copy on the same host and code path is still a copy.
   */
  public function testDatabaseCopyChangesTheHostLocalFingerprint(): void {
    $api = new ConnectApi(ChallengeController::create($this->container));
    $this->connector($api)->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $database = $this->container->get('database');
    $options = $database->getConnectionOptions();
    $options['database'] = (string) ($options['database'] ?? '') . '.staging-copy';
    $copy = $this->createMock(Connection::class);
    $copy->method('getConnectionOptions')->willReturn($options);
    $copy->method('driver')->willReturn($database->driver());
    $this->container->set('database', $copy);
    try {
      $this->assertTrue($this->container->get(Settings::class)->copied());
      $this->assertNull($this->container->get(Settings::class)->connectionIdentity());
      $this->assertNull($this->container->get(Credentials::class)->apiKey());
    }
    finally {
      $this->container->set('database', $database);
    }
    $this->assertFalse($this->container->get(Settings::class)->copied());
  }

  /**
   * Identical database names on separate MySQL sockets cannot share keys.
   */
  public function testDatabaseSocketChangeQuarantinesTheConnection(): void {
    $api = new ConnectApi(ChallengeController::create($this->container));
    $this->connector($api)->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $settings = $this->container->get(Settings::class);
    $credentials = $this->container->get(Credentials::class);
    $binding = $this->container->get(Binding::class);
    $state = $this->container->get('state');
    $record = $binding->record();
    $database = $this->container->get('database');
    $options = [
      'driver' => 'mysql',
      'host' => 'localhost',
      'port' => '3306',
      'database' => 'commerce',
      'prefix' => 'shop_',
      'unix_socket' => '/run/commerce/primary.sock',
    ];
    $connection = $this->createMock(Connection::class);
    $connection->method('getConnectionOptions')->willReturnCallback(static function () use (&$options): array {
      return $options;
    });
    $connection->method('driver')->willReturn('mysql');
    $this->container->set('database', $connection);
    try {
      $bound = $record;
      $bound['binding']['host'] = $binding->host();
      $state->set(Binding::STATE, $bound);
      $identity = $settings->connectionIdentity();
      $this->assertNotNull($identity);
      $this->assertNotNull($credentials->apiKey());

      $options['unix_socket'] = '/run/commerce/staging.sock';
      $this->assertTrue($settings->copied());
      $this->assertNull($settings->connectionIdentity());
      $this->assertNull($credentials->apiKey());

      $options['unix_socket'] = '/run/commerce/primary.sock';
      $this->assertFalse($settings->copied());
      $this->assertSame($identity, $settings->connectionIdentity());
    }
    finally {
      $this->container->set('database', $database);
      $state->set(Binding::STATE, $record);
    }
  }

  /**
   * Even reconnecting to the same workspace invalidates old queued work.
   */
  public function testReconnectRotatesTheLocalGeneration(): void {
    $api = new ConnectApi(ChallengeController::create($this->container));
    $connector = $this->connector($api);
    $connector->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $settings = $this->container->get(Settings::class);
    $identity = $settings->connectionIdentity();
    $connector->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $this->assertNotSame($identity, $settings->connectionIdentity());
    $this->assertSame($settings->connectionIdentity(), $settings->connectionIdentity(), 'Reads never create a new host or connection identity.');
  }

  /**
   * Pending facts cannot move to another evaluation endpoint through config.
   */
  public function testAuthorityUrlChangeInvalidatesOldIdentity(): void {
    $api = new ConnectApi(ChallengeController::create($this->container));
    $connector = $this->connector($api);
    $connector->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $connector->refresh();
    $settings = $this->container->get(Settings::class);
    $identity = $settings->connectionIdentity();
    $this->assertTrue($settings->enforcementAllowed());
    $this->config('decionis.settings')->set('api_base_url', 'https://another-authority.example')->save();
    $this->assertNotSame($identity, $settings->connectionIdentity());
    $this->assertFalse($settings->enforcementAllowed(), 'The new endpoint must not inherit the earlier connection\'s entitlement.');
  }

  /**
   * A reconnect in another PHP request invalidates the current worker's view.
   */
  public function testIdentityBypassesTheRequestStateCache(): void {
    $api = new ConnectApi(ChallengeController::create($this->container));
    $this->connector($api)->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $settings = $this->container->get(Settings::class);
    $identity = $settings->connectionIdentity();
    $state = $this->container->get('state');
    $record = $state->get(Binding::STATE);
    $old = $record;
    $record['generation'] = bin2hex(random_bytes(16));
    $record['org_id'] = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
    $this->container->get('keyvalue')->get('state')->set(Binding::STATE, $record);
    $this->assertSame($old, $state->get(Binding::STATE), 'The request-local State cache still has the old record.');
    $this->assertNotSame($identity, $settings->connectionIdentity());
    $this->assertSame($record['org_id'], $settings->get('org_id'));
  }

  /**
   * Long-lived workers read the new secret after another request reconnects.
   */
  public function testCredentialReadsBypassStaleKeyAndStateValues(): void {
    $api = new ConnectApi(ChallengeController::create($this->container));
    $this->connector($api)->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $credentials = $this->container->get(Credentials::class);
    $this->assertSame(ConnectApi::API_KEY, $credentials->apiKey());
    $key = $this->container->get('key.repository')->getKey(Credentials::API_KEY);
    $this->assertSame(ConnectApi::API_KEY, $key->getKeyValue(), 'Warm the separate Key and State caches too.');
    $store = $this->container->get('keyvalue')->get('state');
    $record = $store->get(Binding::STATE);
    $record['generation'] = bin2hex(random_bytes(16));
    $store->set('decionis.secret.' . Credentials::API_KEY, 'synthetic-reconnected-key');
    $store->set(Binding::STATE, $record);
    $this->assertSame(ConnectApi::API_KEY, $this->container->get('state')->get('decionis.secret.' . Credentials::API_KEY));
    $this->assertSame('synthetic-reconnected-key', $credentials->apiKey());
  }

  /**
   * Completing a legacy CLI baseline does not change its connection identity.
   */
  public function testLegacyHttpBaselineKeepsIdentity(): void {
    $api = new ConnectApi(ChallengeController::create($this->container));
    $this->connector($api)->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $binding = new BindingProbe($this->container->get('state'), $this->container->get('request_stack'));
    $record = $binding->record();
    unset($record['binding']);
    $this->container->get('state')->set(Binding::STATE, $record);
    $binding->url = NULL;
    $binding->baseline();
    $identity = $binding->identity(ConnectApi::ORG, Credentials::API_KEY);
    $binding->url = 'https://shop.example';
    $this->assertFalse($binding->copied());
    $this->assertSame($identity, $binding->identity(ConnectApi::ORG, Credentials::API_KEY));
    $binding->url = 'https://staging.example';
    $this->assertTrue($binding->copied());
  }

  /**
   * External providers must never fall back to a stale stored State secret.
   */
  public function testChangingKeyProviderRemovesStateRecovery(): void {
    $api = new ConnectApi(ChallengeController::create($this->container));
    $this->connector($api)->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $this->assertSame(ConnectApi::API_KEY, $this->container->get(Credentials::class)->apiKey());
    $identity = $this->container->get(Settings::class)->connectionIdentity();
    $key = $this->container->get('entity_type.manager')->getStorage('key')->load(Credentials::API_KEY);
    $this->assertInstanceOf(KeyInterface::class, $key);
    $key->setPlugin('key_provider', 'env');
    $key->getPlugin('key_provider')->setConfiguration(['env_variable' => 'DECIONIS_R12_MISSING_FIXTURE_KEY']);
    $key->save();
    $this->assertNotSame($identity, $this->container->get(Settings::class)->connectionIdentity());
    $key->delete();
    $this->assertNull($this->container->get(Credentials::class)->apiKey());
    $this->assertSame(ConnectApi::API_KEY, $this->container->get('state')->get('decionis.secret.' . Credentials::API_KEY), 'The old value exists but cannot be used.');
  }

  /**
   * URL and base path changes quarantine the exact same database and host.
   */
  public function testHttpUrlBindingGuardsActualCredentialReads(): void {
    $api = new ConnectApi(ChallengeController::create($this->container));
    $this->connector($api)->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $binding = new BindingProbe($this->container->get('state'), $this->container->get('request_stack'));
    $binding->bind('https://SHOP.example:443/store/');
    $settings = new Settings($this->container->get('config.factory'), $this->container->get('state'), $binding);
    $credentials = new Credentials($this->container->get('key.repository'), $this->container->get('entity_type.manager'), $this->container->get('state'), $this->container->get('config.factory'), $binding);
    $binding->url = 'https://shop.example/store';
    $this->assertTrue($settings->connected());
    $this->assertNotNull($credentials->apiKey());
    foreach (['https://staging.example/store', 'https://shop.example/copied-store', 'http://shop.example/store'] as $url) {
      $binding->url = $url;
      $this->assertTrue($settings->copied());
      $this->assertNull($settings->connectionIdentity());
      $this->assertNull($credentials->apiKey());
      $this->assertNull($credentials->siteSecret());
    }
  }

  /**
   * The update migrates a legacy connection and refuses unbound approvals.
   */
  public function testConnectionMigrationIsIdempotentAndRefusesOldHolds(): void {
    $api = new ConnectApi(ChallengeController::create($this->container));
    $this->connector($api)->connect('https://shop.example', 'site-uuid-1', 'Example Shop', 'billing@example.com');
    $this->config('decionis.settings')->set('org_id', ConnectApi::ORG)->set('api_key', Credentials::API_KEY)->set('site_key', Credentials::SITE_KEY)->save();
    $this->container->get('state')->delete(Binding::STATE);
    $hold = Hold::create(['action' => 'refund_request', 'state' => 'approved', 'grant_token' => 'synthetic-old-grant']);
    $hold->save();
    $manager = $this->container->get('entity.definition_update_manager');
    $manager->uninstallFieldStorageDefinition($manager->getFieldStorageDefinition('connection_id', 'decionis_hold'));

    Migration::run();
    $settings = $this->container->get(Settings::class);
    $identity = $settings->connectionIdentity();
    $this->assertTrue($settings->connected());
    $this->assertSame(ConnectApi::API_KEY, $this->container->get(Credentials::class)->apiKey());
    $this->assertNull($this->config('decionis.settings')->get('org_id'));
    $this->assertNotNull($manager->getFieldStorageDefinition('connection_id', 'decionis_hold'));
    $stored = $this->container->get('entity_type.manager')->getStorage('decionis_hold')->loadUnchanged($hold->id());
    $this->assertInstanceOf(Hold::class, $stored);
    $this->assertSame('refused', $stored->value('state'));
    $this->assertSame('CONNECTION_CHANGED', $stored->value('reason'));
    $this->assertSame('', $stored->value('grant_token'));
    Migration::run();
    $this->assertSame($identity, $settings->connectionIdentity());
  }

}
