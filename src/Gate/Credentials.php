<?php

declare(strict_types=1);

namespace Drupal\decionis\Gate;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\decionis\Connect\Binding;
use Drupal\key\KeyRepositoryInterface;
use Drupal\key\KeyInterface;

/**
 * Stores and reads the Decionis credentials through the Key module.
 *
 * Values are written to Drupal State, which config export never includes, and
 * a Key entity of the State provider points at them. The Key entity and the
 * settings hold only names. An admin can move a key to a file or environment
 * provider later; this class then only reads it.
 */
final class Credentials {

  public const API_KEY = 'decionis_api_key';
  public const SITE_KEY = 'decionis_site_key';

  /**
   * Generations for which this request loaded each Key entity's metadata.
   *
   * @var array<string, string>
   */
  private array $loaded = [];

  public function __construct(
    private readonly KeyRepositoryInterface $keys,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ?Binding $binding = NULL,
    private readonly ?KeyValueFactoryInterface $keyValueFactory = NULL,
  ) {}

  /**
   * The site-scoped API key, or NULL when the site is not connected.
   */
  public function apiKey(): ?string {
    return $this->binding?->copied() ? NULL : $this->read('api_key');
  }

  /**
   * The site's Ed25519 secret key (64 bytes), or NULL.
   */
  public function siteSecret(): ?string {
    if ($this->binding?->copied()) {
      return NULL;
    }
    $value = $this->read('site_key');
    $raw = $value === NULL ? FALSE : base64_decode($value, TRUE);
    return is_string($raw) && strlen($raw) === SODIUM_CRYPTO_SIGN_SECRETKEYBYTES ? $raw : NULL;
  }

  /**
   * Stores a secret and points the setting at its Key entity.
   */
  public function store(string $setting, string $id, string $label, string $value): void {
    $variable = 'decionis.secret.' . $id;
    $this->state->set($variable, $value);
    $storage = $this->entityTypeManager->getStorage('key');
    $key = $storage->load($id);
    if ($key === NULL) {
      $key = $storage->create([
        'id' => $id,
        'label' => $label,
        'description' => 'Managed by Decionis. The value lives in Drupal State, never in config export.',
        'key_type' => 'authentication',
        'key_provider' => 'state',
        'key_provider_settings' => ['state_key' => $variable],
        'key_input' => 'none',
      ]);
    }
    if (!$key instanceof KeyInterface) {
      throw new \RuntimeException('DECIONIS_KEY_STORAGE_INVALID');
    }
    $key->setPlugin('key_provider', 'state');
    $key->getPlugin('key_provider')->setConfiguration(['state_key' => $variable]);
    $key->setPlugin('key_input', 'none');
    $key->save();
    $connection = $this->state->get(Binding::STATE, []);
    $connection = is_array($connection) ? $connection : [];
    $connection[$setting] = $id;
    $connection['generation'] = bin2hex(random_bytes(16));
    $connection['managed'][$id] = TRUE;
    $connection['providers'][$id] = hash('sha256', json_encode(['state', ['state_key' => $variable]], JSON_THROW_ON_ERROR));
    $this->state->set(Binding::STATE, $connection);
    $this->configFactory->getEditable('decionis.settings')->clear($setting)->save();
  }

  /**
   * Removes a secret the module manages and clears its setting.
   */
  public function forget(string $setting, string $id): void {
    $this->state->delete('decionis.secret.' . $id);
    $key = $this->entityTypeManager->getStorage('key')->load($id);
    $key?->delete();
    $connection = $this->state->get(Binding::STATE, []);
    $connection = is_array($connection) ? $connection : [];
    $connection[$setting] = '';
    unset($connection['managed'][$id]);
    unset($connection['providers'][$id]);
    $this->state->set(Binding::STATE, $connection);
    $this->configFactory->getEditable('decionis.settings')->clear($setting)->save();
  }

  /**
   * Reads the value of the Key entity a setting names.
   */
  private function read(string $setting): ?string {
    $connection = $this->binding?->record() ?? $this->state->get(Binding::STATE, []);
    $connection = is_array($connection) ? $connection : [];
    $id = (string) ($connection[$setting] ?? $this->configFactory->get('decionis.settings')->get($setting) ?? '');
    if ($id === '') {
      return NULL;
    }
    $generation = (string) ($connection['generation'] ?? 'legacy');
    if (($this->loaded[$id] ?? NULL) !== $generation) {
      // A long-lived queue worker may see a reconnect from another request.
      // Reset only memory caches; a read-only plan must not change the DB.
      $this->configFactory->reset('key.key.' . $id);
      $this->entityTypeManager->getStorage('key')->resetCache([$id]);
      $this->loaded[$id] = $generation;
    }
    $key = $this->keys->getKey($id);
    // A pre-connect config import removes the managed Key entity. Its State
    // provider reference and value survive; external provider references must
    // never silently fall back to an older State value.
    if ($key !== NULL) {
      $provider = $key->getPlugin('key_provider');
      $variable = $provider->getConfiguration()['state_key'] ?? NULL;
      // The State provider also caches within a request. Read its exact State
      // variable fresh; other providers use Key's supported reset argument.
      $value = $provider->getPluginId() === 'state' && is_string($variable)
        ? $this->stateValue($variable)
        : $key->getKeyValue(TRUE);
    }
    else {
      $value = ($connection['managed'][$id] ?? FALSE) === TRUE ? $this->stateValue('decionis.secret.' . $id) : NULL;
    }
    if ($this->binding !== NULL && ($this->binding->copied() || $this->binding->record() !== $connection)) {
      return NULL;
    }
    return is_string($value) && $value !== '' ? $value : NULL;
  }

  /**
   * Reads a State provider value without a stale per-request State cache.
   */
  private function stateValue(string $variable): mixed {
    return $this->keyValueFactory !== NULL ? $this->keyValueFactory->get('state')->get($variable) : $this->state->get($variable);
  }

}
