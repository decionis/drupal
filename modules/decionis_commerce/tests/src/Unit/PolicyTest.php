<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Unit;

use Decionis\Client\Canonical\SortedJson;
use Drupal\decionis_commerce\Policy;
use Drupal\Tests\UnitTestCase;

/**
 * The public limits contract preserves numeric values and rejects unsafe input.
 *
 * @group decionis
 */
final class PolicyTest extends UnitTestCase {

  /**
   * A Node JSON.stringify golden vector covers fractions, key order and -0.
   */
  public function testDigestMatchesCanonicalJavascript(): void {
    $limits = [
      'USD' => ['refund_limit' => -0.0, 'repeat_limit' => 1, 'stacking_ceiling' => 0.1, 'margin_floor' => 1.0E-7],
      'NGN' => ['refund_limit' => 150000.0, 'margin_floor' => 12.5, 'stacking_ceiling' => 30.0, 'repeat_limit' => 3, 'findings' => ['remain local']],
    ];
    $this->assertSame('sha256:47f019207dba98d369d7a0b13364032f4055cd1cb8caa19aa42cad97a61b3fdf', Policy::digest($limits));
    $this->assertSame(1.0E-7, Policy::normalize($limits)['USD']['margin_floor']);
    $this->assertArrayNotHasKey('findings', Policy::normalize($limits)['NGN']);
  }

  /**
   * The portable fixture also runs in the Commerce API contract tests.
   */
  public function testSharedNumericFixtures(): void {
    $path = dirname(__DIR__, 5) . '/tests/fixtures/PolicyLimits.json';
    $fixtures = json_decode((string) file_get_contents($path), TRUE, 32, JSON_THROW_ON_ERROR);
    foreach ($fixtures as $fixture) {
      $limits = json_decode($fixture['input_json'], TRUE, 32, JSON_THROW_ON_ERROR);
      $this->assertSame($fixture['canonical_json'], (new SortedJson())->encode(Policy::normalize($limits)), $fixture['name']);
      $this->assertSame($fixture['sha256'], Policy::digest($limits), $fixture['name']);
    }
  }

  /**
   * Invalid remote values cannot be silently coerced or rounded into a policy.
   */
  public function testInvalidLimitsAreRejected(): void {
    $valid = ['refund_limit' => 150000, 'margin_floor' => 12, 'stacking_ceiling' => 30, 'repeat_limit' => 3];
    $invalid = [
      [],
      ['ngn' => $valid],
      ['NGN' => array_replace($valid, ['refund_limit' => INF])],
      ['NGN' => array_replace($valid, ['margin_floor' => -1])],
      ['NGN' => array_replace($valid, ['stacking_ceiling' => 101])],
      ['NGN' => array_replace($valid, ['repeat_limit' => 1.5])],
      ['NGN' => array_replace($valid, ['refund_limit' => '150000'])],
    ];
    foreach ($invalid as $limits) {
      try {
        Policy::normalize($limits);
        $this->fail('An invalid map was accepted.');
      }
      catch (\InvalidArgumentException $error) {
        $this->assertSame('DECIONIS_LIMITS_INVALID', $error->getMessage());
      }
    }
  }

}
