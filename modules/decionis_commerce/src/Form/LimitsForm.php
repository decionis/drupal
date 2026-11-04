<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\decionis_commerce\Replay\Replay;
use Drupal\decionis_commerce\Report\History;
use Drupal\decionis_commerce\Report\Limits;
use Drupal\decionis_commerce\Report\Xray;

/**
 * Per-currency local limits and the findings behind every value.
 */
final class LimitsForm {

  use StringTranslationTrait;

  private const LABELS = [
    'refund_limit' => 'Refund amount needing approval',
    'margin_floor' => 'Order margin floor (%)',
    'stacking_ceiling' => 'Stacked discount ceiling (%)',
    'repeat_limit' => 'Repeated refunds needing approval',
  ];

  public function __construct(
    private readonly Limits $limits,
    private readonly History $history,
    private readonly Replay $replay,
  ) {}

  /**
   * Adds the local policy section to the existing settings form.
   */
  public function build(array &$form, FormStateInterface $formState): void {
    $samples = $formState->get('decionis_limit_samples');
    if (!is_array($samples)) {
      $samples = [];
      $until = time();
      foreach (['order', 'refund', 'promotion'] as $kind) {
        $after = 0;
        do {
          $batch = $this->replay->sample(90, $kind, $after, $until);
          foreach ($batch['samples'] as $currency => $groups) {
            foreach ($groups as $name => $numbers) {
              $samples[$currency][$name] = [...($samples[$currency][$name] ?? []), ...$numbers];
            }
          }
          $after = $batch['after'];
        } while ($after !== NULL);
      }
      $formState->set('decionis_limit_samples', $samples);
    }
    $values = $this->limits->effective(Limits::draft($samples, $this->history->currencies()));
    $formState->set('decionis_limit_values', $values);
    $submitted = $formState->getValue('decionis_limits') ?? ($formState->getUserInput()['decionis_limits'] ?? []);
    $form['decionis_limits'] = [
      '#type' => 'details',
      '#title' => $this->t('Limits from your store history'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#prefix' => '<div id="decionis-limits">',
      '#suffix' => '</div>',
      'notice' => ['#markup' => '<p>' . $this->t('These limits apply to the local X-ray and sandbox. Publishing them to your connected workspace is not available yet; connected checks still use its current policy. Findings use the last 90 days on this site.') . '</p>'],
    ];
    foreach ($values as $currency => $chosen) {
      $preview = $chosen;
      foreach (array_keys(self::LABELS) as $key) {
        $value = $submitted[$currency][$key] ?? NULL;
        if (is_numeric($value) && is_finite((float) $value)) {
          $preview[$key] = (float) $value;
          if ((float) $value !== (float) $chosen[$key]) {
            $preview['defaults'] = array_values(array_diff($preview['defaults'] ?? [], [$key]));
          }
        }
      }
      $findings = Limits::findings($currency, $preview, $samples[$currency] ?? []);
      $form['decionis_limits'][$currency] = ['#type' => 'fieldset', '#title' => $currency];
      foreach (self::LABELS as $key => $label) {
        $field = [
          '#type' => 'number',
          '#title' => $label,
          '#default_value' => $chosen[$key],
          '#required' => TRUE,
          '#step' => $key === 'repeat_limit' ? 1 : 'any',
          '#description' => $findings[$key],
          '#ajax' => ['callback' => [self::class, 'refresh'], 'wrapper' => 'decionis-limits', 'event' => 'change'],
        ];
        if ($key !== 'margin_floor') {
          $field['#min'] = $key === 'repeat_limit' ? 1 : 0;
        }
        if (in_array($key, ['margin_floor', 'stacking_ceiling'], TRUE)) {
          $field['#max'] = 100;
        }
        $form['decionis_limits'][$currency][$key] = $field;
      }
    }
  }

  /**
   * Refreshes only the limits section when a value changes.
   */
  public static function refresh(array &$form, FormStateInterface $formState): array {
    return $form['decionis_limits'];
  }

  /**
   * Requires finite values and whole repeat-refund counts.
   */
  public function validate(array &$form, FormStateInterface $formState): void {
    foreach ($formState->get('decionis_limit_values') ?? [] as $currency => $prior) {
      foreach (array_keys(self::LABELS) as $key) {
        $value = $formState->getValue(['decionis_limits', $currency, $key]);
        if (!is_numeric($value) || !is_finite((float) $value) || ($key !== 'margin_floor' && (float) $value < 0) || ($key === 'repeat_limit' && ((float) $value < 1 || (float) $value !== floor((float) $value))) || (in_array($key, ['margin_floor', 'stacking_ceiling'], TRUE) && (float) $value > 100)) {
          $formState->setErrorByName('decionis_limits][' . $currency . '][' . $key, $this->t('Enter a valid limit for @currency.', ['@currency' => $currency]));
        }
      }
    }
  }

  /**
   * Stores chosen limits in configuration; only actual edits count as tuned.
   */
  public function submit(array &$form, FormStateInterface $formState): void {
    $chosen = $formState->get('decionis_limit_values') ?? [];
    $samples = $formState->get('decionis_limit_samples') ?? [];
    $changed = FALSE;
    foreach ($chosen as $currency => &$values) {
      $edited = [];
      foreach (array_keys(self::LABELS) as $key) {
        $value = $formState->getValue(['decionis_limits', $currency, $key]);
        if ((float) $value !== (float) $values[$key]) {
          $edited[] = $key;
          $changed = TRUE;
        }
        $values[$key] = $key === 'repeat_limit' ? (int) $value : (float) $value;
      }
      if ($edited !== []) {
        $values['source'] = 'tuned';
        $values['defaults'] = array_values(array_diff($values['defaults'] ?? [], $edited));
      }
      $values['findings'] = Limits::findings($currency, $values, $samples[$currency] ?? []);
    }
    unset($values);
    \Drupal::configFactory()->getEditable('decionis_commerce.settings')->set('limits', $chosen)->save();
    if ($changed) {
      $report = \Drupal::state()->get(Xray::STATE);
      if (is_array($report)) {
        $report['stale'] = TRUE;
        \Drupal::state()->set(Xray::STATE, $report);
      }
      \Drupal::messenger()->addStatus($this->t('Limits updated. Run Store X-ray again to estimate their impact.'));
    }
  }

}
