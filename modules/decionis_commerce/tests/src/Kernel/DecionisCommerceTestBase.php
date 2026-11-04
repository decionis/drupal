<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Kernel;

use Drupal\Tests\commerce_order\Kernel\OrderKernelTestBase;

/**
 * Drupal Commerce with payments, promotions and Decionis installed.
 */
abstract class DecionisCommerceTestBase extends OrderKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'commerce_payment',
    'commerce_promotion',
    'commerce_checkout',
    'key',
    'decionis',
    'decionis_commerce',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('commerce_payment_method');
    $this->installEntitySchema('commerce_promotion');
    $this->installEntitySchema('commerce_promotion_coupon');
    $this->installEntitySchema('decionis_decision');
    $this->installEntitySchema('decionis_hold');
    $this->installSchema('commerce_promotion', ['commerce_promotion_usage']);
    $this->installConfig(['commerce_payment', 'commerce_promotion', 'decionis', 'decionis_commerce']);
  }

  /**
   * Every row of every table, for "nothing changed" assertions.
   *
   * @return array<string, string>
   *   Row counts and content hashes keyed by database table name.
   */
  protected function snapshot(): array {
    $database = $this->container->get('database');
    $tables = $database->schema()->findTables('%');
    sort($tables);
    $snapshot = [];
    foreach ($tables as $table) {
      $rows = array_map(static fn (object $row): array => (array) $row, $database->select($table, 't')->fields('t')->execute()->fetchAll());
      $snapshot[$table] = count($rows) . ':' . hash('sha256', serialize($rows));
    }
    return $snapshot;
  }

}
