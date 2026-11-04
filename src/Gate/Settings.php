<?php

declare(strict_types=1);

namespace Drupal\decionis\Gate;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\decionis\Connect\Binding;

/**
 * Typed access to the Decionis settings and the server's entitlement state.
 *
 * Everything starts in Shadow. Enforce is effective only when an admin chose
 * it for the store or the action and the server's entitlement allows it.
 */
final class Settings {

  public const ENTITLEMENT = 'decionis.entitlement';
  private const SELECTION = 'decionis.enforcement.';

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly ?Binding $binding = NULL,
    private readonly ?KeyValueFactoryInterface $keyValueFactory = NULL,
  ) {}

  /**
   * The effective mode for an action key such as 'refund_request'.
   */
  public function mode(string $action): string {
    if ($this->chosen($action) !== 'enforce') {
      return 'shadow';
    }
    $identity = $this->connectionIdentity();
    if (!$this->enforcementAllowed()) {
      return 'shadow';
    }
    // Status may activate a trial in another request. Imported or historical
    // configuration is not consent to enable another action on this site.
    if ($identity !== NULL && $this->stateValue(self::SELECTION . $identity) === TRUE && $this->stateValue(self::SELECTION . $identity . '.' . $action) !== TRUE) {
      return 'shadow';
    }
    return $identity === $this->connectionIdentity() ? 'enforce' : 'shadow';
  }

  /**
   * The mode an admin chose, before entitlement is applied.
   */
  public function chosen(string $action): string {
    $config = $this->configFactory->get('decionis.settings');
    // An action with no mapping of its own is new: it starts in Shadow, and
    // follows the store-wide mode only once mapped to "inherit".
    $chosen = (string) ($config->get('actions.' . $action) ?? 'shadow');
    $chosen = $chosen === 'inherit' ? (string) $config->get('mode') : $chosen;
    return $chosen === 'enforce' ? 'enforce' : 'shadow';
  }

  /**
   * What happens in Enforce when Decionis cannot decide: 'open' or 'closed'.
   *
   * Unknown values fail closed, except checkout, which the brief fixes open.
   */
  public function failure(string $action): string {
    if ($action === 'order_acceptance') {
      return 'open';
    }
    $policy = $this->configFactory->get('decionis.settings')->get('failure.' . $action);
    return $policy === 'open' ? 'open' : 'closed';
  }

  /**
   * The checkout latency budget, bounded to 50-1000 ms.
   */
  public function budgetMs(): int {
    $budget = (int) $this->configFactory->get('decionis.settings')->get('budget_ms');
    return max(50, min(1000, $budget ?: 120));
  }

  /**
   * Reads a plain setting.
   */
  public function get(string $key): string {
    if (in_array($key, ['org_id', 'api_key', 'site_key'], TRUE)) {
      $connection = $this->binding?->record() ?? $this->state->get(Binding::STATE);
      if (is_array($connection) && array_key_exists($key, $connection)) {
        return is_string($connection[$key]) ? $connection[$key] : '';
      }
    }
    return (string) ($this->configFactory->get('decionis.settings')->get($key) ?? '');
  }

  /**
   * True once the site holds a workspace id and an API key entry.
   */
  public function connected(): bool {
    return !$this->copied() && $this->get('org_id') !== '' && $this->get('api_key') !== '';
  }

  /**
   * TRUE when stored credentials belong to another site or host.
   */
  public function copied(): bool {
    return $this->binding?->copied() ?? FALSE;
  }

  /**
   * Opaque local queue/approval identity, without reading a secret provider.
   *
   * @phpstan-impure
   */
  public function connectionIdentity(): ?string {
    if (!$this->connected()) {
      return NULL;
    }
    return $this->binding !== NULL
      ? $this->binding->identity($this->get('org_id'), $this->get('api_key'), $this->get('api_base_url'))
      : hash('sha256', $this->get('org_id') . ':' . $this->get('api_key') . ':' . $this->get('api_base_url'));
  }

  /**
   * The last entitlement the server returned, or an empty array.
   *
   * @return array<string, mixed>
   *   Cached entitlement fields, or an empty array when none are available.
   */
  public function entitlement(): array {
    $value = $this->stateValue(self::ENTITLEMENT);
    return is_array($value) ? $value : [];
  }

  /**
   * TRUE when imported Enforce preferences need this site's action selection.
   */
  public function selectionsRequired(): bool {
    $identity = $this->connectionIdentity();
    return $identity !== NULL && $this->stateValue(self::SELECTION . $identity) === TRUE;
  }

  /**
   * Starts requiring a deliberate selection before an inactive site enforces.
   *
   * This guard lives outside config export and precedes entitlement adoption.
   * Existing active installations retain their choices across renewal. Separate
   * action keys keep confirmations from overwriting each other's consent.
   */
  public function prepareEnforcement(string $identity, bool $trialAvailable = FALSE): void {
    if ($identity !== $this->connectionIdentity()) {
      return;
    }
    $key = self::SELECTION . $identity;
    if ($trialAvailable) {
      $this->state->set($key, TRUE);
    }
    elseif ($this->keyValueFactory !== NULL) {
      $this->keyValueFactory->get('state')->setIfNotExists($key, !$this->enforcementAllowed());
    }
    elseif ($this->state->get($key) === NULL) {
      $this->state->set($key, !$this->enforcementAllowed());
    }
  }

  /**
   * Records a deliberate action choice only for this still-entitled connection.
   */
  public function selectEnforcement(string $action, bool $enforce, string $identity): bool {
    if ($identity !== $this->connectionIdentity() || !$this->enforcementAllowed()) {
      return FALSE;
    }
    $this->state->set(self::SELECTION . $identity . '.' . $action, $enforce);
    return $identity === $this->connectionIdentity() && $this->enforcementAllowed();
  }

  /**
   * TRUE when the server permits starting this workspace's enforcement trial.
   */
  public function trialAvailable(): bool {
    $entitlement = $this->entitlement();
    return $this->connected() && $this->boundEntitlement($entitlement) && self::validEntitlement($entitlement) && $entitlement['state'] === 'trial_available';
  }

  /**
   * Checks the public enforcement contract without inventing plan authority.
   *
   * @param array<string, mixed> $entitlement
   *   Fields returned by the server, optionally with local binding metadata.
   */
  public static function validEntitlement(array $entitlement): bool {
    $state = $entitlement['state'] ?? NULL;
    $enforcement = $entitlement['enforcement'] ?? NULL;
    if (!in_array($state, ['trial_available', 'trial', 'paid', 'grace', 'expired'], TRUE) || !is_bool($enforcement)) {
      return FALSE;
    }
    if (in_array($state, ['trial_available', 'expired'], TRUE) && $enforcement) {
      return FALSE;
    }
    if (in_array($state, ['trial', 'grace'], TRUE) || array_key_exists('ends_at', $entitlement)) {
      return self::expiry($entitlement['ends_at'] ?? NULL) !== NULL;
    }
    return TRUE;
  }

  /**
   * True when the server's entitlement currently allows enforcement.
   *
   * Without a server answer the site stays in Shadow: the module never
   * grants itself a trial.
   */
  public function enforcementAllowed(): bool {
    $entitlement = $this->entitlement();
    if (!$this->boundEntitlement($entitlement) || !self::validEntitlement($entitlement) || $entitlement['enforcement'] !== TRUE) {
      return FALSE;
    }
    // A trial or grace period that has ended drops back to Shadow at once,
    // without waiting for the next refresh.
    $ends = self::expiry($entitlement['ends_at'] ?? NULL);
    return $ends === NULL || $ends > time();
  }

  /**
   * Rejects an entitlement from a copied or superseded connection.
   */
  private function boundEntitlement(array $entitlement): bool {
    return !$this->copied() && (!array_key_exists('connection_id', $entitlement) || ($entitlement['connection_id'] !== NULL && $entitlement['connection_id'] === $this->connectionIdentity()));
  }

  /**
   * Parses an absolute RFC 3339 expiry; relative and normalized dates fail.
   */
  private static function expiry(mixed $value): ?int {
    if (!is_string($value) || strlen($value) > 64 || preg_match('/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D', $value) !== 1) {
      return NULL;
    }
    try {
      $date = new \DateTimeImmutable($value);
      $errors = \DateTimeImmutable::getLastErrors();
      return $errors === FALSE || ($errors['warning_count'] === 0 && $errors['error_count'] === 0) ? $date->getTimestamp() : NULL;
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Bypasses State's per-request cache for concurrent entitlement changes.
   */
  private function stateValue(string $key): mixed {
    return $this->keyValueFactory !== NULL ? $this->keyValueFactory->get('state')->get($key) : $this->state->get($key);
  }

}
