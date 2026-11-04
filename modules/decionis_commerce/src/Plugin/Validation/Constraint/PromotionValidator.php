<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Plugin\Validation\Constraint;

use Drupal\commerce_promotion\Entity\CouponInterface;
use Drupal\commerce_promotion\Entity\PromotionInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis_commerce\Map\Actions;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Asks Decionis about a promotion or coupon before it is saved.
 */
final class PromotionValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(private readonly Gate $gate, private readonly Actions $actions) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get(Gate::class), $container->get(Actions::class));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    $promotion = $value instanceof CouponInterface ? $value->getPromotion() : $value;
    if (!$promotion instanceof PromotionInterface || !$constraint instanceof Promotion || $this->gate->bypassed()) {
      return;
    }
    $outcome = $this->gate->check(...$this->actions->promotion($promotion, $value instanceof CouponInterface ? 'coupon_constraint' : 'promotion_constraint'));
    if (!$outcome->proceeds()) {
      $this->context->addViolation($constraint->message, ['@reason' => $outcome->message(FALSE)]);
    }
  }

}
