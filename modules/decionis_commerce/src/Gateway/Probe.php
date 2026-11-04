<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Gateway;

use Symfony\Component\Process\Process;

/**
 * Reads what the operating system allows.
 *
 * Kept separate so tests can describe the server's capabilities.
 */
class Probe {

  /**
   * The effective user id of this PHP process.
   */
  public function uid(): int {
    return function_exists('posix_geteuid') ? posix_geteuid() : -1;
  }

  /**
   * The name of a user id, or NULL.
   */
  public function name(int $uid): ?string {
    $user = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : FALSE;
    return is_array($user) ? (string) $user['name'] : NULL;
  }

  /**
   * The uid and home of a named user, or NULL when there is none.
   *
   * @return array{uid: int, home: string}|null
   *   The user's id and home directory.
   */
  public function user(string $name): ?array {
    $user = function_exists('posix_getpwnam') ? posix_getpwnam($name) : FALSE;
    return is_array($user) ? ['uid' => (int) $user['uid'], 'home' => (string) $user['dir']] : NULL;
  }

  /**
   * Reads the package architecture, 'x86_64' or 'arm64'.
   *
   * Returns NULL for an architecture AgentSafe is not built for.
   */
  public function arch(): ?string {
    return match (strtolower(php_uname('m'))) {
      'x86_64', 'amd64' => 'x86_64',
      'aarch64', 'arm64' => 'arm64',
      default => NULL,
    };
  }

  /**
   * Reads the package format: 'deb' for dpkg, 'rpm' for rpm, or NULL.
   */
  public function packager(): ?string {
    foreach (['deb' => 'dpkg', 'rpm' => 'rpm'] as $format => $program) {
      foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
        if ($directory !== '' && is_executable($directory . '/' . $program)) {
          return $format;
        }
      }
    }
    return NULL;
  }

  /**
   * TRUE when sudo runs without a password prompt.
   */
  public function sudo(): bool {
    try {
      $process = new Process(['sudo', '-n', 'true']);
      $process->setTimeout(3)->run();
      return $process->isSuccessful();
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * Proves that the named user's systemd manager survives logout.
   */
  public function linger(string $user): bool {
    try {
      $process = new Process(['loginctl', 'show-user', $user, '--property=Linger', '--value']);
      $process->setTimeout(3)->run();
      return $process->isSuccessful() && trim($process->getOutput()) === 'yes';
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  /**
   * The uid owning a path (the web server writes the public files directory).
   */
  public function owner(string $path): ?int {
    $owner = @fileowner($path);
    return $owner === FALSE ? NULL : $owner;
  }

  /**
   * TRUE when AgentSafe answers on the loopback port.
   *
   * It asks the gateway's own readiness probe, which AgentSafe serves itself
   * and never puts behind an operator token: any path outside /_agentsafe
   * passes through to Fincra with the key, so a check there would reach
   * Fincra. Only a ready AgentSafe answers this one.
   */
  public function answers(int $port): bool {
    if ($port < 1024 || $port > 65535) {
      return FALSE;
    }
    $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 1.0);
    if ($socket === FALSE) {
      return FALSE;
    }
    stream_set_timeout($socket, 2);
    fwrite($socket, "GET /_agentsafe/readyz HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
    $response = stream_get_contents($socket, 65536);
    fclose($socket);
    [$head, $body] = array_pad(explode("\r\n\r\n", (string) $response, 2), 2, '');
    $status = json_decode($body, TRUE);
    return preg_match('#^HTTP/1\.[01] 200#', $head) === 1 && is_array($status) && ($status['ready'] ?? NULL) === TRUE;
  }

}
