<?php

declare(strict_types=1);

namespace Drupal\decionis\Gate;

use Decionis\Client\Decision\Action;
use Decionis\Client\Decision\Decision as Answer;

/**
 * The approval queue seen from the gate: an ESCALATE in Enforce opens a hold.
 */
interface Holds {

  /**
   * Opens a hold for the exact action and returns its id.
   */
  public function open(Action $action, Context $context, int $logId, ?Answer $answer): int;

  /**
   * TRUE when an approved action of this kind can be run once (an executor).
   */
  public function supports(string $action): bool;

}
