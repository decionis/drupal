<?php

declare(strict_types=1);

namespace Drupal\Tests\decionis\Support;

/**
 * A concurrent synthetic API, launched on an OS-assigned loopback port.
 */
final class Loopback {

  /**
   * The child process serving the concurrent API fixture.
   *
   * @var resource
   */
  private $process;

  /**
   * The base URL assigned to the local API server.
   */
  public readonly string $url;

  public function __construct(string $mode = 'ok') {
    $this->process = proc_open(['node', dirname(__DIR__, 2) . '/fixtures/ReplayServer.mjs', $mode], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($this->process)) {
      throw new \RuntimeException('LOOPBACK_START_FAILED');
    }
    stream_set_timeout($pipes[1], 5);
    $url = trim((string) fgets($pipes[1]));
    fclose($pipes[1]);
    if (preg_match('#^http://127\.0\.0\.1:\d+$#', $url) !== 1) {
      $this->stop();
      throw new \RuntimeException('LOOPBACK_START_FAILED');
    }
    $this->url = $url;
  }

  /**
   * Only bounded request metadata, never bearer headers or customer facts.
   */
  public function stats(): array {
    $context = stream_context_create(['http' => ['timeout' => 5]]);
    return json_decode((string) file_get_contents($this->url . '/stats', FALSE, $context), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Reaps the fixture even when an assertion fails.
   */
  public function stop(): void {
    if (is_resource($this->process)) {
      proc_terminate($this->process);
      proc_close($this->process);
    }
  }

}
