<?php

declare(strict_types=1);

namespace Drupal\decionis_commerce;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\decionis_commerce\EventSubscriber\StockSubscriber;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers the stock subscriber when Commerce Stock local storage exists.
 */
final class DecionisCommerceServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $modules = $container->getParameter('container.modules');
    if (is_array($modules) && isset($modules['commerce_stock_local'])) {
      $container->register(StockSubscriber::class, StockSubscriber::class)
        ->setArguments([new Reference('Drupal\decionis\Gate\Gate'), new Reference('Drupal\decionis_commerce\Map\Actions'), new Reference('database')])
        ->addTag('event_subscriber');
    }
  }

}
