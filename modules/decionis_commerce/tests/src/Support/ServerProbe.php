<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis_commerce\Support;

use Drupal\decionis_commerce\Gateway\Probe;

/**
 * A server described for the test.
 */
final class ServerProbe extends Probe {

  public function __construct(
    private readonly int $self,
    private readonly bool $sudo = FALSE,
    private readonly ?int $dedicated = NULL,
    private readonly ?int $web = NULL,
    public bool $answering = FALSE,
    public ?string $arch = 'x86_64',
    public ?string $packager = 'deb',
    public bool $lingering = TRUE,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function arch(): ?string {
    return $this->arch;
  }

  /**
   * {@inheritdoc}
   */
  public function packager(): ?string {
    return $this->packager;
  }

  /**
   * {@inheritdoc}
   */
  public function uid(): int {
    return $this->self;
  }

  /**
   * {@inheritdoc}
   */
  public function name(int $uid): string {
    return 'www';
  }

  /**
   * {@inheritdoc}
   */
  public function user(string $name): ?array {
    return $this->dedicated === NULL ? NULL : ['uid' => $this->dedicated, 'home' => '/home/' . $name];
  }

  /**
   * {@inheritdoc}
   */
  public function sudo(): bool {
    return $this->sudo;
  }

  /**
   * {@inheritdoc}
   */
  public function linger(string $user): bool {
    return $this->lingering;
  }

  /**
   * {@inheritdoc}
   */
  public function owner(string $path): ?int {
    return $this->web;
  }

  /**
   * {@inheritdoc}
   */
  public function answers(int $port): bool {
    return $this->answering;
  }

}
