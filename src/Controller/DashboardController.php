<?php

declare(strict_types=1);

namespace Drupal\decionis\Controller;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\decionis\Entity\Decision;
use Drupal\decionis\Form\DisconnectForm;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis\Report\Activity;
use Drupal\decionis\Report\Evidence;
use Drupal\decionis\Report\Enforcement;
use Drupal\decionis\Report\Plan;
use Drupal\decionis\Report\Protection;
use Drupal\decionis\Report\Reasons;
use Drupal\decionis\Report\Summary;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Decisions dashboard.
 *
 * Reviewed actions, value covered, and what would have been held or blocked,
 * each linked to its Dossier.
 */
final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly Summary $summary,
    private readonly Settings $settings,
    private readonly Plan $plan,
    private readonly Activity $activity,
    private readonly Evidence $evidence,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get(Summary::class), $container->get(Settings::class), $container->get(Plan::class), $container->get(Activity::class), $container->get(Evidence::class));
  }

  /**
   * Renders the Decisions dashboard.
   */
  public function view(): array {
    $totals = $this->summary->totals(time() - 90 * 86400);
    $activity = $this->activity->window(time() - 90 * 86400);
    $build = [];
    if ($this->settings->copied()) {
      $build['copy'] = DisconnectForm::copyNotice();
    }
    foreach ($activity['top'] as $currency => $items) {
      $item = $items[0];
      $build['catch_' . $currency] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['decionis-catch']],
        'title' => ['#markup' => '<h2>' . $this->t('Largest catch in @currency', ['@currency' => $currency ?: 'actions without a currency']) . '</h2>'],
        'detail' => ['#plain_text' => sprintf('%s: %s. %s', Activity::label($item['action'], $item['source']), Activity::money((float) $item['at_stake'], $currency), Reasons::join($item['reasons'], $item['action'] === 'refund_request' && $item['source'] === 'replay'))],
        'rule' => ['#type' => 'item', '#title' => $this->t('Rule'), '#plain_text' => implode(', ', $item['reasons'])],
        'proof' => self::proofLinks((int) $item['id'], (string) $item['dossier_id'], 'catch'),
      ];
    }
    $rows = [];
    foreach ($totals['by_action'] as $action => $currencies) {
      foreach ($currencies as $currency => $line) {
        $rows[] = [Activity::LABELS[$action] ?? $action, $line['count'], Activity::money($line['covered'], $currency), Activity::money($line['held'], $currency)];
      }
    }
    $build['status'] = [
      '#markup' => '<p>' . ($this->settings->connected() ? $this->t('Connected.') : $this->t('Not connected: actions are logged as ungoverned.')) . '</p>',
    ];
    $modes = [];
    foreach (Activity::LABELS as $action => $label) {
      $modes[] = $label . ': ' . strtoupper($this->settings->mode($action));
    }
    $build['modes'] = ['#theme' => 'item_list', '#title' => $this->t('Current action modes'), '#items' => $modes];
    // The days left in a trial or grace period, as on the settings page.
    $build['plan'] = ['#markup' => '<p>' . $this->plan->notice() . '</p>'];
    if ($activity['replay'] !== '') {
      $build['replay_window'] = ['#type' => 'item', '#title' => $this->t('Replay scope'), '#plain_text' => $activity['replay']];
    }
    if ($activity['has_replay']) {
      $build['refund_basis'] = ['#type' => 'item', '#title' => $this->t('Historical refund basis'), '#plain_text' => Activity::REPLAY_CAVEAT];
    }
    $build['enforcement'] = $this->enforcement($activity);
    $protection = \Drupal::service(Protection::class)->read();
    $build['protection'] = ['#type' => 'link', '#title' => $this->t('Protection score: @score/100 — review the checklist', ['@score' => $protection['score']]), '#url' => Url::fromRoute('decionis.checklist')];
    if ($this->moduleHandler()->moduleExists('decionis_commerce')) {
      $build['xray'] = ['#type' => 'link', '#title' => $this->t('Store X-ray: explore your history offline'), '#url' => Url::fromRoute('decionis_commerce.xray')];
    }
    $build['summary'] = [
      '#type' => 'table',
      '#caption' => sprintf('Last 90 days: %d reviewed, %d pending, %d ungoverned, %d refunds made outside Drupal, %d not covered (no cost data).', $totals['reviewed'], $totals['pending'], $totals['ungoverned'], $totals['outside'], $totals['not_covered']),
      '#header' => ['Action', 'Reviewed', 'Value covered', 'Held or blocked (or would have been)'],
      '#rows' => $rows,
      '#empty' => $this->t('No decisions yet. Start with Store X-ray to explore your history, then connect for signed decisions.'),
    ];
    $build['recent'] = $this->recent();
    $build['export'] = [
      '#type' => 'link',
      '#title' => 'Export CSV',
      '#url' => Url::fromRoute('decionis.export'),
    ];
    $build['report'] = ['#type' => 'link', '#title' => $this->t('Printable owner and agency report'), '#url' => Url::fromRoute('decionis.report', ['kind' => 'shadow'])];
    $build['#cache']['max-age'] = 0;
    $build['#attached']['library'][] = 'core/drupal.ajax';
    $build['#attached']['library'][] = 'decionis/admin';
    return $build;
  }

  /**
   * Verifies one decision's Dossier offline against the published keys.
   */
  public function verify(int $decision, Request $request): array|AjaxResponse {
    $entry = $this->entry($decision);
    try {
      $result = $this->evidence->verify($entry);
      $text = sprintf('Signature %s. Evidence coverage %s. Key id: %s. Signed policy version: %s.', $result['signature_valid'] ? 'valid' : 'NOT VERIFIED', $result['complete'] ? 'complete' : 'incomplete', $result['key_id'], $result['policy_version']);
    }
    catch (\Throwable) {
      $text = 'Could not verify this Dossier. Commerce Gate is unavailable, or the returned evidence does not match this action.';
    }
    $build = [
      'summary' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')],
      'download' => ['#type' => 'link', '#title' => $this->t('Download full Dossier JSON'), '#url' => Url::fromRoute('decionis.dossier', ['decision' => $decision])],
      'instructions' => ['#markup' => '<p>Check it yourself: <code>npx -y @decionis/verify --file dossier.json</code></p>'],
      '#cache' => ['max-age' => 0],
    ];
    if ($request->query->get('_wrapper_format') === 'drupal_ajax') {
      $slot = $request->query->get('slot') === 'catch' ? 'catch' : 'recent';
      return (new AjaxResponse())->addCommand(new HtmlCommand('#decionis-proof-' . $slot . '-' . $decision, $build));
    }
    return $build;
  }

  /**
   * Downloads the complete payload, including every signed artifact.
   */
  public function download(int $decision): Response {
    $entry = $this->entry($decision);
    try {
      $record = $this->evidence->record($entry);
      $json = json_encode($record->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT);
    }
    catch (\Throwable) {
      return new Response('Dossier unavailable or does not match this action.', 503, ['Cache-Control' => 'no-store']);
    }
    return new Response($json, 200, ['Content-Type' => 'application/json', 'Content-Disposition' => 'attachment; filename="dossier.json"', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
  }

  /**
   * Loads one local decision with evidence.
   */
  private function entry(int $decision): Decision {
    $entry = $this->entityTypeManager()->getStorage('decionis_decision')->load($decision);
    if (!$entry instanceof Decision || $entry->value('dossier_id') === '') {
      throw new NotFoundHttpException();
    }
    return $entry;
  }

  /**
   * Builds the table of the 50 latest decisions.
   */
  private function recent(): array {
    $storage = $this->entityTypeManager()->getStorage('decionis_decision');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('id', 'DESC')->range(0, 50)->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $entry) {
      assert($entry instanceof Decision);
      $dossier = $entry->value('dossier_id');
      $rows[] = [
        gmdate('Y-m-d H:i', (int) $entry->get('created')->value),
        Activity::label($entry->value('action'), $entry->value('source')),
        $entry->value('source') === 'live' ? strtoupper($entry->value('mode')) : $entry->value('source'),
        $entry->value('verdict') ?: 'no decision',
        $entry->value('effect'),
        Reasons::join($entry->reasons(), $entry->value('action') === 'refund_request' && $entry->value('source') === 'replay'),
        Activity::money((float) $entry->value('at_stake'), $entry->value('currency')),
        ['data' => self::proofLinks((int) $entry->id(), $dossier)],
      ];
    }
    return [
      '#type' => 'table',
      '#header' => ['When (UTC)', 'Action', 'Mode', 'Verdict', 'Effect', 'Reasons', 'At stake', 'Dossier'],
      '#rows' => $rows,
    ];
  }

  /**
   * Gives every supported action its own forecast and enforcement control.
   */
  private function enforcement(array $activity): array {
    $rows = [];
    $fincra = Enforcement::fincraOnly();
    foreach (Activity::LABELS as $action => $label) {
      $enabled = $this->settings->enforcementAllowed() && $this->settings->connected() && $this->currentUser()->hasPermission('administer decionis') && !($action === 'refund_request' && $fincra);
      $control = $enabled
        ? ['#type' => 'link', '#title' => $this->t('Enforce this one'), '#url' => Url::fromRoute('decionis.enforce', ['action' => $action])]
        : ['#type' => 'html_tag', '#tag' => 'button', '#value' => $this->t('Enforce this one'), '#attributes' => ['type' => 'button', 'disabled' => 'disabled', 'aria-disabled' => 'true']];
      $note = $action === 'refund_request' && $fincra ? ' Fincra refunds happen outside Drupal and cannot be stopped here. Start with orders, prices or promotions.' : '';
      $rows[] = [$label, strtoupper($this->settings->mode($action)), Enforcement::impact($activity['by_action'][$action] ?? []) . $note, ['data' => $control]];
    }
    return ['#type' => 'table', '#caption' => $this->t('Enforce one action. Predicted impact from completed decisions in the last 90 days; estimates depend on the workspace policy. Controls need an active enforcement plan and an administrator.'), '#header' => [$this->t('Action'), $this->t('Mode'), $this->t('Predicted impact'), $this->t('Control')], '#rows' => $rows];
  }

  /**
   * A progressive AJAX control with an ordinary verification page fallback.
   */
  private static function proofLinks(int $id, string $dossier, string $slot = 'recent'): array {
    if ($dossier === '') {
      return ['#plain_text' => ''];
    }
    return [
      'id' => ['#type' => 'html_tag', '#tag' => 'small', '#value' => htmlspecialchars($dossier, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')],
      'verify' => ['#type' => 'link', '#title' => t('Verify'), '#url' => Url::fromRoute('decionis.verify', ['decision' => $id], ['query' => ['slot' => $slot]]), '#attributes' => ['class' => ['use-ajax']]],
      'download' => ['#type' => 'link', '#title' => t('Download full Dossier JSON'), '#url' => Url::fromRoute('decionis.dossier', ['decision' => $id])],
      'result' => ['#type' => 'container', '#attributes' => ['id' => 'decionis-proof-' . $slot . '-' . $id, 'role' => 'status', 'aria-live' => 'polite']],
    ];
  }

}
