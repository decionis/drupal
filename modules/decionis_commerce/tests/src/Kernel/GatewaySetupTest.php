<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis_commerce\Drush\Commands\DecionisCommands;
use Drupal\decionis_commerce\Gateway\Probe;
use Drupal\decionis_commerce\Gateway\Custody;
use Drupal\decionis_commerce\Gateway\Rollback;
use Drupal\decionis_commerce\Gateway\Setup;
use Drupal\decionis_commerce\Gateway\Step;
use Drupal\decionis_commerce\Gateway\Tier;
use Drupal\decionis_commerce\Gateway\Watchdog;
use Drupal\Tests\decionis_commerce\Support\RecordingRunner;
use Drupal\Tests\decionis_commerce\Support\ServerProbe;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Drush decionis:gateway-setup.
 *
 * AgentSafe takes the Fincra key over, the strongest way the account allows.
 * Drupal config then holds no Fincra secret key, and --dry-run changes
 * nothing.
 *
 * @group decionis
 */
final class GatewaySetupTest extends DecionisCommerceTestBase {

  private const SECRET = 'fixture-fincra-test-secret';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['decionis_fincra_test'];

  /**
   * The temporary home used for generated gateway setup files.
   */
  private string $home;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->home = sys_get_temp_dir() . '/decionis-gateway-' . bin2hex(random_bytes(4));
    $this->config('decionis.settings')->set('org_id', 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1')->save();
    $this->container->get(Credentials::class)->store('api_key', Credentials::API_KEY, 'Decionis API key', 'dcy_aaaaaaaa_synthetic-gateway-test-key');
    PaymentGateway::create([
      'id' => 'fincra',
      'label' => 'Fincra',
      'plugin' => 'fincra_standard',
      'configuration' => ['mode' => 'test', 'secret_key_test' => self::SECRET, 'public_key_test' => 'pk_test_fixture'],
    ])->save();
  }

  /**
   * A setup on a server described by the probe.
   */
  private function service(Probe $probe): Setup {
    return new Setup($this->container->get('entity_type.manager'), $this->container->get('Drupal\decionis\Gate\Settings'), $this->container->get(Credentials::class), $this->container->get('state'), $probe, 1);
  }

  /**
   * The setup options.
   *
   * @return array{user: string, port: int, mode: string, version: string, home: string, files: string}
   *   The options.
   */
  private function options(): array {
    return ['user' => 'agentsafe', 'port' => 8787, 'mode' => 'shadow', 'version' => '0.7.0', 'home' => $this->home, 'files' => '/var/www/files'];
  }

  /**
   * The gateway's plugin configuration as stored.
   *
   * @return array<string, mixed>
   *   The configuration.
   */
  private function gatewayConfig(): array {
    return $this->container->get('config.factory')->get('commerce_payment.commerce_payment_gateway.fincra')->get('configuration') ?? [];
  }

  /**
   * Tests that the tier follows what the account may do.
   */
  public function testTheTierFollowsThePermissions(): void {
    $this->assertSame(Tier::Root, $this->service(new ServerProbe(0))->tier('agentsafe', '/files'));
    $this->assertSame(Tier::Root, $this->service(new ServerProbe(1000, sudo: TRUE))->tier('agentsafe', '/files'));
    $this->assertSame(Tier::Separate, $this->service(new ServerProbe(1000, dedicated: 1001, web: 1000))->tier('agentsafe', '/files'));
    $this->assertSame(Tier::Web, $this->service(new ServerProbe(1000, dedicated: 1001, web: 1001))->tier('agentsafe', '/files'), 'the web server runs as the AgentSafe user: the key is within its reach');
    $this->assertSame(Tier::Web, $this->service(new ServerProbe(1000))->tier('agentsafe', '/files'));
  }

