<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A variation price change must pass the store's Decionis policy.
 *
 * On the field, so admin forms, imports, JSON:API, ECA and agents that save
 * variations are all checked.
 */
#[Constraint(
  id: 'DecionisPrice',
  label: new TranslatableMarkup('Decionis price policy', [], ['context' => 'Validation']),
)]
final class Price extends SymfonyConstraint {

  /**
   * The policy refusal message with its reason placeholder.
   */
  public string $message = '@reason';

}
