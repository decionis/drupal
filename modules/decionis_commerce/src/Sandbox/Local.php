<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Sandbox;

use Decionis\Client\Canonical\SortedJson;
use Decionis\Client\Decision\Decision;
use Decionis\Client\Decision\Request;
use Decionis\Client\Dossier\Jwks;
use Decionis\Client\Intent\Intent;
use Drupal\decionis\Gate\Authority;
use Drupal\decionis\Report\Summary;
use Drupal\decionis_commerce\Report\Limits;

/**
 * The bundled demo authority.
 *
 * Scripted verdicts for the two starter packs, for offline demos and air-gapped
 * CI. Labelled "demo authority" everywhere it appears. It signs a sandbox
 * Dossier per decision with a key made for this run only, so verification is
 * real but proves nothing about production.
 */
final class Local implements Authority {

  public const POLICY = 'demo-drupal-commerce-starter-v1';
  private const KEY_ID = 'decionis-drupal-sandbox-ephemeral';

  /**
   * The ephemeral signing key used only for this sandbox run.
   */
  private string $secret;

  /**
   * The store's locally configured limits, when available.
   */
  private readonly ?Limits $limits;

  /**
   * The signed sandbox Dossiers keyed by their identifiers.
   *
   * @var array<string, \stdClass>
   */
  private array $dossiers = [];

  public function __construct(?Limits $limits = NULL) {
    $this->limits = $limits ?? (\Drupal::hasContainer() && \Drupal::hasService(Limits::class) ? \Drupal::service(Limits::class) : NULL);
    $this->secret = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return Summary::DEMO;
  }

