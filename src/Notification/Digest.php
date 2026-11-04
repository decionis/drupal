<?php

declare(strict_types=1);

namespace Drupal\decionis\Notification;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis\Gate\Background;
use Drupal\decionis\Report\Activity;
use Drupal\user\UserInterface;

/**
 * An opt-in weekly Shadow summary sent by Drupal's mail system.
 */
final class Digest {

  public function __construct(
    private readonly ConfigFactoryInterface $config,
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
    private readonly KeyValueFactoryInterface $keyValue,
    private readonly LockBackendInterface $lock,
    private readonly MailManagerInterface $mailManager,
    private readonly TimeInterface $time,
    private readonly Settings $settings,
  ) {}

  /**
   * Opt-in configuration kept apart from recipients' private email addresses.
   */
  public static function form(array $form): array {
    $config = \Drupal::config('decionis.settings');
    $ids = (array) ($config->get('digest.recipients') ?? []);
    $users = \Drupal::entityTypeManager()->getStorage('user')->loadMultiple($ids);
    $form['digest'] = [
      '#type' => 'details',
      '#title' => t('Weekly Shadow digest'),
      '#tree' => TRUE,
      '#description' => t('An opt-in summary of actions your policy would have stopped, sent through this site’s Drupal mail system.'),
    ];
    $form['digest']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => t('Send the weekly digest'),
      '#default_value' => (bool) $config->get('digest.enabled'),
    ];
    $form['digest']['recipients'] = [
      '#type' => 'entity_autocomplete',
      '#title' => t('Recipients'),
      '#target_type' => 'user',
      '#tags' => TRUE,
      '#default_value' => array_values($users),
      '#selection_settings' => ['include_anonymous' => FALSE],
      '#description' => t('Select up to 100 active users who can administer Commerce Gate. Permission is checked again before each email.'),
    ];
    $form['digest']['day'] = [
      '#type' => 'select',
      '#title' => t('Day'),
      '#default_value' => (int) $config->get('digest.day'),
      '#options' => [t('Monday'), t('Tuesday'), t('Wednesday'), t('Thursday'), t('Friday'), t('Saturday'), t('Sunday')],
    ];
    return $form;
  }

  /**
   * Checks both the explicit opt-in and the users authorized to receive it.
   */
  public static function validate(array &$form, FormStateInterface $state): void {
    $values = (array) $state->getValue('digest', []);
    if (empty($values['enabled'])) {
      return;
    }
    $recipients = (array) ($values['recipients'] ?? []);
    if (count($recipients) > 100 || $recipients === []) {
      $state->setErrorByName('digest][recipients', t('Choose between 1 and 100 active administrators for the digest.'));
      return;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('user');
    foreach ($recipients as $item) {
      $user = $storage->load($item['target_id'] ?? 0);
      if (!$user instanceof UserInterface || !$user->isActive() || !$user->hasPermission('administer decionis')) {
        $state->setErrorByName('digest][recipients', t('Every recipient must be an active user with permission to administer Commerce Gate.'));
        break;
      }
    }
  }

  /**
   * Saves only local account ids; addresses are resolved at delivery time.
   */
  public static function submit(FormStateInterface $state): void {
    $values = (array) $state->getValue('digest', []);
    $ids = array_values(array_unique(array_map(static fn (array $item): int => (int) $item['target_id'], (array) ($values['recipients'] ?? []))));
    \Drupal::configFactory()->getEditable('decionis.settings')
      ->set('digest.enabled', !empty($values['enabled']))->set('digest.recipients', $ids)
      ->set('digest.day', max(0, min(6, (int) ($values['day'] ?? 0))))->save();
  }

  /**
   * Sends at most once per local calendar week to each eligible recipient.
   */
  public function sendDue(): int {
    if (!Background::allowed()) {
      return 0;
    }
    $config = $this->config->get('decionis.settings');
    $connection = $this->settings->connectionIdentity();
    if (!$config->get('digest.enabled') || $connection === NULL) {
      return 0;
    }
    $zone = (string) ($this->config->get('system.date')->get('timezone.default') ?: 'UTC');
    $now = (new \DateTimeImmutable('@' . $this->time->getCurrentTime()))->setTimezone(new \DateTimeZone($zone));
    $day = max(0, min(6, (int) $config->get('digest.day')));
    if ((int) $now->format('N') - 1 < $day) {
      return 0;
    }
    $week = $now->format('o-W');
    $receipts = $this->keyValue->get('decionis_digest');
    $ids = array_slice(array_unique(array_map('intval', (array) ($config->get('digest.recipients') ?? []))), 0, 100);
    $body = $this->body($now->getTimestamp() - 7 * 86400, $connection);
    $sent = 0;
    foreach ($ids as $id) {
      $user = $this->entities->getStorage('user')->load($id);
      if (!$user instanceof UserInterface || !$user->isActive() || !$user->hasPermission('administer decionis') || !$user->getEmail()) {
        continue;
      }
      $key = $week . ':' . $id;
      $lock = 'decionis_digest:' . $key;
      if (!$this->lock->acquire($lock, 120.0)) {
        continue;
      }
      try {
        if ($this->settings->connectionIdentity() !== $connection) {
          break;
        }
        if ($receipts->get($key) !== NULL) {
          continue;
        }
        // A durable claim prevents a retry after a process interruption from
        // sending the same weekly email twice. Only an explicit mail failure
        // is retried. No message text or email address is persisted here.
        $receipts->set($key, ['status' => 'sending', 'at' => $now->getTimestamp()]);
        $message = $this->mailManager->mail('decionis', 'digest', $user->getEmail(), $user->getPreferredLangcode(), ['body' => $body], NULL, TRUE);
        if (!empty($message['result'])) {
          $receipts->set($key, ['status' => 'sent', 'at' => $now->getTimestamp()]);
          $sent++;
        }
        else {
          $receipts->delete($key);
        }
      }
      finally {
        $this->lock->release($lock);
      }
    }
    return $sent;
  }

  /**
   * Builds a PII-free summary from resolved, successful live Shadow actions.
   *
   * @return list<string>
   *   Plain-text summary lines, including links to the relevant settings.
   */
  private function body(int $since, string $connection): array {
    $query = $this->database->select('decionis_decision', 'd')->fields('d', ['action', 'currency'])
      ->condition('d.mode', 'shadow')->condition('d.source', 'live')->condition('d.effect', 'proceeded')
      ->condition('d.save_confirmed', 1)->condition('d.connection_id', $connection)
      ->condition('d.verdict', ['BLOCK', 'ESCALATE'], 'IN')->condition('d.created', $since, '>=');
    // Checkout never waits for an approver, including in Enforce.
    $query->condition($query->orConditionGroup()->condition('d.verdict', 'BLOCK')->condition('d.checkout', 0)
      ->condition($query->andConditionGroup()->isNull('d.checkout')->condition('d.action', 'order_acceptance', '<>')));
    $query->addExpression('COUNT(*)', 'count');
    $query->addExpression('SUM(COALESCE(d.at_stake, 0))', 'amount');
    $query->groupBy('d.action')->groupBy('d.currency');
    $body = ['Your weekly Commerce Gate Shadow review', 'These saved actions would have been stopped or required approval under your policy. Shadow allowed them to continue.'];
    $found = FALSE;
    foreach ($query->execute() as $row) {
      if (!isset(Activity::LABELS[$row->action])) {
        continue;
      }
      $found = TRUE;
      $body[] = sprintf('%s: %d actions; %s %s at stake.', Activity::LABELS[$row->action], $row->count, number_format((float) $row->amount, 2), $row->currency);
      $body[] = 'Enforce this one: ' . Url::fromRoute('decionis.enforce', ['action' => $row->action], ['absolute' => TRUE])->toString();
    }
    if (!$found) {
      $body[] = 'No completed Shadow reviews of saved actions would have stopped an action in the past seven days.';
    }
    $body[] = 'Review your settings: ' . Url::fromRoute('decionis.settings', [], ['absolute' => TRUE])->toString();
    return $body;
  }

  /**
   * Implements the message builder called by hook_mail().
   */
  public static function mail(string $key, array &$message, array $params): void {
    if ($key === 'digest') {
      $message['subject'] = 'Your weekly Commerce Gate Shadow review';
      $message['body'] = array_values(array_filter((array) ($params['body'] ?? []), 'is_string'));
    }
  }

}
