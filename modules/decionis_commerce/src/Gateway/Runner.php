<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Gateway;

use Symfony\Component\Process\Process;

/**
 * Runs setup steps on this server.
 *
 * Commands run without a shell, with a step's content on their standard input;
 * files are created with their final mode before any content is written.
 */
class Runner {

  /**
   * Runs one step, or throws naming the step that failed.
   */
  public function run(Step $step): void {
    if ($step->command !== NULL) {
      try {
        $process = new Process($step->command);
        $process->setTimeout(300)->disableOutput()->setInput($step->content);
        $status = $process->run();
      }
      catch (\Throwable) {
        throw new \RuntimeException(sprintf('Setup step failed or timed out (%s).', $step->description));
      }
      if ($status !== 0) {
        throw new \RuntimeException(sprintf('Setup step failed (%s, exit %d).', $step->description, $status));
      }
      return;
    }
    if ($step->path !== NULL && $step->content !== NULL) {
      $directory = dirname($step->path);
      if (!is_dir($directory) && !mkdir($directory, 0700, TRUE) && !is_dir($directory)) {
        throw new \RuntimeException('Cannot create ' . $directory);
      }
      $previous = umask(0077);
      try {
        if (file_put_contents($step->path, '') === FALSE || !chmod($step->path, $step->mode) || file_put_contents($step->path, $step->content) === FALSE) {
          throw new \RuntimeException('Cannot write ' . $step->path);
        }
      }
      finally {
        umask($previous);
      }
    }
  }

}
