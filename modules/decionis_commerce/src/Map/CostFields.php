<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Map;

use Drupal\commerce_product\Entity\ProductVariationTypeInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Adds editable unit cost to every variation bundle, without resetting fields.
 */
final class CostFields {

  public const FIELD = 'decionis_cost';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityDisplayRepositoryInterface $entityDisplayRepository,
  ) {}

  /**
   * Installation, recipe reapply and update hooks share this idempotent path.
   */
  public function ensure(): void {
    foreach ($this->entityTypeManager->getStorage('commerce_product_variation_type')->loadMultiple() as $type) {
      if ($type instanceof ProductVariationTypeInterface) {
        $this->bundle($type->id());
      }
    }
  }

  /**
   * Adds the field and its widget to one known variation type.
   */
  public function bundle(string $bundle): void {
    if ($this->entityTypeManager->getStorage('commerce_product_variation_type')->load($bundle) === NULL) {
      return;
    }
    $storage = FieldStorageConfig::loadByName('commerce_product_variation', self::FIELD);
    if ($storage === NULL) {
      $storage = FieldStorageConfig::create(['field_name' => self::FIELD, 'entity_type' => 'commerce_product_variation', 'type' => 'commerce_price', 'cardinality' => 1, 'translatable' => FALSE]);
      $storage->save();
    }
    if ($storage->getType() !== 'commerce_price') {
      throw new \UnexpectedValueException('DECIONIS_COST_FIELD_TYPE_CONFLICT');
    }
    if (FieldConfig::loadByName('commerce_product_variation', $bundle, self::FIELD) === NULL) {
      FieldConfig::create([
        'field_storage' => $storage,
        'bundle' => $bundle,
        'label' => 'Cost',
        'description' => 'What one unit costs you. Leave it empty when unknown; margin checks then report not covered.',
        'required' => FALSE,
        'translatable' => FALSE,
      ])->save();
    }
    $display = $this->entityDisplayRepository->getFormDisplay('commerce_product_variation', $bundle, 'default');
    if ($display->getComponent(self::FIELD) === NULL) {
      $display->setComponent(self::FIELD, ['type' => 'commerce_price_default', 'weight' => 10])->save();
    }
  }

}
