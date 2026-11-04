<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\decionis_commerce\Import\Costs;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Uploads costs through Form API and saves them in bounded Batch operations.
 */
final class CostImportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'decionis_commerce_cost_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['intro'] = ['#markup' => '<p>' . $this->t('Import variation costs from CSV with header sku,cost or sku,cost,currency. An omitted currency uses the variation’s price currency. Existing costs are replaced. The file is validated first, then up to 50 costs are saved per batch. Maximum 2 MiB and 5,000 unique SKUs.') . '</p>'];
    $form['csv'] = ['#type' => 'file', '#title' => $this->t('Cost CSV'), '#required' => TRUE, '#attributes' => ['accept' => '.csv,text/csv']];
    $form['actions']['#type'] = 'actions';
    $form['actions']['import'] = ['#type' => 'submit', '#value' => $this->t('Import costs')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $files = $this->getRequest()->files->get('files', []);
    $file = is_array($files) ? ($files['csv'] ?? NULL) : NULL;
    if (!$file instanceof UploadedFile || !$file->isValid() || strtolower($file->getClientOriginalExtension()) !== 'csv') {
      $form_state->setErrorByName('csv', $this->t('Choose a valid CSV upload.'));
      return;
    }
    try {
      /** @var \Drupal\decionis_commerce\Import\Costs $costs */
      $costs = \Drupal::service(Costs::class);
      $form_state->set('rows', $costs->prepare($costs->read($file->getPathname())));
    }
    catch (\InvalidArgumentException $error) {
      $form_state->setErrorByName('csv', $this->t('@error', ['@error' => $error->getMessage()]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $batch = (new BatchBuilder())->setTitle($this->t('Importing variation costs'))->setFinishCallback([self::class, 'finished']);
    foreach (array_chunk($form_state->get('rows'), 50) as $rows) {
      $batch->addOperation([self::class, 'save'], [$rows]);
    }
    batch_set($batch->toArray());
    $form_state->setRedirect('decionis.cost_import');
  }

  /**
   * Saves at most 50 rows in this request.
   */
  public static function save(array $rows, array &$context): void {
    foreach ($rows as $row) {
      \Drupal::service(Costs::class)->save($row);
      $context['results']['updated'] = ($context['results']['updated'] ?? 0) + 1;
    }
    $context['message'] = t('@count costs saved.', ['@count' => $context['results']['updated']]);
  }

  /**
   * Reports the exact number saved even when a later batch failed.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    if ($success) {
      \Drupal::messenger()->addStatus(t('Cost set on @count variations.', ['@count' => $results['updated'] ?? 0]));
    }
    else {
      \Drupal::messenger()->addError(t('Import stopped after @count saved costs. Review the remaining rows before retrying.', ['@count' => $results['updated'] ?? 0]));
    }
  }

}
