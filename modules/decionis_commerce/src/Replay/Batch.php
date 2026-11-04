<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Replay;

/**
 * Shows initial live replay results before the connect form redirects.
 */
final class Batch {

  /**
   * Attaches three initial operations; the existing queue owns the rest.
   */
  public static function attach(): void {
    $run = \Drupal::service(Runs::class)->current();
    $id = $run['id'] ?? \Drupal::service(Runs::class)->start(90);
    batch_set([
      'title' => t('Replaying recent store history'),
      'init_message' => t('Checking your recent orders, refunds and promotions in Shadow Mode.'),
      'progress_message' => t('Completed @current of @total initial batches.'),
      'error_message' => t('The initial replay was interrupted. Completed results are on Decisions; the remaining history continues on cron.'),
      'operations' => array_fill(0, 3, [[self::class, 'step'], [$id]]),
      'finished' => [self::class, 'finished'],
    ]);
  }

  /**
   * Each operation is bounded even when the remote service is unavailable.
   */
  public static function step(string $id, array &$context): void {
    $run = \Drupal::service(Runs::class)->advance($id, TRUE);
    $context['results']['id'] = $id;
    $context['finished'] = isset($run['busy']) ? 0 : 1;
    $context['message'] = t('Recorded @count checks. Remaining history continues on cron.', ['@count' => $run['reviewed'] ?? 0]);
  }

  /**
   * Explains missing decisions instead of leaving an unexplained empty page.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $run = \Drupal::service(Runs::class)->current();
    if (!$success || ($run['id'] ?? NULL) !== ($results['id'] ?? NULL)) {
      \Drupal::messenger()->addWarning(t('The initial replay was interrupted. Completed checks are on Decisions; check the connection before continuing.'));
      return;
    }
    if ($run['status'] === 'connection_changed') {
      \Drupal::messenger()->addWarning(t('Replay stopped because this site’s connection changed. Reconnect to start a replay for this workspace.'));
    }
    elseif ($run['unavailable'] > 0) {
      $notice = \Drupal::translation()->formatPlural($run['unavailable'], 'Commerce Gate could not decide 1 check in this run.', 'Commerce Gate could not decide @count checks in this run.');
      \Drupal::messenger()->addWarning($notice . ' ' . t('Previous decisions were kept; checks without one are marked ungoverned. An unavailable check is not a policy verdict.'));
    }
    if ($run['status'] === 'running') {
      \Drupal::messenger()->addStatus(t('The first replay results are ready. The remaining @days-day history continues on cron.', ['@days' => $run['days']]));
    }
    elseif ($run['status'] === 'complete' && $run['reviewed'] === 0) {
      \Drupal::messenger()->addStatus(t('There were no matching orders, refunds or promotions in the last @days days.', ['@days' => $run['days']]));
    }
    elseif ($run['status'] === 'complete') {
      \Drupal::messenger()->addStatus(t('Replay complete: @count checks over @days days of store history.', ['@count' => $run['reviewed'], '@days' => $run['days']]));
    }
  }

}
