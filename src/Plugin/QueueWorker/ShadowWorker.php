<?php

declare(strict_types=1);

namespace Drupal\decionis\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\decionis\Gate\Deferred;
use Drupal\decionis\Gate\Background;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resolves durable live Shadow captures.
 *
 * @QueueWorker(
 *   id = "decionis_shadow",
 *   title = @Translation("Commerce Gate Shadow evaluations"),
 *   cron = {"time" = 20},
 * )
 */
#[QueueWorker(id: 'decionis_shadow', title: new TranslatableMarkup('Commerce Gate Shadow evaluations'), cron: ['time' => 20])]
final class ShadowWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected Deferred $deferred) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self($configuration, $plugin_id, $plugin_definition, $container->get(Deferred::class));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!Background::allowed()) {
      throw new SuspendQueueException('Shadow reviews require an explicit cron worker or a finished FPM response.');
    }
    if (is_array($data) && isset($data['id']) && is_int($data['id']) && $data['id'] > 0) {
      $this->deferred->process($data['id']);
    }
  }

}
