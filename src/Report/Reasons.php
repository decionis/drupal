<?php

declare(strict_types=1);

namespace Drupal\decionis\Report;

/**
 * Plain explanations of the Drupal starter rules and local gate outcomes.
 */
final class Reasons {

  public const TEXT = [
    'refund_over_unattended_limit' => 'This refund is above the limit for refunds without approval.',
    'refund_exceeds_refundable' => 'This refund exceeds the amount still available to refund.',
    'serial_refunds_per_customer' => 'The repeat-refund limit has been reached.',
    'price_below_cost' => 'This price is below the recorded cost.',
    'unauthorized_coupon_stacking' => 'The combined promotions exceed the stacking ceiling.',
    'below_margin_floor' => 'This order is below the margin floor.',
    'oversell' => 'This stock change would leave fewer units than promised.',
    'COST_BASIS_UNKNOWN' => 'The cost is missing, so the margin check could not cover this action.',
    'NOT_CONNECTED' => 'The site is not connected, so no policy decision was available.',
    'GATE_ERROR' => 'Commerce Gate was unavailable or could not return a usable decision.',
    'APPROVAL_NOT_AVAILABLE' => 'This action needs approval, but approval is not available.',
    'REJECTED' => 'The approver refused this action.',
    'outside_refund' => 'This refund was made outside Drupal and was observed afterwards.',
    'SHADOW_CONNECTION_CHANGED' => 'The site connection changed before this queued action could be evaluated.',
    'SHADOW_CAPTURE_FAILED' => 'The action could not be queued for a later policy check.',
    'AUTHENTICATION_FAILED' => 'The site credential was not accepted by Commerce Gate.',
    'AUTHORIZATION_FAILED' => 'The site credential does not allow this policy request.',
    'NOT_FOUND' => 'The requested policy resource was not found.',
    'CONFLICT' => 'The policy request conflicted with an existing record.',
    'RATE_LIMITED' => 'The policy service asked the site to wait before trying again.',
    'UPSTREAM_UNAVAILABLE' => 'Commerce Gate was temporarily unavailable.',
    'UPSTREAM_UNREACHABLE' => 'Commerce Gate could not be reached within the request budget.',
    'REQUEST_REJECTED' => 'Commerce Gate did not accept the policy request.',
    'RESPONSE_TOO_LARGE' => 'The policy response exceeded the safe size limit.',
    'EVALUATION_MODE_UNCONFIRMED' => 'The response did not confirm the requested policy mode.',
    'EVALUATION_RESPONSE_MALFORMED' => 'The response did not contain a usable policy decision.',
    'EVALUATION_POLICY_VERSION_MISMATCH' => 'The response used a different policy version from the one requested.',
    'POLICY' => 'The policy requires this action to stop.',
  ];

  /**
   * Returns plain text. Unknown codes are retained, never treated as markup.
   */
  public static function text(string $code): string {
    return self::TEXT[$code] ?? $code;
  }

  /**
   * Joins explanations for a decision without exposing its input payload.
   *
   * @param list<string> $codes
   *   The recorded reason codes.
   * @param bool $historicalRefund
   *   Whether refund explanations should describe cumulative history.
   */
  public static function join(array $codes, bool $historicalRefund = FALSE): string {
    $history = [
      'refund_over_unattended_limit' => 'The payment’s cumulative refunded amount is above the limit for refunds without approval.',
      'refund_exceeds_refundable' => 'The payment’s cumulative refunded amount exceeds the refundable amount in the recorded snapshot.',
      'serial_refunds_per_customer' => 'The history-based repeat-refund limit has been reached.',
    ];
    return implode(' ', array_map(static fn (string $code): string => $historicalRefund ? ($history[$code] ?? self::text($code)) : self::text($code), $codes));
  }

}
