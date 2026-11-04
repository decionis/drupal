<?php

declare(strict_types=1);

namespace Drupal\decionis\Notification;

use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\decionis\Gate\Capture;
use Drupal\decionis\Gate\Outcome;
use Drupal\decionis\Gate\Settings;
use Drupal\decionis\Report\Reasons;

/**
 * One local message for the administrator whose saved action was reviewed.
 */
final class Nudges {

  public function __construct(
    private readonly Connection $database,
    private readonly Settings $settings,
    private readonly AccountProxyInterface $user,
    private readonly MessengerInterface $messenger,
  ) {}

  /**
   * Plain text from bounded commerce facts; never customer or coupon data.
   */
  public static function text(Capture $capture, Outcome $outcome): string {
    if (!in_array($outcome->verdict?->value, ['BLOCK', 'ESCALATE'], TRUE)
      || $capture->context->checkout || $capture->context->source !== 'live') {
      return '';
    }
    $payload = $capture->request->action->payload;
    $action = $capture->context->action;
    $verb = $outcome->verdict?->value === 'BLOCK' ? 'blocked' : 'required approval for';
    if ($action === 'price_change' && ($payload['estimated_cost'] ?? 0) > 0 && $payload['to_price'] < $payload['estimated_cost']) {
      $percent = round(100 * (1 - $payload['to_price'] / $payload['estimated_cost']), 1);
      $sku = mb_substr((string) $payload['sku'], 0, 120);
      return sprintf('Commerce Gate would have %s this price: %s now sells %s%% below cost.', $verb, $sku, $percent);
    }
    $label = match ($action) {
      'price_change' => 'price change',
      'promotion_change' => 'promotion',
      'inventory_mutation' => 'stock adjustment',
      'refund_request' => 'refund',
      'order_acceptance' => 'order',
      default => 'action',
    };
    $reason = Reasons::join($outcome->reasons);
    return mb_substr(sprintf('Commerce Gate would have %s this %s. %s', $verb, $label, $reason), 0, 1000);
  }

  /**
   * Claims messages once, after a successful save and a completed evaluation.
   */
  public function deliver(): int {
    $connection = $this->settings->connectionIdentity();
    if (!$this->user->isAuthenticated() || !$this->user->hasPermission('administer decionis') || $connection === NULL) {
      return 0;
    }
    $query = $this->database->select('decionis_decision', 'd')->fields('d', ['id', 'nudge_text'])
      ->condition('d.recipient', (int) $this->user->id())->condition('d.connection_id', $connection)
      ->condition('d.nudge_state', 'pending')->condition('d.save_confirmed', 1)
      ->condition('d.mode', 'shadow')->condition('d.source', 'live')
      ->condition('d.verdict', ['BLOCK', 'ESCALATE'], 'IN')->condition('d.effect', 'proceeded')
      ->isNotNull('d.nudge_text')->orderBy('d.id')->range(0, 20);
    $count = 0;
    foreach ($query->execute() as $row) {
      $claimed = $this->database->update('decionis_decision')->fields(['nudge_state' => 'delivered'])
        ->condition('id', $row->id)->condition('nudge_state', 'pending')->execute();
      if ($claimed) {
        // Plain strings are escaped by Drupal's message rendering.
        $this->messenger->addWarning((string) $row->nudge_text, TRUE);
        $count++;
      }
    }
    return $count;
  }

}
