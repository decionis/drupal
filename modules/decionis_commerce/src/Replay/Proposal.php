<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Replay;

use Decionis\Client\Canonical\Jcs;
use Decionis\Client\Decision\Action;
use Decionis\Client\Decision\Type;
use Drupal\decionis\Gate\Context;

/**
 * Stable replay identity, independent of the admin or cron's current user.
 *
 * Uses only the client's published action factories and declared fields.
 */
final class Proposal {

  /**
   * Binds the request to its connection, entity, policy and complete inputs.
   *
   * The first unpinned evaluation resolves the run's policy. Its random run
   * token prevents a cached result from silently selecting yesterday's policy.
   */
  public static function action(Action $action, Context $context, string $identity, ?string $policy, string $resolution): Action {
    $payload = $action->payload;
    if ($action->type === Type::RefundRequest) {
      // This is today's balance after the refund, not what could be refunded
      // beforehand. Omit the optional fact rather than inventing a violation.
      unset($payload['remaining_refundable']);
    }
    $key = 'drupal:replay:' . hash('sha256', (new Jcs())->encode([
      'connection' => $identity,
      'source' => 'replay',
      'action' => $action->type->value,
      'entity' => [$context->targetType, $context->targetId],
      'policy' => $policy === NULL ? ['resolve' => $resolution] : ['version' => $policy],
      'payload' => $payload,
      'facts' => $context->facts,
    ]));
    $actor = ['type' => 'WORKFLOW', 'id' => 'drupal:replay'];
    return match ($action->type) {
      Type::OrderAcceptance => Action::order($actor, $action->platform, $key, $payload['order_id'], $payload['gross_amount'], $payload['discount_amount'], $payload['estimated_cost'], $payload['currency'] ?? NULL),
      Type::RefundRequest => Action::refund($actor, $action->platform, $key, $payload['order_id'], $payload['amount'], $payload['currency'] ?? NULL, $payload['reason_code'] ?? NULL, $payload['remaining_refundable'] ?? NULL, $payload['prior_refund_count'] ?? NULL),
      Type::PromotionChange => Action::promotion($actor, $action->platform, $key, $payload['promotion_id'], $payload['percentage_fraction'], $payload['ends_at'], $payload['status'], $payload['combines_with']),
      default => throw new \InvalidArgumentException('REPLAY_ACTION_INVALID'),
    };
  }

}
