<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce;

use Composer\InstalledVersions;
use Drupal\commerce_promotion\Entity\PromotionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;

/**
 * The only place that knows which Drupal Commerce line is installed.
 *
 * The rest of the module never branches on version.
 */
final class Compatibility {

  /**
   * The installed Commerce major version, cached after its first lookup.
   */
  private ?int $major = NULL;

  public function __construct(private readonly EntityFieldManagerInterface $entityFieldManager) {}

  /**
   * The installed Commerce major version (2 or 3).
   */
  public function major(): int {
    if ($this->major === NULL) {
      $version = class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('drupal/commerce')
        ? (string) InstalledVersions::getVersion('drupal/commerce')
        : '3';
      $this->major = (int) $version >= 3 || str_starts_with($version, '3') ? 3 : 2;
    }
    return $this->major;
  }

  /**
   * The payment field that dates a refund.
   *
   * Commerce 3 stamps when a payment last changed, which a refund does.
   * Commerce 2 payments carry only when they completed.
   */
  public function refundedAt(): string {
    return isset($this->entityFieldManager->getBaseFieldDefinitions('commerce_payment')['changed']) ? 'changed' : 'completed';
  }

  /**
   * Which discounts a promotion may combine with, in Commerce Gate terms.
   *
   * @return array
   *   Whether order, product and shipping discounts may combine.
   *
   * @phpstan-return array{order_discounts: bool, product_discounts: bool, shipping_discounts: bool}
   */
  public function combinesWith(PromotionInterface $promotion): array {
    $any = $promotion->getCompatibility() !== PromotionInterface::COMPATIBLE_NONE;
    return ['order_discounts' => $any, 'product_discounts' => $any, 'shipping_discounts' => $any];
  }

  /**
   * Reads a percentage discount as a 0-1 fraction.
   *
   * Returns NULL for a fixed-amount or other offer.
   */
  public function percentage(PromotionInterface $promotion): ?float {
    $offer = $promotion->get('offer')->first()?->getValue() ?? [];
    $percentage = $offer['target_plugin_configuration']['percentage'] ?? NULL;
    return is_numeric($percentage) ? max(0.0, min(1.0, (float) $percentage)) : NULL;
  }

}
