<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Map;

use Drupal\commerce\PurchasableEntityInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Reads unit cost from the recipe's cost field or an admin-mapped field.
 *
 * Returns NULL when unknown: margin rules then report "not covered" instead
 * of guessing.
 */
final class Cost {

  /**
   * Temporary unit costs keyed by entity type and identifier.
   *
   * @var array<string, float>
   */
  private array $overrides = [];

  public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

  /**
   * Returns the unit cost of a purchasable entity, or NULL when unknown.
   */
  public function of(PurchasableEntityInterface $entity, ?string $currency = NULL): ?float {
    $key = $entity->getEntityTypeId() . ':' . ($entity->id() ?? $entity->uuid());
    if (isset($this->overrides[$key])) {
      return $this->overrides[$key];
    }
    $field = (string) $this->configFactory->get('decionis_commerce.settings')->get('cost_field');
    if ($field === '' || !$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return NULL;
    }
    $values = $entity->get($field)->first()?->getValue() ?? [];
    if ($currency !== NULL && isset($values['currency_code']) && $values['currency_code'] !== $currency) {
      return NULL;
    }
    $value = $values['number'] ?? $values['value'] ?? NULL;
    return is_numeric($value) && (float) $value >= 0 ? (float) $value : NULL;
  }

  /**
   * Sets a cost for one entity without a field (the sandbox's synthetic store).
   */
  public function override(PurchasableEntityInterface $entity, float $cost): void {
    $this->overrides[$entity->getEntityTypeId() . ':' . ($entity->id() ?? $entity->uuid())] = $cost;
  }

  /**
   * Forgets every cost set with override().
   */
  public function clear(): void {
    $this->overrides = [];
  }

}