  /**
   * Tests that the root plan installs the package and hands the key over as a systemd credential.
   */
  public function testRootUsesThePackageAndSystemdCredential(): void {
    $plan = $this->service(new ServerProbe(0))->plan(Tier::Root, $this->options());
    $described = implode("\n", array_map(static fn (Step $step): string => $step->describe(), $plan));
    $this->assertStringContainsString("'install' '-d' '-m' '0700' '-o' 'root' '-g' 'root' '/var/cache/agentsafe'", $described);
    $this->assertStringContainsString("'dpkg' '-i' '/var/cache/agentsafe/agentsafe_0.7.0_amd64.deb'", $described);
    $this->assertStringContainsString('releases/download/v0.5.0/agentsafe_0.7.0_amd64.deb', $described, 'runtime 0.7.0 rides on release v0.5.0');
    $this->assertStringContainsString('releases/download/v0.5.0/SHA256SUMS', $described);
    $this->assertStringContainsString("sha256sum -c --strict -' 'sh' '/var/cache/agentsafe' 'agentsafe_0.7.0_amd64.deb'", $described, 'the package must have its own checksum line');
    $this->assertStringNotContainsString('/tmp', $described);
    $this->assertStringNotContainsString('--ignore-missing', $described);
    $this->assertStringContainsString("'systemctl' 'enable' '--now' 'agentsafe'", $described);
    $this->assertStringNotContainsString("'sudo'", $described, 'root needs no sudo');
    $config = array_values(array_filter($plan, static fn (Step $step): bool => str_ends_with((string) $step->path, 'agentsafe.yaml')))[0];
    $this->assertSame(0644, $config->mode, 'The packaged DynamicUser service can read its non-secret YAML.');
    $this->assertStringContainsString("'install' '-d' '-m' '0755' '-o' 'root' '-g' 'root' '/etc/agentsafe'", $described);
    $this->assertStringContainsString('file: "/run/credentials/agentsafe.service/fincra-api-key"', (string) $config->content);
    $this->assertStringNotContainsString(self::SECRET, $described, 'a printed plan never shows the key');
  }

  /**
   * Tests that the sudo tier uses sudo -n with keys on standard input.
   */
  public function testSudoRunsEveryRootStepWithoutPrompting(): void {
    $plan = $this->service(new ServerProbe(1000, sudo: TRUE))->plan(Tier::Root, $this->options());
    foreach ($plan as $step) {
      $this->assertNotNull($step->command, $step->description . ' runs as a command');
      $this->assertSame(['sudo', '-n'], array_slice((array) $step->command, 0, 2), $step->description . ' runs through sudo -n');
    }
    $key = array_values(array_filter($plan, static fn (Step $step): bool => in_array('/etc/agentsafe/fincra-api-key', (array) $step->command, TRUE)))[0];
    $this->assertSame(['sudo', '-n', 'install', '-D', '-m', '0600', '-o', 'root', '-g', 'root', '/dev/stdin', '/etc/agentsafe/fincra-api-key'], $key->command);
    $this->assertSame(self::SECRET, $key->content, 'the key reaches install on standard input');
    $described = implode("\n", array_map(static fn (Step $step): string => $step->describe(), $plan));
    $this->assertStringNotContainsString(self::SECRET, $described, 'never in the printed plan or the process list');
    $this->assertStringContainsString('< [secret, not shown]', $described);
  }

  /**
   * Tests that an RPM system on arm64 gets the RPM package for its machine.
   */
  public function testAnRpmArmServerGetsItsOwnPackage(): void {
    $plan = $this->service(new ServerProbe(0, arch: 'arm64', packager: 'rpm'))->plan(Tier::Root, $this->options());
    $described = implode("\n", array_map(static fn (Step $step): string => $step->describe(), $plan));
    $this->assertStringContainsString('releases/download/v0.5.0/agentsafe-0.7.0-1.aarch64.rpm', $described);
    $this->assertStringContainsString("'rpm' '-U' '--replacepkgs' '/var/cache/agentsafe/agentsafe-0.7.0-1.aarch64.rpm'", $described);
    $this->assertStringContainsString("'sh' '/var/cache/agentsafe' 'agentsafe-0.7.0-1.aarch64.rpm'", $described, 'checked against its own checksum line');
    $this->assertStringNotContainsString('dpkg', $described);

    $deb = $this->service(new ServerProbe(0, arch: 'arm64'))->plan(Tier::Root, $this->options());
    $this->assertStringContainsString('agentsafe_0.7.0_arm64.deb', implode("\n", array_map(static fn (Step $step): string => $step->describe(), $deb)));
  }

