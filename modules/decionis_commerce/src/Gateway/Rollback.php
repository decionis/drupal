<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Gateway;

use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\key\KeyInterface;

/**
 * Explicitly returns a gateway to Drupal using a private file-backed Key.
 */
final class Rollback {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly Custody $custody,
    private readonly Probe $probe,
  ) {}

  /**
   * The exact local action or host steps, without reading or printing a key.
   *
   * @return list<string>
   *   Instructions safe to print.
   */
  public function describe(?string $file = NULL): array {
    $record = $this->record();
    $path = $file ?? (string) $record['key_file'];
    if ($this->readable($path)) {
      return [sprintf('Use the private file %s as a Key file provider for gateway %s (%s).', $path, $record['gateway'], $record['mode']), sprintf('Restore the gateway API base URL to %s and clear the AgentSafe held-key flag. Exported configuration retains empty secret values.', $record['original_url'] ?: 'the Fincra plugin default')];
    }
    $web = $this->webUser();
    $user = $web === NULL ? NULL : $this->probe->name($web);
    if ($user === NULL) {
      throw new \RuntimeException('The host must identify the Drupal PHP account before it can return the key file.');
    }
    $directory = '/var/lib/decionis';
    $destination = $directory . '/fincra-' . (string) $record['gateway'] . '-' . (string) $record['mode'] . '.key';
    return [
      'Drupal cannot read a private payment key file. Ask the host to run these commands, using the account that runs Drupal:',
      implode(' ', array_map('escapeshellarg', ['sudo', 'install', '-d', '-m', '0700', '-o', $user, $directory])),
      implode(' ', array_map('escapeshellarg', ['sudo', 'install', '-m', '0600', '-o', $user, (string) $record['key_file'], $destination])),
      'Then, from this Drupal site: drush decionis:gateway-rollback --key-file=' . escapeshellarg($destination),
    ];
  }

  /**
   * Returns TRUE after rollback; FALSE means the host steps are required.
   */
  public function apply(?string $file = NULL): bool {
    $record = $this->record();
    $path = $file ?? (string) $record['key_file'];
    if (!$this->readable($path)) {
      return FALSE;
    }
    $value = rtrim((string) file_get_contents($path), "\r\n");
    if ($value === '' || !is_string($record['key_digest'] ?? NULL) || !hash_equals($record['key_digest'], hash('sha256', $value))) {
      throw new \RuntimeException('The protected file does not contain this gateway environment\'s recorded key; the gateway is unchanged.');
    }
    $gateway = $this->entityTypeManager->getStorage('commerce_payment_gateway')->load((string) $record['gateway']);
    if (!$gateway instanceof PaymentGatewayInterface || !Custody::matches($record, $gateway)) {
      throw new \RuntimeException('The recorded Fincra gateway no longer exists or was replaced.');
    }
    $keyId = 'decionis_fincra_' . substr(hash('sha256', (string) $record['gateway']), 0, 16);
    $keys = $this->entityTypeManager->getStorage('key');
    $key = $keys->load($keyId) ?? $keys->create(['id' => $keyId, 'label' => 'Fincra protected payment key', 'key_type' => 'authentication']);
    if (!$key instanceof KeyInterface) {
      throw new \RuntimeException('The Key storage did not return a usable key reference.');
    }
    $key->setPlugin('key_provider', 'file');
    $key->getPlugin('key_provider')->setConfiguration(['file_location' => $path, 'strip_line_breaks' => TRUE]);
    $key->setPlugin('key_input', 'none');
    $key->save();

    $record['custody'] = FALSE;
    $record['rollback_key'] = $keyId;
    $record['rollback_file'] = $path;
    $record['protection'] = 'Amber: Drupal reads the payment key from a private file through Key.';
    $configuration = $gateway->getPlugin()->getConfiguration();
    $configuration['secret_key_test'] = '';
    $configuration['secret_key_live'] = '';
    $configuration['api_base_url'] = (string) $record['original_url'];
    $configuration['secret_key_held_by_gateway'] = FALSE;
    $gateway->set('status', (bool) ($record['enabled'] ?? TRUE));
    $gateway->setPluginConfiguration($configuration)->save();
    $this->state->set(Setup::STATE, $record);
    $this->state->delete(Watchdog::STATE);
    $this->configFactory->reset('commerce_payment.commerce_payment_gateway.' . $record['gateway']);
    $this->entityTypeManager->getStorage('commerce_payment_gateway')->resetCache([(string) $record['gateway']]);
    return TRUE;
  }

  /**
   * A complete record still belongs to this site and current connection.
   */
  private function record(): array {
    $record = $this->state->get(Setup::STATE, []);
    if (!is_array($record) || empty($record['custody']) || empty($record['key_file']) || empty($record['gateway']) || !in_array($record['mode'] ?? '', ['test', 'live'], TRUE) || !array_key_exists('original_url', $record)) {
      throw new \RuntimeException('No complete AgentSafe custody record is available for rollback. Earlier installations require the host to return their key through a private Key file provider.');
    }
    if (!$this->custody->owned($record)) {
      throw new \RuntimeException('This custody record belongs to another site or host. A copied site cannot retrieve its production payment key.');
    }
    return $record;
  }

  /**
   * A root Drush must not return a file that PHP cannot read at runtime.
   */
  private function readable(string $path): bool {
    $web = $this->webUser();
    return $web !== NULL && $web !== 0 && $this->probe->owner($path) === $web && Custody::protectedFile($path);
  }

  /**
   * The public files owner is the web account, falling back only to non-root.
   */
  private function webUser(): ?int {
    $files = (string) \Drupal::service('file_system')->realpath('public://');
    $web = $this->probe->owner($files);
    return $web ?? ($this->probe->uid() > 0 ? $this->probe->uid() : NULL);
  }

}
