<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\decionis_commerce\Sandbox\Local;
use Drupal\decionis_commerce\Sandbox\Sandbox;
use Drupal\decionis_commerce\Report\Limits;

/**
 * Runs the existing synthetic, transaction-isolated local sandbox with Batch.
 */
final class SandboxForm extends FormBase {

  public const STATE = 'decionis_commerce.sandbox_report';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'decionis_commerce_sandbox';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $label = 'sandbox, not production evidence · demo authority';
    $form['intro'] = ['#markup' => '<p>' . $this->t('Synthetic store, local demo authority. Nothing real is touched; nothing leaves this site. Every scenario is rolled back. These demo signatures are not production evidence.') . '</p>'];
    $report = \Drupal::state()->get(self::STATE);
    if (is_array($report)) {
      $rows = [];
      foreach ($report['scenarios'] as $row) {
        $rows[] = [$row['scenario'], $row['direct']['text'], $row['shadow']['text'], $row['enforce']['text'], $label];
      }
      $form['report'] = ['#type' => 'table', '#caption' => $label, '#header' => [$this->t('Scenario'), $this->t('Direct'), $this->t('Shadow'), $this->t('Enforce'), $this->t('Evidence scope')], '#rows' => $rows];
      $form['summary'] = [
        '#theme' => 'item_list',
        '#items' => [
          sprintf('Wiring: %d of %d hook points active. %s', $report['wiring']['active'], $report['wiring']['total'], $label),
          sprintf('Exposure: %d risky actions ran directly, %d in Shadow, %d in Enforce. %s', $report['exposure']['direct'], $report['exposure']['shadow'], $report['exposure']['enforce'], $label),
          sprintf('Evidence: %d of %d sandbox Dossiers verified with the local demo key. %s', $report['evidence']['verified'], $report['evidence']['count'], $label),
          $report['verdict'] . '. ' . $label,
        ],
      ];
    }
    $form['actions']['#type'] = 'actions';
    $form['actions']['run'] = ['#type' => 'submit', '#value' => $this->t('Run local sandbox')];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    batch_set((new BatchBuilder())->setTitle($this->t('Local sandbox'))->addOperation([self::class, 'run'])->setFinishCallback([self::class, 'finished'])->toArray());
    $form_state->setRedirect('decionis.sandbox');
  }

  /**
   * The local authority cannot provision, record remotely, or execute a refund.
   */
  public static function run(array &$context): void {
    $context['results']['report'] = \Drupal::service(Sandbox::class)->run(new Local(\Drupal::service(Limits::class)));
    $context['finished'] = 1;
  }

  /**
   * Keeps only the synthetic report after the isolated transaction is gone.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    if ($success && isset($results['report'])) {
      \Drupal::state()->set(self::STATE, $results['report']);
      \Drupal::messenger()->addStatus(t('Local sandbox complete: sandbox, not production evidence.'));
    }
    else {
      \Drupal::messenger()->addError(t('The local sandbox could not finish. Review the site status report.'));
    }
  }

}