  /**
   * Tests that a machine or version setup cannot serve is refused, not guessed.
   */
  public function testAnUnsupportedMachineOrUnknownVersionIsRefused(): void {
    foreach ([[new ServerProbe(0, arch: NULL), Tier::Root, [], 'x86_64 and arm64'], [new ServerProbe(0, packager: NULL), Tier::Root, [], 'Neither dpkg nor rpm'], [new ServerProbe(1000), Tier::Web, ['version' => '9.9.9'], '--agentsafe-release']] as [$probe, $tier, $options, $message]) {
      try {
        $this->service($probe)->plan($tier, $options + $this->options());
        $this->fail('expected a refusal: ' . $message);
      }
      catch (\RuntimeException $error) {
        $this->assertStringContainsString($message, $error->getMessage());
      }
    }
    $named = $this->service(new ServerProbe(1000))->plan(Tier::Web, ['version' => '9.9.9', 'release' => 'v9.0.0'] + $this->options());
    $this->assertMatchesRegularExpression('/AGENTSAFE_RELEASE_TAG=\W*v9\.0\.0\W/', implode("\n", array_map(static fn (Step $step): string => $step->describe(), $named)));
  }

  /**
   * Tests that Presence is configured only with a named approver, safely.
   */
  public function testPresenceNeedsNamedApprover(): void {
    $setup = $this->service(new ServerProbe(1000));
    $yaml = static fn (array $plan): string => (string) array_values(array_filter($plan, static fn (Step $step): bool => str_ends_with((string) $step->path, 'agentsafe.yaml')))[0]->content;
    $without = $yaml($setup->plan(Tier::Web, $this->options()));
    $this->assertStringNotContainsString('managed: true', $without, 'AgentSafe refuses managed escalations with no approver');
    $with = $yaml($setup->plan(Tier::Web, ['approver' => 'owner@example.com'] + $this->options()));
    $this->assertStringContainsString("presence:\n  managed: true\n  approverId: \"owner@example.com\"\n", $with);
    try {
      $setup->plan(Tier::Web, ['approver' => "owner\nmode: shadow"] + $this->options());
      $this->fail('expected a refusal');
    }
    catch (\RuntimeException $error) {
      $this->assertStringContainsString('one line', $error->getMessage());
    }
  }

  /**
   * Tests that the user installer is pinned to the release tag.
   */
  public function testTheUserInstallerIsPinnedToTheReleaseTag(): void {
    $plan = $this->service(new ServerProbe(1000))->plan(Tier::Web, $this->options());
    $described = implode("\n", array_map(static fn (Step $step): string => $step->describe(), $plan));
    $this->assertStringContainsString('agent-safe-pipeline/v0.5.0/packaging/install.sh', $described);
    $this->assertMatchesRegularExpression('/AGENTSAFE_RELEASE_TAG=\W*v0\.5\.0\W/', $described);
    $this->assertStringNotContainsString('/master/', $described);
  }

