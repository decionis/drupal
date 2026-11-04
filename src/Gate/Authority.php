<?php

declare(strict_types=1);

namespace Drupal\decionis\Gate;

use Decionis\Client\Decision\Decision;
use Decionis\Client\Decision\Request;

/**
 * Whoever decides.
 *
 * The store's Decionis workspace, the hosted sandbox, or the labelled local
 * demo authority. The gate never decides itself.
 */
interface Authority {

  /**
   * A short label shown on every log and report line, such as 'account'.
   */
  public function label(): string;

  /**
   * TRUE when the authority can be asked at all (credentials present).
   */
  public function available(): bool;

  /**
   * Asks for a decision.
   *
   * @throws \Decionis\Client\Error\DecionisException
   *   When there is no decision: unreachable, refused or malformed.
   */
  public function evaluate(Request $request, bool $checkout, ?int $budgetMs = NULL): Decision;

}
