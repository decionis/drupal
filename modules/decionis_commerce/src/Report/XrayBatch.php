<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Report;

use Drupal\decionis_commerce\Replay\Replay;

/**
 * Two bounded passes: draft suggestions, then estimate against fixed limits.
 */
final class XrayBatch {

  /**
   * Numeric drafting samples live only in the temporary Batch API sandbox.
   */
  public static function run(int $days, int $until, array &$context): void {
    if ($days < 30 || $days > 365) {
      throw new \InvalidArgumentException('XRAY_DAYS_INVALID');
    }
    $sandbox = &$context['sandbox'];
    if ($sandbox === []) {
      $sandbox = ['phase' => 0, 'kind' => 0, 'after' => 0, 'samples' => [], 'report' => Xray::empty()];
    }
    $kind = History::KINDS[$sandbox['kind']];
    /** @var \Drupal\decionis_commerce\Replay\Replay $replay */
    $replay = \Drupal::service(Replay::class);
    if ($sandbox['phase'] === 0) {
      $batch = $replay->sample($days, $kind, $sandbox['after'], $until);
      foreach ($batch['samples'] as $currency => $kinds) {
        foreach ($kinds as $name => $values) {
          $sandbox['samples'][$currency][$name] = [...($sandbox['samples'][$currency][$name] ?? []), ...$values];
        }
      }
    }
    else {
      $batch = $replay->demo($days, $kind, $sandbox['after'], $sandbox['limits'], $until);
      $sandbox['report'] = Xray::merge($sandbox['report'], $batch);
    }
    if ($batch['after'] !== NULL) {
      $sandbox['after'] = $batch['after'];
    }
    else {
      $sandbox['kind']++;
      $sandbox['after'] = 0;
    }
    if ($sandbox['kind'] === count(History::KINDS)) {
      if ($sandbox['phase'] === 0) {
        $draft = Limits::draft($sandbox['samples'], \Drupal::service(History::class)->currencies());
        $sandbox['limits'] = \Drupal::service(Limits::class)->effective($draft);
        unset($sandbox['samples']);
        $sandbox['phase'] = 1;
        $sandbox['kind'] = 0;
      }
      else {
        $context['results'] = ['report' => $sandbox['report'], 'days' => $days, 'until' => $until, 'limits' => $sandbox['limits']];
        $context['finished'] = 1;
        return;
      }
    }
    $context['message'] = $sandbox['phase'] === 0 ? t('Drafting suggested limits from local history. Nothing leaves this site.') : t('Estimating what those limits would flag. Nothing leaves this site.');
    $context['finished'] = ($sandbox['phase'] * count(History::KINDS) + $sandbox['kind'] + 0.1) / (2 * count(History::KINDS));
  }

  /**
   * Retains only totals, top references and report dates after rollback.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    if (!$success || !isset($results['report'])) {
      \Drupal::messenger()->addError(t('The Store X-ray could not finish. Your previous result is unchanged.'));
      return;
    }
    \Drupal::service(Limits::class)->saveMissing($results['limits']);
    \Drupal::state()->set(Xray::STATE, $results['report'] + ['days' => $results['days'], 'until' => $results['until'], 'limits_fingerprint' => Limits::fingerprint($results['limits'])]);
    \Drupal::messenger()->addStatus(t('Store X-ray complete: estimate on your site, using suggested limits; nothing left this site.'));
  }

}
