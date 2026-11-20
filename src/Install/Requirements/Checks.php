<?php

declare(strict_types=1);

namespace Drupal\decionis\Install\Requirements;

use Drupal\Core\Extension\InstallRequirementsInterface;
use Drupal\decionis\Report\Environment;
use Drupal\decionis\Report\Severity;

// Core loads this file before the module's namespace is registered.
if (!class_exists(Severity::class)) {
  require_once __DIR__ . '/../../Report/Severity.php';
}
if (!class_exists(Environment::class)) {
  require_once __DIR__ . '/../../Report/Environment.php';
}

/**
 * Install-time requirements on Drupal 11.2 and later.
 *
 * Earlier cores read the install phase of decionis_requirements().
 */
final class Checks implements InstallRequirementsInterface {

  /**
   * {@inheritdoc}
   */
  public static function getRequirements(): array {
    return Environment::requirements();
  }

}