  /**
   * Tests that with several Fincra gateways only the chosen one's key moves.
   */
  public function testSeveralGatewaysNeedChoosingAndOnlyThatKeyMoves(): void {
    PaymentGateway::create([
      'id' => 'fincra_ng',
      'label' => 'Fincra NG',
      'plugin' => 'fincra_standard',
      'configuration' => ['mode' => 'test', 'secret_key_test' => 'bbbbbbbbbbbbbbbb', 'public_key_test' => 'pk_test_fixture_ng'],
    ])->save();
    $probe = new ServerProbe(1000, answering: TRUE);
    $setup = $this->service($probe);
    try {
      $setup->plan(Tier::Web, $this->options());
      $this->fail('expected a choice of gateway');
    }
    catch (\RuntimeException $error) {
      $this->assertStringContainsString('--gateway', $error->getMessage());
    }
    $plan = $setup->plan(Tier::Web, ['gateway' => 'fincra_ng'] + $this->options());
    $setup->apply(Tier::Web, $plan, 8787, new RecordingRunner(), FALSE, 'fincra_ng');
    $config = $this->container->get('config.factory');
    $this->assertSame('', $config->get('commerce_payment.commerce_payment_gateway.fincra_ng')->get('configuration.secret_key_test'));
    $this->assertSame(self::SECRET, $this->gatewayConfig()['secret_key_test'], 'the other gateway keeps its key');
    $this->assertSame('bbbbbbbbbbbbbbbb', file_get_contents($this->home . '/.config/agentsafe/fincra-api-key'));
  }

  /**
   * Tests that the key stays in Drupal when the started AgentSafe never answers.
   */
  public function testTheKeyStaysWhenAgentSafeDoesNotAnswer(): void {
    $setup = $this->service(new ServerProbe(1000));
    $plan = $setup->plan(Tier::Web, $this->options());
    try {
      $setup->apply(Tier::Web, $plan, 8787, new RecordingRunner());
      $this->fail('expected AgentSafe to be required');
    }
    catch (\RuntimeException $error) {
      $this->assertStringContainsString('does not answer HTTP', $error->getMessage());
    }
    $this->assertSame(self::SECRET, $this->gatewayConfig()['secret_key_test']);
  }

  /**
   * Tests that the separate-user plan says where each key comes from.
   */
  public function testTheSeparateUserPlanSaysWhereEachKeyComesFrom(): void {
    $plan = $this->service(new ServerProbe(1000, dedicated: 1001, web: 1000))->plan(Tier::Separate, $this->options());
    $printed = implode("\n", array_map(static fn (Step $step): string => $step->description . ': ' . $step->describe(), $plan));
    $this->assertStringContainsString('the test key of gateway "fincra", from the Fincra dashboard', $printed);
    $this->assertStringContainsString('drush key:value-get decionis_api_key', $printed);
    $this->assertStringContainsString('owned by agentsafe', $printed);
    $this->assertStringNotContainsString(self::SECRET, $printed);
  }

  /**
   * Tests that the dry run prints the commands and changes nothing.
   */
  public function testDryRunPrintsTheCommandsAndChangesNothing(): void {
    $before = $this->snapshot();
    $runner = new RecordingRunner();
    $commands = new DecionisCommands($this->container->get('Drupal\decionis_commerce\Sandbox\Sandbox'), $this->container->get('Drupal\decionis_commerce\Replay\Replay'), $this->container->get('Drupal\decionis\Gate\Remote'), $this->container->get('Drupal\decionis\Gate\Settings'), $this->container->get('entity_type.manager'), $this->service(new ServerProbe(0)), $runner);
    $output = new BufferedOutput();
    $commands->setOutput($output);
    $status = $commands->gatewaySetup(['dry-run' => TRUE] + ['user' => 'agentsafe', 'port' => 8787, 'mode' => 'shadow', 'agentsafe-version' => '0.7.0', 'installed' => FALSE]);
    $printed = $output->fetch();
    $this->assertSame(0, $status);
    $this->assertStringContainsString('Permission tier: root', $printed);
    $this->assertStringContainsString("'dpkg' '-i'", $printed);
    $this->assertStringContainsString('Dry run: nothing was changed.', $printed);
    $this->assertStringNotContainsString(self::SECRET, $printed);
    $this->assertSame([], $runner->ran, 'no step ran');
    $this->assertSame($before, $this->snapshot(), 'the database is unchanged');
    $this->assertSame(self::SECRET, $this->gatewayConfig()['secret_key_test']);
  }