  /**
   * {@inheritdoc}
   */
  public function available(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(Request $request, bool $checkout, ?int $budgetMs = NULL): Decision {
    $body = $request->toArray(NULL);
    $signals = is_array($body['context']['signals'] ?? NULL) ? $body['context']['signals'] : [];
    $currency = (string) ($signals['currency'] ?? 'EUR');
    [$outcome, $reason] = self::rule((string) $body['decision_type'], $signals, $this->limits?->forCurrency($currency));
    if ($body['decision_type'] === 'PROMOTION_CHANGE' && !isset($signals['currency']) && !empty($signals['store_currencies'])) {
      [$outcome, $reason] = ['APPROVE', NULL];
      foreach ($signals['store_currencies'] as $storeCurrency) {
        [$outcome, $reason] = self::rule('PROMOTION_CHANGE', $signals, $this->limits?->forCurrency($storeCurrency) ?? Limits::defaults($storeCurrency));
        if ($outcome !== 'APPROVE') {
          break;
        }
      }
    }
    $dossierId = Intent::uuid();
    $this->sign($dossierId, $outcome, $signals);
    return Decision::fromResponse([
      'outcome' => $outcome,
      'confidence' => 1,
      'policy_version' => self::POLICY,
      'objective_profile' => 'demo',
      'dossier_id' => $dossierId,
      'evaluation_id' => Intent::uuid(),
      'mode' => 'SHADOW',
      'fallback_to_legacy' => FALSE,
      'idempotent_replay' => FALSE,
      'reason_codes' => $reason === NULL ? [] : [$reason],
      'governance_metrics' => ['override_drift_rate' => 0, 'governance_tension_index' => 0, 'boundary_volatility_score' => 0, 'decision_volume_under_governance' => 0],
    ]);
  }

  /**
   * The scripted starter-pack rules.
   *
   * @param string $type
   *   The Commerce Gate decision type to evaluate.
   * @param array<string, mixed> $s
   *   The bounded commerce signals for the synthetic action.
   * @param array<string, mixed>|null $limits
   *   The store's currency limits, or NULL to use the starter defaults.
   *
   * @return array
   *   The scripted outcome and optional reason code.
   *
   * @phpstan-return array{string, ?string}
   */
  public static function rule(string $type, array $s, ?array $limits = NULL): array {
    $limits ??= Limits::defaults((string) ($s['currency'] ?? 'EUR'));
    $margin = is_numeric($s['net_margin_percent'] ?? NULL) ? (float) $s['net_margin_percent'] : NULL;
    return match (TRUE) {
      $type === 'REFUND_REQUEST' && (float) ($s['refund_amount'] ?? 0) > (float) $limits['refund_limit'] => ['REVIEW', 'refund_over_unattended_limit'],
      $type === 'REFUND_REQUEST' && ($s['refund_exceeds_refundable'] ?? FALSE) === TRUE => ['REJECT', 'refund_exceeds_refundable'],
      $type === 'REFUND_REQUEST' && (int) ($s['refunds_per_customer_90d'] ?? 0) >= (int) $limits['repeat_limit'] => ['REVIEW', 'serial_refunds_per_customer'],
      $type === 'PRICE_CHANGE' && $margin !== NULL && $margin < 0 => ['REJECT', 'price_below_cost'],
      $type === 'PROMOTION_CHANGE' && ($s['status'] ?? 'active') === 'active' && (float) ($s['stacked_discount_percent'] ?? 0) > (float) $limits['stacking_ceiling'] => ['REJECT', 'unauthorized_coupon_stacking'],
      $type === 'ORDER_ACCEPTANCE' && $margin !== NULL && $margin < (float) $limits['margin_floor'] => ['REJECT', 'below_margin_floor'],
      $type === 'INVENTORY_MUTATION' && (float) ($s['shortfall_units'] ?? 0) > 0 => ['REJECT', 'oversell'],
      default => ['APPROVE', NULL],
    };
  }

  /**
   * The key set that verifies this run's sandbox Dossiers.
   */
  public function jwks(): Jwks {
    $public = sodium_crypto_sign_publickey_from_secretkey($this->secret);
    return Jwks::fromArray(['keys' => [['kty' => 'OKP', 'crv' => 'Ed25519', 'alg' => 'EdDSA', 'use' => 'sig', 'kid' => self::KEY_ID, 'x' => sodium_bin2base64($public, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)]]]);
  }

  /**
   * Returns the signed Dossiers created during this sandbox run.
   *
   * @return array
   *   The Dossiers keyed by their identifiers.
   *
   * @phpstan-return array<string, \stdClass>
   */
  public function dossiers(): array {
    return $this->dossiers;
  }

  /**
   * Signs a synthetic Dossier with this run's ephemeral key.
   *
   * @param string $dossierId
   *   The identifier assigned to the sandbox decision's Dossier.
   * @param string $outcome
   *   The scripted outcome recorded in the signed decision.
   * @param array<string, mixed> $signals
   *   The synthetic commerce signals evaluated by the local rules.
   */
  private function sign(string $dossierId, string $outcome, array $signals): void {
    $portable = (object) [
      'artifact_type' => 'decionis.decision_dossier.portable',
      'dossier_id' => $dossierId,
      'label' => 'sandbox, not production evidence',
      'authority' => 'demo authority',
      'routing_decision' => (object) ['outcome' => $outcome, 'policy_version' => self::POLICY],
    ];
    $inputs = json_decode((string) json_encode($signals, JSON_PRESERVE_ZERO_FRACTION));
    $inputs = $inputs instanceof \stdClass ? $inputs : new \stdClass();
    $encoder = new SortedJson();
    $artifacts = [];
    foreach (['portable_artifact' => $portable, 'inputs_snapshot' => $inputs] as $kind => $document) {
      $bytes = $encoder->encode($document);
      $artifacts[] = (object) [
        'artifact_kind' => $kind,
        'media_type' => 'application/json',
        'document_path' => '/' . $kind,
        'canonical_document_sha256' => hash('sha256', $bytes),
        'signature' => sodium_bin2base64(sodium_crypto_sign_detached($bytes, $this->secret), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
      ];
    }
    $this->dossiers[$dossierId] = (object) [
      'dossier_id' => $dossierId,
      'portable_artifact' => $portable,
      'inputs_snapshot' => $inputs,
      'integrity' => (object) [
        'proof_bundle' => (object) [
          'bundle_type' => 'decionis.decision_dossier.proof_bundle',
          'version' => '2.0',
          'issued_at' => gmdate('Y-m-d\TH:i:s.000\Z'),
          'algorithm' => 'Ed25519',
          'key_id' => self::KEY_ID,
          'rotation_policy' => (object) ['strategy' => 'JWKS_OVERLAP', 'active_key_id' => self::KEY_ID, 'previous_key_ids' => [], 'verification_grace_period_days' => 1, 'rotated_at' => NULL, 'public_jwks_path' => '/.well-known/decision-dossier-jwks.json'],
          'artifacts' => $artifacts,
        ],
      ],
    ];
  }

}
