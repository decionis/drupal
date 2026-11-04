<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Gateway;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\decionis\Gate\Background;
use Drupal\user\UserInterface;

/**
 * Independent Commerce cron health check for a gateway holding payment keys.
 */
final class Watchdog {

  use StringTranslationTrait;

  public const STATE = 'decionis.gateway_health';

  public function __construct(
    private readonly StateInterface $state,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly Custody $custody,
    private readonly MailManagerInterface $mail,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly Probe $probe,
  ) {}

  /**
   * Checks only AgentSafe's own finite-time loopback readiness endpoint.
   */
  public function run(): void {
    if (!Background::allowed()) {
      return;
    }
    $record = $this->state->get(Setup::STATE, []);
    if (!is_array($record) || empty($record['custody']) || !$this->custody->owned($record)) {
      return;
    }
    $previous = $this->state->get(self::STATE, []);
    $previous = is_array($previous) ? $previous : [];
    $answering = $this->probe->answers((int) ($record['port'] ?? 0));
    $current = $this->custody->valid($record);
    $ready = $answering && $current;
    $health = ['checked_at' => time(), 'ready' => $ready, 'answering' => $answering, 'connection_current' => $current, 'notified' => $ready ? [] : ($previous['notified'] ?? [])];
    if ($ready) {
      $this->state->set(self::STATE, $health);
      return;
    }
    if (($previous['ready'] ?? NULL) !== FALSE) {
      $this->loggerFactory->get('decionis')->critical('AgentSafe is unavailable or uses an earlier site connection while it holds the payment key. Check its service or run drush decionis:gateway-rollback.');
    }
    // Retry unsuccessful deliveries at most once every five minutes. A
    // successful recipient is never mailed again during the same outage.
    if ((int) ($previous['mailed_at'] ?? 0) > time() - 300) {
      $health['mailed_at'] = $previous['mailed_at'];
      $this->state->set(self::STATE, $health);
      return;
    }
    $users = $this->entityTypeManager->getStorage('user');
    $ids = $users->getQuery()->accessCheck(FALSE)->condition('status', 1)->condition('uid', 0, '>')->execute();
    foreach ($users->loadMultiple($ids) as $user) {
      if (!$user instanceof UserInterface || !$user->hasPermission('administer decionis') || !$user->getEmail() || !empty($health['notified'][(string) $user->id()])) {
        continue;
      }
      try {
        $message = $this->mail->mail('decionis_commerce', 'gateway_watchdog', $user->getEmail(), $user->getPreferredLangcode(), [], NULL, TRUE);
        if (!empty($message['result'])) {
          $health['notified'][(string) $user->id()] = TRUE;
        }
      }
      catch (\Throwable) {
        $this->loggerFactory->get('decionis')->error('The AgentSafe availability email could not be delivered.');
      }
    }
    $health['mailed_at'] = time();
    $this->state->set(self::STATE, $health);
  }

  /**
   * Cached health for the status report; this never contacts the gateway.
   */
  public function requirements(): array {
    $record = $this->state->get(Setup::STATE, []);
    if (!is_array($record) || empty($record['custody']) || !$this->custody->owned($record)) {
      return [];
    }
    $health = $this->state->get(self::STATE, []);
    $ready = is_array($health) ? ($health['ready'] ?? NULL) : NULL;
    $changed = !$this->custody->valid($record);
    if ($changed) {
      $ready = FALSE;
    }
    return [
      'decionis_agentsafe' => [
        'title' => $this->t('AgentSafe payment gateway'),
        'value' => $changed ? $this->t('Critical: AgentSafe uses an earlier site connection') : ($ready === TRUE ? $this->t('Ready at the last cron check') : ($ready === FALSE ? $this->t('Critical: AgentSafe is unavailable') : $this->t('Awaiting the first cron health check'))),
        'severity' => $ready === TRUE ? 0 : ($ready === FALSE ? 2 : 1),
        'description' => $ready === FALSE ? $this->t('AgentSafe holds the payment key and checkout may fail. Restart its service or run drush decionis:gateway-rollback to return the key through a private Key file provider.') : $this->t('Drupal cron checks the local AgentSafe readiness endpoint. No payment or policy request is sent.'),
      ],
    ];
  }

}