  /**
   * Tests that the web-account tier moves the Fincra key into AgentSafe.
   */
  public function testDrupalConfigHoldsNoFincraSecretKeyAfterwards(): void {
    $setup = $this->service(new ServerProbe(1000, answering: TRUE));
    $plan = $setup->plan(Tier::Web, $this->options());
    $runner = new RecordingRunner();
    $setup->apply(Tier::Web, $plan, 8787, $runner);

    $config = $this->gatewayConfig();
    $this->assertSame('', $config['secret_key_test']);
    $this->assertTrue($config['secret_key_held_by_gateway']);
    $this->assertSame('http://127.0.0.1:8787', $config['api_base_url']);
    $export = '';
    foreach ($this->container->get('config.storage')->listAll() as $name) {
      $export .= json_encode($this->container->get('config.storage')->read($name));
    }
    $this->assertStringNotContainsString(self::SECRET, $export, 'Drupal config holds no Fincra secret key');

    $key = $this->home . '/.config/agentsafe/fincra-api-key';
    $this->assertSame(self::SECRET, file_get_contents($key));
    $this->assertSame('0600', substr(sprintf('%o', fileperms($key)), -4));
    $yaml = (string) file_get_contents($this->home . '/.config/agentsafe/agentsafe.yaml');
    $this->assertStringContainsString('listen: "127.0.0.1:8787"', $yaml);
    $this->assertStringContainsString('upstream: "https://sandboxapi.fincra.com"', $yaml);
    $this->assertStringContainsString('header: api-key', $yaml);
    $this->assertStringContainsString('path: /collections/refund', $yaml);
    $this->assertStringContainsString('unmatched: passthrough', $yaml);
    $this->assertContains(['systemctl', '--user', 'enable', '--now', 'agentsafe'], $runner->ran);
    $this->assertContains(['systemctl', '--user', 'daemon-reload'], $runner->ran);
    $this->assertSame('web', $setup->protection()['tier']);
    $this->assertStringStartsWith('Amber:', $setup->protection()['protection']);
  }

  /**
   * Tests that the separate-user tier moves the key only once AgentSafe answers.
   */
  public function testTheSeparateUserTierWaitsForAgentSafe(): void {
    $probe = new ServerProbe(1000, dedicated: 1001, web: 1000);
    $setup = $this->service($probe);
    $plan = $setup->plan(Tier::Separate, $this->options());
    try {
      $setup->apply(Tier::Separate, $plan, 8787, new RecordingRunner());
      $this->fail('expected the operator to run the steps');
    }
    catch (\RuntimeException) {
    }
    try {
      $setup->apply(Tier::Separate, $plan, 8787, new RecordingRunner(), TRUE);
      $this->fail('expected AgentSafe to be required');
    }
    catch (\RuntimeException) {
    }
    $this->assertSame(self::SECRET, $this->gatewayConfig()['secret_key_test'], 'the key stays until AgentSafe answers');
    $probe->answering = TRUE;
    $setup->apply(Tier::Separate, $plan, 8787, new RecordingRunner(), TRUE);
    $this->assertSame('', $this->gatewayConfig()['secret_key_test']);
    $this->assertSame('separate', $setup->protection()['tier']);
  }

  /**
   * Readiness never substitutes for a user service that survives logout.
   */
  public function testLingerIsRequiredEvenForInstalledUserServices(): void {
    foreach ([[Tier::Web, FALSE], [Tier::Web, TRUE], [Tier::Separate, TRUE]] as [$tier, $installed]) {
      $setup = $this->service(new ServerProbe(1000, dedicated: 1001, web: 1000, answering: TRUE, lingering: FALSE));
      $before = $this->snapshot();
      $plan = $setup->plan($tier, $this->options());
      $runner = new RecordingRunner();
      try {
        $setup->apply($tier, $plan, 8787, $runner, $installed);
        $this->fail('Expected a lifetime preflight refusal.');
      }
      catch (\RuntimeException $error) {
        $this->assertStringContainsString('loginctl enable-linger', $error->getMessage());
      }
      $this->assertSame([], $runner->ran);
      $this->assertSame(self::SECRET, $this->gatewayConfig()['secret_key_test']);
      $this->assertSame($before, $this->snapshot());
    }
  }

