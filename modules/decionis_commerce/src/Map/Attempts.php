<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Map;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Coupon codes tried in this visitor's session, an abuse signal.
 *
 * Only a count leaves the site: never a code, never the session id. A try
 * older than an hour drops out.
 */
final class Attempts {

  public const KEY = 'decionis_commerce.coupon_attempts';
  public const WINDOW = 3600;
  public const LIMIT = 100;

  public function __construct(private readonly RequestStack $requestStack) {}

  /**
   * Records one try of a coupon code.
   */
  public function record(?int $at = NULL): void {
    $session = $this->session();
    if ($session === NULL) {
      return;
    }
    $recent = $this->recent($session->get(self::KEY));
    $recent[] = $at ?? time();
    $session->set(self::KEY, array_slice($recent, -self::LIMIT));
  }

  /**
   * The codes tried in the last hour.
   */
  public function count(): int {
    $session = $this->session();
    return $session === NULL ? 0 : count($this->recent($session->get(self::KEY)));
  }

  /**
   * The stored tries still inside the window.
   *
   * @return list<int>
   *   Unix times.
   */
  private function recent(mixed $stamps): array {
    $since = time() - self::WINDOW;
    return array_values(array_filter(is_array($stamps) ? $stamps : [], static fn ($at): bool => is_int($at) && $at > $since));
  }

  /**
   * The visitor's session, or NULL outside a web request.
   */
  private function session(): ?SessionInterface {
    $request = $this->requestStack->getCurrentRequest();
    return $request !== NULL && $request->hasSession() ? $request->getSession() : NULL;
  }

}
