<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Gateway;

/**
 * One step of the setup: a command to run, a file to write, or a note.
 */
final class Step {

  /**
   * Describes a command, file write or manual step in the setup plan.
   *
   * @param string $description
   *   The human-readable purpose of the step.
   * @param list<string>|null $command
   *   The command and its arguments, run without a shell.
   * @param string|null $path
   *   A file to write.
   * @param string|null $content
   *   Its content.
   * @param int $mode
   *   The filesystem mode to apply when creating a file.
   * @param bool $secret
   *   TRUE when the content must never be printed.
   * @param string|null $owner
   *   The requested file owner, or NULL to retain the current account.
   */
  public function __construct(
    public readonly string $description,
    public readonly ?array $command = NULL,
    public readonly ?string $path = NULL,
    public readonly ?string $content = NULL,
    public readonly int $mode = 0644,
    public readonly bool $secret = FALSE,
    public readonly ?string $owner = NULL,
  ) {}

  /**
   * How the step reads in a dry run: exact, with secrets left out.
   */
  public function describe(): string {
    if ($this->command !== NULL) {
      $input = $this->content === NULL ? '' : ($this->secret ? ' < [secret, not shown]' : "\nStandard input (exact contents):\n" . $this->content);
      return implode(' ', array_map('escapeshellarg', $this->command)) . $input;
    }
    if ($this->path !== NULL) {
      $owner = $this->owner === NULL ? '' : sprintf(', owned by %s', $this->owner);
      return sprintf('write %s (mode %04o%s)%s', $this->path, $this->mode, $owner, $this->secret ? ': [secret, not shown]' : "\nExact contents:\n" . (string) $this->content);
    }
    return $this->description;
  }

}