  /**
   * The inactive environment's key is never discarded during custody setup.
   */
  public function testBothPopulatedEnvironmentKeysRefuseSetup(): void {
    $gateway = PaymentGateway::load('fincra');
    $configuration = $gateway->getPlugin()->getConfiguration();
    $configuration['secret_key_live'] = 'fixture-inactive-live-key';
    $gateway->setPluginConfiguration($configuration)->save();
    $before = $this->snapshot();
    try {
      $this->service(new ServerProbe(1000, answering: TRUE))->plan(Tier::Web, $this->options());
      $this->fail('Expected an inactive key storage refusal.');
    }
    catch (\RuntimeException $error) {
      $this->assertStringContainsString('Both test and live', $error->getMessage());
    }
    $this->assertSame($before, $this->snapshot());
  }

  /**
   * Every non-secret byte is visible, including writes carried through sudo.
   */
  public function testDryRunContainsEveryNonSecretFile(): void {
    foreach ([new ServerProbe(0), new ServerProbe(1000, sudo: TRUE), new ServerProbe(1000)] as $probe) {
      $tier = $probe->uid() === 0 || $probe->sudo() ? Tier::Root : Tier::Web;
      $plan = $this->service($probe)->plan($tier, $this->options());
      $printed = implode("\n", array_map(static fn (Step $step): string => $step->describe(), $plan));
      foreach ($plan as $step) {
        if ($step->content !== NULL) {
          $step->secret ? $this->assertStringNotContainsString($step->content, $printed) : $this->assertStringContainsString($step->content, $printed);
        }
      }
    }
  }

  /**
   * Rollback uses Key at runtime and never writes that value during saves.
   */
  public function testRollbackReadsProtectedFileWithoutExportingIt(): void {
    $setup = $this->service(new ServerProbe(1000, answering: TRUE));
    $setup->apply(Tier::Web, $setup->plan(Tier::Web, $this->options()), 8787, new RecordingRunner());
    $rollback = $this->container->get(Rollback::class);
    $this->assertTrue($rollback->apply());
    $gateway = PaymentGateway::load('fincra');
    $this->assertSame(self::SECRET, $gateway->getPlugin()->getConfiguration()['secret_key_test']);
    $this->assertFalse($gateway->getPlugin()->getConfiguration()['secret_key_held_by_gateway']);
    $gateway->set('label', 'Renamed Fincra')->save();
    $export = json_encode($this->container->get('config.storage')->read('commerce_payment.commerce_payment_gateway.fincra'));
    $this->assertStringNotContainsString(self::SECRET, $export);
    $this->assertSame('', $this->container->get('config.storage')->read('commerce_payment.commerce_payment_gateway.fincra')['configuration']['secret_key_test']);
    $this->assertFalse($this->container->get('state')->get(Setup::STATE)['custody']);
  }

  /**
   * Unreadable tiers print exact private-file steps for their host.
   */
  public function testUnreadableRollbackPrintsHostSteps(): void {
    $setup = $this->service(new ServerProbe(1000, answering: TRUE));
    $setup->apply(Tier::Web, $setup->plan(Tier::Web, $this->options()), 8787, new RecordingRunner());
    $state = $this->container->get('state');
    $record = $state->get(Setup::STATE);
    $record['key_file'] = '/etc/agentsafe/fincra-api-key';
    $state->set(Setup::STATE, $record);
    $rollback = new Rollback($this->container->get('entity_type.manager'), $state, $this->container->get('config.factory'), $this->container->get(Custody::class), new ServerProbe(1000, web: 1000));
    $steps = implode("\n", $rollback->describe());
    $this->assertStringContainsString("'install' '-m' '0600' '-o' 'www' '/etc/agentsafe/fincra-api-key' '/var/lib/decionis/fincra-fincra-test.key'", $steps);
    $this->assertStringContainsString("drush decionis:gateway-rollback --key-file='/var/lib/decionis/fincra-fincra-test.key'", $steps);
    $this->assertStringNotContainsString(self::SECRET, $steps);
    $this->assertFalse($rollback->apply());
    $this->assertTrue($state->get(Setup::STATE)['custody']);
  }

