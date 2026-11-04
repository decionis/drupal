<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Approval;

use Decionis\Client\Decision\Action;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\decionis\Approval\Executor;
use Drupal\decionis\Entity\Hold;
use Drupal\decionis_commerce\Map\Actions;

/**
 * Saves an approved price change on its variation, once.
 */
final class PriceExecutor implements Executor {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Actions $actions,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function handles(string $action): bool {
    return $action === 'price_change';
  }

  /**
   * {@inheritdoc}
   */
  public function capture(Hold $hold): ?Action {
    [$variation, $to] = $this->target($hold);
    if ($variation === NULL || $to === NULL) {
      return NULL;
    }
    // The price as stored now, moving to the held one: any change since the
    // hold was opened changes the hash.
    $from = $variation->getPrice();
    $variation->setPrice($to);
    return $this->actions->price($variation, $from, $hold->value('hook'), 'drupal', $hold->actor())[0] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function run(Hold $hold): void {
    [$variation, $to] = $this->target($hold);
    if ($variation === NULL || $to === NULL) {
      throw new \LogicException('DECIONIS_PRICE_NOT_EXECUTABLE');
    }
    $variation->setPrice($to);
    $variation->save();
  }

  /**
   * The variation, read fresh from storage, and the held price.
   *
   * @return array
   *   The variation and held price, with NULL for either unavailable value.
   *
   * @phpstan-return array{?ProductVariationInterface, ?Price}
   */
  private function target(Hold $hold): array {
    $variation = $hold->value('target_id') === '' ? NULL : $this->entityTypeManager->getStorage('commerce_product_variation')->loadUnchanged($hold->value('target_id'));
    $parameters = $hold->binding()['action']['parameters'] ?? [];
    $to = is_array($parameters) && is_numeric($parameters['to_price'] ?? NULL) && is_string($parameters['currency'] ?? NULL)
      ? new Price((string) $parameters['to_price'], $parameters['currency'])
      : NULL;
    return [$variation instanceof ProductVariationInterface ? $variation : NULL, $to];
  }

}
