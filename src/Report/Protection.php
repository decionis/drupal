<?php

declare(strict_types=1);

namespace Drupal\decionis\Report;

use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis_commerce\Gateway\Custody;
use Drupal\decionis_commerce\Report\Coverage;
use Drupal\decionis_commerce\Report\History;
use Drupal\decionis_commerce\Sandbox\Wiring;

/**
 * Seven named, locally observable setup checks, weighted to 100 points.
 *
 * A setup score does not certify a site or authorize a transaction.
 */
final class Protection {

  public const WEIGHTS = ['connected' => 15, 'hooks' => 20, 'costs' => 20, 'limits' => 15, 'enforced' => 15, 'custody' => 10, 'outside' => 5];

  public function __construct(
    private readonly Settings $settings,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly StateInterface $state,
    private readonly Connection $database,
  ) {}

  /**
   * Computes the current score without sending a request or changing a setting.
   *
   * @phpstan-impure
   */
  public function read(?array $coverage = NULL): array {
    $commerce = \Drupal::hasService(Coverage::class);
    $coverage ??= $commerce ? \Drupal::service(Coverage::class)->totals() : ['variations' => ['total' => 0, 'costed' => 0, 'percent' => 0], 'order_lines' => ['total' => 0, 'costed' => 0, 'percent' => 0]];
    $wiring = $commerce ? \Drupal::service(Wiring::class)->check() : ['points' => [], 'overrides' => []];
    $hooked = $wiring['points'] !== [] && !in_array(FALSE, $wiring['points'], TRUE) && $wiring['overrides'] === [];
    $shares = [];
    foreach (['variations', 'order_lines'] as $kind) {
      if ($coverage[$kind]['total'] > 0) {
        $shares[] = $coverage[$kind]['costed'] / $coverage[$kind]['total'];
      }
    }
    $cost = $shares === [] ? 0.0 : min($shares);
    $limits = $this->configFactory->get('decionis_commerce.settings')->get('limits') ?? [];
    $currencies = $commerce ? array_values(array_unique([...\Drupal::service(History::class)->currencies(), ...array_keys($limits), ...array_keys($coverage['by_currency'] ?? [])])) : [];
    $tuned = $currencies !== [];
    foreach ($currencies as $currency) {
      $line = $limits[$currency] ?? [];
      $tuned = $tuned && in_array($line['source'] ?? '', ['history', 'tuned'], TRUE) && ($line['defaults'] ?? []) === [];
    }
    $actions = array_keys(Activity::LABELS);
    if (Enforcement::fincraOnly()) {
      $actions = array_values(array_diff($actions, ['refund_request']));
    }
    $enforced = $this->settings->connected() && in_array('enforce', array_map($this->settings->mode(...), $actions), TRUE);
    $outside = (int) $this->database->select('decionis_decision', 'd')->condition('effect', 'outside')->condition('hook', 'outside_refund')->condition('authority', Summary::DEMO, '<>')->countQuery()->execute()->fetchField() > 0;
    $checks = [
      'connected' => ['label' => 'Connected', 'share' => $this->settings->connected() ? 1 : 0, 'detail' => 'A workspace and site-bound credentials are present.', 'route' => 'decionis.connect'],
      'hooks' => ['label' => 'Hook points active', 'share' => $hooked ? 1 : 0, 'detail' => sprintf('%d of %d hooks active; %d custom overrides need review.', count(array_filter($wiring['points'])), count($wiring['points']), count($wiring['overrides'])), 'route' => 'decionis.sandbox'],
      'costs' => ['label' => 'Cost coverage', 'share' => $cost, 'detail' => self::coverageText($coverage), 'route' => 'decionis.cost_import'],
      'limits' => ['label' => 'Limits tuned', 'share' => $tuned ? 1 : 0, 'detail' => 'Every store currency uses history-derived or edited limits, without fallback defaults.', 'route' => 'decionis.settings'],
      'enforced' => ['label' => 'At least one action enforced', 'share' => $enforced ? 1 : 0, 'detail' => 'An action is in Enforce and the current server entitlement allows it.', 'route' => 'decionis.decisions'],
      'custody' => ['label' => 'Payment key held by AgentSafe', 'share' => $this->custody() ? 1 : 0, 'detail' => 'The configured payment key is held by AgentSafe under root or a separate user, outside Drupal’s account.', 'route' => 'decionis.gateway_preview'],
      'outside' => ['label' => 'Outside refunds visible', 'share' => $outside ? 1 : 0, 'detail' => 'At least one outside refund has reached the local payment-update observer. Configure and test your payment webhook if none has arrived.', 'route' => 'decionis.checklist', 'fragment' => 'outside-refunds'],
    ];
    $score = 0;
    foreach ($checks as $key => &$check) {
      $check['weight'] = self::WEIGHTS[$key];
      $check['points'] = (int) floor(self::WEIGHTS[$key] * max(0.0, min(1.0, $check['share'])) + 0.000001);
      $score += $check['points'];
    }
    unset($check);
    return ['score' => $score, 'checks' => $checks, 'coverage' => $coverage];
  }

  /**
   * Coverage in both denominators; empty data never masquerades as 100%.
   */
  public static function coverageText(array $coverage): string {
    $v = $coverage['variations'];
    $o = $coverage['order_lines'];
    return sprintf('Variations with cost: %d of %d (%s%%). Order lines with cost: %d of %d (%s%%).', $v['costed'], $v['total'], $v['percent'], $o['costed'], $o['total'], $o['percent']);
  }

  /**
   * Requires the recorded move and the matching gateway's non-secret flags.
   */
  private function custody(): bool {
    $protection = $this->state->get('decionis.protection');
    if (!is_array($protection) || empty($protection['custody']) || !in_array($protection['tier'] ?? '', ['root', 'separate'], TRUE) || !$this->entityTypeManager->hasDefinition('commerce_payment_gateway') || !\Drupal::hasService(Custody::class) || !\Drupal::service(Custody::class)->valid($protection)) {
      return FALSE;
    }
    $gateway = $this->entityTypeManager->getStorage('commerce_payment_gateway')->load($protection['gateway'] ?? '');
    if (!$gateway instanceof PaymentGatewayInterface) {
      return FALSE;
    }
    $config = $gateway->getPluginConfiguration();
    return ($config['secret_key_held_by_gateway'] ?? FALSE) === TRUE && ($config['secret_key_test'] ?? '') === '' && ($config['secret_key_live'] ?? '') === '' && ($config['api_base_url'] ?? '') === 'http://127.0.0.1:' . ($protection['port'] ?? '');
  }

}