  /**
   * A test key cannot silently become the live gateway's key after setup.
   */
  public function testCustodyRefusesEnvironmentChanges(): void {
    $setup = $this->service(new ServerProbe(1000, answering: TRUE));
    $setup->apply(Tier::Web, $setup->plan(Tier::Web, $this->options()), 8787, new RecordingRunner());
    $gateway = PaymentGateway::load('fincra');
    $configuration = $gateway->getPlugin()->getConfiguration();
    $configuration['mode'] = 'live';
    $this->expectExceptionMessage('bound to its test or live environment');
    $gateway->setPluginConfiguration($configuration)->save();
  }

  /**
   * Copies cannot reach the original host gateway or retrieve its key.
   */
  public function testCopiedGatewayCannotUseProductionCustody(): void {
    $binding = $this->container->get(Binding::class);
    $binding->bind('https://shop.example');
    $setup = $this->service(new ServerProbe(1000, answering: TRUE));
    $setup->apply(Tier::Web, $setup->plan(Tier::Web, $this->options()), 8787, new RecordingRunner());
    $record = $binding->record();
    $record['binding']['host'] = hash('sha256', 'copied host');
    $this->container->get('state')->set(Binding::STATE, $record);
    $this->container->get('config.factory')->reset('commerce_payment.commerce_payment_gateway.fincra');
    $this->container->get('entity_type.manager')->getStorage('commerce_payment_gateway')->resetCache();
    $gateway = PaymentGateway::load('fincra');
    $this->assertFalse($gateway->status());
    $this->assertSame('http://127.0.0.1:1', $gateway->getPlugin()->getConfiguration()['api_base_url']);
    $this->expectExceptionMessage('copied site cannot retrieve');
    $this->container->get(Rollback::class)->apply();
  }

  /**
   * A legitimate reconnect stops stale remote authority but permits recovery.
   */
  public function testSameSiteReconnectKeepsRollbackAndWatchdogAvailable(): void {
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    $binding = $this->container->get(Binding::class);
    $binding->bind('https://shop.example');
    foreach (['reconnect', 'provider', 'authority'] as $change) {
      $setup = $this->service(new ServerProbe(1000, answering: TRUE));
      $setup->apply(Tier::Web, $setup->plan(Tier::Web, $this->options()), 8787, new RecordingRunner());
      if ($change === 'reconnect') {
        $binding->bind('https://shop.example');
      }
      elseif ($change === 'provider') {
        $this->container->get(Credentials::class)->store('api_key', Credentials::API_KEY, 'Decionis API key', 'synthetic-reconnected-gateway-key');
      }
      else {
        $this->config('decionis.settings')->set('api_base_url', 'https://another-authority.example')->save();
      }
      $custody = $this->container->get(Custody::class);
      $record = $this->container->get('state')->get(Setup::STATE);
      $this->assertTrue($custody->owned($record));
      $this->assertFalse($custody->valid($record));
      $this->container->get('config.factory')->reset('commerce_payment.commerce_payment_gateway.fincra');
      $this->container->get('entity_type.manager')->getStorage('commerce_payment_gateway')->resetCache();
      $this->assertFalse(PaymentGateway::load('fincra')->status(), 'The old AgentSafe authority is not used.');
      $watchdog = new Watchdog($this->container->get('state'), $this->container->get('entity_type.manager'), $custody, $this->container->get('plugin.manager.mail'), $this->container->get('logger.factory'), new ServerProbe(1000, answering: TRUE));
      $watchdog->run();
      $this->assertTrue($this->container->get('state')->get(Watchdog::STATE)['answering']);
      $this->assertSame(2, $watchdog->requirements()['decionis_agentsafe']['severity']);
      $this->assertTrue($this->container->get(Rollback::class)->apply());
      $gateway = PaymentGateway::load('fincra');
      $this->assertTrue($gateway->status(), 'Rollback restores the original enabled status.');
      $this->assertSame(self::SECRET, $gateway->getPlugin()->getConfiguration()['secret_key_test']);
      $this->assertSame([], $watchdog->requirements());
    }
    // The returned payment key is local site custody, independent of later
    // Decionis key rotations. It still never enters exported configuration.
    $binding->bind('https://shop.example');
    $this->container->get('config.factory')->reset('commerce_payment.commerce_payment_gateway.fincra');
    $this->container->get('entity_type.manager')->getStorage('commerce_payment_gateway')->resetCache();
    $this->assertSame(self::SECRET, PaymentGateway::load('fincra')->getPlugin()->getConfiguration()['secret_key_test']);
    $this->assertSame('', $this->container->get('config.storage')->read('commerce_payment.commerce_payment_gateway.fincra')['configuration']['secret_key_test']);
  }

