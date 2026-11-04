<?php

declare(strict_types=1);

namespace Drupal\decionis\Gate;

use Decionis\Client\Decision\Action;
use Decionis\Client\Decision\Request;
use Decionis\Client\Decision\Type;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * A bounded, immutable local capture for an evaluation after the response.
 *
 * It contains the same commerce facts as the request, never credentials,
 * recipient addresses, a session id, customer names or coupon codes.
 */
final class Capture {

  public const MAX_BYTES = 65536;

  private const FACTS = [
    'customer_hash', 'refunds_per_customer_90d', 'margin_coverage',
    'coupon_count', 'stacked_discount_percent', 'has_end_date',
    'orders_last_hour', 'coupon_attempts_session', 'adjustment_units',
    'shortfall_units',
    'store_currencies',
  ];

  public function __construct(
    public readonly Request $request,
    public readonly Context $context,
  ) {}

  /**
   * Serializes scalar data only; a queued item never deserializes PHP objects.
   */
  public function encode(): string {
    foreach ($this->context->facts as $key => $value) {
      if ($key === 'store_currencies') {
        if (!is_array($value) || count($value) > 20 || array_filter($value, static fn ($currency): bool => !is_string($currency) || preg_match('/^[A-Z]{3}$/D', $currency) !== 1)) {
          throw new \InvalidArgumentException('SHADOW_FACTS_UNSUPPORTED');
        }
        continue;
      }
      if (!in_array($key, self::FACTS, TRUE) || (!is_scalar($value) && $value !== NULL)) {
        throw new \InvalidArgumentException('SHADOW_FACTS_UNSUPPORTED');
      }
      if (is_string($value) && strlen($value) > 120) {
        throw new \InvalidArgumentException('SHADOW_FACTS_TOO_LARGE');
      }
    }
    $json = json_encode([
      'version' => 1,
      'action' => $this->request->action->toArray(),
      'source' => $this->request->source,
      'channel' => $this->request->channel,
      'policy' => $this->request->policyVersion,
      'context' => [
        'action' => $this->context->action,
        'hook' => $this->context->hook,
        'checkout' => $this->context->checkout,
        'target_type' => $this->context->targetType,
        'target_id' => $this->context->targetId,
        'at_stake' => $this->context->atStake,
        'currency' => $this->context->currency,
        'facts' => $this->context->facts,
        'source' => $this->context->source,
        'occurred' => $this->context->occurredAt,
      ],
    ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    if (strlen($json) > self::MAX_BYTES) {
      throw new \InvalidArgumentException('SHADOW_CAPTURE_TOO_LARGE');
    }
    return $json;
  }

  /**
   * Recreates the public client request through its validating action factories.
   */
  public static function decode(string $json): self {
    if ($json === '' || strlen($json) > self::MAX_BYTES) {
      throw new \InvalidArgumentException('SHADOW_CAPTURE_INVALID');
    }
    $data = json_decode($json, TRUE, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data) || ($data['version'] ?? NULL) !== 1 || !is_array($data['context'] ?? NULL)) {
      throw new \InvalidArgumentException('SHADOW_CAPTURE_INVALID');
    }
    if (!is_array($data['action'] ?? NULL) || !is_array($data['action']['payload'] ?? NULL)) {
      throw new \InvalidArgumentException('SHADOW_CAPTURE_INVALID');
    }
    $wire = $data['action'];
    self::requires($data, ['source', 'channel', 'policy']);
    self::requires($wire, ['action_type', 'actor', 'platform', 'idempotency_key']);
    $payload = $wire['payload'];
    $actor = $wire['actor'];
    $platform = $wire['platform'];
    $key = $wire['idempotency_key'];
    if (!is_string($wire['action_type']) || ($type = Type::tryFrom($wire['action_type'])) === NULL) {
      throw new \InvalidArgumentException('SHADOW_CAPTURE_INVALID');
    }
    $required = match ($type) {
      Type::RefundRequest => ['order_id', 'amount'],
      Type::PriceChange => ['sku', 'from_price', 'to_price', 'estimated_cost'],
      Type::PromotionChange => ['promotion_id', 'percentage_fraction', 'ends_at', 'status', 'combines_with'],
      Type::OrderAcceptance => ['order_id', 'gross_amount', 'discount_amount', 'estimated_cost'],
      Type::InventoryMutation => ['sku', 'from', 'to'],
      Type::FulfillmentAction => ['order_id', 'action'],
      Type::ReturnAuthorization => ['order_id'],
    };
    self::requires($payload, $required);
    $action = match ($type) {
      Type::RefundRequest => Action::refund($actor, $platform, $key, $payload['order_id'], $payload['amount'], $payload['currency'] ?? NULL, $payload['reason_code'] ?? NULL, $payload['remaining_refundable'] ?? NULL, $payload['prior_refund_count'] ?? NULL),
      Type::PriceChange => Action::price($actor, $platform, $key, $payload['sku'], $payload['from_price'], $payload['to_price'], $payload['estimated_cost'], $payload['currency'] ?? NULL),
      Type::PromotionChange => Action::promotion($actor, $platform, $key, $payload['promotion_id'], $payload['percentage_fraction'], $payload['ends_at'], $payload['status'], $payload['combines_with']),
      Type::OrderAcceptance => Action::order($actor, $platform, $key, $payload['order_id'], $payload['gross_amount'], $payload['discount_amount'], $payload['estimated_cost'], $payload['currency'] ?? NULL),
      Type::InventoryMutation => Action::inventory($actor, $platform, $key, $payload['sku'], $payload['from'], $payload['to'], $payload['location_id'] ?? NULL),
      Type::FulfillmentAction => Action::fulfillment($actor, $platform, $key, $payload['order_id'], $payload['action']),
      Type::ReturnAuthorization => Action::returnAuthorization($actor, $platform, $key, $payload['order_id'], $payload['rma_id'] ?? NULL, $payload['amount'] ?? NULL),
    };
    $where = $data['context'];
    self::requires($where, ['action', 'hook', 'checkout', 'target_type', 'target_id', 'at_stake', 'currency', 'facts', 'source']);
    $context = new Context($where['action'], $where['hook'], $where['checkout'], $where['target_type'], $where['target_id'], $where['at_stake'], $where['currency'], $where['facts'], $where['source'], 'shadow', occurredAt: $where['occurred'] ?? NULL);
    $capture = new self(new Request($action, $data['source'], $data['channel'], $data['policy'], $context->facts), $context);
    // Apply the same size and fact restrictions to stored data as to new data.
    $capture->encode();
    return $capture;
  }

  /**
   * Rejects corrupt stored data before indexing into it.
   *
   * @param array $values
   *   Decoded stored fields to check for required keys.
   * @param list<string> $keys
   *   Required JSON object keys, including nullable values.
   */
  private static function requires(array $values, array $keys): void {
    foreach ($keys as $key) {
      if (!array_key_exists($key, $values)) {
        throw new \InvalidArgumentException('SHADOW_CAPTURE_INVALID');
      }
    }
  }

  /**
   * A nudge is qualified only by the corresponding entity actually being saved.
   */
  public function matches(EntityInterface $entity): bool {
    if ($entity->getEntityTypeId() !== $this->context->targetType) {
      return FALSE;
    }
    $id = $this->context->targetId;
    if ($id !== NULL && $id !== '' && $id !== (string) $entity->id()) {
      return FALSE;
    }
    $payload = $this->request->action->payload;
    if ($this->context->action === 'price_change') {
      if (!$entity instanceof FieldableEntityInterface || !$entity->hasField('price') || !$entity->hasField('sku')) {
        return FALSE;
      }
      $price = $entity->get('price')->first()?->getValue() ?? [];
      return (string) $entity->get('sku')->value === $payload['sku']
        && (string) ($price['currency_code'] ?? '') === ($payload['currency'] ?? '')
        && abs((float) ($price['number'] ?? -1) - (float) $payload['to_price']) < 0.000001;
    }
    if ($id === NULL || $id === '') {
      return ($payload['promotion_id'] ?? $payload['order_id'] ?? NULL) === $entity->uuid();
    }
    return TRUE;
  }

}
