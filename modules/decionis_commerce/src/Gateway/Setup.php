<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Gateway;

use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\decionis\Connect\Binding;
use Drupal\decionis\Gate\Credentials;
use Drupal\decionis\Gate\Settings;

/**
 * Drush decionis:gateway-setup: AgentSafe in front of Fincra.
 *
 * It installs AgentSafe the strongest way this account's permissions allow,
 * moves the Fincra secret key into AgentSafe's key file, points Commerce
 * Fincra at the local proxy, and records the protection level. The plan is
 * the same in a dry run, which prints it and changes nothing.
 */
final class Setup {

  public const STATE = 'decionis.protection';
  public const PLUGIN = 'fincra_standard';
  public const RELEASES = 'https://github.com/decionis/agent-safe-pipeline/releases/download';
  public const INSTALLER = 'https://raw.githubusercontent.com/decionis/agent-safe-pipeline/%s/packaging/install.sh';

  /**
   * The repository release that carries each AgentSafe runtime version.
   *
   * The runtime is versioned on its own; its packages ride on releases
   * tagged by the library's version (the release listing, 2026-11-02).
   */
  public const CARRIERS = ['0.7.0' => 'v0.5.0', '0.6.0' => 'v0.4.1'];

  /**
   * Where the root install downloads to: root's own, never a shared /tmp.
   */
  public const DOWNLOADS = '/var/cache/agentsafe';

