<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Sandbox;

use Drupal\Core\Entity\EntityInterface;
use Drupal\commerce_promotion\Entity\PromotionInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\decionis_commerce\Map\Cost;

/**
 * A throwaway synthetic store.
 *
 * A few variations with cost, a payment on a manual gateway, and a promotion.
 * Built inside the sandbox transaction, so nothing persists.
 */
final class Store {

  public const CURRENCY = 'EUR';
  private const GATEWAY = 'decionis_sandbox_manual';
  private const MAIL = 'sandbox@example.invalid';
  private const SKU = 'decionis-sandbox-';

  /**
   * The synthetic store created for the current sandbox run.
   */
  public StoreInterface $store;

  /**
   * TRUE when this run imported the currency, so removing it is ours to do.
   */
  private bool $imported = FALSE;

  /**
   * The synthetic variations keyed by the scenario's SKU labels.
   *
   * @var array<string, ProductVariationInterface>
   */
  public array $variations = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Cost $cost,
  ) {}

  /**
   * Creates the synthetic store and its variations.
   */
  public function build(): self {
    if ($this->entityTypeManager->getStorage('commerce_currency')->load(self::CURRENCY) === NULL) {
      \Drupal::service('commerce_price.currency_importer')->import(self::CURRENCY);
      $this->imported = TRUE;
    }
    $this->bundles();
    $store = $this->create('commerce_store', [
      'type' => 'online',
      'name' => 'Decionis sandbox store',
      'mail' => self::MAIL,
      'default_currency' => self::CURRENCY,
      'timezone' => 'UTC',
      'address' => ['country_code' => 'DE', 'locality' => 'Berlin', 'postal_code' => '10115', 'address_line1' => 'Synthetic 1'],
    ]);
    assert($store instanceof StoreInterface);
    $this->store = $store;
    $this->create('commerce_payment_gateway', ['id' => self::GATEWAY, 'label' => 'Sandbox (manual)', 'plugin' => 'manual', 'configuration' => ['display_label' => 'Sandbox', 'mode' => 'n/a', 'instructions' => ['value' => '', 'format' => 'plain_text']]]);
    foreach (['SKU-1' => [100, 60], 'SKU-2' => [40, 30], 'SKU-3' => [50, 46]] as $sku => [$price, $cost]) {
      $variation = $this->create('commerce_product_variation', ['type' => 'default', 'sku' => self::SKU . $sku, 'title' => $sku, 'price' => new Price((string) $price, self::CURRENCY), 'status' => 1]);
      assert($variation instanceof ProductVariationInterface);
      $this->cost->override($variation, (float) $cost);
      $this->variations[$sku] = $variation;
    }
    $this->create('commerce_product', ['type' => 'default', 'title' => 'Sandbox product', 'stores' => [$store], 'variations' => array_values($this->variations), 'status' => 1]);
    return $this;
  }

  /**
   * A draft order for $quantity of a SKU, optionally with a completed payment.
   */
  public function order(string $sku, int $quantity, bool $paid = FALSE): OrderInterface {
    $item = $this->create('commerce_order_item', ['type' => 'default', 'purchased_entity' => $this->variations[$sku], 'quantity' => (string) $quantity, 'unit_price' => $this->variations[$sku]->getPrice(), 'title' => $sku]);
    $order = $this->create('commerce_order', ['type' => 'default', 'store_id' => $this->store->id(), 'mail' => self::MAIL, 'uid' => 0, 'state' => 'draft', 'order_items' => [$item]]);
    assert($order instanceof OrderInterface);
    $order->recalculateTotalPrice()->save();
    if ($paid) {
      $this->payment($order);
    }
    return $order;
  }

  /**
   * A completed payment for the whole order on the manual gateway.
   */
  public function payment(OrderInterface $order): PaymentInterface {
    $payment = $this->create('commerce_payment', ['type' => 'payment_manual', 'payment_gateway' => self::GATEWAY, 'order_id' => $order->id(), 'amount' => $order->getTotalPrice(), 'state' => 'completed']);
    assert($payment instanceof PaymentInterface);
    return $payment;
  }

  /**
   * An active order percentage promotion that combines with others.
   */
  public function promotion(string $name, float $fraction, bool $withCoupon = FALSE): PromotionInterface {
    $promotion = $this->entityTypeManager->getStorage('commerce_promotion')->create([
      'name' => $name,
      'display_name' => $name,
      'order_types' => ['default'],
      'stores' => [$this->store->id()],
      'status' => 1,
      'compatibility' => 'any',
      'offer' => ['target_plugin_id' => 'order_percentage_off', 'target_plugin_configuration' => ['percentage' => (string) $fraction]],
    ]);
    assert($promotion instanceof PromotionInterface);
    if ($withCoupon) {
      $promotion->set('require_coupon', TRUE);
    }
    return $promotion;
  }

  /**
   * Removes what a run left in the database, when its transaction was lost.
   *
   * Every record is found by a marker only the sandbox writes: its reserved
   * example.invalid address, its gateway id, its SKU prefix and its decision
   * source. The default bundles stay, as a store may have come to rely on them.
   */
  public function purge(): void {
    $gateways = $this->entityTypeManager->getStorage('commerce_payment_gateway');
    $stores = $this->ids('commerce_store', 'mail', self::MAIL);
    if ($stores === [] && $gateways->load(self::GATEWAY) === NULL) {
      return;
    }
    $this->remove('commerce_payment', $this->ids('commerce_payment', 'payment_gateway', self::GATEWAY));
    $this->remove('commerce_order', $this->ids('commerce_order', 'mail', self::MAIL));
    if ($stores !== []) {
      $this->remove('commerce_product', $this->ids('commerce_product', 'stores', $stores, 'IN'));
      $this->remove('commerce_promotion', $this->ids('commerce_promotion', 'stores', $stores, 'IN'));
    }
    $this->remove('commerce_product_variation', $this->ids('commerce_product_variation', 'sku', self::SKU, 'STARTS_WITH'));
    $this->remove('commerce_store', $stores);
    $gateways->load(self::GATEWAY)?->delete();
    $decisions = $this->ids('decionis_decision', 'source', 'sandbox');
    if ($decisions !== []) {
      $this->remove('decionis_hold', $this->ids('decionis_hold', 'log_id', $decisions, 'IN'));
      $this->remove('decionis_decision', $decisions);
    }
    if ($this->imported) {
      $this->entityTypeManager->getStorage('commerce_currency')->load(self::CURRENCY)?->delete();
    }
  }

  /**
   * The ids of the entities matching one condition.
   *
   * @param string $type
   *   The entity type to query.
   * @param string $field
   *   The entity field holding the sandbox marker.
   * @param mixed $value
   *   The value to match.
   * @param string $operator
   *   The comparison operator for the marker condition.
   *
   * @return list<int|string>
   *   The entity ids.
   */
  private function ids(string $type, string $field, mixed $value, string $operator = '='): array {
    return array_values($this->entityTypeManager->getStorage($type)->getQuery()->accessCheck(FALSE)->condition($field, $value, $operator)->execute());
  }

  /**
   * Deletes the entities with these ids.
   *
   * @param string $type
   *   The entity type whose storage owns the records.
   * @param list<int|string> $ids
   *   The entity ids.
   */
  private function remove(string $type, array $ids): void {
    if ($ids !== []) {
      $storage = $this->entityTypeManager->getStorage($type);
      $storage->delete($storage->loadMultiple($ids));
    }
  }

  /**
   * Creates the default bundles the sandbox needs on a site that has none.
   */
  private function bundles(): void {
    $bundles = [
      'commerce_order_item_type' => ['id' => 'default', 'label' => 'Default', 'orderType' => 'default', 'purchasableEntityType' => 'commerce_product_variation'],
      'commerce_product_variation_type' => ['id' => 'default', 'label' => 'Default', 'orderItemType' => 'default'],
      'commerce_product_type' => ['id' => 'default', 'label' => 'Default', 'variationTypes' => ['default']],
    ];
    foreach ($bundles as $type => $values) {
      if ($this->entityTypeManager->getStorage($type)->load('default') === NULL) {
        $this->create($type, $values);
      }
    }
  }

  /**
   * Creates and saves one synthetic entity.
   *
   * @param string $type
   *   The entity type to create.
   * @param array<string, mixed> $values
   *   The initial field values, including the sandbox's identifying markers.
   */
  private function create(string $type, array $values): EntityInterface {
    $entity = $this->entityTypeManager->getStorage($type)->create($values);
    $entity->save();
    return $entity;
  }

}
