<?php

declare(strict_types=1);

namespace Drupal\decionis\Connect;

use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigEvents;
use Drupal\Core\State\StateInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Remembers whether a managed key still uses its original State provider.
 */
final class KeyChanges implements EventSubscriberInterface {

  public function __construct(private readonly StateInterface $state, private readonly ?Binding $binding = NULL) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [ConfigEvents::SAVE => 'saved'];
  }

  /**
   * Never recover an old State secret after an administrator changes provider.
   */
  public function saved(ConfigCrudEvent $event): void {
    $config = $event->getConfig();
    if (!str_starts_with($config->getName(), 'key.key.')) {
      return;
    }
    $id = substr($config->getName(), strlen('key.key.'));
    $connection = $this->binding?->record() ?? $this->state->get(Binding::STATE, []);
    if (!is_array($connection) || !array_key_exists($id, $connection['managed'] ?? [])) {
      return;
    }
    $connection['managed'][$id] = $config->get('key_provider') === 'state' && $config->get('key_provider_settings.state_key') === 'decionis.secret.' . $id;
    $provider = hash('sha256', json_encode([$config->get('key_provider'), $config->get('key_provider_settings')], JSON_THROW_ON_ERROR));
    if (($connection['providers'][$id] ?? NULL) !== $provider) {
      $connection['providers'][$id] = $provider;
      $connection['generation'] = bin2hex(random_bytes(16));
    }
    $this->state->set(Binding::STATE, $connection);
  }

}
