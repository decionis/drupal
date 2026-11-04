<?php

declare(strict_types=1);

namespace Drupal\decionis\Gate;

use Drupal\Core\Config\ConfigFactoryInterface;
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

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly ?Binding $binding = NULL,
  ) {}

  /**
   * The effective mode for an action key such as 'refund_request'.
   */
  public function mode(string $action): string {
    return $this->chosen($action) === 'enforce' && $this->enforcementAllowed() ? 'enforce' : 'shadow';
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
    $value = $this->state->get(self::ENTITLEMENT);
    return is_array($value) ? $value : [];
  }

  /**
   * True when the server's entitlement currently allows enforcement.
   *
   * Without a server answer the site stays in Shadow: the module never
   * grants itself a trial.
   */
  public function enforcementAllowed(): bool {
    if ($this->copied()) {
      return FALSE;
    }
    $entitlement = $this->entitlement();
    if (array_key_exists('connection_id', $entitlement) && ($entitlement['connection_id'] === NULL || $entitlement['connection_id'] !== $this->connectionIdentity())) {
      return FALSE;
    }
    if (($entitlement['enforcement'] ?? FALSE) !== TRUE) {
      return FALSE;
    }
    // A trial or grace period that has ended drops back to Shadow at once,
    // without waiting for the next refresh.
    $ends = $entitlement['ends_at'] ?? NULL;
    return !is_string($ends) || (strtotime($ends) !== FALSE && strtotime($ends) > time());
  }

}
