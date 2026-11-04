<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Unit;

use Drupal\decionis_commerce\Report\Limits;
use Drupal\Tests\UnitTestCase;

/**
 * Locally drafted limits are based on each currency's own distribution.
 *
 * @group decionis
 */
final class LimitsTest extends UnitTestCase {

  /**
   * A single outlier cannot set the 95th percentile of twenty refunds.
   */
  public function testSkewedCurrencyDistributions(): void {
    $draft = Limits::draft([
      'NGN' => ['refunds' => [...array_fill(0, 19, 100000), 2400000], 'margins' => range(1, 20), 'stacking' => array_fill(0, 20, 25), 'repeats' => array_fill(0, 20, 1)],
      'EUR' => ['refunds' => [...array_fill(0, 19, 70), 2000]],
    ]);
    $this->assertSame(100000.0, $draft['NGN']['refund_limit']);
    $this->assertSame(70.0, $draft['EUR']['refund_limit']);
    $this->assertSame(2.0, $draft['NGN']['margin_floor']);
    $this->assertSame(25.0, $draft['NGN']['stacking_ceiling']);
    $this->assertSame(2, $draft['NGN']['repeat_limit']);
    $this->assertStringContainsString('19 of 20 cumulative refunded payments', $draft['NGN']['findings']['refund_limit']);
    $this->assertStringContainsString('NGN 2,400,000.00', $draft['NGN']['findings']['refund_limit']);
  }

  /**
   * A cumulative payment total is never described as one refund event.
   */
  public function testCumulativePaymentFindingsAreExplicit(): void {
    $values = Limits::defaults('EUR');
    $findings = Limits::findings('EUR', $values, ['refunds' => [300 + 300]]);
    $this->assertStringContainsString('0 of 1 cumulative refunded payments', $findings['refund_limit']);
    $this->assertStringContainsString('payment totals, not individual refund events', $findings['refund_limit']);
    $this->assertStringContainsString('EUR 600.00', $findings['refund_limit']);
  }

  /**
   * Empty data and thin history make their starting-point fallback explicit.
   */
  public function testEmptyAndSingleCurrencyHistory(): void {
    $empty = Limits::draft([], ['NGN', 'EUR', 'JPY']);
    $this->assertSame(150000.0, $empty['NGN']['refund_limit']);
    $this->assertSame(500.0, $empty['EUR']['refund_limit']);
    $this->assertSame(0.0, $empty['JPY']['refund_limit']);
    $this->assertStringContainsString('Fewer than 20 observations', $empty['NGN']['findings']['refund_limit']);
    $single = Limits::draft(['EUR' => ['refunds' => [15], 'margins' => [4]]]);
    $this->assertSame(['EUR'], array_keys($single));
    $this->assertSame(4.0, $single['EUR']['margin_floor'], 'even a fallback never exceeds the observed median');
    $this->assertSame(500.0, $single['EUR']['refund_limit']);
  }

}