  /**
   * Non-secret snapshot checked again before custody changes.
   *
   * @var array<string, mixed>
   */
  private array $planned = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Settings $settings,
    private readonly Credentials $credentials,
    private readonly StateInterface $state,
    private readonly Probe $probe = new Probe(),
    private readonly int $waitSeconds = 10,
  ) {}

  /**
   * The tier this account allows, given the name of a dedicated user.
   */
  public function tier(string $user, string $filesPath): Tier {
    $uid = $this->probe->uid();
    if ($uid === 0 || $this->probe->sudo()) {
      return Tier::Root;
    }
    $dedicated = $this->probe->user($user);
    $web = $this->probe->owner($filesPath);
    // The key is out of Drupal's reach only when neither this process nor
    // the web server runs as the AgentSafe user.
    if ($dedicated !== NULL && $dedicated['uid'] !== $uid && $dedicated['uid'] !== $web) {
      return Tier::Separate;
    }
    return Tier::Web;
  }

  /**
   * Selects the patched Fincra gateway whose key AgentSafe will hold.
   *
   * Refuses when Commerce Fincra lacks the patches or the choice is unclear.
   *
   * One AgentSafe holds one Fincra key: with several gateways, the operator
   * names the one whose key moves, and the others keep theirs.
   */
  public function gateway(?string $id = NULL): PaymentGatewayInterface {
    $gateways = array_filter(
      $this->entityTypeManager->getStorage('commerce_payment_gateway')->loadByProperties(['plugin' => self::PLUGIN]),
      static fn ($gateway): bool => $gateway instanceof PaymentGatewayInterface,
    );
    if ($gateways === []) {
      throw new \RuntimeException('No Commerce Fincra gateway is configured on this site.');
    }
    if ($id !== NULL && !isset($gateways[$id])) {
      throw new \RuntimeException(sprintf('No Commerce Fincra gateway has the id "%s". Choose one of: %s.', $id, implode(', ', array_keys($gateways))));
    }
    if ($id === NULL && count($gateways) > 1) {
      throw new \RuntimeException(sprintf('This site has %d Commerce Fincra gateways; choose one with --gateway (%s).', count($gateways), implode(', ', array_keys($gateways))));
    }
    $gateway = $id === NULL ? reset($gateways) : $gateways[$id];
    $defaults = $gateway->getPlugin()->defaultConfiguration();
    if (!array_key_exists('api_base_url', $defaults) || !array_key_exists('secret_key_held_by_gateway', $defaults)) {
      throw new \RuntimeException('Commerce Fincra needs the API base URL and held-key patches first: see "Keep your payment key out of Drupal" in the Decionis README.');
    }
    return $gateway;
  }

  /**
   * The steps, in order.
   *
   * @param \Drupal\decionis_commerce\Gateway\Tier $tier
   *   The protection level supported by this server account.
   * @param array{user: string, port: int, mode: string, version: string, home: string, files: string, gateway?: ?string, release?: ?string, approver?: ?string} $options
   *   The setup options.
   *
   * @return list<Step>
   *   The plan.
   */
  public function plan(Tier $tier, array $options): array {
    if ($options['port'] < 1024 || $options['port'] > 65535 || !in_array($options['mode'], ['shadow', 'enforcement'], TRUE)) {
      throw new \RuntimeException('Use a loopback port from 1024 to 65535 and mode shadow or enforcement.');
    }
    if (preg_match('#^/[A-Za-z0-9_./-]+$#', $options['home']) !== 1 || preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]*[$]?$/', $options['user']) !== 1) {
      throw new \RuntimeException('The AgentSafe home must be an absolute path without spaces or shell syntax; name an existing system user.');
    }
    $gateway = $this->gateway($options['gateway'] ?? NULL);
    $previous = $this->protection();
    if (!empty($previous['gateway']) && $previous['gateway'] !== (string) $gateway->id()) {
      $old = $this->entityTypeManager->getStorage('commerce_payment_gateway')->load((string) $previous['gateway']);
      if ($old instanceof PaymentGatewayInterface && Custody::matches($previous, $old)) {
        throw new \RuntimeException('AgentSafe or its rollback provider is already bound to another Fincra gateway. Retire that gateway binding before moving another key.');
      }
    }
    $configuration = $gateway->getPlugin()->getConfiguration();
    self::oneKey($configuration);
    $live = ($configuration['mode'] ?? 'test') === 'live';
    $secret = (string) ($configuration[$live ? 'secret_key_live' : 'secret_key_test'] ?? '');
    $decionis = $this->credentials->apiKey();
    if ($secret === '' || $decionis === NULL || !$this->settings->connected()) {
      throw new \RuntimeException($decionis === NULL ? 'Connect the site to Decionis first.' : 'The Fincra gateway holds no secret key to move.');
    }
    try {
      $authority = Binding::normalize($this->settings->get('api_base_url'));
    }
    catch (\InvalidArgumentException) {
      throw new \RuntimeException('The Decionis API base URL must use HTTP or HTTPS without embedded credentials, query parameters, or a fragment.');
    }
    if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $this->settings->get('org_id')) !== 1) {
      throw new \RuntimeException('Connect this site to a valid Decionis workspace before gateway-setup.');
    }
    [$etc, $user, $credential] = match ($tier) {
      Tier::Root => ['/etc/agentsafe', 'root', '/run/credentials/agentsafe.service/fincra-api-key'],
      default => [$options['home'] . '/.config/agentsafe', $tier === Tier::Separate ? $options['user'] : (string) $this->probe->name($this->probe->uid()), $options['home'] . '/.config/agentsafe/fincra-api-key'],
    };
    $this->planned = [
      'tier' => $tier->value,
      'gateway' => (string) $gateway->id(),
      'gateway_uuid' => $gateway->uuid(),
      'enabled' => $gateway->status(),
      'mode' => $live ? 'live' : 'test',
      'key_hash' => hash('sha256', $secret),
      'key_file' => $etc . '/fincra-api-key',
      'user' => $user,
      'port' => $options['port'],
      'original_url' => (string) ($configuration['api_base_url'] ?? ''),
      'connection_id' => $this->settings->connectionIdentity(),
      'org_id' => $this->settings->get('org_id'),
      'api_key_id' => $this->settings->get('api_key'),
      'host' => \Drupal::service(Binding::class)->host(),
      'site_url' => \Drupal::service(Binding::class)->record()['binding']['url'] ?? NULL,
    ];
    $config = strtr((string) file_get_contents(dirname(__DIR__, 2) . '/gateway/Fincra.yaml'), [
      '{{port}}' => (string) $options['port'],
      '{{upstream}}' => $live ? 'https://api.fincra.com' : 'https://sandboxapi.fincra.com',
      '{{credential_file}}' => $credential,
      '{{authority}}' => substr(json_encode($authority, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 1, -1),
      '{{org_id}}' => $this->settings->get('org_id'),
      '{{mode}}' => $options['mode'] === 'enforcement' ? 'enforcement' : 'shadow',
      '{{presence}}' => self::presence($options['approver'] ?? NULL),
    ]);
    $sudo = $tier === Tier::Root && $this->probe->uid() !== 0;
    $release = self::release($options['version'], $options['release'] ?? NULL);
    $steps = $tier === Tier::Root ? $this->root($options['version'], $release, $sudo) : $this->user($tier, $options, $release);
    // Run by hand as the AgentSafe user, the plan cannot print the keys: it
    // says where each one comes from instead.
    $separate = $tier === Tier::Separate;
    $files = [
      // The packaged unit uses DynamicUser and reads this itself. Keys and
      // environment are read by PID 1; only this non-secret YAML is public.
      ['AgentSafe configuration', $etc . '/agentsafe.yaml', $config, 0644, FALSE],
      [$separate ? sprintf('Fincra secret key for AgentSafe (the %s key of gateway "%s", from the Fincra dashboard)', $live ? 'live' : 'test', $gateway->id()) : 'Fincra secret key for AgentSafe', $etc . '/fincra-api-key', $secret, 0600, TRUE],
      [$separate ? 'Decionis key for AgentSafe (this site\'s key: drush key:value-get ' . Credentials::API_KEY . ')' : 'Decionis key for AgentSafe', $etc . '/decionis-api-key', $decionis, 0600, TRUE],
      ['Decionis workspace for AgentSafe', $etc . '/environment', 'DECIONIS_TENANT_ID=' . $this->settings->get('org_id') . "\n", 0600, FALSE],
    ];
    foreach ($files as [$description, $path, $content, $mode, $secretContent]) {
      $steps[] = $sudo ? self::sudoWrite($description, $path, $content, $mode, $secretContent) : new Step($description, NULL, $path, $content, $mode, $secretContent, $user);
    }
    return [...$steps, ...$this->start($tier, $options, $etc, $sudo)];
  }

  /**
   * Runs the plan, then moves the key out of Drupal and records the level.
   *
   * @param \Drupal\decionis_commerce\Gateway\Tier $tier
   *   The protection level selected when the plan was built.
   * @param list<Step> $plan
   *   The steps from plan().
   * @param int $port
   *   The loopback port where AgentSafe must report readiness.
   * @param \Drupal\decionis_commerce\Gateway\Runner $runner
   *   The executor for the reviewed setup steps.
   * @param bool $installed
   *   Whether AgentSafe was already installed by the host administrator.
   * @param string|null $gateway
   *   The gateway id, as plan() was given it.
   */
  public function apply(Tier $tier, array $plan, int $port, Runner $runner, bool $installed = FALSE, ?string $gateway = NULL): void {
    if ($tier === Tier::Separate && !$installed) {
      // This account cannot act as the AgentSafe user (no sudo): the operator
      // runs the plan as that user, then reruns with --installed.
      throw new \RuntimeException('Run the steps above as the AgentSafe user, then rerun with --installed.');
    }
    $chosen = $this->gateway($gateway);
    $configuration = $chosen->getPlugin()->getConfiguration();
    self::oneKey($configuration);
    $mode = ($configuration['mode'] ?? 'test') === 'live' ? 'live' : 'test';
    if (($this->planned['gateway'] ?? NULL) !== (string) $chosen->id() || ($this->planned['tier'] ?? NULL) !== $tier->value || ($this->planned['port'] ?? NULL) !== $port || ($this->planned['mode'] ?? NULL) !== $mode || ($this->planned['connection_id'] ?? NULL) !== $this->settings->connectionIdentity() || !hash_equals((string) ($this->planned['key_hash'] ?? ''), hash('sha256', (string) ($configuration['secret_key_' . $mode] ?? '')))) {
      throw new \RuntimeException('The gateway or connection changed after the plan was built. Run gateway-setup again.');
    }
    // --installed proves readiness, not service lifetime. Both user tiers
    // must independently prove linger before any secret file or config moves.
    if ($tier !== Tier::Root && !$this->probe->linger((string) $this->planned['user'])) {
      throw new \RuntimeException(sprintf('AgentSafe must survive logout. Ask the host to run: sudo loginctl enable-linger %s. Then rerun gateway-setup; no key has moved.', escapeshellarg((string) $this->planned['user'])));
    }
    if (!$installed) {
      foreach ($plan as $step) {
        $runner->run($step);
      }
    }
    // A freshly started AgentSafe gets a moment to come up.
    if (!$this->answering($port, $installed ? 1 : $this->waitSeconds)) {
      throw new \RuntimeException(sprintf('AgentSafe does not answer HTTP on 127.0.0.1:%d; the Fincra key stays where it is.', $port));
    }
    if ($this->settings->connectionIdentity() !== $this->planned['connection_id']) {
      throw new \RuntimeException('The site connection changed while AgentSafe started; the Fincra key stays in Drupal.');
    }
    // Only now that AgentSafe holds the key does Drupal let go of it.
    $plugin = $chosen->getPlugin();
    $configuration = $plugin->getConfiguration();
    $configuration['api_base_url'] = 'http://127.0.0.1:' . $port;
    $configuration['secret_key_held_by_gateway'] = TRUE;
    $configuration['secret_key_test'] = '';
    $configuration['secret_key_live'] = '';
    $plugin->setConfiguration($configuration);
    $chosen->setPluginConfiguration($plugin->getConfiguration());
    $chosen->save();
    $custody = $this->planned;
    $custody['key_digest'] = $custody['key_hash'];
    unset($custody['key_hash']);
    $custody += ['protection' => $tier->protection(), 'at' => time(), 'custody' => TRUE];
    $this->state->set(self::STATE, $custody);
    $this->state->delete(Watchdog::STATE);
    $this->entityTypeManager->getStorage('commerce_payment_gateway')->resetCache([(string) $chosen->id()]);
    \Drupal::configFactory()->reset('commerce_payment.commerce_payment_gateway.' . $chosen->id());
  }

  /**
   * One installation must never destroy the inactive environment's key.
   */
  private static function oneKey(array $configuration): void {
    if (!empty($configuration['secret_key_test']) && !empty($configuration['secret_key_live'])) {
      throw new \RuntimeException('Both test and live Fincra keys are configured. Keep the inactive key in the host secret store and remove that inactive configuration value before gateway-setup.');
    }
  }

  /**
   * TRUE once AgentSafe answers HTTP, trying for up to $seconds.
   */
  private function answering(int $port, int $seconds): bool {
    for ($try = 0; $try < $seconds * 2; $try++) {
      if ($this->probe->answers($port)) {
        return TRUE;
      }
      if ($try + 1 < $seconds * 2) {
        usleep(500000);
      }
    }
    return FALSE;
  }

  /**
   * Plans a root package install using a systemd credential for the key.
   *
   * Downloads land in a directory only root can write, and the package must
   * have its own line in the release checksums, so nothing another account
   * placed is installed.
   *
   * @return list<Step>
   *   The steps.
   */
  private function root(string $version, string $release, bool $sudo): array {
    $arch = $this->probe->arch();
    $format = $this->probe->packager();
    if ($arch === NULL || $format === NULL) {
      throw new \RuntimeException($arch === NULL
        ? 'AgentSafe is built for x86_64 and arm64 only; this machine is ' . php_uname('m') . '.'
        : 'Neither dpkg nor rpm runs here; install AgentSafe from its archive by hand, then rerun with --installed.');
    }
    // Debian and RPM name the same architectures differently.
    $asset = $format === 'deb'
      ? sprintf('agentsafe_%s_%s.deb', $version, $arch === 'x86_64' ? 'amd64' : 'arm64')
      : sprintf('agentsafe-%s-1.%s.rpm', $version, $arch === 'x86_64' ? 'x86_64' : 'aarch64');
    $base = self::RELEASES . '/' . $release;
    $dir = self::DOWNLOADS;
    $as = static fn (array $command): array => $sudo ? ['sudo', '-n', ...$command] : $command;
    $verify = 'cd "$1" && awk -v f="$2" \'$2 == f || $2 == "*" f\' SHA256SUMS | sha256sum -c --strict -';
    $dropIn = "[Service]\nLoadCredential=fincra-api-key:/etc/agentsafe/fincra-api-key\n";
    return [
      new Step('Create the download directory', $as(['install', '-d', '-m', '0700', '-o', 'root', '-g', 'root', $dir])),
      new Step('Download the AgentSafe package', $as(['curl', '-fsSLo', $dir . '/' . $asset, $base . '/' . $asset])),
      new Step('Download its checksums', $as(['curl', '-fsSLo', $dir . '/SHA256SUMS', $base . '/SHA256SUMS'])),
      new Step('Verify the package against its own checksum line', $as(['sh', '-c', $verify, 'sh', $dir, $asset])),
      new Step('Install the package', $as($format === 'deb' ? ['dpkg', '-i', $dir . '/' . $asset] : ['rpm', '-U', '--replacepkgs', $dir . '/' . $asset])),
      new Step('Make the non-secret configuration reachable by the dynamic service user', $as(['install', '-d', '-m', '0755', '-o', 'root', '-g', 'root', '/etc/agentsafe'])),
      $sudo
        ? self::sudoWrite('Hand the Fincra key to the service as a systemd credential', '/etc/systemd/system/agentsafe.service.d/fincra.conf', $dropIn, 0644, FALSE)
        : new Step('Hand the Fincra key to the service as a systemd credential', NULL, '/etc/systemd/system/agentsafe.service.d/fincra.conf', $dropIn, 0644, FALSE, 'root'),
    ];
  }

  /**
   * The Presence block: managed escalations need the approver they go to.
   *
   * Without one, AgentSafe does not forward what it would escalate.
   */
  private static function presence(?string $approver): string {
    if ($approver === NULL || $approver === '') {
      return "# Managed escalations through Presence need an approver: rerun with --approver.\n";
    }
    if (strlen($approver) > 200 || preg_match('/[\x00-\x1f]/', $approver) === 1) {
      throw new \RuntimeException('--approver must be one line of at most 200 characters.');
    }
    // A JSON string is a YAML double-quoted scalar: nothing in it can add keys.
    return "presence:\n  managed: true\n  approverId: " . json_encode($approver, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
  }

  /**
   * Resolves the named release or the known carrier for a runtime version.
   */
  private static function release(string $version, ?string $release): string {
    $release ??= self::CARRIERS[$version] ?? NULL;
    if ($release === NULL || preg_match('/^v\d+\.\d+\.\d+$/', $release) !== 1) {
      throw new \RuntimeException(sprintf('Name the agent-safe-pipeline release that carries AgentSafe %s with --agentsafe-release (for example v0.5.0 for 0.7.0).', $version));
    }
    return $release;
  }

  /**
   * Plans a root-owned file write through sudo.
   *
   * Content travels on standard input so a key never appears in a process list.
   */
  private static function sudoWrite(string $description, string $path, string $content, int $mode, bool $secret): Step {
    return new Step($description, ['sudo', '-n', 'install', '-D', '-m', sprintf('%04o', $mode), '-o', 'root', '-g', 'root', '/dev/stdin', $path], NULL, $content, $mode, $secret, 'root');
  }

  /**
   * The user-level install under the dedicated user or Drupal's own account.
   *
   * @param \Drupal\decionis_commerce\Gateway\Tier $tier
   *   The protection level supported by this server account.
   * @param array{user: string, port: int, mode: string, version: string, home: string, files: string, gateway?: ?string, release?: ?string, approver?: ?string} $options
   *   The setup options.
   * @param string $release
   *   The release carrying the requested AgentSafe runtime.
   *
   * @return list<Step>
   *   The steps.
   */
  private function user(Tier $tier, array $options, string $release): array {
    // The installer comes from the release tag, never a moving branch, and
    // is told which release carries the version.
    $install = ['sh', '-c', 'curl -fsSL ' . escapeshellarg(sprintf(self::INSTALLER, $release)) . ' | AGENTSAFE_VERSION=' . escapeshellarg($options['version']) . ' AGENTSAFE_RELEASE_TAG=' . escapeshellarg($release) . ' AGENTSAFE_INSTALL_PREFIX=' . escapeshellarg($options['home'] . '/.local') . ' sh'];
    $user = $tier === Tier::Separate ? $options['user'] : (string) $this->probe->name($this->probe->uid());
    return [
      new Step('Host requirement: sudo loginctl enable-linger ' . escapeshellarg($user) . '; gateway-setup verifies Linger=yes before moving a key'),
      new Step('Install AgentSafe for ' . ($tier === Tier::Separate ? $options['user'] : 'this account'), $install),
    ];
  }

  /**
   * Starting the gateway.
   *
   * @param \Drupal\decionis_commerce\Gateway\Tier $tier
   *   The protection level and service owner selected by the plan.
   * @param array{user: string, port: int, mode: string, version: string, home: string, files: string, gateway?: ?string, release?: ?string, approver?: ?string} $options
   *   The setup options.
   * @param string $etc
   *   The directory containing the AgentSafe configuration and key files.
   * @param bool $sudo
   *   Whether starting the service requires passwordless sudo.
   *
   * @return list<Step>
   *   The steps.
   */
  private function start(Tier $tier, array $options, string $etc, bool $sudo = FALSE): array {
    if ($tier === Tier::Root) {
      $as = $sudo ? ['sudo', '-n'] : [];
      return [
        new Step('Reload systemd', [...$as, 'systemctl', 'daemon-reload']),
        new Step('Start AgentSafe', [...$as, 'systemctl', 'enable', '--now', 'agentsafe']),
        new Step('Load the current configuration and credentials', [...$as, 'systemctl', 'restart', 'agentsafe']),
      ];
    }
    $unit = "[Unit]\nDescription=AgentSafe in front of Fincra\n\n[Service]\nExecStart=" . $options['home'] . "/.local/bin/agentsafe run --config " . $etc . "/agentsafe.yaml\nEnvironmentFile=" . $etc . "/environment\nEnvironment=DECIONIS_API_KEY_FILE=" . $etc . "/decionis-api-key\nEnvironment=NODE_ENV=production\nRestart=on-failure\n\n[Install]\nWantedBy=default.target\n";
    return [
      new Step('AgentSafe user service', NULL, $options['home'] . '/.config/systemd/user/agentsafe.service', $unit, 0644, FALSE, $tier === Tier::Separate ? $options['user'] : NULL),
      new Step('Reload the user service manager', ['systemctl', '--user', 'daemon-reload']),
      new Step('Start AgentSafe', ['systemctl', '--user', 'enable', '--now', 'agentsafe']),
      new Step('Load the current configuration and credentials', ['systemctl', '--user', 'restart', 'agentsafe']),
    ];
  }

  /**
   * The protection level last recorded, for the settings page.
   *
   * @return array<string, mixed>
   *   The tier, its description, the port and when it was set.
   */
  public function protection(): array {
    $value = $this->state->get(self::STATE);
    return is_array($value) ? $value : [];
  }

}
