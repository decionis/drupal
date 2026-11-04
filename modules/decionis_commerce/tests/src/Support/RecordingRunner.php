<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Support;

use Drupal\decionis_commerce\Gateway\Runner;
use Drupal\decionis_commerce\Gateway\Step;

/**
 * Writes files for real but only records commands.
 */
final class RecordingRunner extends Runner {

  /**
   * The commands that would have run.
   *
   * @var list<list<string>>
   */
  public array $ran = [];

  /**
   * {@inheritdoc}
   */
  public function run(Step $step): void {
    if ($step->command !== NULL) {
      $this->ran[] = $step->command;
      return;
    }
    parent::run($step);
  }

}
