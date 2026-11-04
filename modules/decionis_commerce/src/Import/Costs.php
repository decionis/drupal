<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Import;

use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Validates a bounded cost CSV before any variation is changed.
 */
final class Costs {

  public const MAX_BYTES = 2097152;
  public const MAX_ROWS = 5000;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Parses at most 2 MiB and 5,000 unique rows, retaining no uploaded file.
   */
  public function read(string $path): array {
    if (!is_file($path) || !is_readable($path) || filesize($path) > self::MAX_BYTES) {
      throw new \InvalidArgumentException('Choose a readable CSV no larger than 2 MiB.');
    }
    $handle = fopen($path, 'r');
    if ($handle === FALSE) {
      throw new \InvalidArgumentException('The CSV could not be read.');
    }
    try {
      $header = fgetcsv($handle, self::MAX_BYTES + 1, ',', '"', '\\');
      $header = is_array($header) ? array_map(static fn ($value): string => strtolower(trim((string) $value, "\xEF\xBB\xBF \t\r\n")), $header) : [];
      if (count(array_unique($header)) !== count($header) || !in_array('sku', $header, TRUE) || !in_array('cost', $header, TRUE) || array_diff($header, ['sku', 'cost', 'currency']) !== []) {
        throw new \InvalidArgumentException('Use the header sku,cost, with an optional currency column.');
      }
      $columns = array_flip($header);
      $rows = [];
      while (($line = fgetcsv($handle, self::MAX_BYTES + 1, ',', '"', '\\')) !== FALSE) {
        if ($line === [NULL]) {
          continue;
        }
        $number = count($rows) + 2;
        $sku = trim((string) ($line[$columns['sku']] ?? ''));
        $cost = trim((string) ($line[$columns['cost']] ?? ''));
        $currency = isset($columns['currency']) ? strtoupper(trim((string) ($line[$columns['currency']] ?? ''))) : '';
        if (count($rows) >= self::MAX_ROWS || count($line) !== count($header) || $sku === '' || strlen($sku) > 255 || preg_match('/[\x00-\x1f]/', $sku) || isset($rows[$sku]) || preg_match('/^\d{1,13}(?:\.\d{1,6})?$/D', $cost) !== 1 || ($currency !== '' && preg_match('/^[A-Z]{3}$/D', $currency) !== 1)) {
          throw new \InvalidArgumentException(sprintf('Row %d is invalid or repeated. Use unique SKUs, a non-negative decimal cost, and at most 5,000 rows.', $number));
        }
        $rows[$sku] = ['sku' => $sku, 'cost' => $cost, 'currency' => $currency];
      }
      if ($rows === []) {
        throw new \InvalidArgumentException('The CSV has no cost rows.');
      }
      return array_values($rows);
    }
    finally {
      fclose($handle);
    }
  }

  /**
   * Resolves all rows and rejects mismatched currencies before starting Batch.
   */
  public function prepare(array $rows): array {
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $field = (string) $this->configFactory->get('decionis_commerce.settings')->get('cost_field');
    foreach ($rows as $index => &$row) {
      $matches = $storage->loadByProperties(['sku' => $row['sku']]);
      $variation = reset($matches);
      if (count($matches) !== 1 || !$variation instanceof ProductVariationInterface || $field === '' || !$variation->hasField($field) || $variation->getPrice() === NULL) {
        throw new \InvalidArgumentException(sprintf('Row %d needs an existing variation with a configured cost field and price currency.', $index + 2));
      }
      $currency = $variation->getPrice()->getCurrencyCode();
      if ($row['currency'] !== '' && $row['currency'] !== $currency) {
        throw new \InvalidArgumentException(sprintf('Row %d has a different currency from its variation.', $index + 2));
      }
      $row['currency'] = $currency;
      $row['id'] = (string) $variation->id();
      $this->set($variation, $field, $row);
      // Validation is limited to the cost field, so this read-only preflight
      // does not invoke the product-price policy constraint.
      if ($variation->get($field)->validate()->count() > 0) {
        throw new \InvalidArgumentException(sprintf('Row %d has a cost the configured field cannot store.', $index + 2));
      }
      $storage->resetCache([$variation->id()]);
    }
    unset($row);
    return $rows;
  }

  /**
   * Saves one prepared row after rechecking the current field and currency.
   */
  public function save(array $row): void {
    $storage = $this->entityTypeManager->getStorage('commerce_product_variation');
    $variation = $storage->loadUnchanged($row['id']);
    $field = (string) $this->configFactory->get('decionis_commerce.settings')->get('cost_field');
    if (!$variation instanceof ProductVariationInterface || !$variation->hasField($field) || $variation->getSku() !== $row['sku'] || $variation->getPrice()?->getCurrencyCode() !== $row['currency']) {
      throw new \RuntimeException('A variation changed during the import. Review it before importing again.');
    }
    $this->set($variation, $field, $row);
    $variation->save();
    $storage->resetCache([$variation->id()]);
  }

  /**
   * Uses the configured field's own representation without currency guessing.
   */
  private function set(ProductVariationInterface $variation, string $field, array $row): void {
    $type = $variation->get($field)->getFieldDefinition()->getType();
    if (!in_array($type, ['commerce_price', 'decimal', 'float', 'integer'], TRUE)) {
      throw new \InvalidArgumentException('The configured cost field must hold a number or a Commerce price.');
    }
    $variation->set($field, $type === 'commerce_price' ? ['number' => $row['cost'], 'currency_code' => $row['currency']] : $row['cost']);
  }

}
