<?php

declare(strict_types=1);

namespace Drupal\decionis\Approval;

use Decionis\Client\Decision\Action;
use Drupal\decionis\Entity\Hold;

/**
 * Runs one kind of held action once it is approved.
 */
interface Executor {

  /**
   * TRUE for the action keys this executor runs ('refund_request').
   */
  public function handles(string $action): bool;

  /**
   * The action as it stands now, or NULL when its target is gone.
   */
  public function capture(Hold $hold): ?Action;

  /**
   * Performs the action. Called once, after a successful claim.
   */
  public function run(Hold $hold): void;

}
