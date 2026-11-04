<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Report;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Suggested limits from this site's history, separately for every currency.
 *
 * These are local estimates until an administrator accepts them. They never
 * claim to change the connected workspace's policy.
 */
final class Limits {

  public const MINIMUM = 20;

  public function __construct(private readonly ConfigFactoryInterface $configFactory) {}

  /**
   * Documented starting points, not exchange-rate conversions.
   *
   * Unknown currencies start at zero: every positive refund needs review
   * until there is enough history or the merchant chooses an amount.
   */
  public static function defaults(string $currency): array {
    return [
      'refund_limit' => ['EUR' => 500.0, 'USD' => 500.0, 'GBP' => 400.0, 'NGN' => 150000.0, 'GHS' => 5000.0][$currency] ?? 0.0,
      'margin_floor' => 12.0,
      'stacking_ceiling' => 30.0,
      'repeat_limit' => 3,
      'source' => 'defaults',
    ];
  }

  /**
   * The configured values, or explicit currency defaults.
   */
  public function forCurrency(string $currency): array {
    $saved = $this->configFactory->get('decionis_commerce.settings')->get('limits.' . $currency);
    return is_array($saved) ? $saved + self::defaults($currency) : self::defaults($currency);
  }

  /**
   * Drafts the 95th percentile refund, stacking and repeat-refund limits.
   *
   * Margin uses the 10th percentile, capped at the median. Samples contain
   * numbers only, grouped by currency, never customer or coupon identifiers.
   */
  public static function draft(array $samples, array $currencies = []): array {
    $draft = [];
    foreach (array_unique([...$currencies, ...array_keys($samples)]) as $currency) {
      $data = $samples[$currency] ?? [];
      $values = self::defaults($currency);
      $fallback = [];
      foreach (['refund_limit' => 'refunds', 'margin_floor' => 'margins', 'stacking_ceiling' => 'stacking', 'repeat_limit' => 'repeats'] as $limit => $kind) {
        $numbers = array_values(array_filter($data[$kind] ?? [], static fn ($value): bool => is_numeric($value) && is_finite((float) $value)));
        if (count($numbers) >= self::MINIMUM) {
          $value = self::percentile($numbers, $kind === 'margins' ? 0.1 : 0.95);
          $values[$limit] = $kind === 'repeats' ? max(1, (int) $value + 1) : round($value, 6);
          if ($kind === 'stacking') {
            $values[$limit] = max(0.0, min(100.0, $values[$limit]));
          }
        }
        else {
          $fallback[] = $limit;
        }
        if ($kind === 'margins' && $numbers !== []) {
          $values[$limit] = min($values[$limit], self::percentile($numbers, 0.5));
        }
      }
      $values['source'] = $fallback === [] ? 'history' : (count($fallback) === 4 ? 'defaults' : 'history_with_defaults');
      $values['defaults'] = $fallback;
      $values['findings'] = self::findings($currency, $values, $data);
      $draft[$currency] = $values;
    }
    ksort($draft);
    return $draft;
  }

  /**
   * Nearest-rank percentile. Empty samples cannot invent an amount.
   */
  public static function percentile(array $numbers, float $percentile): float {
    if ($numbers === []) {
      return 0.0;
    }
    sort($numbers, SORT_NUMERIC);
    return (float) $numbers[max(0, min(count($numbers) - 1, (int) ceil(count($numbers) * $percentile) - 1))];
  }

  /**
   * Recomputes the findings when an administrator edits a proposed value.
   */
  public static function findings(string $currency, array $values, array $samples): array {
    $findings = [];
    foreach (['refund_limit' => 'refunds', 'margin_floor' => 'margins', 'stacking_ceiling' => 'stacking', 'repeat_limit' => 'repeats'] as $limit => $kind) {
      $numbers = $samples[$kind] ?? [];
      $threshold = (float) $values[$limit];
      $outside = array_filter($numbers, static fn ($value): bool => $kind === 'margins' ? $value < $threshold : ($kind === 'repeats' ? $value >= $threshold : $value > $threshold));
      $count = count($numbers);
      $within = $count - count($outside);
      $text = sprintf('%d of %d observations are within this limit; %d would be flagged.', $within, $count, count($outside));
      if ($kind === 'refunds') {
        $text = sprintf('%d of %d cumulative refunded payments are at or below %s %s; the %d above it total %s %s. These are payment totals, not individual refund events.', $within, $count, $currency, number_format($threshold, 2), count($outside), $currency, number_format(array_sum($outside), 2));
      }
      if (in_array($limit, $values['defaults'] ?? [], TRUE)) {
        $text .= sprintf(' Fewer than %d observations: using the documented starting point%s.', self::MINIMUM, $kind === 'margins' && $count > 0 ? ', capped at the observed median' : '');
      }
      $findings[$limit] = $text;
    }
    return $findings;
  }

  /**
   * A saved choice takes precedence over a newly drafted suggestion.
   */
  public function effective(array $draft): array {
    $saved = $this->configFactory->get('decionis_commerce.settings')->get('limits') ?? [];
    if (is_array($saved)) {
      foreach ($saved as $currency => $values) {
        if (is_array($values)) {
          $draft[$currency] = $values + self::defaults($currency);
        }
      }
    }
    return $draft;
  }

  /**
   * Keeps first-run suggestions in configuration, without replacing choices.
   */
  public function saveMissing(array $draft): void {
    $config = $this->configFactory->getEditable('decionis_commerce.settings');
    $existing = $config->get('limits') ?? [];
    $config->set('limits', array_replace($draft, is_array($existing) ? $existing : []))->save();
  }

  /**
   * A non-personal marker detects changed limits, including config imports.
   */
  public static function fingerprint(array $limits): string {
    $numbers = [];
    foreach ($limits as $currency => $values) {
      foreach (['refund_limit', 'margin_floor', 'stacking_ceiling', 'repeat_limit'] as $name) {
        $numbers[$currency][$name] = (float) ($values[$name] ?? self::defaults($currency)[$name]);
      }
    }
    ksort($numbers);
    return hash('sha256', json_encode($numbers, JSON_THROW_ON_ERROR));
  }

}
