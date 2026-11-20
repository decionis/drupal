<?php

declare(strict_types=1);

namespace Drupal\decionis\Report;

/**
 * Status report severities for every supported Drupal core.
 *
 * Drupal 11.2 added the RequirementSeverity enum and deprecated the
 * REQUIREMENT_* constants; Drupal 12 removes the constants and reads only the
 * enum. Earlier cores have no enum and read the integers.
 *
 * @see https://www.drupal.org/node/3410939
 */
final class Severity {

  private const ENUM = 'Drupal\Core\Extension\Requirement\RequirementSeverity';

  /**
   * The requirement is met.
   */
  public static function ok(): \UnitEnum|int {
    return self::level('OK', 0);
  }

  /**
   * The requirement is met with a condition worth flagging.
   */
  public static function warning(): \UnitEnum|int {
    return self::level('Warning', 1);
  }

  /**
   * The requirement is not met.
   */
  public static function error(): \UnitEnum|int {
    return self::level('Error', 2);
  }

  /**
   * Returns the enum case where core has it, or the legacy integer.
   */
  private static function level(string $case, int $legacy): \UnitEnum|int {
    return enum_exists(self::ENUM) ? constant(self::ENUM . '::' . $case) : $legacy;
  }

}
