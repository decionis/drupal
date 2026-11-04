<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Plugin\Validation\Constraint;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis_commerce\Map\Actions;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Asks Decionis about a changed price; in Enforce a BLOCK or hold refuses it.
 */
final class PriceValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    private readonly Gate $gate,
    private readonly Actions $actions,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get(Gate::class), $container->get(Actions::class), $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    $variation = $value?->getEntity();
    if (!$variation instanceof ProductVariationInterface || !$constraint instanceof Price || $this->gate->bypassed()) {
      return;
    }
    $original = $variation->isNew() ? NULL : $this->entityTypeManager->getStorage('commerce_product_variation')->loadUnchanged($variation->id());
    $mapped = $this->actions->price($variation, $original instanceof ProductVariationInterface ? $original->getPrice() : NULL, 'price_constraint');
    if ($mapped === NULL) {
      return;
    }
    $outcome = $this->gate->check(...$mapped);
    if (!$outcome->proceeds()) {
      $this->context->addViolation($constraint->message, ['@reason' => $outcome->message(FALSE)]);
    }
  }

}
