<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A promotion (or its coupons) must pass the store's Decionis policy.
 */
#[Constraint(
  id: 'DecionisPromotion',
  label: new TranslatableMarkup('Decionis promotion policy', [], ['context' => 'Validation']),
  type: ['entity'],
)]
final class Promotion extends SymfonyConstraint {

  /**
   * The policy refusal message with its reason placeholder.
   */
  public string $message = '@reason';

}
