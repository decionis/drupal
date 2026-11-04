<?php

declare(strict_types=1);

namespace Drupal\decionis\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\decionis\Approval\Approvals;
use Drupal\decionis\Entity\Hold;
use Drupal\decionis\Gate\Gate;
use Drupal\decionis\Gate\Settings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Pending approvals: open each held action in Presence, then run it once.
 */
final class ApprovalsForm extends FormBase {

  public function __construct(protected Approvals $approvals, protected Gate $gate, protected Settings $settings) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self($container->get(Approvals::class), $container->get(Gate::class), $container->get(Settings::class));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'decionis_approvals_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $storage = \Drupal::entityTypeManager()->getStorage('decionis_hold');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('state', ['pending', 'approved'], 'IN')->sort('id', 'DESC')->range(0, 100)->execute();
    $form['holds'] = [
      '#type' => 'table',
      '#header' => [$this->t('Held'), $this->t('Action'), $this->t('Target'), $this->t('At stake'), $this->t('State'), $this->t('Approve'), $this->t('Run')],
      '#empty' => $this->t('Nothing is waiting for approval.'),
    ];
    $hidden = 0;
    $identity = $this->settings->connectionIdentity();
    foreach ($storage->loadMultiple($ids) as $hold) {
      assert($hold instanceof Hold);
      $captured = $hold->value('connection_id');
      if ($identity === NULL || $captured === '' || !hash_equals($identity, $captured)) {
        $hidden++;
        continue;
      }
      $id = (int) $hold->id();
      $url = $hold->value('approval_url');
      $form['holds'][$id] = [
        'held' => ['#markup' => gmdate('Y-m-d H:i', (int) $hold->get('created')->value) . ' UTC'],
        'action' => ['#plain_text' => $hold->value('action')],
        'target' => ['#plain_text' => $hold->value('target_type') . ' ' . $hold->value('target_id')],
        'at_stake' => ['#markup' => trim($hold->value('currency') . ' ' . number_format((float) $hold->value('at_stake'), 2))],
        'state' => ['#markup' => $hold->value('state')],
        'approve' => $url !== '' && str_starts_with($url, 'https://')
          ? ['#type' => 'link', '#title' => $this->t('Open in Presence'), '#url' => Url::fromUri($url)]
          : ['#markup' => $this->t('No approval link yet')],
        'run' => $hold->value('state') === 'approved'
          ? ['#type' => 'submit', '#value' => $this->t('Run once'), '#name' => 'run_' . $id, '#hold' => $id]
          : ['#type' => 'submit', '#value' => $this->t('Check approval'), '#name' => 'check_' . $id, '#hold' => $id],
      ];
    }
    if ($identity === NULL || $hidden > 0) {
      $form['connection'] = ['#markup' => '<p>' . $this->t('Approvals from a copied, disconnected or previous connection are hidden. Reconnect this site before requesting new approvals; existing approvals stay with their original connection.') . '</p>'];
    }
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $hold = \Drupal::entityTypeManager()->getStorage('decionis_hold')->load((int) ($trigger['#hold'] ?? 0));
    if (!$hold instanceof Hold) {
      return;
    }
    $identity = $this->settings->connectionIdentity();
    if ($identity === NULL || $hold->value('connection_id') === '' || !hash_equals($identity, $hold->value('connection_id'))) {
      $this->messenger()->addError($this->t('This approval belongs to a previous site connection. Request a new approval after reconnecting.'));
      return;
    }
    if (str_starts_with((string) ($trigger['#name'] ?? ''), 'run_')) {
      $result = $this->approvals->execute($hold, $this->gate);
      $result === 'executed'
        ? $this->messenger()->addStatus($this->t('The approved action ran once.'))
        : $this->messenger()->addError($this->t('Not run: @reason.', ['@reason' => $result]));
      return;
    }
    $state = $this->approvals->refresh($hold)->value('state');
    $this->messenger()->addStatus($this->t('Approval state: @state.', ['@state' => $state]));
  }

}
