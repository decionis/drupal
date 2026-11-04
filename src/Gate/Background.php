<?php

declare(strict_types=1);

namespace Drupal\decionis\Gate;

use Drupal\Core\Form\FormStateInterface;
use Drupal\system\Form\CronForm;

/**
 * Keeps automated web cron from putting remote work back on a live request.
 */
final class Background {

  /**
   * Whether FPM has closed the current web response.
   */
  private static bool $finished = FALSE;

  /**
   * Whether the explicitly selected Run cron operation is executing.
   */
  private static bool $manual = FALSE;

  /**
   * Allows explicit cron, CLI workers, or a response that FPM has closed.
   */
  public static function allowed(): bool {
    if (PHP_SAPI === 'cli' || self::$finished || self::$manual) {
      return TRUE;
    }
    $request = \Drupal::service('request_stack')->getMainRequest();
    return in_array($request?->attributes->get('_route'), ['system.cron', 'system.run_cron'], TRUE);
  }

  /**
   * Called before AutomatedCron's terminate subscriber, never a mere flush.
   */
  public static function finishResponse(): bool {
    if (self::$finished) {
      return TRUE;
    }
    $finish = function_exists('fastcgi_finish_request') ? static fn (): bool => fastcgi_finish_request() : NULL;
    self::$finished = self::close(PHP_SAPI, $finish);
    return self::$finished;
  }

  /**
   * Wraps only the native Run cron button, after normal form validation.
   */
  public static function alterCronForm(array &$form, FormStateInterface $state): void {
    foreach ($form['run']['#submit'] ?? [] as $index => $callback) {
      if ($callback === '::runCron') {
        $form['run']['#submit'][$index] = [self::class, 'runCron'];
      }
    }
  }

  /**
   * Allows the explicitly selected operation, then restores the web guard.
   */
  public static function runCron(array &$form, FormStateInterface $state): void {
    $object = $state->getFormObject();
    if (!$object instanceof CronForm) {
      throw new \LogicException('EXPLICIT_CRON_FORM_REQUIRED');
    }
    $previous = self::$manual;
    self::$manual = TRUE;
    try {
      $object->runCron($form, $state);
    }
    finally {
      self::$manual = $previous;
    }
  }

  /**
   * A testable boundary for the native FPM completion function.
   *
   * In FPM, TRUE means it closed the response; FALSE means it was already
   * closed (as Symfony Response::send normally does before kernel.terminate).
   * Calling any other SAPI's flush is never sufficient.
   *
   * @param string $sapi
   *   PHP server API identifier for the current process.
   * @param (callable(): bool)|null $finish
   *   The native fastcgi_finish_request function, only when it exists.
   */
  private static function close(string $sapi, ?callable $finish): bool {
    if ($sapi !== 'fpm-fcgi' || $finish === NULL) {
      return FALSE;
    }
    $finish();
    return TRUE;
  }

}
