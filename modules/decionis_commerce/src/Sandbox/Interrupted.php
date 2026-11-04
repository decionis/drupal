<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Sandbox;

/**
 * Thrown when the database ended a sandbox run's transaction before its end.
 */
final class Interrupted extends \RuntimeException {}
