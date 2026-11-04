<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Drush\Commands;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\key\KeyRepositoryInterface;
use Drupal\decionis\Gate\Remote;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis\Report\Summary;
use Drupal\decionis_commerce\Gateway\Rollback;
use Drupal\decionis_commerce\Gateway\Runner;
use Drupal\decionis_commerce\Gateway\Setup;
use Drupal\decionis_commerce\Gateway\Tier;
use Drupal\decionis_commerce\Replay\Replay;
use Drupal\decionis_commerce\Sandbox\Hosted;
use Drupal\decionis_commerce\Sandbox\Interrupted;
use Drupal\decionis_commerce\Sandbox\Local;
use Drupal\decionis_commerce\Sandbox\Sandbox;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for Decionis Commerce.
 */
final class DecionisCommands extends DrushCommands {

  /**
   * Exit code when the sandbox could not run at all (missing setting). */
  public const NOT_RUN = 2;

  public function __construct(
    private readonly Sandbox $sandbox,
    private readonly Replay $replay,
    private readonly Remote $remote,
    private readonly Settings $settings,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ?Setup $setup = NULL,
    private readonly ?Runner $runner = NULL,
    private readonly ?KeyRepositoryInterface $keys = NULL,
    private readonly ?Rollback $rollback = NULL,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(Sandbox::class),
      $container->get(Replay::class),
      $container->get(Remote::class),
      $container->get(Settings::class),
      $container->get('entity_type.manager'),
      $container->get(Setup::class),
      $container->get(Runner::class),
      $container->get('key.repository'),
      $container->get(Rollback::class),
    );
  }

  /**
   * Puts AgentSafe in front of Fincra, the strongest way this account allows.
   */
  #[CLI\Command(name: 'decionis:gateway-setup')]
  #[CLI\Option(name: 'dry-run', description: 'Print the exact commands and files instead, for the host\'s support team; change nothing.')]
  #[CLI\Option(name: 'user', description: 'The dedicated system user for AgentSafe, if the host provides one.')]
  #[CLI\Option(name: 'port', description: 'The loopback port AgentSafe listens on.')]
  #[CLI\Option(name: 'mode', description: 'shadow (default: watch first) or enforcement (refunds and payouts need a grant).')]
  #[CLI\Option(name: 'agentsafe-version', description: 'The AgentSafe runtime version to install.')]
  #[CLI\Option(name: 'agentsafe-release', description: 'The agent-safe-pipeline release that carries that version, when the module does not know it (v0.5.0 carries 0.7.0).')]
  #[CLI\Option(name: 'installed', description: 'AgentSafe was installed by hand as the dedicated user: verify it and move the key.')]
  #[CLI\Option(name: 'gateway', description: 'The Commerce Fincra gateway whose key moves, when the site has several.')]
  #[CLI\Option(name: 'approver', description: 'Who approves an escalated refund or payout in Presence (their Decionis approver id). Without it, AgentSafe refuses what it would escalate.')]
  public function gatewaySetup(array $options = ['dry-run' => FALSE, 'user' => 'agentsafe', 'port' => 8787, 'mode' => 'shadow', 'agentsafe-version' => '0.7.0', 'agentsafe-release' => NULL, 'installed' => FALSE, 'gateway' => NULL, 'approver' => NULL]): int {
    if ($this->setup === NULL || $this->runner === NULL) {
      return self::EXIT_FAILURE;
    }
    $files = (string) \Drupal::service('file_system')->realpath('public://');
    $tier = $this->setup->tier((string) $options['user'], $files);
    $dedicated = $tier === Tier::Separate ? (posix_getpwnam((string) $options['user']) ?: []) : [];
    $home = (string) ($dedicated['dir'] ?? (getenv('HOME') ?: sys_get_temp_dir()));
    $port = (int) $options['port'];
    $gateway = is_string($options['gateway'] ?? NULL) && $options['gateway'] !== '' ? $options['gateway'] : NULL;
    try {
      $plan = $this->setup->plan($tier, ['user' => (string) $options['user'], 'port' => $port, 'mode' => (string) $options['mode'], 'version' => (string) $options['agentsafe-version'], 'home' => $home, 'files' => $files, 'gateway' => $gateway, 'release' => is_string($options['agentsafe-release'] ?? NULL) && $options['agentsafe-release'] !== '' ? $options['agentsafe-release'] : NULL, 'approver' => is_string($options['approver'] ?? NULL) ? $options['approver'] : NULL]);
    }
    catch (\RuntimeException $error) {
      $this->logger()?->error($error->getMessage());
      return self::EXIT_FAILURE;
    }
    $this->output()->writeln(sprintf('Permission tier: %s. %s', $tier->value, $tier->protection()));
    foreach ($plan as $index => $step) {
      $this->output()->writeln(sprintf('%2d. %s: %s', $index + 1, $step->description, $step->describe()));
    }
    $this->output()->writeln(sprintf('Then: point Commerce Fincra at http://127.0.0.1:%d and remove its secret key from Drupal\'s configuration.', $port));
    $this->output()->writeln('Recommended: in the Fincra dashboard, allow API access only from this server\'s IP address, so a copied key cannot be used elsewhere.');
    if (!empty($options['dry-run'])) {
      $this->output()->writeln('Dry run: nothing was changed.');
      return self::EXIT_SUCCESS;
    }
    try {
      $this->setup->apply($tier, $plan, $port, $this->runner, !empty($options['installed']), $gateway);
    }
    catch (\RuntimeException $error) {
      $this->logger()?->error($error->getMessage());
      return self::EXIT_FAILURE;
    }
    $this->logger()?->success('AgentSafe now holds the Fincra key. ' . $tier->protection());
    return self::EXIT_SUCCESS;
  }

  /**
   * Returns Fincra to Drupal through a private Key file provider.
   */
  #[CLI\Command(name: 'decionis:gateway-rollback')]
  #[CLI\Option(name: 'key-file', description: 'A private file made readable by the Drupal PHP account by the host. Its key must match the recorded gateway environment.')]
  #[CLI\Option(name: 'dry-run', description: 'Print the rollback or exact host steps without changing anything.')]
  public function gatewayRollback(array $options = ['key-file' => NULL, 'dry-run' => FALSE]): int {
    if ($this->rollback === NULL) {
      return self::EXIT_FAILURE;
    }
    $file = is_string($options['key-file'] ?? NULL) && $options['key-file'] !== '' ? $options['key-file'] : NULL;
    try {
      foreach ($this->rollback->describe($file) as $step) {
        $this->output()->writeln($step);
      }
      if (!empty($options['dry-run'])) {
        $this->output()->writeln('Dry run: nothing was changed.');
        return self::EXIT_SUCCESS;
      }
      if (!$this->rollback->apply($file)) {
        return self::EXIT_FAILURE;
      }
    }
    catch (\RuntimeException $error) {
      $this->logger()?->error($error->getMessage());
      return self::EXIT_FAILURE;
    }
    $this->logger()?->success('Fincra now reads its key through a private Key file provider. Exported configuration contains no payment secret.');
    return self::EXIT_SUCCESS;
  }

  /**
   * Runs the whole decision path on a synthetic store; nothing real is touched.
   */
  #[CLI\Command(name: 'decionis:sandbox')]
  #[CLI\Option(name: 'authority', description: 'hosted (Decionis sandbox, default), local (bundled demo authority) or account (your workspace, in Shadow).')]
  #[CLI\Option(name: 'policy', description: 'A policy version to pin on hosted or account; local runs the Drupal Commerce starter packs.')]
  #[CLI\Option(name: 'json', description: 'Print the report as one line of JSON.')]
  #[CLI\Usage(name: 'drush decionis:sandbox --authority=local --json', description: 'Offline, for CI after every deploy.')]
  public function sandbox(array $options = ['authority' => 'hosted', 'policy' => NULL, 'json' => FALSE]): int {
    $verify = NULL;
    $policy = is_string($options['policy']) && $options['policy'] !== '' ? $options['policy'] : NULL;
    switch ((string) $options['authority']) {
      case 'local':
        if ($policy !== NULL && $policy !== Local::POLICY) {
          return $this->notRun(sprintf('--authority=local runs only the Drupal Commerce starter packs (%s); pin other policies on hosted or account.', Local::POLICY), $options);
        }
        $authority = new Local();
        break;

      case 'account':
        if (!$this->remote->available()) {
          return $this->notRun('This site is not connected. Connect at /admin/commerce/config/decionis/connect, or use --authority=local.', $options);
        }
        $authority = $this->remote;
        $client = $this->remote->client();
        $verify = static fn (string $id) => $client->verify($client->dossier($id));
        break;

      case 'hosted':
        [$authority, $missing] = Hosted::fromEnvironment($this->settings->get('api_base_url') ?: 'https://api.decionis.com', fn (string $id): ?string => $this->keys?->getKey($id)?->getKeyValue());
        if ($authority === NULL) {
          return $this->notRun(sprintf('The hosted sandbox needs %s in the environment or as the Key entries %s (the shared sandbox credential is never bundled). Set them, or run offline with --authority=local.', implode(' and ', $missing), implode(' and ', array_map(static fn (string $variable): string => Hosted::KEYS[$variable], $missing))), $options);
        }
        $client = $authority->client();
        $verify = static fn (string $id) => $client->verify($client->dossier($id));
        break;

      default:
        return $this->notRun('--authority must be hosted, local or account.', $options);
    }
    try {
      $report = $this->sandbox->run($authority, $verify, $policy);
    }
    catch (Interrupted $error) {
      return $this->notRun($error->getMessage(), $options);
    }
    $this->output()->writeln($options['json'] ? json_encode($report, JSON_UNESCAPED_SLASHES) : $this->text($report));
    return (int) $report['exit_code'];
  }

  /**
   * Read-only Shadow replay of past orders, refunds and promotions.
   */
  #[CLI\Command(name: 'decionis:replay')]
  #[CLI\Option(name: 'days', description: 'How far back to look (1-365).')]
  #[CLI\Option(name: 'authority', description: 'account (default, your workspace) or local (demo authority, offline).')]
  #[CLI\Option(name: 'json', description: 'Print the totals as one line of JSON.')]
  public function replay(array $options = ['days' => 90, 'authority' => 'account', 'json' => FALSE]): int {
    $days = max(1, min(365, (int) $options['days']));
    $local = $options['authority'] === 'local';
    if (!$local && !$this->remote->available()) {
      return $this->notRun('This site is not connected; connect first, or run --authority=local for a labelled demo replay.', $options);
    }
    $totals = $local ? $this->replay->demo($days) : ['label' => 'account'] + $this->replay->run($days);
    $this->output()->writeln($options['json'] ? json_encode($totals) : $this->replayText($totals, $days));
    return self::EXIT_SUCCESS;
  }

  /**
   * Imports variation costs from a CSV with the columns sku,cost.
   */
  #[CLI\Command(name: 'decionis:cost-import')]
  #[CLI\Argument(name: 'file', description: 'Path to a CSV file with a header row sku,cost.')]
  public function costImport(string $file): int {
    $field = (string) \Drupal::config('decionis_commerce.settings')->get('cost_field');
    $handle = is_readable($file) ? fopen($file, 'r') : FALSE;
    if ($handle === FALSE || $field === '') {
      $this->logger()?->error('Cannot read the file, or no cost field is configured.');
      return self::EXIT_FAILURE;
    }
    $header = fgetcsv($handle, NULL, ',', '"', '\\');
    $columns = is_array($header) ? array_flip(array_map('strtolower', array_map('trim', $header))) : [];
    if (!isset($columns['sku'], $columns['cost'])) {
      $this->logger()?->error('The CSV needs a header row with sku and cost.');
      return self::EXIT_FAILURE;
    }
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $updated = 0;
    $skipped = 0;
    while (($row = fgetcsv($handle, NULL, ',', '"', '\\')) !== FALSE) {
      $sku = trim((string) ($row[$columns['sku']] ?? ''));
      $cost = trim((string) ($row[$columns['cost']] ?? ''));
      $variations = $sku === '' || !is_numeric($cost) || (float) $cost < 0 ? [] : $storage->loadByProperties(['sku' => $sku]);
      $variation = reset($variations);
      if (!$variation instanceof ProductVariationInterface || !$variation->hasField($field)) {
        $skipped++;
        continue;
      }
      $currency = $variation->getPrice()?->getCurrencyCode() ?? 'USD';
      $variation->set($field, $variation->get($field)->getFieldDefinition()->getType() === 'commerce_price' ? ['number' => $cost, 'currency_code' => $currency] : $cost);
      $variation->save();
      $updated++;
    }
    fclose($handle);
    $this->logger()?->success(sprintf('Cost set on %d variations; %d rows skipped (unknown SKU or invalid cost).', $updated, $skipped));
    return self::EXIT_SUCCESS;
  }

  /**
   * Reports why the sandbox could not start and returns its failure status.
   *
   * @param string $message
   *   The missing setting or other reason the sandbox could not run.
   * @param array<string, mixed> $options
   *   The command options controlling text or JSON output.
   */
  private function notRun(string $message, array $options): int {
    if (!empty($options['json'])) {
      $this->output()->writeln(json_encode(['schema' => 'decionis.drupal.sandbox/1', 'verdict' => 'NOT RUN', 'error' => $message, 'exit_code' => self::NOT_RUN]));
    }
    else {
      $this->logger()?->error($message);
    }
    return self::NOT_RUN;
  }

  /**
   * Formats the synthetic sandbox report for terminal output.
   *
   * @param array<string, mixed> $report
   *   The scenario results, wiring checks and Dossier verification counts.
   */
  private function text(array $report): string {
    $suffix = '   [' . Sandbox::LABEL . ($report['authority'] === Summary::DEMO ? ' · ' . Summary::DEMO : ' · ' . $report['authority']) . ']';
    $lines = ['Synthetic store · ' . $report['authority'] . ' · nothing real is touched' . $suffix, ''];
    $lines[] = sprintf('%-36s %-12s %-26s %s', '', 'direct', 'shadow', 'enforcement') . $suffix;
    foreach ($report['scenarios'] as $row) {
      $lines[] = sprintf('%-36s %-12s %-26s %s', $row['scenario'], $row['direct']['text'], $row['shadow']['text'], $row['enforce']['text']) . $suffix;
    }
    $lines[] = '';
    $wiring = $report['wiring'];
    $lines[] = sprintf('Wiring      %d of %d hook points active · %s', $wiring['active'], $wiring['total'], $wiring['overrides'] === [] ? 'no overrides found' : implode('; ', $wiring['overrides'])) . $suffix;
    $exposure = $report['exposure'];
    $lines[] = sprintf('Exposure    %d of %d risky actions went through directly, %d in shadow, %d under enforcement', $exposure['direct'], $exposure['risky'], $exposure['shadow'], $exposure['enforce']) . $suffix;
    $lines[] = sprintf('Evidence    %d sandbox dossiers, %d Ed25519 verified', $report['evidence']['count'], $report['evidence']['verified']) . $suffix;
    $lines[] = 'Verdict     ' . $report['verdict'] . $suffix;
    return implode(PHP_EOL, $lines);
  }

  /**
   * Formats the replay totals for the requested history window.
   *
   * @param array<string, mixed> $totals
   *   The replay counts and authority label returned by the replay service.
   * @param int $days
   *   The number of days included in the replay window.
   */
  private function replayText(array $totals, int $days): string {
    // A demo line copied out of context still says whose verdicts it shows.
    $suffix = $totals['label'] === Summary::DEMO ? '   [demo authority, not your policy; nothing was recorded]' : '';
    $lines = [sprintf('Shadow replay, last %d days (%s): %d reviewed, %d not covered (no cost data).', $days, $totals['label'], $totals['reviewed'], $totals['not_covered']) . $suffix];
    foreach ($totals['by_action'] as $action => $currencies) {
      foreach ($currencies as $currency => $line) {
        $lines[] = sprintf('  %-20s %5d reviewed   %s %s covered   %s %s would have been held or blocked', $action, $line['count'], $currency, number_format($line['covered'], 2), $currency, number_format($line['held'], 2)) . $suffix;
      }
    }
    return implode(PHP_EOL, $lines);
  }

}
