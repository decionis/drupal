<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\decionis_commerce\Report\Coverage;
use Drupal\decionis_commerce\Report\Limits;
use Drupal\decionis_commerce\Report\Xray;
use Drupal\decionis_commerce\Report\XrayBatch;

/**
 * The merchant's first result, on their own site before connecting.
 */
final class XrayForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'decionis_commerce_xray';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['intro'] = ['#markup' => '<p>' . $this->t('Store X-ray: estimate on your site, using suggested limits; nothing left this site.') . '</p>'];
    $form['coverage'] = ['#markup' => '<p>' . $this->t('Uses recorded order prices and currently stored variation costs. Refund entries are cumulative refunded payments, not individual refund events; the window uses the available payment changed time (Commerce 3) or completion time (Commerce 2). Promotion checks use current configurations changed in the window. Outside refunds include only observations received locally; refunds your site never saw cannot be counted. These estimates are not signed evidence.') . '</p>'];
    $coverage = \Drupal::service(Coverage::class)->variations();
    $form['variation_coverage'] = ['#markup' => '<p>' . $this->t('Variations with cost: @costed of @total (@percent%).', ['@costed' => $coverage['costed'], '@total' => $coverage['total'], '@percent' => $coverage['percent']]) . '</p>'];
    $report = \Drupal::state()->get(Xray::STATE);
    if (is_array($report)) {
      $form['result'] = self::report($report);
    }
    $form['days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days to review'),
      '#min' => 30,
      '#max' => 365,
      '#default_value' => $report['days'] ?? 90,
      '#required' => TRUE,
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['run'] = ['#type' => 'submit', '#value' => $this->t('Run Store X-ray')];
    $form['settings'] = ['#type' => 'link', '#title' => $this->t('Review limits and settings'), '#url' => Url::fromRoute('decionis.settings')];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * Totals and safe entity references, shared by the printable report.
   */
  public static function report(array $report): array {
    $changed = isset($report['limits_fingerprint']) && $report['limits_fingerprint'] !== Limits::fingerprint(\Drupal::service(Limits::class)->effective([]));
    if (!empty($report['stale']) || $changed) {
      $build['stale'] = ['#markup' => '<p>' . t('Limits have changed since this estimate. Run Store X-ray again before using it to judge the new limits.') . '</p>'];
    }
    $build['label'] = ['#markup' => '<p>' . t('Last @days days: estimate on your site, using suggested limits; nothing left this site.', ['@days' => $report['days'] ?? 90]) . '</p>'];
    $rows = [];
    foreach ($report['currencies'] ?? [] as $currency => $line) {
      $coverage = $line['order_lines'] > 0 ? round(100 * $line['costed_lines'] / $line['order_lines'], 1) . '%' : 'No order lines';
      $build['headline_' . $currency] = ['#markup' => '<p>' . t('@count orders sold under your margin floor, @currency @value below it, and @outside refunds were observed outside Drupal.', ['@count' => $line['orders_below'], '@currency' => $currency, '@value' => number_format($line['value_below'], 2), '@outside' => $line['outside_refunds']]) . '</p>'];
      $rows[] = [$currency, $line['orders_below'], number_format($line['value_below'], 2), $line['refunds_over'], number_format($line['refund_value'], 2), $line['stacked_promotions'], $line['outside_refunds'], $coverage . ' (' . $line['costed_lines'] . '/' . $line['order_lines'] . ')'];
    }
    $build['totals'] = [
      '#type' => 'table',
      '#header' => [t('Currency'), t('Orders below floor'), t('Value below floor'), t('Payments over refund limit'), t('Cumulative refunded value'), t('Stacked promotions'), t('Outside refunds'), t('Order lines with cost')],
      '#rows' => $rows,
      '#empty' => t('No recorded history in this window.'),
    ];
    $top = [];
    foreach ($report['top'] ?? [] as $action => $currencies) {
      foreach ($currencies as $currency => $items) {
        foreach ($items as $item) {
          $top[] = [$action, $item['id'], $currency, number_format($item['amount'], 2), number_format($item['below'], 2), $item['reason']];
        }
      }
    }
    $build['top'] = ['#type' => 'table', '#caption' => t('Top five items per action and currency'), '#header' => [t('Action'), t('Local reference'), t('Currency'), t('Value'), t('Below floor'), t('Suggested rule')], '#rows' => $top, '#empty' => t('No items were flagged by these limits.')];
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $days = (int) $form_state->getValue('days');
    $batch = (new BatchBuilder())->setTitle($this->t('Store X-ray'))->addOperation([XrayBatch::class, 'run'], [$days, time()])->setFinishCallback([XrayBatch::class, 'finished']);
    batch_set($batch->toArray());
    $form_state->setRedirect('decionis_commerce.xray');
  }

}