  /**
   * Reconnecting a URL copy on the same host cannot retrieve the original key.
   */
  public function testUrlCopyReconnectCannotRecoverOriginalCustody(): void {
    $binding = $this->container->get(Binding::class);
    $binding->bind('https://shop.example');
    $setup = $this->service(new ServerProbe(1000, answering: TRUE));
    $setup->apply(Tier::Web, $setup->plan(Tier::Web, $this->options()), 8787, new RecordingRunner());
    $binding->bind('https://staging.example');
    $this->assertFalse($binding->copied(), 'The copy established its own new connection.');
    $this->assertFalse($this->container->get(Custody::class)->owned($this->container->get('state')->get(Setup::STATE)));
    $this->expectExceptionMessage('copied site cannot retrieve');
    $this->container->get(Rollback::class)->apply();
  }

  /**
   * A replacement gateway cannot inherit a deleted gateway's runtime key.
   */
  public function testReusedGatewayNameDoesNotInheritCustody(): void {
    $setup = $this->service(new ServerProbe(1000, answering: TRUE));
    $setup->apply(Tier::Web, $setup->plan(Tier::Web, $this->options()), 8787, new RecordingRunner());
    $previous = PaymentGateway::load('fincra');
    $uuid = $previous->uuid();
    $previous->delete();
    $replacement = PaymentGateway::create(['id' => 'fincra', 'label' => 'Replacement', 'plugin' => 'fincra_standard', 'configuration' => ['secret_key_test' => 'synthetic-replacement-payment-key']]);
    $replacement->save();
    $this->assertNotSame($uuid, $replacement->uuid());
    $this->assertSame('synthetic-replacement-payment-key', PaymentGateway::load('fincra')->getPlugin()->getConfiguration()['secret_key_test']);
  }

  /**
   * Another gateway cannot overwrite the key file still used by rollback.
   */
  public function testAnotherGatewayCannotReplaceTheBoundKey(): void {
    $setup = $this->service(new ServerProbe(1000, answering: TRUE));
    $setup->apply(Tier::Web, $setup->plan(Tier::Web, $this->options()), 8787, new RecordingRunner());
    $this->container->get(Rollback::class)->apply();
    PaymentGateway::create(['id' => 'other_fincra', 'label' => 'Other Fincra', 'plugin' => 'fincra_standard', 'configuration' => ['secret_key_test' => 'synthetic-other-gateway-key']])->save();
    $this->expectExceptionMessage('already bound to another Fincra gateway');
    $setup->plan(Tier::Web, ['gateway' => 'other_fincra'] + $this->options());
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->home)) {
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->home, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
      foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
      }
      rmdir($this->home);
    }
    parent::tearDown();
  }

}
