<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Unit;

use Drupal\decionis\Gate\Background;
use Drupal\Tests\UnitTestCase;

/**
 * FPM closure semantics never grant a mere non-FPM flush background access.
 *
 * @group decionis
 */
final class BackgroundTest extends UnitTestCase {

  /**
   * FPM TRUE closes the request; FALSE means Response::send already closed it.
   */
  public function testFpmNewlyClosedAndAlreadyClosedResponses(): void {
    $close = new \ReflectionMethod(Background::class, 'close');
    foreach ([TRUE, FALSE] as $nativeResult) {
      $calls = 0;
      $finish = static function () use (&$calls, $nativeResult): bool {
        $calls++;
        return $nativeResult;
      };
      $this->assertTrue($close->invoke(NULL, 'fpm-fcgi', $finish));
      $this->assertSame(1, $calls);
    }
  }

  /**
   * A flush on Apache or the built-in server can never close the response.
   */
  public function testOtherSapisAndMissingFunctionDoNotEnableBackgroundWork(): void {
    $close = new \ReflectionMethod(Background::class, 'close');
    foreach (['cli-server', 'apache2handler', 'cli'] as $sapi) {
      $calls = 0;
      $finish = static function () use (&$calls): bool {
        $calls++;
        return TRUE;
      };
      $this->assertFalse($close->invoke(NULL, $sapi, $finish));
      $this->assertSame(0, $calls);
    }
    $this->assertFalse($close->invoke(NULL, 'fpm-fcgi', NULL));
  }

}
