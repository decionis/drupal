<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Unit;

use Decionis\Client\Decision\Action;
use Decionis\Client\Decision\Request;
use Drupal\decionis\Gate\Capture;
use Drupal\decionis\Gate\Context;
use Drupal\Tests\UnitTestCase;

/**
 * Deferred captures preserve every canonical Commerce Gate action contract.
 *
 * @group decionis
 */
final class CaptureTest extends UnitTestCase {

  /**
   * Payloads, derived facts, pins and idempotency survive storage unchanged.
   */
  public function testAllSevenActionsKeepTheirRequestAndContext(): void {
    $actor = ['type' => 'WORKFLOW', 'id' => 'drupal:synthetic-capture'];
    $actions = [
      Action::price($actor, 'drupal', 'synthetic-price', 'SKU-1', NULL, 96.0, 100.0, 'USD'),
      Action::inventory($actor, 'drupal', 'synthetic-inventory', 'SKU-1', NULL, 5, 'warehouse-1'),
      Action::order($actor, 'drupal', 'synthetic-order', NULL, 100.0, 10.0, 50.0, 'USD'),
      Action::fulfillment($actor, 'drupal', 'synthetic-fulfillment', 'order-1', 'ship'),
      Action::promotion($actor, 'drupal', 'synthetic-promotion', 'promotion-1', 0.15, NULL, 'active', ['order_discounts' => TRUE, 'product_discounts' => FALSE, 'shipping_discounts' => TRUE]),
      Action::refund($actor, 'drupal', 'synthetic-refund', 'order-1', 20.0, 'USD', 'OTHER', 80.0, 2),
      Action::returnAuthorization($actor, 'drupal', 'synthetic-return', 'order-1', 'rma-1', 20.0),
    ];
    $facts = ['store_currencies' => ['USD', 'EUR'], 'refunds_per_customer_90d' => 2];
    foreach ($actions as $action) {
      $request = new Request($action, 'drupal:synthetic', 'workflow', 'synthetic-v1', $facts);
      $context = new Context($action->type->transactionType(), 'synthetic_capture', TRUE, 'synthetic_entity', 'fixture-1', 20.0, 'USD', $facts, 'webhook', 'shadow', occurredAt: 1700000000);
      $json = (new Capture($request, $context))->encode();
      $decoded = Capture::decode($json);
      $org = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaa1';
      $this->assertSame($request->toArray($org), $decoded->request->toArray($org), $action->type->value);
      $this->assertSame($json, $decoded->encode());
    }
  }

  /**
   * Unknown and malformed action types keep the stored-capture refusal.
   */
  public function testInvalidActionTypesAreRejected(): void {
    $action = Action::price(['type' => 'WORKFLOW', 'id' => 'drupal:synthetic'], 'drupal', 'synthetic-invalid-type', 'SKU-1', 100.0, 96.0, 100.0, 'USD');
    $capture = new Capture(new Request($action, 'drupal:synthetic'), new Context('price_change', 'synthetic_capture'));
    $wire = json_decode($capture->encode(), TRUE, 32, JSON_THROW_ON_ERROR);
    foreach (['UNSUPPORTED_ACTION', NULL, 42, []] as $type) {
      $wire['action']['action_type'] = $type;
      try {
        Capture::decode(json_encode($wire, JSON_THROW_ON_ERROR));
        $this->fail('The invalid action type was accepted.');
      }
      catch (\InvalidArgumentException $error) {
        $this->assertSame('SHADOW_CAPTURE_INVALID', $error->getMessage());
      }
    }
  }

}
