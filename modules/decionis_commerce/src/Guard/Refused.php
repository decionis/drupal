<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Guard;

/**
 * Stops a refused write when no form can display the error.
 *
 * Used for state transitions and stock transactions.
 */
final class Refused extends \RuntimeException {}
