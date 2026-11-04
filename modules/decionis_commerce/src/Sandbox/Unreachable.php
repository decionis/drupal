<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Sandbox;

use Decionis\Client\Decision\Decision;
use Decionis\Client\Decision\Request;
use Decionis\Client\Error\TransportException;
use Drupal\decionis\Gate\Authority;

/**
 * An authority that never answers, for the "authority unreachable" scenario.
 */
final class Unreachable implements Authority {

  public function __construct(private readonly string $label) {}

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return $this->label;
  }

  /**
   * {@inheritdoc}
   */
  public function available(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function evaluate(Request $request, bool $checkout, ?int $budgetMs = NULL): Decision {
    throw new TransportException('UPSTREAM_UNREACHABLE');
  }

}
