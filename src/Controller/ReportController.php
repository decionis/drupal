<?php

declare(strict_types=1);

namespace Drupal\decionis\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\decionis\Report\Activity;
use Drupal\decionis\Report\Protection;
use Drupal\decionis\Report\Reasons;
use Drupal\decionis_commerce\Form\XrayForm;
use Drupal\decionis_commerce\Report\Coverage;
use Drupal\decionis_commerce\Report\Xray;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Printable owner reports and a matching data-only CSV, without customer data.
 */
final class ReportController extends ControllerBase {

  /**
   * Renders one local estimate or the last 90 days of Shadow decisions.
   */
  public function view(string $kind): array {
    $data = $this->data($kind);
    $build = ['#type' => 'container', '#attributes' => ['class' => ['decionis-report']], '#cache' => ['max-age' => 0], '#attached' => ['library' => ['decionis/admin']]];
    $build['label'] = ['#markup' => '<h2>' . $this->t('@label', ['@label' => $data['label']]) . '</h2><p>' . $this->t('Use your browser’s Print command to save this page as a PDF. Amounts stay in their recorded currencies.') . '</p>'];
    $build['refund_basis'] = ['#type' => 'item', '#title' => $this->t('Historical refund basis'), '#plain_text' => Activity::REPLAY_CAVEAT];
    $build['links'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['decionis-report-links']],
      'csv' => ['#type' => 'link', '#title' => $this->t('Download report CSV'), '#url' => Url::fromRoute('decionis.report_csv', ['kind' => $kind])],
      'other' => ['#type' => 'link', '#title' => $kind === 'xray' ? $this->t('90-day Shadow report') : $this->t('Store X-ray report'), '#url' => Url::fromRoute('decionis.report', ['kind' => $kind === 'xray' ? 'shadow' : 'xray'])],
    ];
    if ($kind === 'xray') {
      if ($data['report'] === []) {
        $build['empty'] = ['#markup' => '<p>' . $this->t('Run Store X-ray first to produce an estimate.') . '</p>'];
      }
      elseif ($this->moduleHandler()->moduleExists('decionis_commerce')) {
        $report = $data['report'];
        foreach ($report['top'] ?? [] as $action => $currencies) {
          foreach ($currencies as $currency => $items) {
            foreach ($items as $index => $item) {
              $report['top'][$action][$currency][$index]['reason'] = Reasons::join([$item['reason']], $action === 'refund_request');
            }
          }
        }
        $build['estimate'] = XrayForm::report($report);
      }
      if ($this->moduleHandler()->moduleExists('decionis_commerce')) {
        $build['xray'] = ['#type' => 'link', '#title' => $this->t('Run Store X-ray'), '#url' => Url::fromRoute('decionis_commerce.xray')];
      }
    }
    else {
      $report = $data['report'];
      if ($report['replay'] !== '') {
        $build['replay_window'] = ['#type' => 'item', '#title' => $this->t('Replay scope'), '#plain_text' => $report['replay']];
      }
      $build['headline'] = ['#markup' => '<p>' . $this->t('@reviewed completed Shadow decisions, @pending pending, @outside outside refunds, @uncovered without cost coverage, @ungoverned ungoverned. The window uses the action date for replay and the recorded date for live decisions.', ['@reviewed' => $report['reviewed'], '@pending' => $report['pending'], '@outside' => $report['outside'], '@uncovered' => $report['not_covered'], '@ungoverned' => $report['ungoverned']]) . '</p>'];
      $top = [];
      foreach ($report['top'] as $currency => $items) {
        foreach ($items as $item) {
          $top[] = [Activity::label($item['action'], $item['source']), $item['target_type'] . ':' . $item['target_id'], Activity::money((float) $item['at_stake'], $currency), Reasons::join($item['reasons'], $item['action'] === 'refund_request' && $item['source'] === 'replay'), $item['dossier_id']];
        }
      }
      $build['top'] = ['#type' => 'table', '#caption' => $this->t('Largest flagged actions, up to five per currency'), '#header' => [$this->t('Action'), $this->t('Local reference'), $this->t('At stake'), $this->t('Reason'), $this->t('Dossier id')], '#rows' => $top, '#empty' => $this->t('No completed Shadow actions were flagged in this window.')];
      $dossiers = [];
      foreach ($report['dossiers'] as $id => $dossier) {
        $dossiers[] = [$id, $dossier, ['data' => ['#type' => 'link', '#title' => $this->t('Verify and download'), '#url' => Url::fromRoute('decionis.verify', ['decision' => $id])]]];
      }
      $build['verification'] = ['#markup' => '<p>' . $this->t('This assembled report is unsigned. The Dossier ids below refer to individual decisions; verify each Dossier before relying on its signature. Download its full JSON and check it with: <code>npx -y @decionis/verify --file dossier.json</code>.') . '</p>'];
      $build['dossiers'] = ['#type' => 'table', '#caption' => $this->t('Individual decision evidence'), '#header' => [$this->t('Log id'), $this->t('Dossier id'), $this->t('Verification')], '#rows' => $dossiers, '#empty' => $this->t('No Dossier ids in this window.')];
    }
    $rows = [];
    foreach ($data['report']['by_action'] ?? [] as $action => $currencies) {
      foreach ($currencies as $currency => $line) {
        $rows[] = [Activity::LABELS[$action] ?? $action, $currency, $line['count'], Activity::money((float) $line['covered'], $currency), Activity::money((float) ($line['held'] ?? $line['value']), $currency)];
      }
    }
    $build['actions'] = ['#type' => 'table', '#caption' => $this->t('Per-action totals'), '#header' => [$this->t('Action'), $this->t('Currency'), $this->t('Reviewed'), $this->t('Reviewed value'), $this->t('Flagged value')], '#rows' => $rows];
    $build['coverage'] = ['#type' => 'item', '#title' => $this->t('Current cost coverage'), '#plain_text' => Protection::coverageText($data['protection']['coverage'])];
    $build['score'] = ['#type' => 'item', '#title' => $this->t('Protection score'), '#plain_text' => $data['protection']['score'] . '/100'];
    return $build;
  }

  /**
   * CSV of the same totals, top references, evidence ids, coverage, and score.
   */
  public function csv(string $kind): StreamedResponse {
    $data = $this->data($kind);
    return new StreamedResponse(static function () use ($kind, $data): void {
      $out = fopen('php://output', 'w');
      if ($out === FALSE) {
        return;
      }
      $write = static function (array $row) use ($out): void {
        fputcsv($out, array_map(static fn ($cell): string => ExportController::cell((string) $cell), $row), ',', '"', '\\');
      };
      $write(['section', 'action', 'currency', 'reference', 'metric', 'value', 'reason', 'dossier_id']);
      $write(['report', '', '', '', 'label', $data['label'], '', '']);
      $write(['report', '', '', '', 'refund_replay_basis', Activity::REPLAY_CAVEAT, '', '']);
      if (!empty($data['report']['replay'])) {
        $write(['report', '', '', '', 'replay_scope', $data['report']['replay'], '', '']);
      }
      foreach (['reviewed', 'pending', 'outside', 'not_covered', 'ungoverned'] as $metric) {
        if (isset($data['report'][$metric])) {
          $write(['headline', '', '', '', $metric, $data['report'][$metric], '', '']);
        }
      }
      foreach ($data['report']['currencies'] ?? [] as $currency => $line) {
        foreach ($line as $metric => $value) {
          if (is_scalar($value)) {
            $write(['headline', '', $currency, '', $metric, $value, '', '']);
          }
        }
      }
      foreach ($data['report']['by_action'] ?? [] as $action => $currencies) {
        foreach ($currencies as $currency => $line) {
          foreach ($line as $metric => $value) {
            $write(['action', $action, $currency, '', $metric, $value, '', '']);
          }
        }
      }
      if ($kind === 'xray') {
        foreach ($data['report']['top'] ?? [] as $action => $currencies) {
          foreach ($currencies as $currency => $items) {
            foreach ($items as $item) {
              $write(['top', $action, $currency, $item['id'], 'at_stake', $item['amount'], Reasons::join([$item['reason']], $action === 'refund_request'), '']);
            }
          }
        }
      }
      else {
        foreach ($data['report']['top'] as $currency => $items) {
          foreach ($items as $item) {
            $write(['top', $item['action'], $currency, $item['target_type'] . ':' . $item['target_id'], 'at_stake', $item['at_stake'], Reasons::join($item['reasons'], $item['action'] === 'refund_request' && $item['source'] === 'replay'), $item['dossier_id']]);
          }
        }
        foreach ($data['report']['dossiers'] as $id => $dossier) {
          $write(['evidence', '', '', $id, 'verify with npx -y @decionis/verify --file dossier.json', '', '', $dossier]);
        }
      }
      foreach (['variations', 'order_lines'] as $name) {
        foreach ($data['protection']['coverage'][$name] as $metric => $value) {
          $write(['coverage', $name, '', '', $metric, $value, '', '']);
        }
      }
      $write(['protection', '', '', '', 'score_out_of_100', $data['protection']['score'], '', '']);
      fclose($out);
    }, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="decionis-' . $kind . '-report.csv"', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
  }

  /**
   * Reads only safe summaries and references; never customer or coupon fields.
   */
  private function data(string $kind): array {
    $xray = $kind === 'xray';
    $report = $xray ? ($this->moduleHandler()->moduleExists('decionis_commerce') ? \Drupal::state()->get(Xray::STATE, []) : []) : \Drupal::service(Activity::class)->window(time() - 90 * 86400, 'shadow');
    $report = is_array($report) ? $report : [];
    $coverage = \Drupal::hasService(Coverage::class) ? \Drupal::service(Coverage::class)->totals($xray ? (int) ($report['days'] ?? 90) : 90) : NULL;
    return [
      'label' => $xray ? 'Store X-ray: unsigned estimate on your site, using suggested limits; nothing left this site.' : '90-day Shadow report: unsigned summary of individual recorded decisions.',
      'report' => $report,
      'protection' => \Drupal::service(Protection::class)->read($coverage),
    ];
  }

}
