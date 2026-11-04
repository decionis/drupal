<?php

declare(strict_types=1);

namespace Drupal\decionis\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\decionis\Report\Protection;

/**
 * A weighted setup checklist with a concrete next step for every missing check.
 */
final class ChecklistController extends ControllerBase {

  /**
   * Renders the score and the seven checks.
   */
  public function view(): array {
    $report = \Drupal::service(Protection::class)->read();
    $rows = [];
    foreach ($report['checks'] as $check) {
      $fix = ['#type' => 'link', '#title' => $this->t('Review this check'), '#url' => Url::fromRoute($check['route'], [], isset($check['fragment']) ? ['fragment' => $check['fragment']] : [])];
      $rows[] = [$check['label'], $check['points'] . '/' . $check['weight'], $check['detail'], $check['points'] === $check['weight'] ? $this->t('Complete') : ['data' => $fix]];
    }
    return [
      'score' => ['#markup' => '<h2>' . $this->t('Protection score: @score/100', ['@score' => $report['score']]) . '</h2><p>' . $this->t('This score describes local setup. It does not certify the site or authorize any transaction. Cost points use the lower coverage share where catalog and order history exist.') . '</p>'],
      'checks' => ['#type' => 'table', '#header' => [$this->t('Check'), $this->t('Points'), $this->t('Evidence'), $this->t('Next step')], '#rows' => $rows],
      'outside' => ['#type' => 'container', '#attributes' => ['id' => 'outside-refunds'], 'text' => ['#markup' => '<h2>' . $this->t('Make outside refunds visible') . '</h2><p>' . $this->t('Where your payment provider and Commerce payment plugin support refund notifications, configure and test them with a test-mode refund. Commerce Gate can observe a refund only when the matching Drupal payment records it. A separate Fincra customer payout is not automatically reflected as a Drupal refund. An event that never reaches this site cannot appear in its reports.') . '</p>']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
