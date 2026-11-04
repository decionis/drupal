<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Support;

use Drupal\decionis\Connect\Binding;

/**
 * Supplies the HTTP URL while kernel tests run through CLI.
 */
final class BindingProbe extends Binding {

  /**
   * The site URL reported to connection-binding checks.
   */
  public ?string $url = 'https://shop.example';

  /**
   * {@inheritdoc}
   */
  public function currentUrl(): ?string {
    return $this->url;
  }

}
