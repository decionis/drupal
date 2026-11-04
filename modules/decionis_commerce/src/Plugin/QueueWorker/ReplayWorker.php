<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\decionis\Gate\Background;
use Drupal\decionis_commerce\Replay\Runs;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs one replay batch per cron queue item after the site connects.
 */
#[QueueWorker(
  id: 'decionis_replay',
  title: new TranslatableMarkup('Commerce Gate 90-day replay'),
  cron: ['time' => 30],
)]
final class ReplayWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(array $configuration, $plugin_id, $plugin_definition, protected Runs $runs) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self($configuration, $plugin_id, $plugin_definition, $container->get(Runs::class));
  }

  /**
   * Queues the first replay batch.
   */
  public static function enqueue(int $days): void {
    \Drupal::service(Runs::class)->start($days);
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    // Pre-Revision-1 items have no frozen connection identity. They cannot
    // safely inherit whichever workspace happens to be connected today.
    if (!is_array($data) || !is_string($data['run'] ?? NULL) || preg_match('/^[a-f0-9]{32}$/', $data['run']) !== 1) {
      return;
    }
    if (!Background::allowed()) {
      throw new SuspendQueueException('REPLAY_REQUIRES_BACKGROUND_CRON');
    }
    $run = $this->runs->advance($data['run']);
    if (isset($run['busy']) || isset($run['deferred'])) {
      throw new SuspendQueueException('REPLAY_BUSY');
    }
    if (($run['id'] ?? NULL) === $data['run'] && ($run['status'] ?? NULL) === 'running') {
      $this->runs->queue($data['run']);
    }
  }

}
